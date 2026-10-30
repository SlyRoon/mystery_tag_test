<?php

namespace App\Support;

interface PostbackDeliveryHandler
{
    public function deliver(int $eventId): void;
}
