<?php

namespace Tests\Unit;

use App\Support\RuntimeConfiguration;
use InvalidArgumentException;
use Tests\TestCase;

class RuntimeConfigurationTest extends TestCase
{
    public function test_runtime_defaults_match_the_service_contract(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('database', config('queue.default'));
        $this->assertSame('mysql', config('queue.connections.database.connection'));
        $this->assertFalse(config('queue.connections.database.after_commit'));
        $this->assertSame('phpredis', config('database.redis.client'));
        $this->assertSame("SET time_zone = '+00:00'", config('database.connections.mysql.options')[\PDO::MYSQL_ATTR_INIT_COMMAND]);
        $this->assertSame(2, config('postback.partner.connect_timeout'));
        $this->assertSame(5, config('postback.partner.timeout'));
        $this->assertSame(15, config('postback.worker_timeout'));
        $this->assertSame(30, config('postback.retry_after'));
        $this->assertSame(45, config('postback.delivery_lease_seconds'));
        $this->assertSame(600, config('postback.recovery_lease_seconds'));
        $this->assertSame(15000, config('postback.redis_reservation_lease_ms'));
        $this->assertSame([1, 5], config('postback.retry_delays_seconds'));
    }

    public function test_runtime_configuration_rejects_invalid_timeout_order(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RuntimeConfiguration::assertValid([
            'app_key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'timezone' => 'UTC',
            'database' => [
                'driver' => 'mysql',
                'name' => 'mystery_tag',
                'username' => 'mystery_tag',
                'password' => 'local-only-password',
            ],
            'queue' => [
                'driver' => 'database',
                'connection' => 'mysql',
                'after_commit' => false,
            ],
            'redis' => [
                'client' => 'phpredis',
                'host' => 'redis',
            ],
            'partner_url' => 'http://mock-partner:9000/postback',
            'timeouts' => [
                'connect' => 6,
                'http' => 5,
                'worker' => 4,
                'retry_after' => 3,
                'delivery_lease' => 2,
                'recovery_lease' => 600,
                'redis_reservation_lease' => 15,
            ],
        ]);
    }

    public function test_runtime_configuration_rejects_connect_timeout_longer_than_http_timeout(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RuntimeConfiguration::assertValid([
            'app_key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'timezone' => 'UTC',
            'database' => [
                'driver' => 'mysql',
                'name' => 'mystery_tag',
                'username' => 'mystery_tag',
                'password' => 'local-only-password',
            ],
            'queue' => [
                'driver' => 'database',
                'connection' => 'mysql',
                'after_commit' => false,
            ],
            'redis' => [
                'client' => 'phpredis',
                'host' => 'redis',
            ],
            'partner_url' => 'http://mock-partner:9000/postback',
            'timeouts' => [
                'connect' => 6,
                'http' => 5,
                'worker' => 15,
                'retry_after' => 30,
                'delivery_lease' => 45,
                'recovery_lease' => 600,
                'redis_reservation_lease' => 15,
            ],
        ]);
    }
}
