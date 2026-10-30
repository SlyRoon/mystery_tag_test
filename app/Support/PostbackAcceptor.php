<?php

namespace App\Support;

interface PostbackAcceptor
{
    /**
     * @param  array{click_id: string, event: string, amount: string}  $postback
     */
    public function accept(array $postback): PostbackAcceptanceOutcome;
}
