<?php

namespace App\Support;

use InvalidArgumentException;
use LengthException;

final class PostbackQueryParser
{
    private const MAX_QUERY_BYTES = 4096;

    private const REQUIRED_FIELDS = ['click_id', 'event', 'amount'];

    public function __construct(private readonly DecimalAmountNormalizer $amountNormalizer) {}

    /**
     * @return array{click_id: string, event: string, amount: string}
     */
    public function parse(string $rawQuery): array
    {
        if (strlen($rawQuery) > self::MAX_QUERY_BYTES) {
            throw new LengthException('Query string exceeds the maximum size');
        }

        $values = [];
        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$rawName, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($rawName);

            foreach (self::REQUIRED_FIELDS as $field) {
                if ($name === $field || str_starts_with($name, $field.'[')) {
                    if ($name !== $field || array_key_exists($field, $values)) {
                        throw new InvalidArgumentException('Required query parameters must be single values');
                    }

                    $values[$field] = urldecode($rawValue);
                    break;
                }
            }
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $values)) {
                throw new InvalidArgumentException('Required query parameters are missing');
            }
        }

        foreach (['click_id', 'event'] as $field) {
            if (preg_match('//u', $values[$field]) !== 1
                || preg_match('/\A(?:\s|\p{Z})*\z/u', $values[$field]) === 1) {
                throw new InvalidArgumentException("{$field} must be non-empty valid UTF-8");
            }
        }

        $values['amount'] = $this->amountNormalizer->normalize($values['amount']);

        return $values;
    }
}
