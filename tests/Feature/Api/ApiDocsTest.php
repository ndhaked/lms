<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;

class ApiDocsTest extends ApiTestCase
{
    public function test_swagger_page_and_spec_cover_every_api_route(): void
    {
        $this->get('/api/docs')->assertOk()->assertSee('swagger-ui');
        $spec = $this->getJson('/api/docs/openapi.json')->assertOk()->json();
        $this->assertSame('3.0.3', $spec['openapi']);

        $documented = 0;
        foreach ($spec['paths'] as $ops) {
            $documented += count($ops);
        }
        $routes = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'api.v1.'))
            ->sum(fn ($r) => count(array_diff($r->methods(), ['HEAD'])));
        $this->assertSame($routes, $documented, 'every API route appears in Swagger');

        $login = $spec['paths']['/api/v1/auth/login']['post'];
        $this->assertSame([], $login['security']);
        $this->assertContains('device_name', $login['requestBody']['content']['application/json']['schema']['required']);
        $alloc = $spec['paths']['/api/v1/libraries/{library}/seats/{seat}/allocate']['post'];
        $this->assertSame(['morning', 'afternoon', 'evening', 'night', 'fullday'], $alloc['requestBody']['content']['application/json']['schema']['properties']['shift']['enum']);
        $this->assertStringContainsString('seats.allocate', $alloc['description']);
    }

    public function test_docs_can_be_switched_off(): void
    {
        \App\Support\BillingSettings::set(['api_docs' => 'off']);
        $this->get('/api/docs')->assertNotFound();
        $this->getJson('/api/docs/openapi.json')->assertNotFound();
    }
}
