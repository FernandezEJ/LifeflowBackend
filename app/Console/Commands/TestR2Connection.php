<?php

namespace App\Console\Commands;

use Aws\S3\S3ClientInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('r2:test-connection {--confirm : Explicitly allow temporary marker write/read/delete on both R2 disks}')]
#[Description('Opt-in private R2 connection check; touches only its own temporary markers')]
class TestR2Connection extends Command
{
    public function handle(): int
    {
        if (! $this->option('confirm')) {
            $this->warn('Not tested. Run r2:test-connection --confirm to allow temporary marker write/read/delete on both disks.');

            return self::FAILURE;
        }

        $passed = true;
        foreach (['r2_media', 'r2_proofs'] as $name) {
            $result = $this->checkDisk($name);
            $passed = $result && $passed;
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function checkDisk(string $name): bool
    {
        if (! $this->safeConfiguration($name)) {
            $this->error($name.': FAIL (configuration; check private disk settings and required R2 variables).');

            return false;
        }

        $bucket = config('filesystems.disks.'.$name.'.bucket');
        $id = (string) Str::uuid();
        $key = '__lifeflow_connection_test__/'.$id.'.txt';
        $contents = 'LifeFlow private connection marker: '.$id."\n";
        $client = null;
        $attempted = false;
        $written = false;
        $verified = false;
        $cleaned = false;
        $stage = 'connection';

        try {
            $client = Storage::disk($name)->getClient();
            $stage = 'write';
            $attempted = true;
            // R2 does not implement object ACLs. Use the disk's configured client without ACL operations.
            $client->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => $contents,
                'ContentType' => 'text/plain', 'IfNoneMatch' => '*']);
            $written = true;
            $stage = 'read';
            $verified = hash_equals($contents, (string) $client->getObject(['Bucket' => $bucket, 'Key' => $key])['Body']);
            if (! $verified) {
                throw new RuntimeException('Marker verification failed.');
            }
            $stage = 'cleanup';
        } catch (Throwable) {
            // SDK errors can contain credentials, headers and private object URLs; never output or report them.
        } finally {
            if ($client !== null && $attempted) {
                try {
                    // A failed write may have committed. Verify ownership before removing an uncertain marker.
                    if ($written || $this->ownsMarker($client, $bucket, $key, $contents)) {
                        $client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
                        $cleaned = true;
                    }
                } catch (Throwable) {
                    $cleaned = false;
                }
            }
        }

        if ($verified && $cleaned) {
            $this->info($name.': PASS (write/read/delete).');

            return true;
        }

        $this->error($name.': FAIL ('.$stage.'; '.($cleaned ? 'own marker cleaned' : 'cleanup unverified').').');

        return false;
    }

    private function ownsMarker(S3ClientInterface $client, string $bucket, string $key, string $contents): bool
    {
        return hash_equals($contents, (string) $client->getObject(['Bucket' => $bucket, 'Key' => $key])['Body']);
    }

    private function safeConfiguration(string $name): bool
    {
        $disk = config('filesystems.disks.'.$name, []);
        foreach (['key', 'secret', 'bucket', 'endpoint'] as $field) {
            if (! is_string($disk[$field] ?? null) || trim($disk[$field]) === '') {
                return false;
            }
        }

        $endpoint = parse_url($disk['endpoint']);

        return ($disk['driver'] ?? null) === 's3'
            && ($disk['visibility'] ?? null) === 'private'
            && ($disk['directory_visibility'] ?? null) === 'private'
            && ($disk['region'] ?? null) === 'auto'
            && ($disk['use_path_style_endpoint'] ?? null) === true
            && ($disk['serve'] ?? false) === false
            && empty($disk['url'])
            && ($endpoint['scheme'] ?? null) === 'https'
            && str_ends_with($endpoint['host'] ?? '', '.r2.cloudflarestorage.com')
            && ! isset($endpoint['user'])
            && ! isset($endpoint['pass'])
            && ! isset($endpoint['query'])
            && in_array($endpoint['path'] ?? '', ['', '/'], true)
            && config('filesystems.disks.r2_media.bucket') !== config('filesystems.disks.r2_proofs.bucket');
    }
}
