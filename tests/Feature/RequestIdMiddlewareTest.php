<?php

namespace Tests\Feature;

use Tests\TestCase;

class RequestIdMiddlewareTest extends TestCase
{
    public function test_response_includes_generated_request_id_header(): void
    {
        $response = $this->getJson('/api/currencies/base');

        $response->assertHeader('X-Request-ID');
        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
    }

    public function test_reuses_incoming_request_id_header(): void
    {
        $incomingId = 'test-trace-12345';

        $response = $this->withHeader('X-Request-ID', $incomingId)
            ->getJson('/api/currencies/base');

        $response->assertHeader('X-Request-ID', $incomingId);
    }
}
