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
        $handler->shouldReceive('deliver')->once()->with(123)->andReturn(null);

        $job = new SendPostbackToPartner(123);
        $job->handle($handler);

        $this->assertSame(123, $job->eventId);
    }

    public function test_job_releases_itself_when_delivery_is_blocked_until_a_later_time(): void
    {
        $handler = Mockery::mock(PostbackDeliveryHandler::class);
        $handler->shouldReceive('deliver')->once()->with(456)->andReturn(7);

        $job = (new SendPostbackToPartner(456))->withFakeQueueInteractions();
        $job->handle($handler);

        $job->assertReleased(7);
        $this->assertSame(0, $job->tries);
    }
}
