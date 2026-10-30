<?php

namespace Tests\Unit;

use App\Jobs\SendPostbackToPartner;
use App\Support\PostbackDeliveryHandler;
use Mockery;
use Tests\TestCase;

class SendPostbackToPartnerTest extends TestCase
{
    public function test_job_delegates_delivery_using_only_the_persisted_event_id(): void
    {
        $handler = Mockery::mock(PostbackDeliveryHandler::class);
        $handler->shouldReceive('deliver')->once()->with(123);

        $job = new SendPostbackToPartner(123);
        $job->handle($handler);

        $this->assertSame(123, $job->eventId);
    }
}
