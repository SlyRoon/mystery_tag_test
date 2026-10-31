<?php

namespace App\Support;

interface PostbackAcceptanceStore
{
    /**
     * @param  array{click_id: string, event: string, amount: string}  $postback
     */
    public function accept(array $postback, string $fingerprint): PostbackAcceptanceOutcome;

    public function expirationMilliseconds(string $fingerprint): int;
}
