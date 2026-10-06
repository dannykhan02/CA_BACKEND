<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No test may reach a real service (Anthropic, Voyage, Paystack, Google...).
        // Any HTTP call without a matching Http::fake() fails the test instead of spending.
        Http::preventStrayRequests();
    }
}
