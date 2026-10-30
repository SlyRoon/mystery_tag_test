<?php

namespace Tests\Unit;

use App\Support\PartnerPostbackClient;
use App\Support\PartnerPostbackResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PartnerPostbackClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'postback.partner.url' => 'http://partner.test/postback',
            'postback.partner.connect_timeout' => 2,
            'postback.partner.timeout' => 5,
        ]);
    }

    public function test_it_sends_exact_json_with_a_numeric_amount_and_accepts_any_2xx(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $result = app(PartnerPostbackClient::class)->send('clk_123', 'purchase', '499e-1');

        $this->assertSame(PartnerPostbackResult::Sent, $result);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://partner.test/postback'
            && $request->method() === 'POST'
            && $request->header('Content-Type') === ['application/json']
            && $request->body() === '{"click_id":"clk_123","event":"purchase","amount":499e-1}');
        Http::assertSentCount(1);
    }

    public function test_it_sends_zero_as_a_numeric_json_literal(): void
    {
        Http::fake(['*' => Http::response(['accepted' => true], 202)]);

        $result = app(PartnerPostbackClient::class)->send('c', 'e', '0');

        $this->assertSame(PartnerPostbackResult::Sent, $result);
        Http::assertSent(fn (Request $request): bool => $request->body() === '{"click_id":"c","event":"e","amount":0}');
    }

    public function test_it_marks_throttling_and_server_responses_as_retryable(): void
    {
        Http::fakeSequence()
            ->push('throttled', 429)
            ->push('unavailable', 503);

        $client = app(PartnerPostbackClient::class);

        $this->assertSame(PartnerPostbackResult::RetryableHttpFailure, $client->send('c', 'e', '1e0'));
        $this->assertSame(PartnerPostbackResult::RetryableHttpFailure, $client->send('c', 'e', '1e0'));
        Http::assertSentCount(2);
    }

    public function test_it_does_not_retry_other_client_errors_or_redirects(): void
    {
        Http::fakeSequence()
            ->push('bad request', 400)
            ->push('', 302, ['Location' => 'http://partner.test/elsewhere']);

        $client = app(PartnerPostbackClient::class);

        $this->assertSame(PartnerPostbackResult::PermanentFailure, $client->send('c', 'e', '1e0'));
        $this->assertSame(PartnerPostbackResult::PermanentFailure, $client->send('c', 'e', '1e0'));
        Http::assertSentCount(2);
    }

    public function test_it_marks_network_errors_as_retryable(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('connection failed', 0, new \RuntimeException('Could not resolve host'));
        });

        $this->assertSame(
            PartnerPostbackResult::NetworkFailure,
            app(PartnerPostbackClient::class)->send('c', 'e', '1e0'),
        );
    }

    public function test_it_distinguishes_timeouts_from_other_network_errors(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('Connection error', 0, new \RuntimeException('cURL error 28: Operation timed out'));
        });

        $this->assertSame(
            PartnerPostbackResult::Timeout,
            app(PartnerPostbackClient::class)->send('c', 'e', '1e0'),
        );
    }
}
