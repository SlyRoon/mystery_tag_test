<?php

namespace Tests\Unit;

use App\Support\DecimalAmountNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DecimalAmountNormalizerTest extends TestCase
{
    public function test_normalizes_equivalent_decimal_notations_to_the_same_value(): void
    {
        $normalizer = new DecimalAmountNormalizer;

        foreach ([
            '49.9' => '499e-1',
            '49.90' => '499e-1',
            '049.900' => '499e-1',
            '4.99e1' => '499e-1',
            '100' => '1e2',
            '1e2' => '1e2',
            '.5' => '5e-1',
            '1.' => '1e0',
            '+49.9' => '499e-1',
            '0.001' => '1e-3',
            '1200.00' => '12e2',
            '120e-2' => '12e-1',
            '1.20e-1' => '12e-2',
        ] as $input => $expected) {
            $this->assertSame($expected, $normalizer->normalize($input), $input);
        }
    }

    public function test_normalizes_all_zero_values_without_preserving_sign_or_exponent(): void
    {
        $normalizer = new DecimalAmountNormalizer;

        foreach (['0', '0.00', '-0', '-0e9999999999999999999999999999999999999999'] as $input) {
            $this->assertSame('0', $normalizer->normalize($input), $input);
        }
    }

    public function test_adjusts_exponents_larger_than_php_integer_range_using_decimal_strings(): void
    {
        $normalizer = new DecimalAmountNormalizer;

        $this->assertSame(
            '12e1234567890123456789012345678901234567889',
            $normalizer->normalize('1.20e1234567890123456789012345678901234567890'),
        );
        $this->assertSame(
            '12e-1234567890123456789012345678901234567891',
            $normalizer->normalize('1.20e-1234567890123456789012345678901234567890'),
        );
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_negative_nonzero_and_malformed_amounts(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DecimalAmountNormalizer)->normalize($input);
    }

    public static function invalidAmounts(): iterable
    {
        yield 'negative nonzero integer' => ['-1'];
        yield 'negative nonzero fraction' => ['-0.001'];
        yield 'not a number' => ['NaN'];
        yield 'infinity' => ['Infinity'];
        yield 'comma decimal separator' => ['1,2'];
        yield 'leading whitespace' => [' 1'];
        yield 'trailing whitespace' => ['1 '];
        yield 'dot without digits' => ['.'];
        yield 'missing exponent digits' => ['1e'];
        yield 'missing signed exponent digits' => ['1e+'];
        yield 'empty value' => [''];
        yield 'malformed sign' => ['--1'];
        yield 'invalid utf8 byte' => ["\xFF"];
    }
}
