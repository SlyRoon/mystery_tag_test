<?php

namespace Tests\Unit;

use App\Support\PostbackFingerprint;
use PHPUnit\Framework\TestCase;

class PostbackFingerprintTest extends TestCase
{
    public function test_fingerprint_uses_length_prefixed_utf8_fields_and_canonical_amount(): void
    {
        $expected = hash('sha256', pack('N', 3).'clk'.pack('N', 8).'purchase'.pack('N', 6).'499e-1', true);

        $this->assertSame($expected, PostbackFingerprint::from('clk', 'purchase', '499e-1'));
    }

    public function test_length_prefixes_keep_different_field_boundaries_distinct(): void
    {
        $this->assertNotSame(
            PostbackFingerprint::from('ab', 'c', '1e0'),
            PostbackFingerprint::from('a', 'bc', '1e0'),
        );
    }

    public function test_fingerprint_preserves_exact_utf8_bytes(): void
    {
        $clickId = ' x ';
        $event = 'Покупка';
        $amount = '0';
        $expected = hash(
            'sha256',
            pack('N', strlen($clickId)).$clickId
                .pack('N', strlen($event)).$event
                .pack('N', strlen($amount)).$amount,
            true,
        );

        $this->assertSame($expected, PostbackFingerprint::from($clickId, $event, $amount));
    }
}
