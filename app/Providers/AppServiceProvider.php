<?php

namespace App\Providers;

use App\Support\MySqlPostbackAcceptanceStore;
use App\Support\MySqlPostbackDeliveryHandler;
use App\Support\PostbackAcceptanceService;
use App\Support\PostbackAcceptanceStore;
use App\Support\PostbackAcceptor;
use App\Support\PostbackDeliveryHandler;
use App\Support\RedisPostbackReservation;
use App\Support\RuntimeConfiguration;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PostbackAcceptor::class, PostbackAcceptanceService::class);
        $this->app->bind(PostbackAcceptanceStore::class, MySqlPostbackAcceptanceStore::class);
        $this->app->bind(PostbackDeliveryHandler::class, MySqlPostbackDeliveryHandler::class);

        $this->app->bind(RedisPostbackReservation::class, fn (): RedisPostbackReservation => new RedisPostbackReservation(
            fn () => Redis::connection(),
            (int) config('postback.redis_reservation_lease_ms'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningConsoleCommand('key:generate', 'package:discover')) {
            return;
        }

        RuntimeConfiguration::assertValid([
            'app_key' => config('app.key'),
            'timezone' => config('app.timezone'),
            'database' => [
                'driver' => config('database.default'),
                'name' => config('database.connections.mysql.database'),
                'username' => config('database.connections.mysql.username'),
                'password' => config('database.connections.mysql.password'),
            ],
            'queue' => [
                'driver' => config('queue.default'),
                'connection' => config('queue.connections.database.connection'),
                'after_commit' => config('queue.connections.database.after_commit'),
            ],
            'redis' => [
                'client' => config('database.redis.client'),
                'host' => config('database.redis.default.host'),
            ],
            'partner_url' => config('postback.partner.url'),
            'timeouts' => [
                'connect' => config('postback.partner.connect_timeout'),
                'http' => config('postback.partner.timeout'),
                'worker' => config('postback.worker_timeout'),
                'retry_after' => config('postback.retry_after'),
                'delivery_lease' => config('postback.delivery_lease_seconds'),
                'recovery_lease' => config('postback.recovery_lease_seconds'),
                'redis_reservation_lease' => config('postback.redis_reservation_lease_ms'),
            ],
        ]);
    }
}
