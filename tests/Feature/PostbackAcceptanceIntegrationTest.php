<?php

namespace Tests\Feature;

use App\Jobs\SendPostbackToPartner;
use App\Support\PostbackDeliveryHandler;
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
        $originalEvent = DB::table('events')->first();
        $this->assertNotNull($originalKey->accepted_at);
        $this->assertNotNull($originalKey->expires_at);
        $this->assertSame($originalEvent->accepted_at, $originalEvent->next_attempt_at);

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
        Http::assertSent(fn (Request $request): bool => $request->body() === '{"click_id":"'.$clickId.'","event":"purchase","amount":499e-1}');
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

    private function postbackUrl(string $clickId, string $amount): string
    {
        return '/postback?click_id='.rawurlencode($clickId).'&event=purchase&amount='.rawurlencode($amount);
    }
}
