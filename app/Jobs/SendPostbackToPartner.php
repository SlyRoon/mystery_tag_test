<?php

namespace App\Jobs;

use App\Support\PostbackDeliveryHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendPostbackToPartner implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public function __construct(public readonly int $eventId) {}

    public function handle(PostbackDeliveryHandler $handler): void
    {
        $delay = $handler->deliver($this->eventId);
        if ($delay !== null) {
            $this->release($delay);
        }
    }
}
