<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;

class ProductionReadiness
{
    /** Configuration lint only: never resolve a database/cache/storage/mail/HTTP connection or credential file. */
    public function inspect(): array
    {
        $checks = [];
        $add = static function (string $code, bool $valid, string $requirement) use (&$checks): void {
            $checks[] = ['code' => $code, 'status' => $valid ? 'PASS' : 'BLOCKED', 'requirement' => $requirement];
        };
        $add('environment', app()->environment('production'), 'Use the production application environment.');
        $add('debug', config('app.debug') === false, 'Disable production debug output.');
        $add('public_url', $this->publicHttpsOrigin(config('app.url')), 'Configure a public HTTPS application origin.');
        $keys = config('app.previous_keys', []);
        $add('encryption_keys', $this->validKey(config('app.key')) && is_array($keys)
            && collect($keys)->every(fn ($key): bool => $this->validKey($key)), 'Configure valid current/previous encryption keys; retain required historical keys.');
        $add('database_driver', config('database.default') === 'mysql', 'Use the approved MySQL database architecture.');
        $database = config('database.connections.mysql', []);
        $add('database_identity', is_array($database) && ($this->present($database['url'] ?? null)
            || ($this->present($database['host'] ?? null) && $this->present($database['database'] ?? null)
                && $this->present($database['username'] ?? null))), 'Configure the MySQL connection identity privately; connectivity/TLS/permissions are unverified.');
        $add('database_queue', config('queue.default') === 'database'
            && config('queue.connections.database.driver') === 'database'
            && $this->present(config('queue.connections.database.queue')), 'Serve the database default queue and all four named authentication queues.');
        $retry = config('queue.connections.database.retry_after');
        $add('queue_reservation', is_int($retry) && $retry >= 90, 'Database retry_after must be at least 90 seconds for the current 60/45-second jobs.');
        $cache = config('cache.default');
        $add('shared_lock_store', $cache === 'database' && config('cache.stores.database.driver') === 'database', 'Use the current shared database cache/lock store; other architectures require approval.');
        $add('calendar', config('app.calendar_timezone') === 'Asia/Manila', 'Keep the approved Manila donation and cleanup calendar.');
        $origins = config('cors.allowed_origins');
        $add('cors_origins', is_array($origins) && count($origins) > 0
            && collect($origins)->every(fn ($origin): bool => $this->publicHttpsOrigin($origin) && ! parse_url($origin, PHP_URL_PATH))
            && config('cors.allowed_origins_patterns', []) === [], 'Allow only explicit approved HTTPS Admin origins; no wildcard/pattern origin policy.');
        $paths = config('cors.paths', []);
        $headers = config('cors.allowed_headers', []);
        $headers = is_array($headers) ? array_map(static fn ($header) => is_string($header) ? strtolower($header) : '', $headers) : [];
        $add('cors_bearer', is_array($paths) && in_array('api/*', $paths, true)
            && (in_array('*', $headers, true) || in_array('authorization', $headers, true))
            && config('cors.supports_credentials') === false, 'Cover API bearer preflights without enabling cookie credentials.');
        $smtp = config('mail.mailers.password_reset', []);
        $add('smtp', config('mail.default') === 'smtp' && is_array($smtp)
            && ($smtp['transport'] ?? null) === 'smtp' && ($smtp['require_tls'] ?? null) === true
            && in_array($smtp['scheme'] ?? null, ['smtp', 'smtps'], true)
            && $this->present($smtp['host'] ?? null) && $this->present($smtp['username'] ?? null) && $this->present($smtp['password'] ?? null)
            && (is_int($smtp['port'] ?? null) || is_string($smtp['port'] ?? null)) && ctype_digit((string) $smtp['port'])
            && (int) $smtp['port'] > 0 && (int) $smtp['port'] <= 65535,
            'Configure the dedicated encrypted SMTP transport used by all authentication mail.');
        $sender = config('mail.from.address');
        $add('mail_sender', is_string($sender) && filter_var($sender, FILTER_VALIDATE_EMAIL) !== false
            && $this->present(config('mail.from.name')), 'Configure an authorized sender identity; DNS/delivery still require staging.');
        $add('media_switch', in_array(config('filesystems.media_disk'), ['public', 'r2_media'], true), 'Keep a supported reversible new-media upload disk.');
        $add('proof_switch', in_array(config('filesystems.proof_disk'), ['proofs', 'r2_proofs'], true)
            && config('filesystems.disks.proofs.visibility') === 'private' && config('filesystems.disks.proofs.serve') === false,
            'Use a supported private proof disk and retain private local-history access.');
        foreach (['r2_media' => 'lifeflow-media', 'r2_proofs' => 'lifeflow-proofs'] as $disk => $bucket) {
            $add($disk, $this->privateR2($disk, $bucket), 'Configure the existing private R2 disk; bucket policy/object access are unverified.');
        }
        $add('groq', $this->present(config('services.groq.key')) && $this->present(config('services.groq.model')), 'Configure private Flowie credentials/model; quotas and live responses require approval.');
        $enabled = config('services.fcm.enabled');
        $add('fcm_configuration', $enabled === false || ($enabled === true && $this->present(config('services.fcm.project_id'))
            && $this->present(config('services.fcm.credentials'))), 'Keep FCM disabled until approved staging; enabled mode requires server project and private credential path.');
        foreach (['proxy_tls', 'database_schema_backup_tls', 'workers_scheduler_shared_locks', 'durable_local_history',
            'smtp_delivery', 'r2_policy_history_access', 'fcm_native_devices', 'groq_privacy_quotas', 'client_release_origins', 'first_staff_bootstrap'] as $code) {
            $checks[] = ['code' => $code, 'status' => 'REVIEW', 'requirement' => 'Owner-approved staging/operator acceptance is required; configuration lint cannot verify this gate.'];
        }

        return ['configuration_valid' => ! collect($checks)->contains('status', 'BLOCKED'), 'production_verified' => false, 'checks' => $checks];
    }

