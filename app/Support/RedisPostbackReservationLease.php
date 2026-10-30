<?php

namespace App\Support;

use Illuminate\Redis\Connections\Connection;

final class RedisPostbackReservationLease
{
    public function __construct(
        private readonly Connection $redis,
        private readonly string $key,
        private readonly string $token,
    ) {}

    public function release(): bool
    {
        return RedisPostbackReservation::releaseOwned($this->redis, $this->key, $this->token);
    }
}
