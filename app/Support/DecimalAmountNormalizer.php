<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Приводить суму до канонічного десяткового запису без експоненти:
 * 49.90 -> "49.9", 1e2 -> "100", 0.00 -> "0". Однакові числові значення
 * завжди дають однаковий рядок, тому він придатний і для дедуплікації,
 * і для JSON-числа, яке отримує партнер.
 */
final class DecimalAmountNormalizer
{
    private const GRAMMAR = '/\A[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?\z/';

    public const MAX_INTEGER_DIGITS = 30;

    public const MAX_FRACTION_DIGITS = 30;

    public function normalize(string $amount): string
    {
        if (preg_match(self::GRAMMAR, $amount) !== 1) {
            throw new InvalidArgumentException('Amount must be a non-negative decimal number');
        }

        $negative = $amount[0] === '-';
        $amount = ltrim($amount, '+-');

        $exponent = 0;
        $exponentDigits = '';
        $marker = strcspn($amount, 'eE');
        if ($marker < strlen($amount)) {
            $exponentText = substr($amount, $marker + 1);
            $exponentNegative = $exponentText[0] === '-';
            $exponentDigits = ltrim(ltrim($exponentText, '+-'), '0');
            $amount = substr($amount, 0, $marker);
            if (strlen($exponentDigits) <= 4) {
                $exponent = (int) ($exponentDigits === '' ? '0' : $exponentDigits) * ($exponentNegative ? -1 : 1);
            }
        }

        [$integerPart, $fractionalPart] = array_pad(explode('.', $amount, 2), 2, '');
        $digits = ltrim($integerPart.$fractionalPart, '0');

        if ($digits === '') {
            return '0';
        }

        if ($negative) {
            throw new InvalidArgumentException('Amount must not be negative');
        }

        if (strlen($exponentDigits) > 4) {
            throw new InvalidArgumentException('Amount exponent is too large');
        }

        $trailingZeros = strlen($digits) - strlen(rtrim($digits, '0'));
        $digits = substr($digits, 0, strlen($digits) - $trailingZeros);
        $scale = $exponent + $trailingZeros - strlen($fractionalPart);

        if ($scale >= 0) {
            if (strlen($digits) + $scale > self::MAX_INTEGER_DIGITS) {
                throw new InvalidArgumentException('Amount is too large');
            }

            return $digits.str_repeat('0', $scale);
        }

        $fractionLength = -$scale;
        if ($fractionLength > self::MAX_FRACTION_DIGITS) {
            throw new InvalidArgumentException('Amount has too many fractional digits');
        }

        if ($fractionLength >= strlen($digits)) {
            return '0.'.str_repeat('0', $fractionLength - strlen($digits)).$digits;
        }

        $integer = substr($digits, 0, strlen($digits) - $fractionLength);
        if (strlen($integer) > self::MAX_INTEGER_DIGITS) {
            throw new InvalidArgumentException('Amount is too large');
        }

        return $integer.'.'.substr($digits, -$fractionLength);
    }
}
