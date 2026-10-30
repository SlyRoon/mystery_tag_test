<?php

namespace App\Support;

interface PostbackDeliveryHandler
{
    /** Return a delay in seconds when the job should be released until delivery is safe. */
    public function deliver(int $eventId): ?int;
}
