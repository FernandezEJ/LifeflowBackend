<?php

namespace App\Services;

use App\Models\DonationParticipation;
use App\Models\User;
use Aws\CommandInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PrivateDonationProof
{
    private const string R2_PREFIX = 'r2_proofs:';

    public function store(DonationParticipation $item, UploadedFile $file): string
    {
        $this->authorize($item, false);
        $disk = config('filesystems.proof_disk', 'proofs');
        abort_unless(in_array($disk, ['proofs', 'r2_proofs'], true), 503, 'Private proof storage configuration is invalid.');
        if ($disk === 'r2_proofs') {
            $this->requirePrivateR2();
        }
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$file->getMimeType()];
        $directory = 'donation-proofs/'.$item->user_id.'/'.$item->id;
        $filename = Str::uuid().'.'.$extension;
        try {
            $options = $disk === 'r2_proofs' ? ['before_upload' => static function (CommandInterface $command): void {
                unset($command['ACL']);
                $command['IfNoneMatch'] = '*';
            }] : [];
            $path = Storage::disk($disk)->putFileAs($directory, $file, $filename, $options);
            abort_unless(is_string($path) && $path !== '', 503);
        } catch (Throwable) {
            // SDK exception chains can contain credentials and private request details.
            abort(503, 'Private proof storage is unavailable. Please try again.');
        }

        return ($disk === 'r2_proofs' ? self::R2_PREFIX : '').$path;
    }

    /** Only a successful new write from the current rolled-back request may be discarded. */
    public function discardNewUpload(DonationParticipation $item, string $reference): void
    {
        try {
            [$disk, $path] = $this->location($item, $reference);
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Preserve originals and the primary error if orphan cleanup is unavailable.
        }
    }

    public function path(DonationParticipation $item): string
    {
        [$disk, $path] = $this->location($item, $item->proof_path);
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][pathinfo($path, PATHINFO_EXTENSION)];
        abort_unless($item->proof_mime_type === $mime, 404, 'Proof is unavailable.');
        try {
            $exists = Storage::disk($disk)->exists($path);
        } catch (Throwable) {
            abort(503, 'Private proof storage is unavailable. Please try again.');
        }
        abort_unless($exists, 404, 'Proof is unavailable.');

        return $path;
    }

    public function response(DonationParticipation $item, bool $inline = false): StreamedResponse
    {
        $this->authorize($item);
        $path = $this->path($item);
        [$disk] = $this->location($item, $item->proof_path);
        $stream = null;
        try {
            $stream = Storage::disk($disk)->readStream($path);
            abort_unless(is_resource($stream), 503);
            // Proofs are at most 5 MiB. Finish the bounded read before sending HTTP 200.
            $contents = stream_get_contents($stream, 5 * 1024 * 1024 + 1);
            abort_unless(is_string($contents) && strlen($contents) <= 5 * 1024 * 1024 && feof($stream), 503);
        } catch (Throwable) {
            abort(503, 'Private proof storage is unavailable. Please try again.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return response()->stream(static function () use ($contents): void {
            echo $contents;
        }, 200, [
            'Content-Type' => $item->proof_mime_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.basename($path).'"',
            'Content-Length' => (string) strlen($contents),
        ]);
    }

    private function authorize(DonationParticipation $item, bool $reviewer = true): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && ! $user->isDeactivated() && ! $user->trashed(), 403, 'Access denied.');
        $owner = $user->isDonor() && $user->id === $item->user_id;
        $staff = $reviewer && $user->isAdminPanelUser() && ! $user->must_change_password;
        abort_unless($owner || $staff, 403, 'Access denied.');
    }

    /** @return array{string, string} */
    private function location(DonationParticipation $item, ?string $reference): array
    {
        $r2 = str_starts_with($reference ?? '', self::R2_PREFIX);
        $path = $r2 ? substr($reference, strlen(self::R2_PREFIX)) : $reference;
        $prefix = 'donation-proofs/'.$item->user_id.'/'.$item->id.'/';
        abort_unless(is_string($path) && str_starts_with($path, $prefix)
            && preg_match('~\A[a-f0-9-]{36}\.(jpg|png|pdf)\z~', substr($path, strlen($prefix))), 404, 'Proof is unavailable.');
        if ($r2) {
            $this->requirePrivateR2();
        }

        return [$r2 ? 'r2_proofs' : 'proofs', $path];
    }

    private function requirePrivateR2(): void
    {
        $disk = config('filesystems.disks.r2_proofs', []);
        $endpoint = is_string($disk['endpoint'] ?? null) ? parse_url($disk['endpoint']) : false;
        $valid = ($disk['driver'] ?? null) === 's3' && ($disk['visibility'] ?? null) === 'private'
            && ($disk['directory_visibility'] ?? null) === 'private' && ($disk['serve'] ?? false) === false
            && ($disk['region'] ?? null) === 'auto' && ($disk['use_path_style_endpoint'] ?? null) === true
            && ($disk['bucket'] ?? null) === 'lifeflow-proofs' && empty($disk['root'])
            && empty($disk['url']) && empty($disk['temporary_url'])
            && is_string($disk['key'] ?? null) && trim($disk['key']) !== ''
            && is_string($disk['secret'] ?? null) && trim($disk['secret']) !== ''
            && ($endpoint['scheme'] ?? null) === 'https' && str_ends_with($endpoint['host'] ?? '', '.r2.cloudflarestorage.com')
            && ! isset($endpoint['user']) && ! isset($endpoint['pass']) && ! isset($endpoint['query'])
            && in_array($endpoint['path'] ?? '', ['', '/'], true);
        abort_unless($valid, 503, 'Private proof storage configuration is invalid.');
    }
}
