<?php

namespace App\Support;

use InvalidArgumentException;

final class DecimalAmountNormalizer
{
    private const GRAMMAR = '/\A[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?\z/';

    public function normalize(string $amount): string
    {
        if (preg_match(self::GRAMMAR, $amount) !== 1) {
            throw new InvalidArgumentException('Amount must be a non-negative decimal number');
        }

        $negative = str_starts_with($amount, '-');
        if ($amount[0] === '+' || $amount[0] === '-') {
            $amount = substr($amount, 1);
        }

        $exponent = '0';
        $exponentMarker = strcspn($amount, 'eE');
        if ($exponentMarker < strlen($amount)) {
            $exponent = $this->normalizeSignedInteger(substr($amount, $exponentMarker + 1));
            $amount = substr($amount, 0, $exponentMarker);
        }

        [$integerPart, $fractionalPart] = array_pad(explode('.', $amount, 2), 2, '');
        $digits = ltrim($integerPart.$fractionalPart, '0');

        if ($digits === '') {
            return '0';
        }

        if ($negative) {
            throw new InvalidArgumentException('Amount must not be negative');
        }

        $trailingZeros = strlen($digits) - strlen(rtrim($digits, '0'));
        $coefficient = $trailingZeros === 0
            ? $digits
            : substr($digits, 0, -$trailingZeros);
        $exponent = $this->addSmallInteger($exponent, $trailingZeros - strlen($fractionalPart));

        return $coefficient.'e'.$exponent;
    }

    private function normalizeSignedInteger(string $value): string
    {
        $negative = str_starts_with($value, '-');
        if ($value[0] === '+' || $value[0] === '-') {
            $value = substr($value, 1);
        }

        $magnitude = ltrim($value, '0');
        if ($magnitude === '') {
            return '0';
        }

        return $negative ? '-'.$magnitude : $magnitude;
    }

    private function addSmallInteger(string $left, int $right): string
    {
        $leftNegative = str_starts_with($left, '-');
        $leftMagnitude = $leftNegative ? substr($left, 1) : $left;
        $rightNegative = $right < 0;
        $rightMagnitude = (string) abs($right);

        if ($leftNegative === $rightNegative) {
            $magnitude = $this->addMagnitudes($leftMagnitude, $rightMagnitude);
            $negative = $leftNegative;
        } else {
            $comparison = $this->compareMagnitudes($leftMagnitude, $rightMagnitude);
            if ($comparison === 0) {
                return '0';
            }

            if ($comparison > 0) {
                $magnitude = $this->subtractMagnitudes($leftMagnitude, $rightMagnitude);
                $negative = $leftNegative;
            } else {
                $magnitude = $this->subtractMagnitudes($rightMagnitude, $leftMagnitude);
                $negative = $rightNegative;
            }
        }

        if ($magnitude === '0') {
            return '0';
        }

        return $negative ? '-'.$magnitude : $magnitude;
    }

    private function addMagnitudes(string $left, string $right): string
    {
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        $carry = 0;
        $result = '';

        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = $carry;
            if ($leftIndex >= 0) {
                $sum += ord($left[$leftIndex--]) - 48;
            }
            if ($rightIndex >= 0) {
                $sum += ord($right[$rightIndex--]) - 48;
            }

            $result .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }

        return strrev($result);
    }

    private function subtractMagnitudes(string $larger, string $smaller): string
    {
        $largerIndex = strlen($larger) - 1;
        $smallerIndex = strlen($smaller) - 1;
        $borrow = 0;
        $result = '';

        while ($largerIndex >= 0) {
            $digit = ord($larger[$largerIndex--]) - 48 - $borrow;
            $subtrahend = $smallerIndex >= 0 ? ord($smaller[$smallerIndex--]) - 48 : 0;

            if ($digit < $subtrahend) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }

            $result .= (string) ($digit - $subtrahend);
        }

        $magnitude = ltrim(strrev($result), '0');

        return $magnitude === '' ? '0' : $magnitude;
    }

    private function compareMagnitudes(string $left, string $right): int
    {
        $lengthComparison = strlen($left) <=> strlen($right);

        return $lengthComparison !== 0 ? $lengthComparison : strcmp($left, $right);
    }
}
