<?php

namespace Tests\Unit;

use App\Support\PostbackQueryParser;
use InvalidArgumentException;
use LengthException;
use Tests\TestCase;

class PostbackQueryParserTest extends TestCase
{
    public function test_decodes_query_values_without_trimming_and_canonicalizes_amount(): void
    {
        $parsed = $this->app->make(PostbackQueryParser::class)->parse(
            'click_id=%20x%20&event=Purchase+Event&amount=%2B049.900&extra=ignored'
        );

        $this->assertSame(' x ', $parsed['click_id']);
        $this->assertSame('Purchase Event', $parsed['event']);
        $this->assertSame('499e-1', $parsed['amount']);
    }

    public function test_accepts_percent_encoded_required_parameter_names(): void
    {
        $parsed = $this->app->make(PostbackQueryParser::class)->parse(
            'click%5Fid=one&%65vent=purchase&amount=1'
        );

        $this->assertSame('one', $parsed['click_id']);
        $this->assertSame('purchase', $parsed['event']);
        $this->assertSame('1e0', $parsed['amount']);
    }

    public function test_rejects_query_longer_than_4096_bytes(): void
    {
        $this->expectException(LengthException::class);

        $this->app->make(PostbackQueryParser::class)->parse(str_repeat('x', 4097));
    }

    public function test_rejects_repeated_and_array_parameter_names(): void
    {
        $rejected = 0;

        foreach ([
            'click_id=one&click_id=two&event=purchase&amount=1',
            'click_id=one&click%5Fid=two&event=purchase&amount=1',
            'click_id[]=one&event=purchase&amount=1',
            'click_id=one&event[]=purchase&amount=1',
            'click_id=one&event=purchase&amount[]=1',
        ] as $query) {
            try {
                $this->app->make(PostbackQueryParser::class)->parse($query);
            } catch (InvalidArgumentException) {
                $rejected++;

                continue;
            }

            $this->fail('Expected the parser to reject: '.$query);
        }

        $this->assertSame(5, $rejected);
    }
}
