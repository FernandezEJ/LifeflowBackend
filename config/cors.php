<?php

$origins = static function (string $value, bool $local = false): array {
    return array_values(array_unique(array_filter(array_map(trim(...), explode(',', $value)), static function (string $origin) use ($local): bool {
        if (! filter_var($origin, FILTER_VALIDATE_URL) || preg_match('/[\s\\\\*]/', $origin)) {
            return false;
        }

        $parts = parse_url($origin);

        if (! is_array($parts) || array_intersect(['user', 'pass', 'path', 'query', 'fragment'], array_keys($parts))) {
            return false;
        }

        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return false;
        }

        return $local
            ? in_array($parts['scheme'], ['http', 'https'], true) && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)
            : $parts['scheme'] === 'https';
    })));
};

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_unique([
        ...$origins((string) env('CORS_ALLOWED_ORIGINS', '')),
        ...(in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)
            ? $origins((string) env('CORS_LOCAL_ALLOWED_ORIGINS', ''), true) : []),
    ])),
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
    'Authorization',
    'Content-Type',
    'Accept',
    'ngrok-skip-browser-warning',
    ],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
