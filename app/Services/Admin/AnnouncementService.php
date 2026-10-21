<?php

namespace App\Services\Admin;

use App\Models\DonationOpportunity;
use App\Models\User;
use App\Services\AnnouncementContent;
use App\Services\MediaStorage;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AnnouncementService
{
    public function __construct(private readonly MediaStorage $media) {}

    public function detail(int $id): array
    {
        $item = $this->find($id, true);
        $joined = $item->participations()->where('status', '!=', 'cancelled');

        return [...$this->profile($item),
            'participants_count' => (clone $joined)->distinct()->count('user_id'),
            'participants_preview' => User::withTrashed()->select(['id', 'name'])
                ->whereIn('id', $joined->select('user_id'))->orderBy('id')->limit(3)->get()
                ->map(fn (User $donor): array => ['id' => $donor->id, 'name' => $donor->name])->all()];
    }

    /** Staff history includes cancelled attempts; joined count is distinct non-cancelled donors. */
    public function participants(int $id): array
    {
        $item = $this->find($id, true);
        $page = $item->participations()->with(['user' => fn ($query) => $query->withTrashed()->select(['id', 'name'])])
            ->orderByDesc('joined_at')->orderByDesc('id')->paginate(20);

        return ['data' => $page->getCollection()->map(fn ($entry): array => [
            'id' => $entry->id, 'status' => $entry->status,
            'joined_at' => $entry->joined_at?->toISOString(), 'cancelled_at' => $entry->cancelled_at?->toISOString(),
            'donor' => ['id' => $entry->user_id, 'name' => $entry->user?->name ?? 'Unavailable donor'],
        ])->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total()]];
    }

    public function profile(DonationOpportunity $item): array
    {
        return [...$item->toArray(), 'status' => $item->managementStatus(),
            'scheduled_for_deletion_at' => $item->deleted_at?->copy()->addDays(30)->toISOString()];
    }

    public function listing(array $filters): array
    {
        $query = DonationOpportunity::withTrashed()->where('source_type', 'admin_announcement');
        if (trim($filters['search'] ?? '') !== '') {
            $query->where(function ($query) use ($filters): void {
                $query->whereLike('title', '%'.trim($filters['search']).'%')->orWhereLike('location', '%'.trim($filters['search']).'%');
            });
        }
        if (isset($filters['month'])) {
            $query->whereMonth('event_date', $filters['month']);
        }
        if (isset($filters['year'])) {
            $query->whereYear('event_date', $filters['year']);
        }
        $items = $query->orderByDesc('id')->get()->map(fn (DonationOpportunity $item): array => $this->profile($item));
        if (($filters['status'] ?? 'all') !== 'all') {
            $items = $items->where('status', $filters['status']);
        }

        return ['data' => $items->values()->all()];
    }

    public function find(int $id, bool $deleted = false): DonationOpportunity
    {
        return DonationOpportunity::query()->when($deleted, fn ($query) => $query->withTrashed())
            ->where('source_type', 'admin_announcement')->findOrFail($id);
    }

    private function audit(User $actor, DonationOpportunity $item, string $action): void
    {
        $actor->auditLogs()->create(['actor_role' => $actor->role, 'action' => 'announcement_'.$action,
            'module' => 'announcements', 'target_type' => 'donation_opportunity', 'target_id' => $item->id]);
    }

    private function publish(DonationOpportunity $item, User $actor, bool $notify): void
    {
        if (! $item->expires_at || $item->expires_at->lte(now())) {
            throw ValidationException::withMessages(['expires_at' => 'Choose a future expiration date before publishing.']);
        }
        if ($item->status === 'published') {
            return;
        }
        $item->status = 'published';
        $item->published_at = now();
        $item->save();
        $this->audit($actor, $item, 'published');
        app(NotificationService::class)->notifyImportantAnnouncement($item, $notify);
    }

    public function save(User $actor, array $data, ?int $id = null): DonationOpportunity
    {
        $newPath = null;
        try {
            $item = DB::transaction(function () use ($actor, $data, $id, &$newPath): DonationOpportunity {
                $item = $id === null ? new DonationOpportunity : DonationOpportunity::where('source_type', 'admin_announcement')->lockForUpdate()->findOrFail($id);
                $creating = ! $item->exists;
                $item->fill(['title' => $data['title'], 'description' => $data['description'], 'location' => $data['location'],
                    'event_date' => $data['donation_date'],
                    'expires_at' => Carbon::parse($data['expires_at'] ?? $data['donation_date'], config('app.calendar_timezone'))->endOfDay()->utc()]);
                $item->description_document = $data['description_document'] ?? null;
                if ($item->description_document !== null) {
                    $item->description = AnnouncementContent::plainText($item->description_document);
                }
                if ($creating) {
                    $item->created_by = $actor->id;
                    $item->source_type = 'admin_announcement';
                    $item->status = 'draft';
                }
                if (isset($data['image'])) {
                    $newPath = $this->media->store($data['image'], 'announcements');
                    $item->image_path = $newPath;
                }
                $item->save();
                $this->audit($actor, $item, $creating ? 'created' : 'updated');
                if (($data['status'] ?? $item->status) === 'published') {
                    $this->publish($item, $actor, (bool) ($data['notify_donors'] ?? true));
                } elseif ($item->status !== 'draft') {
                    $item->status = 'draft';
                    $item->published_at = null;
                    $item->save();
                    $this->audit($actor, $item, 'moved_to_draft');
                }

                return $item;
            });
        } catch (Throwable $error) {
            if ($newPath) {
                $this->media->discardNewUpload($newPath);
            }
            throw $error;
        }
        // Replacement is non-destructive; historical local/R2 media is retained.

        return $item;
    }

    public function transition(User $actor, int $id, string $action): DonationOpportunity
    {
        return DB::transaction(function () use ($actor, $id, $action): DonationOpportunity {
            $item = DonationOpportunity::withTrashed()->where('source_type', 'admin_announcement')->lockForUpdate()->findOrFail($id);
            if ($action === 'restore') {
                abort_unless($item->trashed(), 409, 'Announcement is not deleted.');
                abort_if($item->deleted_at->lte(now()->subDays(30)), 409, 'The 30-day recovery period has ended.');
                $item->status = 'draft';
                $item->published_at = null;
                $item->restore();
                $this->audit($actor, $item, 'restored');
            } else {
                abort_if($item->trashed(), 404);
                if ($action === 'publish') {
                    $this->publish($item, $actor, true);
                } elseif ($action === 'draft') {
                    if ($item->status !== 'draft') {
                        $item->status = 'draft';
                        $item->published_at = null;
                        $item->save();
                        $this->audit($actor, $item, 'moved_to_draft');
                    }
                } elseif ($action === 'delete') {
                    $item->delete();
                    $this->audit($actor, $item, 'deleted');
                }
            }

            return $item;
        });
    }

    /** @return array{purged: int, retained: int} */
    public function purge(): array
    {
        $counts = ['purged' => 0, 'retained' => 0];
        DonationOpportunity::onlyTrashed()->where('source_type', 'admin_announcement')->where('deleted_at', '<=', now()->subDays(30))
            ->chunkById(100, function ($items) use (&$counts): void {
                foreach ($items as $item) {
                    DB::transaction(function () use ($item, &$counts): void {
                        $locked = DonationOpportunity::onlyTrashed()->whereKey($item->id)->where('deleted_at', '<=', now()->subDays(30))->lockForUpdate()->first();
                        if (! $locked) {
                            return;
                        }
                        if ($locked->participations()->exists()) {
                            $counts['retained']++;

                            return;
                        }
                        // Media retention is separate from definition purging; never remove originals here.
                        $locked->forceDelete();
                        $counts['purged']++;
                    });
                }
            });

        return $counts;
    }
}
