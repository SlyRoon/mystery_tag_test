<?php

namespace Tests\Feature;

use App\Jobs\SendPostbackToPartner;
use App\Support\MySqlPostbackAcceptanceStore;
use App\Support\PostbackAcceptanceOutcome;
use App\Support\PostbackAcceptanceService;
use App\Support\PostbackDeliveryHandler;
use App\Support\PostbackFingerprint;
use App\Support\RedisPostbackReservation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
            throw new RuntimeException('Integration tests require the dedicated mystery_tag_test database.');
        }

        try {
            DB::connection('mysql')->getPdo();
            Redis::connection()->ping();
        } catch (Throwable $exception) {
            throw new RuntimeException('Integration tests require the configured MySQL and Redis services.', 0, $exception);
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
        $originalEvent = DB::table('events')->first();
        $this->assertNotNull($originalKey->accepted_at);
        $this->assertNotNull($originalKey->expires_at);
        $this->assertSame($originalEvent->accepted_at, $originalEvent->next_attempt_at);

        $cacheKey = 'dedupe:'.bin2hex(PostbackFingerprint::from($clickId, 'purchase', '49.9'));
        $this->assertSame('1', Redis::get($cacheKey));
        $this->assertGreaterThan(0, Redis::pttl($cacheKey));
        $this->assertLessThanOrEqual(86_400_000, Redis::pttl($cacheKey));

        $this->get($this->postbackUrl($clickId, '4.99e1'))
            ->assertOk()
            ->assertExactJson(['status' => 'duplicate', 'message' => 'Event already received']);

        Redis::del($cacheKey);
        $this->get($this->postbackUrl($clickId, '49.9'))
            ->assertOk()
            ->assertExactJson(['status' => 'duplicate', 'message' => 'Event already received']);
        $this->assertSame('1', Redis::get($cacheKey));

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
        Redis::del('dedupe:'.bin2hex(PostbackFingerprint::from($clickId, 'purchase', '1')));

        $this->get($this->postbackUrl($clickId, '1'))
            ->assertOk()
            ->assertExactJson(['status' => 'accepted', 'message' => 'Event received']);

        $this->assertSame(2, DB::table('events')->count());
        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_redis_outage_uses_mysql_for_acceptance_and_duplicates(): void
    {
        $service = new PostbackAcceptanceService(
            new RedisPostbackReservation(fn () => throw new RuntimeException('Redis unavailable'), 15000),
            new MySqlPostbackAcceptanceStore,
        );
        $postback = [
            'click_id' => 'redis-outage-'.bin2hex(random_bytes(8)),
            'event' => 'purchase',
            'amount' => '1',
        ];

        $this->assertSame(PostbackAcceptanceOutcome::Accepted, $service->accept($postback));
        $this->assertSame(PostbackAcceptanceOutcome::Duplicate, $service->accept($postback));
        $this->assertSame(1, DB::table('events')->count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_expired_deadline_does_not_create_a_redis_marker(): void
    {
        $fingerprint = random_bytes(32);
        $key = 'dedupe:'.bin2hex($fingerprint);
        $deadlineMilliseconds = (int) floor(microtime(true) * 1000) - 1000;

        app(RedisPostbackReservation::class)->rememberAccepted($fingerprint, $deadlineMilliseconds);

        $this->assertNull(Redis::get($key));
    }

    public function test_near_expiry_deadline_uses_mysql_instead_of_a_redis_marker(): void
    {
        $fingerprint = random_bytes(32);
        $key = 'dedupe:'.bin2hex($fingerprint);
        $deadlineMilliseconds = (int) floor(microtime(true) * 1000) + 500;

        app(RedisPostbackReservation::class)->rememberAccepted($fingerprint, $deadlineMilliseconds);

        $this->assertNull(Redis::get($key));
    }

    public function test_job_insert_failure_rolls_back_event_and_deduplication_key(): void
    {
        $clickId = 'rollback-'.bin2hex(random_bytes(8));
        $jobInsertFailed = false;
        DB::listen(function (QueryExecuted $query) use (&$jobInsertFailed): void {
            if (! $jobInsertFailed && preg_match('/\binsert\s+into\s+[`"]?jobs\b/i', $query->sql) === 1) {
                $jobInsertFailed = true;
                throw new RuntimeException('Forced database queue insert failure');
            }
        });

        $this->get($this->postbackUrl($clickId, '1'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'error', 'message' => 'Service unavailable']);

        $this->assertTrue($jobInsertFailed);
        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('deduplication_keys')->count());
        $this->assertNull(Redis::get('dedupe:'.bin2hex(PostbackFingerprint::from($clickId, 'purchase', '1'))));
    }

    public function test_successful_partner_response_marks_the_event_sent(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $clickId = 'delivery-'.bin2hex(random_bytes(8));

        $this->get($this->postbackUrl($clickId, '49.90'))->assertOk();
        $event = DB::table('events')->first();

        app(PostbackDeliveryHandler::class)->deliver((int) $event->id);

        $event = DB::table('events')->first();
        $this->assertSame('sent', $event->status);
        $this->assertSame(1, $event->attempt_count);
        $this->assertNotNull($event->sent_at);
        $this->assertNull($event->last_error);
        Http::assertSent(fn (Request $request): bool => $request->body() === '{"click_id":"'.$clickId.'","event":"purchase","amount":49.9}');
        Http::assertSentCount(1);
    }

    public function test_retryable_partner_failures_stop_after_three_post_attempts(): void
    {
        Http::fakeSequence()
            ->push('', 503)
            ->push('', 429)
            ->push('', 500)
            ->push(['status' => 'ok'], 200);
        $clickId = 'retry-'.bin2hex(random_bytes(8));

        $this->get($this->postbackUrl($clickId, '1'))->assertOk();
        $eventId = (int) DB::table('events')->value('id');
        $handler = app(PostbackDeliveryHandler::class);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            DB::table('jobs')->delete();
            if ($attempt > 1) {
                DB::table('events')->where('id', $eventId)->update(['next_attempt_at' => DB::raw('UTC_TIMESTAMP(6)')]);
            }

            $handler->deliver($eventId);
            $event = DB::table('events')->where('id', $eventId)->first();
            $this->assertSame($attempt, (int) $event->attempt_count);
            $this->assertSame($attempt === 3 ? 'failed' : 'pending', $event->status);
            $this->assertSame('http_'.[503, 429, 500][$attempt - 1], $event->last_error);
            $this->assertSame($attempt < 3 ? 1 : 0, DB::table('jobs')->count());
        }

        $handler->deliver($eventId);

        $this->assertSame(3, (int) DB::table('events')->where('id', $eventId)->value('attempt_count'));
        Http::assertSentCount(3);
    }

    public function test_other_client_errors_fail_without_a_retry(): void
    {
        Http::fake(['*' => Http::response('bad request', 400)]);
        $clickId = 'permanent-'.bin2hex(random_bytes(8));

        $this->get($this->postbackUrl($clickId, '1'))->assertOk();
        $eventId = (int) DB::table('events')->value('id');
        DB::table('jobs')->delete();

        app(PostbackDeliveryHandler::class)->deliver($eventId);

        $event = DB::table('events')->where('id', $eventId)->first();
        $this->assertSame('failed', $event->status);
        $this->assertSame(1, (int) $event->attempt_count);
        $this->assertSame('http_400', $event->last_error);
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertSentCount(1);
    }

    public function test_event_index_filters_and_paginates_stably_without_losing_amount_precision(): void
    {
        DB::table('events')->insert([
            [
                'click_id' => 'view-first',
                'event_name' => 'purchase',
                'amount_canonical' => '49.9',
                'accepted_at' => '2026-10-30 10:00:00.000000',
                'status' => 'pending',
                'attempt_count' => 0,
                'last_error' => null,
                'sent_at' => null,
            ],
            [
                'click_id' => 'view-second',
                'event_name' => 'refund',
                'amount_canonical' => '0',
                'accepted_at' => '2026-10-30 10:00:00.000000',
                'status' => 'pending',
                'attempt_count' => 2,
                'last_error' => 'http_503',
                'sent_at' => null,
            ],
            [
                'click_id' => 'view-sent',
                'event_name' => 'purchase',
                'amount_canonical' => '1',
                'accepted_at' => '2026-10-30 09:00:00.000000',
                'status' => 'sent',
                'attempt_count' => 1,
                'last_error' => null,
                'sent_at' => '2026-10-30 09:01:02.123456',
            ],
        ]);

        $this->getJson('/api/events?status=pending&per_page=1&page=1')
            ->assertOk()
            ->assertExactJson([
                'data' => [[
                    'id' => (int) DB::table('events')->where('click_id', 'view-second')->value('id'),
                    'click_id' => 'view-second',
                    'event' => 'refund',
                    'amount' => '0',
                    'accepted_at' => '2026-10-30T10:00:00.000000Z',
                    'status' => 'pending',
                    'attempt_count' => 2,
                    'last_error' => 'http_503',
                    'sent_at' => null,
                ]],
                'meta' => ['page' => 1, 'per_page' => 1, 'total' => 2],
            ])
            ->assertHeaderContains('Cache-Control', 'no-store');

        $this->getJson('/api/events?status=pending&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.click_id', 'view-first')
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/events?status=pending&per_page=1&page=9223372036854775807')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'meta' => ['page' => PHP_INT_MAX, 'per_page' => 1, 'total' => 2],
            ]);
    }

    private function postbackUrl(string $clickId, string $amount): string
    {
        return '/postback?click_id='.rawurlencode($clickId).'&event=purchase&amount='.rawurlencode($amount);
    }
}
