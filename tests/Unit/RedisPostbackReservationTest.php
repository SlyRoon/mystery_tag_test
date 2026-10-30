<?php

namespace Tests\Unit;

use App\Support\RedisPostbackReservation;
use Illuminate\Redis\Connections\PhpRedisConnection;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class RedisPostbackReservationTest extends TestCase
{
    public function test_acquire_uses_millisecond_lease_and_returns_owner_safe_reservation(): void
    {
        $redis = Mockery::mock(PhpRedisConnection::class);
        $fingerprint = str_repeat("\xAB", 32);
        $token = str_repeat('a', 40);

        $redis->shouldReceive('set')->once()
            ->with('dedupe:'.bin2hex($fingerprint), $token, 'PX', 15000, 'NX')
            ->andReturnTrue();
        $redis->shouldReceive('command')->once()->with('eval', Mockery::on(
            fn (array $parameters): bool => is_string($parameters[0])
                && $parameters[1] === ['dedupe:'.bin2hex($fingerprint), $token]
                && $parameters[2] === 1,
        ))->andReturn(1);

        $reservation = new RedisPostbackReservation($redis, 15000, fn (): string => $token);
        $lease = $reservation->acquire($fingerprint);

        $this->assertNotNull($lease);
        $this->assertTrue($lease->release());
    }

    public function test_release_deletes_only_a_key_still_owned_by_its_token(): void
    {
        $redis = Mockery::mock(PhpRedisConnection::class);
        $fingerprint = str_repeat("\x01", 32);
        $key = 'dedupe:'.bin2hex($fingerprint);
        $token = str_repeat('b', 40);
        $releaseScript = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";

        $redis->shouldReceive('set')->once()
            ->with($key, $token, 'PX', 700, 'NX')
            ->andReturnTrue();
        $redis->shouldReceive('command')->once()
            ->with('eval', [$releaseScript, [$key, $token], 1])
            ->andReturn(0);

        $lease = (new RedisPostbackReservation($redis, 700, fn (): string => $token))->acquire($fingerprint);

        $this->assertNotNull($lease);
        $this->assertFalse($lease->release());
    }

    public function test_acquire_returns_null_when_another_request_owns_the_reservation(): void
    {
        $redis = Mockery::mock(PhpRedisConnection::class);
        $redis->shouldReceive('set')->once()->andReturnFalse();

        $reservation = new RedisPostbackReservation($redis, 15000, fn (): string => str_repeat('c', 40));

        $this->assertNull($reservation->acquire(str_repeat("\x01", 32)));
    }

    public function test_lease_and_fingerprint_must_be_valid(): void
    {
        $redis = Mockery::mock(PhpRedisConnection::class);

        $this->expectException(InvalidArgumentException::class);
        new RedisPostbackReservation($redis, 0);
    }
}
