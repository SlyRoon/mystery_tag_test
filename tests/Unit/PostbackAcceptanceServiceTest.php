<?php

namespace Tests\Unit;

use App\Support\PostbackAcceptanceOutcome;
use App\Support\PostbackAcceptanceService;
use App\Support\PostbackAcceptanceStore;
use App\Support\PostbackFingerprint;
use App\Support\RedisPostbackReservation;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PostbackAcceptanceServiceTest extends TestCase
{
    public function test_new_event_is_persisted_under_a_redis_reservation_and_released(): void
    {
        [$redis, $store, $reservation, $data, $key, $token] = $this->dependencies();
        $this->expectAcquiredReservation($redis, $key, $token);
        $store->shouldReceive('accept')->once()->with($data, PostbackFingerprint::from('clk_123', 'purchase', '499e-1'))
            ->andReturn(PostbackAcceptanceOutcome::Accepted);
        $this->expectReservationRelease($redis, $key, $token, 1);

        $result = (new PostbackAcceptanceService($reservation, $store))->accept($data);

        $this->assertSame(PostbackAcceptanceOutcome::Accepted, $result);
    }

    public function test_redis_contention_returns_duplicate_only_when_mysql_confirms_active_window(): void
    {
        [$redis, $store, $reservation, $data, $key] = $this->dependencies();
        $redis->shouldReceive('set')->once()->with($key, Mockery::type('string'), 'PX', 10000, 'NX')->andReturnFalse();
        $store->shouldReceive('isDuplicate')->once()->with(PostbackFingerprint::from('clk_123', 'purchase', '499e-1'))
            ->andReturnTrue();

        $result = (new PostbackAcceptanceService($reservation, $store))->accept($data);

        $this->assertSame(PostbackAcceptanceOutcome::Duplicate, $result);
    }

    public function test_redis_contention_without_a_committed_duplicate_returns_unavailable(): void
    {
        [$redis, $store, $reservation, $data, $key] = $this->dependencies();
        $redis->shouldReceive('set')->once()->with($key, Mockery::type('string'), 'PX', 10000, 'NX')->andReturnFalse();
        $store->shouldReceive('isDuplicate')->once()->andReturnFalse();

        $result = (new PostbackAcceptanceService($reservation, $store))->accept($data);

        $this->assertSame(PostbackAcceptanceOutcome::Unavailable, $result);
    }

    public function test_storage_failure_returns_unavailable_and_releases_its_reservation(): void
    {
        [$redis, $store, $reservation, $data, $key, $token] = $this->dependencies();
        $this->expectAcquiredReservation($redis, $key, $token);
        $store->shouldReceive('accept')->once()->andThrow(new RuntimeException('database unavailable'));
        Log::shouldReceive('warning')->once();
        $this->expectReservationRelease($redis, $key, $token, 1);

        $result = (new PostbackAcceptanceService($reservation, $store))->accept($data);

        $this->assertSame(PostbackAcceptanceOutcome::Unavailable, $result);
    }

    public function test_redis_failure_returns_unavailable_without_consulting_mysql(): void
    {
        [$redis, $store, $reservation, $data] = $this->dependencies();
        $redis->shouldReceive('set')->once()->andThrow(new RuntimeException('redis unavailable'));
        $store->shouldNotReceive('accept');
        $store->shouldNotReceive('isDuplicate');
        Log::shouldReceive('warning')->once();

        $result = (new PostbackAcceptanceService($reservation, $store))->accept($data);

        $this->assertSame(PostbackAcceptanceOutcome::Unavailable, $result);
    }

    public function test_release_failure_does_not_reverse_a_confirmed_acceptance(): void
    {
        [$redis, $store, $reservation, $data, $key, $token] = $this->dependencies();
        $this->expectAcquiredReservation($redis, $key, $token);
        $store->shouldReceive('accept')->once()->andReturn(PostbackAcceptanceOutcome::Accepted);
        Log::shouldReceive('warning')->once();
        $redis->shouldReceive('command')->once()->with('eval', Mockery::type('array'))
            ->andThrow(new RuntimeException('redis unavailable after commit'));

        $result = (new PostbackAcceptanceService($reservation, $store))->accept($data);

        $this->assertSame(PostbackAcceptanceOutcome::Accepted, $result);
    }

    /**
     * @return array{PhpRedisConnection, PostbackAcceptanceStore, RedisPostbackReservation, array{click_id: string, event: string, amount: string}, string, string}
     */
    private function dependencies(): array
    {
        $redis = Mockery::mock(PhpRedisConnection::class);
        $store = Mockery::mock(PostbackAcceptanceStore::class);
        $data = ['click_id' => 'clk_123', 'event' => 'purchase', 'amount' => '499e-1'];
        $fingerprint = PostbackFingerprint::from($data['click_id'], $data['event'], $data['amount']);
        $key = 'dedupe:'.bin2hex($fingerprint);
        $token = str_repeat('a', 40);

        return [
            $redis,
            $store,
            new RedisPostbackReservation($redis, 10000, fn (): string => $token),
            $data,
            $key,
            $token,
        ];
    }

    private function expectAcquiredReservation(PhpRedisConnection $redis, string $key, string $token): void
    {
        $redis->shouldReceive('set')->once()->with($key, $token, 'PX', 10000, 'NX')->andReturnTrue();
    }

    private function expectReservationRelease(PhpRedisConnection $redis, string $key, string $token, int $result): void
    {
        $redis->shouldReceive('command')->once()->with('eval', Mockery::on(
            fn (array $parameters): bool => $parameters[1] === [$key, $token] && $parameters[2] === 1,
        ))->andReturn($result);
    }
}
