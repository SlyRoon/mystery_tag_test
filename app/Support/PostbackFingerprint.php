<?php

namespace App\Support;

use InvalidArgumentException;

final class PostbackFingerprint
{
    private const MAX_FIELD_BYTES = 4_294_967_295;

    public static function from(string $clickId, string $event, string $canonicalAmount): string
    {
        $payload = '';

        foreach ([$clickId, $event, $canonicalAmount] as $field) {
            $length = strlen($field);

            if ($length > self::MAX_FIELD_BYTES) {
                throw new InvalidArgumentException('Postback field exceeds the fingerprint format limit');
            }

            $payload .= pack('N', $length).$field;
        }

        return hash('sha256', $payload, binary: true);
    }
}
