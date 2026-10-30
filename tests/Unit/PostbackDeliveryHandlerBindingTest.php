<?php

namespace Tests\Unit;

use App\Support\MySqlPostbackDeliveryHandler;
use App\Support\PostbackDeliveryHandler;
use Tests\TestCase;

class PostbackDeliveryHandlerBindingTest extends TestCase
{
    public function test_delivery_handler_resolves_to_the_mysql_implementation(): void
    {
        $this->assertInstanceOf(MySqlPostbackDeliveryHandler::class, app(PostbackDeliveryHandler::class));
    }
}
