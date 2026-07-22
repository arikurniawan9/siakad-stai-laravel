<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_local_csp_allows_vite_react_bootstrap_and_ipv4_assets(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringContainsString('http://127.0.0.1:5173', $policy);
        $this->assertStringContainsString('ws://127.0.0.1:5173', $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
    }
}
