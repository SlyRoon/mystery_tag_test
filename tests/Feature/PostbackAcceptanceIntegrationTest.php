<?php

namespace Tests\Feature;

use App\Jobs\SendPostbackToPartner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PostbackAcceptanceIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('POSTBACK_INTEGRATION_TESTS') !== '1') {
            $this->markTestSkipped('Set POSTBACK_INTEGRATION_TESTS=1 to run destructive integration tests.');
        }

        if (config('app.env') !== 'testing'
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.host') !== 'mysql-test'
            || config('database.connections.mysql.database') !== 'mystery_tag_test'
            || config('database.connections.mysql.username') !== 'mystery_tag_test') {
            $this->markTestSkipped('Integration tests require the dedicated mystery_tag_test database.');
        }

        try {
            DB::connection('mysql')->getPdo();
            Redis::connection()->ping();
        } catch (Throwable) {
            $this->markTestSkipped('Integration tests require the configured MySQL and Redis services.');
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_equivalent_amounts_create_one_event_and_one_database_job(): void
    {
        $clickId = 'integration-'.bin2hex(random_bytes(8));

        $this->get($this->postbackUrl($clickId, '49.90'))
            ->assertOk()
            ->assertExactJson(['status' => 'accepted', 'message' => 'Event received']);

        $originalKey = DB::table('deduplication_keys')->first();
        $this->assertNotNull($originalKey->accepted_at);
        $this->assertNotNull($originalKey->expires_at);

        $this->get($this->postbackUrl($clickId, '4.99e1'))
            ->assertOk()
            ->assertExactJson(['status' => 'duplicate', 'message' => 'Event already received']);

        $this->assertSame(1, DB::table('events')->count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('deduplication_keys')->count());
        $this->assertSame($originalKey->accepted_at, DB::table('deduplication_keys')->value('accepted_at'));
        $this->assertSame($originalKey->expires_at, DB::table('deduplication_keys')->value('expires_at'));
        $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize($payload['data']['command']);
        $this->assertInstanceOf(SendPostbackToPartner::class, $job);
        $this->assertSame(DB::table('events')->value('id'), $job->eventId);
    }

    public function test_expired_24_hour_window_accepts_a_new_event(): void
    {
        $clickId = 'expired-'.bin2hex(random_bytes(8));
        $this->get($this->postbackUrl($clickId, '1'))->assertOk();

        DB::table('deduplication_keys')->update(['expires_at' => DB::raw('UTC_TIMESTAMP(6)')]);

        $this->get($this->postbackUrl($clickId, '1'))
            ->assertOk()
            ->assertExactJson(['status' => 'accepted', 'message' => 'Event received']);

        $this->assertSame(2, DB::table('events')->count());
        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_job_insert_failure_rolls_back_event_and_deduplication_key(): void
    {
        $jobInsertFailed = false;
        DB::listen(function (QueryExecuted $query) use (&$jobInsertFailed): void {
            if (! $jobInsertFailed && preg_match('/\binsert\s+into\s+[`"]?jobs\b/i', $query->sql) === 1) {
                $jobInsertFailed = true;
                throw new RuntimeException('Forced database queue insert failure');
            }
        });

        $this->get($this->postbackUrl('rollback-'.bin2hex(random_bytes(8)), '1'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'error', 'message' => 'Service unavailable']);

        $this->assertTrue($jobInsertFailed);
        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('deduplication_keys')->count());
    }

    private function postbackUrl(string $clickId, string $amount): string
    {
        return '/postback?click_id='.rawurlencode($clickId).'&event=purchase&amount='.rawurlencode($amount);
    }
}
