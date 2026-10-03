<?php

namespace App\Services;

use App\Models\DonationParticipation;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateDonationProof
{
    public function path(DonationParticipation $item): string
    {
        $prefix = 'donation-proofs/'.$item->user_id.'/'.$item->id.'/';
        $path = $item->proof_path;
        abort_unless(is_string($path) && str_starts_with($path, $prefix)
            && preg_match('~^[a-f0-9-]{36}\.(jpg|png|pdf)$~D', substr($path, strlen($prefix))), 404, 'Proof is unavailable.');
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][pathinfo($path, PATHINFO_EXTENSION)];
        abort_unless($item->proof_mime_type === $mime && Storage::disk('proofs')->exists($path), 404, 'Proof is unavailable.');

        return $path;
    }

    public function response(DonationParticipation $item, bool $inline = false): StreamedResponse
    {
        $path = $this->path($item);

        return Storage::disk('proofs')->response($path, basename($path), [
            'Content-Type' => $item->proof_mime_type, 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }
}
