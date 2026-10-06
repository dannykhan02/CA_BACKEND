<?php

namespace Tests;

use App\Services\AI\ProviderGate\MemoryGateStore;
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
        // Provider admission uses the in-process store in tests (Redis-backed tests opt in).
        MemoryGateStore::reset();
        config(['document_intelligence.provider_gate.driver' => 'memory']);
    }
}
