<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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

    public function test_dashboard_shows_live_offline_and_expired_customer_details(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Main POP', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Starter', 'mikrotik_profile' => 'starter',
            'rate_limit' => '10M/10M', 'price' => 499,
            'validity_days' => 30, 'is_active' => true,
        ]);
        foreach ([
            ['name' => 'Online Person', 'username' => 'online001', 'expires_at' => today()->addMonth()],
            ['name' => 'Offline Person', 'username' => 'offline001', 'expires_at' => today()->addMonth()],
            ['name' => 'Expired Person', 'username' => 'expired001', 'expires_at' => today()->subDay()],
        ] as $data) {
            Customer::create($data + [
                'router_id' => $router->id,
                'package_id' => $package->id,
                'password' => 'customer-secret',
                'status' => 'active',
            ]);
        }

        Http::fake([
            '*/rest/ppp/active*' => Http::response([
                ['name' => 'online001', 'address' => '10.20.0.2', 'uptime' => '5m'],
            ]),
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Online customers')
            ->assertSee('Offline Person')
            ->assertSee('Expired Person')
            ->assertSee('Main POP');

        Http::assertSentCount(1);
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

    public function test_admin_can_import_customers_from_excel_for_one_router(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Import Router', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Import Package', 'mikrotik_profile' => 'import-profile',
            'rate_limit' => '20M/20M', 'price' => 699,
            'validity_days' => 30, 'is_active' => true,
        ]);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['full name', 'mobile', 'address', 'pppoe username', 'pppoe password', 'status', 'expiry date'],
            ['Excel Customer One', '9876543210', 'Address One', 'excel001', 'pass-one', 'active', '2026-12-31'],
            ['Excel Customer Two', '9876543211', 'Address Two', 'excel002', 'pass-two', 'disabled', '31/12/2026'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'customer-import-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $file = new UploadedFile(
            $path,
            'customers.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $this->actingAs($user)->post(route('customers.import.store'), [
            'router_id' => $router->id,
            'package_id' => $package->id,
            'file' => $file,
            'duplicate_action' => 'skip',
        ])->assertRedirect(route('customers.import.create'))
            ->assertSessionHas('success', 'Excel import complete — 2 created, 0 updated, 0 skipped.');

        $this->assertDatabaseHas('customers', [
            'router_id' => $router->id,
            'package_id' => $package->id,
            'username' => 'excel001',
            'status' => 'active',
            'expires_at' => '2026-12-31 00:00:00',
        ]);
        $this->assertDatabaseHas('customers', [
            'username' => 'excel002',
            'status' => 'suspended',
        ]);
        $this->assertSame('pass-one', Customer::where('username', 'excel001')->firstOrFail()->password);
    }

    public function test_admin_can_download_the_excel_import_template(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('customers.import.template'))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_admin_can_sync_all_customers_matching_the_current_filters(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Bulk Sync Router', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Bulk Package', 'mikrotik_profile' => 'bulk-profile',
            'rate_limit' => '10M/10M', 'price' => 500,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $active = Customer::create([
            'router_id' => $router->id, 'package_id' => $package->id,
            'name' => 'Active Customer', 'username' => 'bulk-active',
            'password' => 'password', 'status' => 'active',
            'expires_at' => today()->addMonth(),
        ]);
        $suspended = Customer::create([
            'router_id' => $router->id, 'package_id' => $package->id,
            'name' => 'Suspended Customer', 'username' => 'bulk-suspended',
            'password' => 'password', 'status' => 'suspended',
            'expires_at' => today()->addMonth(),
        ]);
        $lastActive = null;
        foreach (range(2, 9) as $number) {
            $lastActive = Customer::create([
                'router_id' => $router->id, 'package_id' => $package->id,
                'name' => "Active Customer {$number}", 'username' => "bulk-active-{$number}",
                'password' => 'password', 'status' => 'active',
                'expires_at' => today()->addMonth(),
            ]);
        }

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response([]);
            }

            return Http::response(['.id' => '*1']);
        });

        $firstBatch = $this->actingAs($user)->postJson(route('customers.sync-all'), [
            'router_id' => $router->id,
            'status' => 'active',
            'after_id' => 0,
            'synced_total' => 0,
            'failed_total' => 0,
        ])->assertOk()
            ->assertJsonPath('total', 9)
            ->assertJsonPath('processed', 8)
            ->assertJsonPath('synced_total', 8)
            ->assertJsonPath('failed_total', 0)
            ->assertJsonPath('has_more', true);

        $this->actingAs($user)->postJson(route('customers.sync-all'), [
            'router_id' => $router->id,
            'status' => 'active',
            'after_id' => $firstBatch->json('next_after_id'),
            'synced_total' => 8,
            'failed_total' => 0,
        ])->assertOk()
            ->assertJsonPath('total', 9)
            ->assertJsonPath('processed', 9)
            ->assertJsonPath('synced_total', 9)
            ->assertJsonPath('failed_total', 0)
            ->assertJsonPath('has_more', false);

        $this->assertNotNull($active->fresh()->last_synced_at);
        $this->assertNotNull($lastActive->fresh()->last_synced_at);
        $this->assertNull($suspended->fresh()->last_synced_at);
        Http::assertSentCount(22);
    }

    public function test_admin_delete_removes_the_mikrotik_secret_before_deleting_the_customer(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Delete Router', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Delete Package', 'mikrotik_profile' => 'delete-profile',
            'rate_limit' => '10M/10M', 'price' => 500,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $customer = Customer::create([
            'router_id' => $router->id, 'package_id' => $package->id,
            'name' => 'Delete Customer', 'username' => 'delete-user',
            'password' => 'password', 'status' => 'active',
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response([['.id' => '*9', 'name' => 'delete-user']]);
            }

            return Http::response([]);
        });

        $this->actingAs($user)->delete(route('customers.destroy', $customer))
            ->assertRedirect()
            ->assertSessionHas('success', 'Customer deleted from the admin panel and MikroTik PPP Secrets.');

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/rest/ppp/secret/%2A9')
        );
    }

    public function test_admin_delete_keeps_the_customer_when_mikrotik_cleanup_fails(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Offline Router', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Delete Package', 'mikrotik_profile' => 'delete-profile',
            'rate_limit' => '10M/10M', 'price' => 500,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $customer = Customer::create([
            'router_id' => $router->id, 'package_id' => $package->id,
            'name' => 'Preserved Customer', 'username' => 'preserved-user',
            'password' => 'password', 'status' => 'active',
        ]);
        Http::fake(['*' => Http::response(['message' => 'failure'], 500)]);

        $this->actingAs($user)->delete(route('customers.destroy', $customer))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_suspend_disables_the_secret_and_disconnects_an_active_ppp_session(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Suspend Router', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Suspend Package', 'mikrotik_profile' => 'suspend-profile',
            'rate_limit' => '10M/10M', 'price' => 500,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $customer = Customer::create([
            'router_id' => $router->id, 'package_id' => $package->id,
            'name' => 'Online Customer', 'username' => 'online-user',
            'password' => 'password', 'status' => 'active',
            'expires_at' => today()->addMonth(),
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/profile')) {
                return Http::response([['.id' => '*1', 'name' => 'suspend-profile']]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/secret')) {
                return Http::response([['.id' => '*2', 'name' => 'online-user']]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/active')) {
                return Http::response([['.id' => '*3', 'name' => 'online-user']]);
            }

            return Http::response([]);
        });

        $this->actingAs($user)->post(route('customers.toggle', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('success', 'Suspended and synced with MikroTik.');

        $this->assertSame('suspended', $customer->fresh()->status);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/rest/ppp/secret/')
            && ($request->data()['disabled'] ?? null) === 'true'
        );
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/rest/ppp/active/%2A3')
        );
    }

    public function test_sync_changes_an_expired_active_customer_to_suspended_in_admin_and_mikrotik(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Expired Router', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Expired Package', 'mikrotik_profile' => 'expired-profile',
            'rate_limit' => '10M/10M', 'price' => 500,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $customer = Customer::create([
            'router_id' => $router->id, 'package_id' => $package->id,
            'name' => 'Expired Active Customer', 'username' => 'expired-active',
            'password' => 'password', 'status' => 'active',
            'expires_at' => today()->subDay(),
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/profile')) {
                return Http::response([['.id' => '*1', 'name' => 'expired-profile']]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/secret')) {
                return Http::response([['.id' => '*2', 'name' => 'expired-active']]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/active')) {
                return Http::response([]);
            }

            return Http::response([]);
        });

        $this->actingAs($user)->post(route('customers.sync', $customer))
            ->assertRedirect(route('customers.index'));

        $this->assertSame('suspended', $customer->fresh()->status);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/rest/ppp/secret/')
            && ($request->data()['disabled'] ?? null) === 'true'
        );

        Http::fake();
        $this->actingAs($user)->post(route('customers.toggle', $customer->fresh()))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('warning', 'This customer is expired. Record a payment/renewal or move the expiry date before activating PPPoE.');
        $this->assertSame('suspended', $customer->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_payment_renewal_activates_and_syncs_the_customer(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Renew Router', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Renew Package', 'mikrotik_profile' => 'renew-profile',
            'rate_limit' => '10M/10M', 'price' => 500,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $customer = Customer::create([
            'router_id' => $router->id, 'package_id' => $package->id,
            'name' => 'Expired Customer', 'username' => 'renew-user',
            'password' => 'password', 'status' => 'suspended',
            'expires_at' => today()->subDay(),
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/profile')) {
                return Http::response([['.id' => '*1', 'name' => 'renew-profile']]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/secret')) {
                return Http::response([['.id' => '*2', 'name' => 'renew-user']]);
            }

            return Http::response([]);
        });

        $this->actingAs($user)->post(route('payments.store'), [
            'customer_id' => $customer->id,
            'amount' => 500,
            'method' => 'cash',
            'paid_at' => now()->format('Y-m-d H:i:s'),
            'renew' => '1',
        ])->assertRedirect()
            ->assertSessionHas('success', 'Payment recorded; customer renewed and synced with MikroTik.');

        $customer->refresh();
        $this->assertSame('active', $customer->status);
        $this->assertSame(today()->addDays(30)->toDateString(), $customer->expires_at->toDateString());
        $this->assertDatabaseHas('payments', ['customer_id' => $customer->id, 'amount' => 500]);
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/rest/ppp/secret/')
            && ($request->data()['disabled'] ?? null) === 'false'
        );
    }

    public function test_admin_can_import_a_jaze_all_users_csv_with_automatic_package_mapping(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Jaze Migration Router', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $rookie = Package::create([
            'name' => 'ROOKIE', 'mikrotik_profile' => 'zostream_rookie',
            'rate_limit' => '10M/10M', 'price' => 500,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $rookie500 = Package::create([
            'name' => 'ROOKIE 500', 'mikrotik_profile' => 'ROOKIE_500',
            'rate_limit' => '20M/20M', 'price' => 500,
            'validity_days' => 30, 'is_active' => true,
        ]);

        $csv = implode("\n", [
            'Username,Password,First_name,Last_name,Phone,Status,Address_state,Installation_address_line1,Installation_address_city,Installation_address_state,Expiration_time,Group_name,Sub_plan',
            'ZNET001,password,Lal,Rin,9876543210,active,Mizoram,Lungpho,Aizawl,Mizoram,20-07-2026 00:00:00,ROOKIE,zostream_rookie',
            'ZNET002,password,Expired,User,9876543211,expired,Mizoram,Lungpho,Aizawl,Mizoram,11-07-2026 00:00:00,ROOKIE,ROOKIE_500',
            'ZNET003,password,Blocked,User,9876543212,blacklisted,Mizoram,Lungpho,Aizawl,Mizoram,12-07-2026 00:00:00,ROOKIE,zostream_rookie',
        ]);
        $file = UploadedFile::fake()->createWithContent('allUsers.csv', $csv);

        $this->actingAs($user)->post(route('customers.import.store'), [
            'router_id' => $router->id,
            'file' => $file,
            'duplicate_action' => 'skip',
        ])->assertRedirect(route('customers.import.create'))
            ->assertSessionHas('success', 'Excel import complete — 3 created, 0 updated, 0 skipped.');

        $this->assertDatabaseHas('customers', [
            'username' => 'ZNET001',
            'name' => 'Lal Rin',
            'phone' => '9876543210',
            'address' => 'Lungpho, Aizawl, Mizoram',
            'package_id' => $rookie->id,
            'status' => 'active',
            'expires_at' => '2026-07-20 00:00:00',
        ]);
        $this->assertDatabaseHas('customers', [
            'username' => 'ZNET002',
            'package_id' => $rookie500->id,
            'status' => 'suspended',
            'expires_at' => '2026-07-11 00:00:00',
        ]);
        $this->assertDatabaseHas('customers', [
            'username' => 'ZNET003',
            'package_id' => $rookie->id,
            'status' => 'suspended',
        ]);
        $this->assertSame('password', Customer::where('username', 'ZNET001')->firstOrFail()->password);
    }

    public function test_jaze_import_stops_before_writing_when_sub_plan_has_no_matching_active_package(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Jaze Migration Router', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        Package::create([
            'name' => 'ROOKIE', 'mikrotik_profile' => 'zostream_rookie',
            'rate_limit' => '10M/10M', 'price' => 500,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $file = UploadedFile::fake()->createWithContent('allUsers.csv', implode("\n", [
            'Username,Password,First_name,Status,Expiration_time,Group_name,Sub_plan',
            'ZNET999,password,Unknown Plan,active,20-07-2026 00:00:00,UNKNOWN,missing_profile',
        ]));

        $this->actingAs($user)->from(route('customers.import.create'))->post(route('customers.import.store'), [
            'router_id' => $router->id,
            'file' => $file,
            'duplicate_action' => 'skip',
        ])->assertRedirect(route('customers.import.create'))
            ->assertSessionHasErrors('file');

        $this->assertDatabaseMissing('customers', ['username' => 'ZNET999']);
    }

    public function test_admin_can_pull_existing_ppp_secrets_from_one_mikrotik(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Existing Users Router', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $matchedPackage = Package::create([
            'name' => 'Family', 'mikrotik_profile' => 'family-30m',
            'rate_limit' => '30M/30M', 'price' => 899,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $fallbackPackage = Package::create([
            'name' => 'Fallback', 'mikrotik_profile' => 'fallback',
            'rate_limit' => '10M/10M', 'price' => 499,
            'validity_days' => 15, 'is_active' => true,
        ]);

        Http::fake([
            '*/rest/ppp/secret*' => Http::response([
                ['.id' => '*1', 'name' => 'olduser1', 'password' => 'old-pass-1', 'profile' => 'family-30m', 'disabled' => 'false'],
                ['.id' => '*2', 'name' => 'olduser2', 'password' => 'old-pass-2', 'profile' => 'unknown-profile', 'disabled' => 'true'],
                ['.id' => '*3', 'name' => 'hidden-pass', 'profile' => 'family-30m', 'disabled' => 'false'],
            ]),
        ]);

        $this->actingAs($user)->post(route('customers.import-mikrotik.store'), [
            'router_id' => $router->id,
            'fallback_package_id' => $fallbackPackage->id,
            'duplicate_action' => 'skip',
            'default_expires_at' => '2027-01-31',
        ])->assertRedirect(route('customers.import-mikrotik.create'))
            ->assertSessionHas('warning', 'MikroTik import complete — 2 created, 0 updated, 1 skipped from Existing Users Router.');

        $this->assertDatabaseHas('customers', [
            'router_id' => $router->id,
            'package_id' => $matchedPackage->id,
            'username' => 'olduser1',
            'status' => 'active',
            'mikrotik_id' => '*1',
            'expires_at' => '2027-01-31 00:00:00',
        ]);
        $this->assertDatabaseHas('customers', [
            'package_id' => $fallbackPackage->id,
            'username' => 'olduser2',
            'status' => 'suspended',
        ]);
        $this->assertDatabaseMissing('customers', ['username' => 'hidden-pass']);
        $this->assertSame('old-pass-1', Customer::where('username', 'olduser1')->firstOrFail()->password);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/rest/ppp/secret')
            && ($request->data()['.proplist'] ?? null) === '.id,name,password,profile,disabled,comment'
        );
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

    public function test_customer_sync_does_not_update_an_existing_ppp_profile(): void
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
            'name' => 'Starter 10 Mbps',
            'mikrotik_profile' => 'starter-10m',
            'rate_limit' => '10M/10M',
            'price' => 499,
            'validity_days' => 30,
            'is_active' => true,
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/profile')) {
                return Http::response([['.id' => '*1', 'name' => 'starter-10m']]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/secret')) {
                return Http::response([['.id' => '*2', 'name' => 'TESTUSER1']]);
            }
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/rest/ppp/secret/')) {
                return Http::response(['.id' => '*2']);
            }

            return Http::response([], 500);
        });

        $this->actingAs($user)->post('/customers', [
            'router_id' => $router->id,
            'package_id' => $package->id,
            'name' => 'Test Customer',
            'phone' => '9876543210',
            'username' => 'TESTUSER1',
            'password' => 'customer-secret',
            'status' => 'active',
            'expires_at' => now()->addMonth()->toDateString(),
        ])->assertRedirect('/customers')
            ->assertSessionHas('success', 'Customer created and synced with MikroTik.');

        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PATCH' && str_contains($request->url(), '/rest/ppp/profile/')
        );
        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/rest/ppp/secret/')
            && ! array_key_exists('name', $request->data())
            && ($request->data()['profile'] ?? null) === 'starter-10m'
        );
    }

    public function test_the_same_pppoe_username_can_exist_on_different_routers_only(): void
    {
        $user = User::factory()->create();
        $routerOne = Router::create([
            'name' => 'POP One', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $routerTwo = Router::create([
            'name' => 'POP Two', 'host' => '10.77.0.3', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Starter', 'mikrotik_profile' => 'starter',
            'rate_limit' => '10M/10M', 'price' => 499,
            'validity_days' => 30, 'is_active' => true,
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/profile')) {
                return Http::response([['.id' => '*1', 'name' => 'starter']]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/secret')) {
                return Http::response([]);
            }

            return Http::response(['.id' => '*2']);
        });

        $payload = [
            'package_id' => $package->id,
            'name' => 'Shared Username',
            'username' => 'customer001',
            'password' => 'customer-secret',
            'status' => 'active',
            'expires_at' => now()->addMonth()->toDateString(),
        ];

        $this->actingAs($user)->post('/customers', $payload + ['router_id' => $routerOne->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/customers', $payload + ['router_id' => $routerTwo->id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('customers', 2);
        $this->actingAs($user)->post('/customers', $payload + ['router_id' => $routerOne->id])
            ->assertSessionHasErrors('username');
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_package_sync_can_target_one_router(): void
    {
        $user = User::factory()->create();
        Router::create([
            'name' => 'POP One', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $routerTwo = Router::create([
            'name' => 'POP Two', 'host' => '10.77.0.3', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Starter', 'mikrotik_profile' => 'starter',
            'rate_limit' => '10M/10M', 'price' => 499,
            'validity_days' => 30, 'is_active' => true,
        ]);

        Http::fake([
            '*' => Http::response([['.id' => '*1', 'name' => 'starter']]),
        ]);

        $this->actingAs($user)->post(route('packages.sync', $package), [
            'router_id' => $routerTwo->id,
        ])->assertSessionHas('success', 'Package synced to POP Two.');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '10.77.0.3'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '10.77.0.2'));
    }
}
