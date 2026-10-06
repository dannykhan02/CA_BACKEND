<?php

namespace Tests\Unit;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StrayHttpRequestGuardTest extends TestCase
{
    public function test_an_unfaked_provider_request_fails_the_test_instead_of_reaching_the_network(): void
    {
        $this->expectException(StrayRequestException::class);
        Http::post('https://api.anthropic.com/v1/messages', ['model' => 'claude-haiku-4-5-20251001']);
    }

    public function test_faked_requests_still_work(): void
    {
        Http::fake(['*/v1/messages' => Http::response(['ok' => true])]);
        self::assertTrue(Http::post('https://api.anthropic.com/v1/messages')->json('ok'));
    }
}