    private function present(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function validKey(mixed $key): bool
    {
        if (! is_string($key) || ! is_string(config('app.cipher'))) {
            return false;
        }
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return is_string($decoded) && Encrypter::supported($decoded, config('app.cipher'));
    }

    private function publicHttpsOrigin(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/[\s\\\\]/', $value)) {
            return false;
        }
        $parts = parse_url($value);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return false;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return str_contains($host, ':') ? (bool) preg_match('/^[23][0-9a-f]{3}:/', $host)
                : (int) explode('.', $host)[0] < 224 && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
        }

        return str_contains($host, '.') && ! preg_match('/(?:^|\.)(?:localhost|local|internal|lan|0x[0-9a-f]+|[0-9]+)\.?$/', $host)
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function privateR2(string $name, string $bucket): bool
    {
        $disk = config('filesystems.disks.'.$name, []);
        $endpoint = is_array($disk) && is_string($disk['endpoint'] ?? null) ? parse_url($disk['endpoint']) : false;

        return is_array($disk) && $endpoint && ($endpoint['scheme'] ?? '') === 'https'
            && str_ends_with($endpoint['host'] ?? '', '.r2.cloudflarestorage.com')
            && ! isset($endpoint['user']) && ! isset($endpoint['pass']) && ! isset($endpoint['query']) && ! isset($endpoint['fragment'])
            && in_array($endpoint['path'] ?? '', ['', '/'], true) && empty($disk['root']) && empty($disk['url']) && empty($disk['temporary_url'])
            && ($disk['driver'] ?? '') === 's3' && ($disk['bucket'] ?? '') === $bucket && ($disk['region'] ?? '') === 'auto'
            && ($disk['visibility'] ?? '') === 'private' && ($disk['directory_visibility'] ?? '') === 'private'
            && ($disk['serve'] ?? null) === false && ($disk['use_path_style_endpoint'] ?? null) === true
            && $this->present($disk['key'] ?? null) && $this->present($disk['secret'] ?? null);
    }
}
