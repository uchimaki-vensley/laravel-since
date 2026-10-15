<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // テストから外部へ HTTP を送らない。必要な応答は各テストで Http::fake する。
        Http::preventStrayRequests();
    }
}
