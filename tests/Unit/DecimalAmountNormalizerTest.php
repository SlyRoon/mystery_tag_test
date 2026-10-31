<?php

namespace Tests\Unit;

use App\Support\DecimalAmountNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DecimalAmountNormalizerTest extends TestCase
{
    public function test_equivalent_notations_give_the_same_plain_decimal(): void
    {
        $normalizer = new DecimalAmountNormalizer;

        foreach (['49.9', '49.90', '049.900', '4.99e1', '+49.9'] as $input) {
            $this->assertSame('49.9', $normalizer->normalize($input), $input);
        }

        $this->assertSame('100', $normalizer->normalize('1e2'));
        $this->assertSame('0.5', $normalizer->normalize('.5'));
        $this->assertSame('0', $normalizer->normalize('-0'));
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_negative_malformed_and_out_of_range_amounts(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DecimalAmountNormalizer)->normalize($input);
    }

    public static function invalidAmounts(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'not a number' => ['NaN'];
        yield 'comma separator' => ['1,2'];
        yield 'empty' => [''];
        yield 'huge exponent' => ['1e999'];
        yield 'too many integer digits' => [str_repeat('9', 31)];
    }
}
