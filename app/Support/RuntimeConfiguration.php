<?php

namespace App\Support;

use InvalidArgumentException;

final class RuntimeConfiguration
{
    public static function assertValid(array $settings): void
    {
        $problems = [];
        $key = $settings['app_key'] ?? null;
        $decodedKey = is_string($key) && str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true)
            : false;

        if (! is_string($decodedKey) || strlen($decodedKey) !== 32) {
            $problems[] = 'APP_KEY must be a valid 32-byte Laravel key';
        }

        if (($settings['timezone'] ?? null) !== 'UTC') {
            $problems[] = 'APP_TIMEZONE must be UTC';
        }

        $database = $settings['database'] ?? [];
        foreach (['driver' => 'mysql', 'name' => null, 'username' => null, 'password' => null] as $key => $expected) {
            $value = $database[$key] ?? null;

            if (! is_string($value) || $value === '' || ($expected !== null && $value !== $expected)) {
                $problems[] = "MySQL configuration is invalid: {$key}";
            }
        }

        $queue = $settings['queue'] ?? [];
        if (($queue['driver'] ?? null) !== 'database' || ($queue['connection'] ?? null) !== 'mysql') {
            $problems[] = 'QUEUE_CONNECTION and DB_QUEUE_CONNECTION must use MySQL database queue';
        }

        if (($queue['after_commit'] ?? null) !== false) {
            $problems[] = 'database queue after_commit must be false';
        }

        $redis = $settings['redis'] ?? [];
        if (($redis['client'] ?? null) !== 'phpredis' || empty($redis['host'])) {
            $problems[] = 'REDIS_CLIENT and REDIS_HOST must be configured';
        }

        $partnerUrl = $settings['partner_url'] ?? null;
        $urlParts = is_string($partnerUrl) ? parse_url($partnerUrl) : false;
        if (! is_array($urlParts)
            || ! in_array($urlParts['scheme'] ?? null, ['http', 'https'], true)
            || empty($urlParts['host'])) {
            $problems[] = 'PARTNER_API_URL must be a valid HTTP or HTTPS URL';
        }

        $timeouts = $settings['timeouts'] ?? [];
        $connect = $timeouts['connect'] ?? 0;
        $http = $timeouts['http'] ?? 0;
        $worker = $timeouts['worker'] ?? 0;
        $retryAfter = $timeouts['retry_after'] ?? 0;
        $deliveryLease = $timeouts['delivery_lease'] ?? 0;

        if ($connect <= 0 || $http <= 0 || $connect > $http || $worker <= $http || $retryAfter <= $worker || $deliveryLease <= $retryAfter) {
            $problems[] = 'timeouts must satisfy 0 < connect <= HTTP < worker < retry_after < delivery lease';
        }

        foreach (['recovery_lease', 'redis_reservation_lease'] as $name) {
            if (($timeouts[$name] ?? 0) <= 0) {
                $problems[] = "{$name} must be positive";
            }
        }

        if ($problems !== []) {
            throw new InvalidArgumentException('Invalid runtime configuration: '.implode('; ', $problems));
        }
    }
}
