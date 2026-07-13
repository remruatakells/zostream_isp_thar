<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
