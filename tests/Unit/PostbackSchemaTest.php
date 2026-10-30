<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostbackSchemaTest extends TestCase
{
    public function test_postback_tables_compile_to_the_required_mysql_schema(): void
    {
        $migrationFiles = glob(database_path('migrations/*create_events_and_deduplication_keys_tables.php'));

        $this->assertCount(1, $migrationFiles);
        $migration = require $migrationFiles[0];

        $statements = DB::connection('mysql')->pretend(fn () => $migration->up());
        $sql = implode("\n", array_column($statements, 'query'));

        $this->assertStringContainsString('create table `events`', $sql);
        $this->assertStringContainsString('`click_id` text', $sql);
        $this->assertStringContainsString('`event_name` text', $sql);
        $this->assertStringContainsString('`amount_canonical` text', $sql);
        $this->assertStringContainsString('`accepted_at` datetime(6)', $sql);
        $this->assertStringContainsString('`attempt_count` tinyint unsigned', $sql);
        $this->assertStringContainsString('`last_error` varchar(512)', $sql);
        $this->assertStringContainsString('events_accepted_at_id_index`(accepted_at desc, id desc)', strtolower($sql));
        $this->assertStringContainsString('create table `deduplication_keys`', $sql);
        $this->assertStringContainsString('`fingerprint` binary(32)', $sql);
        $this->assertStringContainsString('`expires_at` datetime(6)', $sql);
    }

    public function test_delivery_tracking_migration_adds_only_the_required_retry_state(): void
    {
        $migrationFiles = glob(database_path('migrations/*add_delivery_tracking_to_events_table.php'));

        $this->assertCount(1, $migrationFiles);
        $migration = require $migrationFiles[0];

        $statements = DB::connection('mysql')->pretend(fn () => $migration->up());
        $sql = strtolower(implode("\n", array_column($statements, 'query')));

        $this->assertStringContainsString('`delivery_token` binary(16)', $sql);
        $this->assertStringContainsString('`delivery_lease_until` datetime(6)', $sql);
        $this->assertStringContainsString('`next_attempt_at` datetime(6) not null default current_timestamp', $sql);
        $this->assertStringNotContainsString('delivery_attempts', $sql);
        $this->assertStringNotContainsString('recovery_lease_until', $sql);
    }
}
