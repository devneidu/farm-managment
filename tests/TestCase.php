<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety net: refuse to run any test unless it targets a disposable "*_test"
     * database. Runs before RefreshDatabase or any test can touch the schema.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against non-test database [{$database}]. Check phpunit.xml DB_DATABASE.");
        }
    }
}
