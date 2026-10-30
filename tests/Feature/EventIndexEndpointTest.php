<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventIndexEndpointTest extends TestCase
{
    public function test_invalid_status_is_rejected_without_querying_events(): void
    {
        $this->getJson('/api/events?status=unknown')
            ->assertUnprocessable()
            ->assertExactJson(['status' => 'error', 'message' => 'Invalid parameters'])
            ->assertHeaderContains('Cache-Control', 'no-store');
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_pagination_parameters_are_rejected(string $query): void
    {
        $this->getJson('/api/events?'.$query)
            ->assertUnprocessable()
            ->assertExactJson(['status' => 'error', 'message' => 'Invalid parameters'])
            ->assertHeaderContains('Cache-Control', 'no-store');
    }

    public static function invalidQueries(): iterable
    {
        yield 'zero page' => ['page=0'];
        yield 'negative page' => ['page=-1'];
        yield 'decimal page' => ['page=1.5'];
        yield 'page over signed 64-bit range' => ['page=9223372036854775808'];
        yield 'page array' => ['page[]=1'];
        yield 'empty page' => ['page='];
        yield 'zero per page' => ['per_page=0'];
        yield 'per page too high' => ['per_page=101'];
        yield 'per page array' => ['per_page[]=20'];
        yield 'empty per page' => ['per_page='];
        yield 'empty status' => ['status='];
        yield 'status array' => ['status[]=pending'];
    }

    public function test_database_failure_returns_service_unavailable(): void
    {
        DB::shouldReceive('table')
            ->once()
            ->with('events')
            ->andThrow(new QueryException('mysql', 'select count(*) from events', [], new PDOException('connection failed')));

        $this->getJson('/api/events')
            ->assertServiceUnavailable()
            ->assertExactJson(['status' => 'error', 'message' => 'Service unavailable'])
            ->assertHeaderContains('Cache-Control', 'no-store');
    }
}
