<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TemporaryAuthRetention
{
    public const GRACE_HOURS = 24;

    public const PAGE_SIZE = 100;

    public const SCAN_LIMIT = 1000;

    public const TABLES = ['pending_registrations' => 'email_hash', 'donor_login_codes' => 'mobile_hash',
        'password_reset_codes' => 'email_hash', 'email_changes' => 'user_id', 'password_reset_tokens' => 'email'];

    /** Primary-key scans bound examined rows even where expiry has no index. Cursors are non-authoritative hints. */
    public function run(bool $dryRun = false): array
    {
        $lock = Cache::lock('lifeflow:temporary-auth-retention', 120);
        if (! $lock->get()) {
            return ['busy' => true, 'tables' => []];
        }
        $counts = [];
        $cutoff = now()->subHours(self::GRACE_HOURS);
        $deadline = microtime(true) + 45;
        try {
            foreach (self::TABLES as $table => $key) {
                $counts[$table] = ['scanned' => 0, 'eligible' => 0, 'deleted' => 0];
                $cursorKey = 'lifeflow:auth-retention-cursor:'.$table;
                $cursor = null;
                if (! $dryRun && ($hint = Cache::get($cursorKey)) !== null) {
                    try {
                        $cursor = Crypt::decryptString($hint);
                    } catch (Throwable) {
                        // A lost/corrupt hint only restarts the bounded scan; it cannot authorize deletion.
                    }
                }
                do {
                    $columns = $table === 'password_reset_tokens' ? [$key, 'created_at'] : [$key, 'request_id'];
                    $page = DB::table($table)->select($columns)->when($cursor !== null, fn ($query) => $query->where($key, '>', $cursor))
                        ->orderBy($key)->limit(min(self::PAGE_SIZE, self::SCAN_LIMIT - $counts[$table]['scanned']))->get();
                    foreach ($page as $candidate) {
                        $result = $this->pruneCandidate($table, $key, $candidate, $cutoff, $dryRun);
                        $counts[$table]['scanned']++;
                        $counts[$table]['eligible'] += $result ? 1 : 0;
                        $counts[$table]['deleted'] += $result && ! $dryRun ? 1 : 0;
                        $cursor = $candidate->{$key};
                    }
                    if (! $dryRun) {
                        if ($page->count() < self::PAGE_SIZE) {
                            Cache::forget($cursorKey);
                        } else {
                            // Encrypted, short-lived scan positions also protect the legacy broker's email PK.
                            Cache::put($cursorKey, Crypt::encryptString((string) $cursor), now()->addDays(2));
                        }
                    }
                } while ($page->count() === self::PAGE_SIZE && $counts[$table]['scanned'] < self::SCAN_LIMIT && microtime(true) < $deadline);
                if (microtime(true) >= $deadline) {
                    break;
                }
            }
            Log::info('Temporary authentication retention completed.', ['dry_run' => $dryRun, 'counts' => $counts]);

            return ['busy' => false, 'tables' => $counts];
        } finally {
            $lock->release();
        }
    }

    protected function pruneCandidate(string $table, string $key, object $candidate, CarbonInterface $cutoff, bool $dryRun): bool
    {
        return DB::transaction(function () use ($table, $key, $candidate, $cutoff, $dryRun): bool {
            $query = DB::table($table)->where($key, $candidate->{$key});
            if ($table !== 'password_reset_tokens') {
                $query->where('request_id', $candidate->request_id);
            } else {
                $query->where('created_at', $candidate->created_at);
            }
            $columns = $table === 'password_reset_tokens' ? [$key, 'created_at'] : [$key, 'expires_at', 'created_at', 'updated_at', 'used_at'];
            if (in_array($table, ['pending_registrations', 'donor_login_codes', 'email_changes'], true)) {
                $columns = [...$columns, 'resend_at', 'delivery_claimed_at'];
            }
            if ($table === 'password_reset_codes') {
                $columns[] = 'reset_token_expires_at';
            }
            if ($table === 'donor_login_codes') {
                $columns[] = 'confirmed_at';
            }
            $row = (clone $query)->select($columns)->lockForUpdate()->first();
            if (! $row || ! $this->eligible($table, $row, $cutoff)) {
                return false;
            }

            return $dryRun || $query->delete() === 1;
        }, 3);
    }

    private function eligible(string $table, object $row, CarbonInterface $cutoff): bool
    {
        if ($table === 'password_reset_tokens') {
            $minutes = config('auth.passwords.users.expire');
            $created = SessionTokenPolicy::timestamp($row->created_at);

            return config('auth.passwords.users.table') === $table && is_int($minutes) && $minutes > 0
                && $created !== null && $created->addMinutes($minutes)->lte($cutoff);
        }
        foreach (['expires_at', 'created_at', 'updated_at'] as $field) {
            $time = SessionTokenPolicy::timestamp($row->{$field});
            if (! $time || $time->gt($cutoff)) {
                return false;
            }
        }
        foreach (['used_at', 'resend_at', 'delivery_claimed_at', 'reset_token_expires_at', 'confirmed_at'] as $field) {
            if (! property_exists($row, $field)) {
                continue;
            }
            if ($row->{$field} === null) {
                if ($field === 'resend_at') {
                    return false;
                }

                continue;
            }
            $time = SessionTokenPolicy::timestamp($row->{$field});
            if (! $time || ($field === 'confirmed_at' ? $time->addMinutes(10) : $time)->gt($cutoff)) {
                return false;
            }
        }

        return true;
    }
}
