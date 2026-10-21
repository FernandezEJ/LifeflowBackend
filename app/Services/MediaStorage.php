<?php

namespace App\Services;

use App\Models\DonationOpportunity;
use App\Models\Reward;
use App\Models\User;
use Aws\CommandInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MediaStorage
{
    /** Explicit provenance in existing image_path / snapshot image_url fields; never a signed URL. */
    private const string R2_PREFIX = 'r2_media:';

    public function store(UploadedFile $image, string $collection): string
    {
        abort_unless(in_array($collection, ['announcements', 'rewards'], true), 503, 'Invalid media collection.');
        $disk = config('filesystems.media_disk', 'public');
        abort_unless(in_array($disk, ['public', 'r2_media'], true), 503, 'Media storage configuration is invalid.');
        if ($disk === 'r2_media') {
            abort_unless($this->privateR2Configuration(), 503, 'Private media storage configuration is invalid.');
        }
        $filename = Str::uuid().'.'.$image->extension();
        try {
            $options = $disk === 'r2_media' ? ['before_upload' => static function (CommandInterface $command): void {
                // Flysystem otherwise adds an ACL; R2 uses bucket privacy without object ACLs.
                unset($command['ACL']);
                $command['IfNoneMatch'] = '*';
            }] : [];
            $path = Storage::disk($disk)->putFileAs($collection, $image, $filename, $options);
            abort_unless(is_string($path) && $path !== '', 503);
        } catch (Throwable) {
            // Do not chain/report SDK exceptions: they may contain signed request details.
            abort(503, 'Unable to store the image. Please try again.');
        }

        return ($disk === 'r2_media' ? self::R2_PREFIX : '').$path;
    }

    /** Only for the newly created image of a rolled-back request, never a former image or snapshot. */
    public function discardNewUpload(string $reference): void
    {
        $r2 = $this->r2Key($reference);
        if ($r2 === null && ! preg_match('~\A(?:announcements|rewards)/[a-f0-9-]{36}\.(?:png|jpe?g)\z~', $reference)) {
            return;
        }
        try {
            Storage::disk($r2 === null ? 'public' : 'r2_media')->delete($r2 ?? $reference);
        } catch (Throwable) {
            // Retain an orphan rather than masking the original failure or leaking SDK credentials.
        }
    }

    public function announcementUrl(DonationOpportunity $item): ?string
    {
        $reference = $item->image_path;
        if (! $reference || ! str_starts_with($reference, self::R2_PREFIX)) {
            return $reference ? Storage::disk('public')->url($reference) : null;
        }
        // Published expired events remain visible through the donor's authorized activity history.
        $published = ! $item->trashed() && $item->status === 'published' && $item->published_at?->lte(now());
        if (! $this->staffCanView() && ! ($this->donorCanView() && $published)) {
            return null;
        }

        return $this->temporaryUrl($reference, 'announcements');
    }

    public function rewardUrl(Reward $reward, ?string $legacyUrl): ?string
    {
        $reference = $reward->image_path;
        if (! $reference || ! str_starts_with($reference, self::R2_PREFIX)) {
            return $reference ? url(Storage::disk('public')->url($reference)) : $legacyUrl;
        }
        $current = ! $reward->trashed() && $reward->active
            && (! $reward->starts_at || $reward->starts_at->lte(now()))
            && (! $reward->ends_at || $reward->ends_at->gt(now()));
        if (! $this->staffCanView() && ! ($this->donorCanView() && $current)) {
            return null;
        }

        return $this->temporaryUrl($reference, 'rewards');
    }

    public function snapshotReference(Reward $reward): ?string
    {
        return str_starts_with($reward->image_path ?? '', self::R2_PREFIX) ? $reward->image_path : $reward->image_url;
    }

    /** Call only after voucher-owner or staff claim authorization; legacy snapshots stay unchanged. */
    public function snapshotUrl(?string $reference): ?string
    {
        return str_starts_with($reference ?? '', self::R2_PREFIX) ? $this->temporaryUrl($reference, 'rewards') : $reference;
    }

    private function temporaryUrl(string $reference, string $collection): ?string
    {
        $key = $this->r2Key($reference);
        if ($key === null || ! str_starts_with($key, $collection.'/') || ! $this->privateR2Configuration()) {
            return null;
        }
        try {
            $disk = Storage::disk('r2_media');
            if (! $disk->exists($key)) {
                return null;
            }

            return $disk->temporaryUrl($key, now()->addMinutes(15), ['ResponseCacheControl' => 'private, no-store']);
        } catch (Throwable) {
            return null;
        }
    }

    private function r2Key(string $reference): ?string
    {
        return preg_match('~\Ar2_media:((?:announcements|rewards)/[a-f0-9-]{36}\.(?:png|jpe?g))\z~', $reference, $match) ? $match[1] : null;
    }

    private function staffCanView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdminPanelUser() && ! $user->isDeactivated()
            && ! $user->trashed() && ! $user->must_change_password;
    }

    private function donorCanView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isDonor() && ! $user->isDeactivated() && ! $user->trashed();
    }

    private function privateR2Configuration(): bool
    {
        $disk = config('filesystems.disks.r2_media', []);
        $endpoint = is_string($disk['endpoint'] ?? null) ? parse_url($disk['endpoint']) : false;

        return ($disk['driver'] ?? null) === 's3' && ($disk['visibility'] ?? null) === 'private'
            && ($disk['directory_visibility'] ?? null) === 'private' && ($disk['region'] ?? null) === 'auto'
            && ($disk['use_path_style_endpoint'] ?? null) === true && ($disk['serve'] ?? false) === false
            && ($disk['bucket'] ?? null) === 'lifeflow-media'
            && is_string($disk['key'] ?? null) && trim($disk['key']) !== ''
            && is_string($disk['secret'] ?? null) && trim($disk['secret']) !== ''
            && empty($disk['root']) && empty($disk['url']) && empty($disk['temporary_url'])
            && ($endpoint['scheme'] ?? null) === 'https' && str_ends_with($endpoint['host'] ?? '', '.r2.cloudflarestorage.com')
            && ! isset($endpoint['user']) && ! isset($endpoint['pass']) && ! isset($endpoint['query'])
            && in_array($endpoint['path'] ?? '', ['', '/'], true);
    }
}
