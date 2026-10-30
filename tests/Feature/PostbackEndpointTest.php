<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostbackEndpointTest extends TestCase
{
    public function test_valid_query_is_not_acknowledged_before_persistence_is_implemented(): void
    {
        $this->get('/postback?click_id=clk_123&event=purchase&amount=49.90')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'error', 'message' => 'Service unavailable'])
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderContains('Cache-Control', 'no-store');
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_query_returns_the_exact_validation_response(string $query): void
    {
        $this->assertInvalidResponse($this->get('/postback'.($query === '' ? '' : '?'.$query)));
    }

    public static function invalidQueries(): iterable
    {
        yield 'missing amount' => ['click_id=clk_123&event=purchase'];
        yield 'empty click id' => ['click_id=&event=purchase&amount=1'];
        yield 'whitespace click id' => ['click_id=%20%09&event=purchase&amount=1'];
        yield 'unicode whitespace click id' => ['click_id=%C2%85&event=purchase&amount=1'];
        yield 'empty event' => ['click_id=clk_123&event=&amount=1'];
        yield 'whitespace event' => ['click_id=clk_123&event=%20%0A&amount=1'];
        yield 'unicode whitespace event' => ['click_id=clk_123&event=%C2%85&amount=1'];
        yield 'missing all fields' => [''];
        yield 'negative amount' => ['click_id=clk_123&event=purchase&amount=-0.1'];
        yield 'malformed amount' => ['click_id=clk_123&event=purchase&amount=1e'];
        yield 'duplicate click id' => ['click_id=one&click_id=two&event=purchase&amount=1'];
        yield 'encoded duplicate field name' => ['click_id=one&click%5Fid=two&event=purchase&amount=1'];
        yield 'duplicate event' => ['click_id=one&event=purchase&event=refund&amount=1'];
        yield 'duplicate amount' => ['click_id=one&event=purchase&amount=1&amount=2'];
        yield 'click id array' => ['click_id[]=one&event=purchase&amount=1'];
        yield 'event array' => ['click_id=one&event[]=purchase&amount=1'];
        yield 'amount array' => ['click_id=one&event=purchase&amount[]=1'];
        yield 'invalid click id utf8' => ['click_id=%FF&event=purchase&amount=1'];
        yield 'invalid event utf8' => ['click_id=one&event=%C3%28&amount=1'];
    }

    public function test_get_body_does_not_supply_query_parameters(): void
    {
        $response = $this->json('GET', '/postback', [
            'click_id' => 'clk_123',
            'event' => 'purchase',
            'amount' => '49.90',
        ]);

        $this->assertInvalidResponse($response);
    }

    public function test_unknown_query_parameters_are_ignored(): void
    {
        $this->get('/postback?click_id=clk_123&event=purchase&amount=49.90&source=affiliate')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'error', 'message' => 'Service unavailable'])
            ->assertHeaderContains('Cache-Control', 'no-store');
    }

    public function test_raw_query_of_4096_bytes_reaches_validation_and_larger_query_is_rejected(): void
    {
        $prefix = 'click_id=';
        $suffix = '&event=e&amount=0';
        $paddingLength = 4096 - strlen($prefix) - strlen($suffix);

        $this->get('/postback?'.$prefix.str_repeat('x', $paddingLength).$suffix)
            ->assertStatus(503)
            ->assertHeaderContains('Cache-Control', 'no-store');

        $this->get('/postback?'.$prefix.str_repeat('x', $paddingLength + 1).$suffix)
            ->assertStatus(414)
            ->assertExactJson(['status' => 'error', 'message' => 'Query string too long'])
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderContains('Cache-Control', 'no-store');
    }

    private function assertInvalidResponse(TestResponse $response): void
    {
        $response->assertStatus(422)
            ->assertExactJson(['status' => 'error', 'message' => 'Invalid parameters'])
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderContains('Cache-Control', 'no-store');
    }
}
