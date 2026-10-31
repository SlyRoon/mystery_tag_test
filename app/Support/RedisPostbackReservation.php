<?php

namespace App\Support;

use Closure;
use Illuminate\Redis\Connections\Connection;
use InvalidArgumentException;

final class RedisPostbackReservation
{
    private const RELEASE_SCRIPT = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";

    private const REMEMBER_SCRIPT = "local now = redis.call('TIME'); local remaining = tonumber(ARGV[1]) - (now[1] * 1000 + math.floor(now[2] / 1000)) - 1000; if remaining <= 0 then return 0 end; return redis.call('SET', KEYS[1], '1', 'PX', remaining)";

    private readonly Connection|Closure $redis;

    public function __construct(
        Connection|Closure $redis,
        private readonly int $leaseMilliseconds,
        private readonly ?Closure $tokenGenerator = null,
    ) {
        $this->redis = $redis;

        if ($leaseMilliseconds <= 0) {
            throw new InvalidArgumentException('Redis reservation lease must be positive');
        }
    }

    public function acquire(string $fingerprint): ?RedisPostbackReservationLease
    {
        if (strlen($fingerprint) !== 32) {
            throw new InvalidArgumentException('Postback fingerprint must contain exactly 32 bytes');
        }

        $key = 'dedupe:lease:'.bin2hex($fingerprint);
        $token = ($this->tokenGenerator ?? static fn (): string => bin2hex(random_bytes(20)))();
        $redis = $this->connection();

        if (! $redis->set($key, $token, 'PX', $this->leaseMilliseconds, 'NX')) {
            return null;
        }

        return new RedisPostbackReservationLease($redis, $key, $token);
    }

    public function isAccepted(string $fingerprint): bool
    {
        return $this->connection()->get('dedupe:'.bin2hex($fingerprint)) === '1';
    }

    public function rememberAccepted(string $fingerprint, int $expirationMilliseconds): void
    {
        if ($expirationMilliseconds > 0) {
            $this->connection()->eval(
                self::REMEMBER_SCRIPT,
                1,
                'dedupe:'.bin2hex($fingerprint),
                $expirationMilliseconds,
            );
        }
    }

    public static function releaseOwned(Connection $redis, string $key, string $token): bool
    {
        return (int) $redis->eval(self::RELEASE_SCRIPT, 1, $key, $token) === 1;
    }

    private function connection(): Connection
    {
        return $this->redis instanceof Closure ? ($this->redis)() : $this->redis;
    }
}
