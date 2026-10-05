<?php

namespace App\Services\Admin;

use App\Models\Reward;
use App\Models\User;
use App\Models\UserVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RewardManagementService
{
    public function profile(Reward $reward): array
    {
        return [...$reward->toArray(), 'status' => $reward->trashed() ? 'deleted' : ($reward->active ? 'active' : 'inactive'),
            'scheduled_for_deletion_at' => $reward->deleted_at?->copy()->addDays(30)->toISOString(),
            'recoverable' => $reward->trashed() && $reward->deleted_at->gt(now()->subDays(30))];
    }

    public function listing(array $filters): array
    {
        $query = Reward::withTrashed()->orderByDesc('id');
        if (trim($filters['search'] ?? '') !== '') {
            $query->whereLike('name', '%'.trim($filters['search']).'%');
        }
        if (isset($filters['amount'])) {
            $query->where('voucher_value', $filters['amount']);
        }
        if (isset($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }
        $status = $filters['status'] ?? 'all';
        if ($status === 'deleted') {
            $query->onlyTrashed();
        } elseif (in_array($status, ['active', 'inactive'], true)) {
            $query->whereNull('deleted_at')->where('active', $status === 'active');
        }

        return ['data' => $query->get()->map(fn (Reward $reward): array => $this->profile($reward))->all()];
    }

    private function audit(User $actor, Reward $reward, string $action): void
    {
        $actor->auditLogs()->create(['actor_role' => $actor->role, 'action' => 'reward_'.$action,
            'module' => 'rewards', 'target_type' => 'reward', 'target_id' => $reward->id]);
    }

    public function save(User $actor, array $data, ?int $id = null): Reward
    {
        $newPath = null;
        try {
            return DB::transaction(function () use ($actor, $data, $id, &$newPath): Reward {
                $reward = $id === null ? new Reward : Reward::lockForUpdate()->findOrFail($id);
                // Inventory is remaining stock, not total issued. Reject an old
                // edit form if a donor or another admin changed it while open.
                abort_if($id !== null && $reward->stock_quantity !== (int) $data['expected_stock'], 409,
                    'Stock changed. Reload the reward before saving again.');
                $wasActive = $reward->active;
                $reward->fill(['name' => $data['name'], 'category' => $data['category'], 'amount_mode' => $data['amount_mode'] ?? 'custom',
                    'voucher_value' => $data['voucher_value'], 'points_cost' => $data['points_cost'],
                    'stock_quantity' => $data['stock_quantity'], 'active' => $data['status'] === 'active']);
                if (isset($data['image'])) {
                    $newPath = $data['image']->store('rewards', 'public');
                    throw_if(! $newPath, \RuntimeException::class, 'Unable to store reward image.');
                    $reward->image_path = $newPath;
                }
                // Old image files stay available to immutable owned-voucher snapshots.
                $reward->save();
                $this->audit($actor, $reward, $id === null ? 'created' : 'updated');
                if ($id !== null && $wasActive !== $reward->active) {
                    $this->audit($actor, $reward, $reward->active ? 'activated' : 'moved_to_draft');
                }

                return $reward;
            });
        } catch (Throwable $error) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }
            throw $error;
        }
    }

    public function transition(User $actor, int $id, string $action): Reward
    {
        return DB::transaction(function () use ($actor, $id, $action): Reward {
            $reward = Reward::withTrashed()->lockForUpdate()->findOrFail($id);
            if ($action === 'restore') {
                abort_unless($reward->trashed(), 409, 'Reward is not deleted.');
                abort_if($reward->deleted_at->lte(now()->subDays(30)), 410, 'The 30-day recovery period has ended.');
                $reward->active = false;
                $reward->restore();
                $this->audit($actor, $reward, 'restored');
            } elseif (! $reward->trashed()) {
                $reward->delete();
                $this->audit($actor, $reward, 'deleted');
            }

            return $reward;
        });
    }

    /** History-linked definitions are retained permanently; no voucher or ledger is purged. */
    public function purge(): array
    {
        $counts = ['purged' => 0, 'retained' => 0];
        Reward::onlyTrashed()->where('deleted_at', '<=', now()->subDays(30))->chunkById(100, function ($rewards) use (&$counts): void {
            foreach ($rewards as $reward) {
                DB::transaction(function () use ($reward, &$counts): void {
                    $locked = Reward::onlyTrashed()->where('deleted_at', '<=', now()->subDays(30))->lockForUpdate()->find($reward->id);
                    if (! $locked) {
                        return;
                    }
                    if (UserVoucher::where('reward_id', $locked->id)->exists()) {
                        $counts['retained']++;

                        return;
                    }
                    $locked->forceDelete();
                    $counts['purged']++;
                });
            }
        });

        return $counts;
    }
}
