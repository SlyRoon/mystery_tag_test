<?php

namespace Tests\Unit;

use App\Models\Event;
use PHPUnit\Framework\TestCase;

class EventModelTest extends TestCase
{
    public function test_event_model_exposes_persisted_fields_and_utc_timestamp_casts(): void
    {
        $event = new Event;

        $this->assertSame('events', $event->getTable());
        $this->assertSame([
            'click_id',
            'event_name',
            'amount_canonical',
            'accepted_at',
            'status',
            'attempt_count',
            'last_error',
            'sent_at',
        ], $event->getFillable());
        $this->assertSame('immutable_datetime', $event->getCasts()['accepted_at']);
        $this->assertSame('immutable_datetime', $event->getCasts()['sent_at']);
        $this->assertSame('integer', $event->getCasts()['attempt_count']);
    }
}
