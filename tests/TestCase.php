<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with((string) $database, '_testing')) {
            throw new RuntimeException("Os testes só podem correr numa base *_testing (actual: {$database}).");
        }
    }
}
