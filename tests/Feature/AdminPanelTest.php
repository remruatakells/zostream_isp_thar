<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Welcome back');
    }

    public function test_admin_can_sign_in_and_open_dashboard(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertOk()->assertSee('Your ISP, at a glance');
    }

    public function test_admin_can_create_router_and_package(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/routers', [
            'name' => 'Main Router', 'host' => '192.168.88.1', 'port' => 443,
            'username' => 'isp-panel', 'password' => 'router-secret',
            'use_ssl' => '1', 'verify_ssl' => '1', 'is_active' => '1',
        ])->assertRedirect('/routers');

        $this->actingAs($user)->post('/packages', [
            'name' => 'Home 20M', 'mikrotik_profile' => 'home-20m',
            'rate_limit' => '20M/20M', 'price' => 699, 'validity_days' => 30,
            'is_active' => '1',
        ])->assertRedirect('/packages');

        $this->assertDatabaseHas('routers', ['name' => 'Main Router']);
        $this->assertSame('router-secret', Router::first()->password);
        $this->assertDatabaseHas('packages', ['mikrotik_profile' => 'home-20m']);
    }

    public function test_api_requires_bearer_token(): void
    {
        config(['services.isp_api.token' => 'test-token']);

        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $this->withToken('test-token')->getJson('/api/v1/dashboard')
            ->assertOk()->assertJsonPath('data.customers', 0);
    }

    public function test_customer_sync_creates_the_package_profile_before_the_ppp_secret(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Test Router',
            'host' => '10.77.0.2',
            'port' => 80,
            'username' => 'zostream-api',
            'password' => 'router-secret',
            'use_ssl' => false,
            'verify_ssl' => false,
            'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Family 30 Mbps',
            'mikrotik_profile' => 'family-30m',
            'rate_limit' => '30M/30M',
            'price' => 899,
            'validity_days' => 30,
            'is_active' => true,
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/profile')) {
                return Http::response([]);
            }
            if ($request->method() === 'PUT' && str_ends_with($request->url(), '/rest/ppp/profile')) {
                return Http::response(['.id' => '*1']);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/secret')) {
                return Http::response([]);
            }
            if ($request->method() === 'PUT' && str_ends_with($request->url(), '/rest/ppp/secret')) {
                return Http::response(['.id' => '*2']);
            }

            return Http::response([], 404);
        });

        $this->actingAs($user)->post('/customers', [
            'router_id' => $router->id,
            'package_id' => $package->id,
            'name' => 'Test Customer',
            'phone' => '9876543210',
            'username' => 'TEST1USER',
            'password' => 'customer-secret',
            'status' => 'active',
            'expires_at' => now()->addMonth()->toDateString(),
        ])->assertRedirect('/customers')
            ->assertSessionHas('success', 'Customer created and synced with MikroTik.');

        $requests = Http::recorded();
        $this->assertCount(4, $requests);
        $this->assertSame('.id,name', $requests[0][0]->data()['.proplist']);
        $this->assertStringEndsWith('/rest/ppp/profile', $requests[1][0]->url());
        $this->assertSame('PUT', $requests[1][0]->method());
        $this->assertSame('family-30m', $requests[1][0]['name']);
        $this->assertSame('.id,name', $requests[2][0]->data()['.proplist']);
        $this->assertStringEndsWith('/rest/ppp/secret', $requests[3][0]->url());
        $this->assertSame('family-30m', $requests[3][0]['profile']);

        $customer = Customer::firstOrFail();
        $this->assertSame('*2', $customer->mikrotik_id);
        $this->assertNotNull($customer->last_synced_at);
    }
}
