<?php

namespace Tests\Unit;

use Tests\Support\TicketTestDatabase;
use Tests\TestCase;

class TicketDatabaseSafetyTest extends TestCase
{
    public function test_runtime_database_is_isolated_without_writing_to_it(): void
    {
        $this->assertSame([
            'environment' => 'testing',
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
            'host' => null,
            'configuration_cached' => false,
        ], TicketTestDatabase::verify($this->app));
    }
}
