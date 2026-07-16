<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PaymentCheckout;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
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

        $this->get('/dashboard')->assertOk()
            ->assertSee('Your ISP, at a glance')
            ->assertSee('aria-controls="sidebar"', false)
            ->assertSee('nav-label', false);
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
            'radius_secret' => 'unique-radius-secret-001', 'radius_enabled' => '1',
            'use_ssl' => '1', 'verify_ssl' => '1', 'is_active' => '1',
        ])->assertRedirect('/routers');

        $this->actingAs($user)->post('/packages', [
            'name' => 'Home 20M', 'mikrotik_profile' => 'home-20m',
            'rate_limit' => '20M/20M', 'price' => 699, 'validity_days' => 30,
            'is_active' => '1',
        ])->assertRedirect('/packages');

        $this->assertDatabaseHas('routers', ['name' => 'Main Router']);
        $this->assertSame('router-secret', Router::first()->password);
        $this->assertSame('unique-radius-secret-001', Router::first()->radius_secret);
        $this->assertDatabaseHas('nas', [
            'nasname' => '192.168.88.1', 'secret' => 'unique-radius-secret-001',
        ]);
        $this->assertDatabaseHas('packages', ['mikrotik_profile' => 'home-20m']);
    }

    public function test_admin_can_create_a_branch_operator_panel_user(): void
    {
        $admin = User::factory()->create();
        $branch = Branch::create(['name' => 'Ngopa', 'is_active' => true]);

        $this->actingAs($admin)->get(route('users.create'))
            ->assertOk()->assertSee('Branch operator')->assertSee('Ngopa');

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Ngopa Operator',
            'email' => 'ngopa.operator@example.com',
            'password' => 'operator-secret',
            'password_confirmation' => 'operator-secret',
            'role' => 'branch_operator',
            'branch_id' => $branch->id,
            'is_active' => '1',
        ])->assertRedirect(route('users.index'))->assertSessionHas('success');

        $operator = User::where('email', 'ngopa.operator@example.com')->firstOrFail();
        $this->assertTrue($operator->isBranchOperator());
        $this->assertSame($branch->id, $operator->branch_id);
        $this->assertTrue(Hash::check('operator-secret', $operator->password));
        $this->actingAs($admin)->get(route('users.index'))
            ->assertOk()->assertSee('Ngopa Operator')->assertSee('Branch operator');
    }

    public function test_branch_operator_only_sees_and_manages_the_assigned_branch(): void
    {
        $ownBranch = Branch::create(['name' => 'Ngopa', 'is_active' => true]);
        $otherBranch = Branch::create(['name' => 'Saitual', 'is_active' => true]);
        $operator = User::factory()->create([
            'role' => 'branch_operator', 'branch_id' => $ownBranch->id,
        ]);
        $router = Router::create([
            'name' => 'Operator Router', 'host' => '10.77.0.18', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Operator Package', 'mikrotik_profile' => 'operator-package',
            'rate_limit' => '30M/30M', 'price' => 550,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $ownCustomer = Customer::create([
            'router_id' => $router->id, 'package_id' => $package->id, 'branch_id' => $ownBranch->id,
            'name' => 'Own Branch Customer', 'username' => 'own-branch-user',
            'password' => 'password', 'status' => 'active',
        ]);
        $otherCustomer = Customer::create([
            'router_id' => $router->id, 'package_id' => $package->id, 'branch_id' => $otherBranch->id,
            'name' => 'Other Branch Customer', 'username' => 'other-branch-user',
            'password' => 'password', 'status' => 'active',
        ]);
        Payment::create([
            'customer_id' => $ownCustomer->id, 'amount' => 550, 'method' => 'cash',
            'reference' => 'OWN-PAYMENT', 'paid_at' => now(),
        ]);
        Payment::create([
            'customer_id' => $otherCustomer->id, 'amount' => 550, 'method' => 'cash',
            'reference' => 'OTHER-PAYMENT', 'paid_at' => now(),
        ]);

        $this->actingAs($operator)->get(route('customers.index'))
            ->assertOk()->assertSee('Own Branch Customer')->assertDontSee('Other Branch Customer');
        $this->actingAs($operator)->get(route('customers.edit', $otherCustomer))->assertForbidden();
        $this->actingAs($operator)->post(route('customers.store'), [
            'router_id' => $router->id, 'package_id' => $package->id,
            'branch_id' => $otherBranch->id, 'name' => 'Escaped Customer',
            'username' => 'escaped-customer', 'password' => 'password',
            'status' => 'active',
        ])->assertSessionHasErrors('branch_id');
        $this->assertDatabaseMissing('customers', ['username' => 'escaped-customer']);
        $this->actingAs($operator)->get(route('payments.index'))
            ->assertOk()->assertSee('Own Branch Customer')->assertDontSee('Other Branch Customer');
        $this->actingAs($operator)->get(route('routers.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('users.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('customers.import.create'))->assertForbidden();
    }

    public function test_disabled_panel_user_cannot_sign_in_or_keep_using_an_existing_session(): void
    {
        $user = User::factory()->create(['password' => 'secret-password', 'is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertSessionHasErrors('email');
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_admin_can_manage_branches_and_cannot_delete_an_assigned_branch(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('branches.store'), [
            'name' => 'Ngopa',
            'operator_percentage' => 35,
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $branch = Branch::firstOrFail();
        $this->actingAs($user)->get(route('branches.index'))
            ->assertOk()->assertSee('Customer branches')->assertSee('Ngopa');

        $this->actingAs($user)->put(route('branches.update', $branch), [
            'name' => 'Ngopa Main',
            'operator_percentage' => 40,
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('branches', [
            'name' => 'Ngopa Main',
            'operator_percentage' => 40,
            'is_active' => true,
        ]);

        $router = Router::create([
            'name' => 'Branch Router', 'host' => '10.77.0.20', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Branch Package', 'mikrotik_profile' => 'branch-package',
            'rate_limit' => '30M/30M', 'price' => 550,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $this->actingAs($user)->get(route('customers.create'))
            ->assertOk()->assertSee('name="branch_id"', false)->assertSee('Ngopa Main');

        Customer::create([
            'router_id' => $router->id, 'package_id' => $package->id,
            'branch_id' => $branch->id, 'name' => 'Assigned Customer',
            'username' => 'assigned-branch-user', 'password' => 'password',
            'status' => 'active',
        ]);

        $this->actingAs($user)->delete(route('branches.destroy', $branch))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
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
            ['full name', 'mobile', 'branch', 'address', 'pppoe username', 'pppoe password', 'status', 'expiry date'],
            ['Excel Customer One', '9876543210', 'Ngopa', 'Address One', 'excel001', 'pass-one', 'active', '2026-12-31'],
            ['Excel Customer Two', '9876543211', 'Saitual', 'Address Two', 'excel002', 'pass-two', 'disabled', '31/12/2026'],
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

        $ngopaBranch = Branch::where('name', 'Ngopa')->firstOrFail();
        $this->assertDatabaseHas('customers', [
            'router_id' => $router->id,
            'package_id' => $package->id,
            'username' => 'excel001',
            'branch_id' => $ngopaBranch->id,
            'status' => 'active',
            'expires_at' => '2026-12-31 00:00:00',
        ]);
        $this->assertDatabaseHas('customers', [
            'username' => 'excel002',
            'status' => 'suspended',
        ]);
        $this->assertSame('pass-one', Customer::where('username', 'excel001')->firstOrFail()->password);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'excel001', 'attribute' => 'Cleartext-Password', 'value' => 'pass-one',
        ]);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'excel002', 'attribute' => 'Auth-Type', 'value' => 'Reject',
        ]);
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
        $this->assertNotNull($suspended->fresh()->last_synced_at);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'bulk-active', 'attribute' => 'Cleartext-Password', 'value' => 'password',
        ]);
        $this->assertDatabaseHas('radreply', [
            'username' => 'bulk-active', 'attribute' => 'Mikrotik-Rate-Limit', 'value' => '10M/10M',
        ]);
        Http::assertNothingSent();
    }

    public function test_admin_can_filter_customers_by_branch(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Branch Filter Router', 'host' => '10.77.0.3', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Branch Filter Package', 'mikrotik_profile' => 'branch-filter',
            'rate_limit' => '30M/30M', 'price' => 550,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $ngopa = Branch::create(['name' => 'Ngopa', 'is_active' => true]);
        $saitual = Branch::create(['name' => 'Saitual', 'is_active' => true]);

        foreach ([
            ['name' => 'Ngopa Customer', 'username' => 'ngopa-user', 'branch_id' => $ngopa->id],
            ['name' => 'Saitual Customer', 'username' => 'saitual-user', 'branch_id' => $saitual->id],
            ['name' => 'No Branch Customer', 'username' => 'no-branch-user', 'branch_id' => null],
        ] as $customer) {
            Customer::create($customer + [
                'router_id' => $router->id,
                'package_id' => $package->id,
                'password' => 'password',
                'status' => 'active',
                'expires_at' => today()->addMonth(),
            ]);
        }

        $this->actingAs($user)->get(route('customers.index', [
            'router_id' => $router->id,
            'branch_id' => $ngopa->id,
        ]))->assertOk()
            ->assertSee('Ngopa Customer')
            ->assertSee('Ngopa')
            ->assertDontSee('Saitual Customer')
            ->assertDontSee('No Branch Customer');
    }

    public function test_admin_can_filter_customers_by_online_offline_expired_and_suspended_status(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Status Filter Router', 'host' => '10.77.0.9', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Status Filter Package', 'mikrotik_profile' => 'status-filter',
            'rate_limit' => '30M/30M', 'price' => 550,
            'validity_days' => 30, 'is_active' => true,
        ]);
        foreach ([
            ['name' => 'Filter Online', 'username' => 'filter-online', 'status' => 'active', 'expires_at' => today()->addMonth()],
            ['name' => 'Filter Offline', 'username' => 'filter-offline', 'status' => 'active', 'expires_at' => today()->addMonth()],
            ['name' => 'Filter Expired', 'username' => 'filter-expired', 'status' => 'suspended', 'expires_at' => today()->subDay()],
            ['name' => 'Filter Suspended', 'username' => 'filter-suspended', 'status' => 'suspended', 'expires_at' => today()->addMonth()],
        ] as $attributes) {
            Customer::create($attributes + [
                'router_id' => $router->id, 'package_id' => $package->id, 'password' => 'password',
            ]);
        }
        Http::fake([
            '*/rest/ppp/active*' => Http::response([['.id' => '*9', 'name' => 'filter-online']]),
        ]);

        $this->actingAs($user)->get(route('customers.index', ['status' => 'online']))
            ->assertOk()->assertSee('Filter Online')->assertDontSee('Filter Offline');
        $this->actingAs($user)->get(route('customers.index', ['status' => 'offline']))
            ->assertOk()->assertSee('Filter Offline')->assertDontSee('Filter Online');
        $this->actingAs($user)->get(route('customers.index', ['status' => 'expired']))
            ->assertOk()->assertSee('Filter Expired')->assertDontSee('Filter Suspended');
        $this->actingAs($user)->get(route('customers.index', ['status' => 'suspended']))
            ->assertOk()->assertSee('Filter Suspended')->assertSee('Filter Expired')->assertDontSee('Filter Online');
    }

    public function test_customer_list_shows_accounting_usage_scoped_to_its_router(): void
    {
        $user = User::factory()->create();
        $package = Package::create([
            'name' => 'Usage Package', 'mikrotik_profile' => 'usage-package',
            'rate_limit' => '30M/30M', 'price' => 550,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $routerOne = Router::create([
            'name' => 'Usage Router One', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $routerTwo = Router::create([
            'name' => 'Usage Router Two', 'host' => '10.77.0.3', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        foreach ([$routerOne, $routerTwo] as $router) {
            Customer::withoutEvents(fn () => Customer::create([
                'router_id' => $router->id, 'package_id' => $package->id,
                'name' => $router->name.' Customer', 'username' => 'same-user',
                'password' => 'password', 'status' => 'active',
            ]));
        }
        DB::table('radacct')->insert([
            [
                'router_id' => $routerOne->id, 'acctsessionid' => 'usage-one',
                'acctuniqueid' => md5('usage-one'), 'username' => 'same-user',
                'nasipaddress' => $routerOne->host, 'acctinputoctets' => 1048576,
                'acctoutputoctets' => 2097152, 'acctupdatetime' => now(),
            ],
            [
                'router_id' => $routerTwo->id, 'acctsessionid' => 'usage-two',
                'acctuniqueid' => md5('usage-two'), 'username' => 'same-user',
                'nasipaddress' => $routerTwo->host, 'acctinputoctets' => 3145728,
                'acctoutputoctets' => 4194304, 'acctupdatetime' => now(),
            ],
        ]);

        $this->actingAs($user)->get(route('customers.index', ['router_id' => $routerOne->id]))
            ->assertOk()
            ->assertSee('↓ 2.00 MB')
            ->assertSee('↑ 1.00 MB')
            ->assertDontSee('↓ 4.00 MB')
            ->assertDontSee('↑ 3.00 MB');
    }

    public function test_customer_edit_returns_to_the_same_filtered_list_and_page(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Filtered Router', 'host' => '10.77.0.3', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Filtered Package', 'mikrotik_profile' => 'filtered-package',
            'rate_limit' => '30M/30M', 'price' => 550,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $ngopa = Branch::create(['name' => 'Ngopa', 'is_active' => true]);
        $saitual = Branch::create(['name' => 'Saitual', 'is_active' => true]);
        $customer = Customer::create([
            'router_id' => $router->id,
            'package_id' => $package->id,
            'name' => 'Before Edit',
            'branch_id' => $ngopa->id,
            'username' => 'filtered-edit-user',
            'password' => 'password',
            'status' => 'active',
            'expires_at' => today()->addMonth(),
        ]);
        $filteredUrl = route('customers.index', [
            'search' => 'filtered',
            'router_id' => $router->id,
            'branch_id' => $ngopa->id,
            'status' => 'active',
            'page' => 3,
        ]);

        $this->actingAs($user)->get(route('customers.edit', [
            'customer' => $customer,
            'return_to' => $filteredUrl,
        ]))->assertOk()
            ->assertSee('name="return_to"', false)
            ->assertSee(e($filteredUrl), false);

        $this->actingAs($user)->put(route('customers.update', $customer), [
            'router_id' => $router->id,
            'package_id' => $package->id,
            'name' => 'After Edit',
            'phone' => '9876543210',
            'branch_id' => $saitual->id,
            'username' => $customer->username,
            'password' => '',
            'status' => 'active',
            'expires_at' => today()->addMonth()->toDateString(),
            'return_to' => $filteredUrl,
        ])->assertRedirect($filteredUrl)
            ->assertSessionHas('success', 'Customer updated and synced with RADIUS.');

        $this->assertSame('After Edit', $customer->fresh()->name);
        $this->assertSame($saitual->id, $customer->fresh()->branch_id);
    }

    public function test_admin_delete_removes_radius_credentials_and_deletes_the_customer(): void
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
        DB::table('radcheck')->insert([
            'username' => 'delete-user', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'password',
        ]);
        DB::table('radreply')->insert([
            'username' => 'delete-user', 'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => '10M/10M',
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/active')) {
                return Http::response([]);
            }

            return Http::response([]);
        });

        $this->actingAs($user)->delete(route('customers.destroy', $customer))
            ->assertRedirect()
            ->assertSessionHas('success', 'Customer deleted from the admin panel and RADIUS.');

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseMissing('radcheck', ['username' => 'delete-user']);
        $this->assertDatabaseMissing('radreply', ['username' => 'delete-user']);
    }

    public function test_admin_delete_still_removes_radius_customer_when_router_is_offline(): void
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
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_suspend_rejects_radius_login_and_disconnects_an_active_ppp_session(): void
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
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/active')) {
                return Http::response([['.id' => '*3', 'name' => 'online-user']]);
            }

            return Http::response([]);
        });

        $this->actingAs($user)->post(route('customers.toggle', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('success', 'Suspended and synced with RADIUS.');

        $this->assertSame('suspended', $customer->fresh()->status);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'online-user', 'attribute' => 'Auth-Type', 'value' => 'Reject',
        ]);
        $this->assertDatabaseMissing('radreply', ['username' => 'online-user']);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/rest/ppp/active/*3')
        );
    }

    public function test_sync_changes_an_expired_active_customer_to_suspended_in_admin_and_radius(): void
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
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/active')) {
                return Http::response([]);
            }

            return Http::response([]);
        });

        $this->actingAs($user)->post(route('customers.sync', $customer))
            ->assertRedirect(route('customers.index'));

        $this->assertSame('suspended', $customer->fresh()->status);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'expired-active', 'attribute' => 'Auth-Type', 'value' => 'Reject',
        ]);

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
        $branch = Branch::create([
            'name' => 'Pawlrang',
            'operator_percentage' => 40,
            'is_active' => true,
        ]);
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
            'branch_id' => $branch->id,
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
            ->assertSessionHas('success', 'Payment recorded; customer renewed and synced with RADIUS.');

        $customer->refresh();
        $this->assertSame('active', $customer->status);
        $this->assertSame(today()->addDays(30)->toDateString(), $customer->expires_at->toDateString());
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'operator_id' => $user->id,
            'package_amount' => 500,
            'ott_deduction' => 50,
            'distributable_amount' => 450,
            'operator_percentage' => 40,
            'operator_commission' => 180,
            'amount' => 320,
        ]);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'renew-user', 'attribute' => 'Cleartext-Password', 'value' => 'password',
        ]);
    }

    public function test_razorpay_checkout_uses_package_amount_and_records_only_a_verified_payment(): void
    {
        config()->set('services.zostream_subscription.api_key', 'external-api-key');
        config()->set('services.zostream_subscription.environment', 'SANDBOX');
        config()->set('services.zostream_subscription.razorpay_secret', 'razorpay-secret');
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Razorpay Router', 'host' => '10.77.0.21', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Razorpay Package', 'mikrotik_profile' => 'razorpay-package',
            'rate_limit' => '30M/30M', 'price' => 499,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $customer = Customer::create([
            'router_id' => $router->id, 'package_id' => $package->id,
            'name' => 'Razorpay Customer', 'phone' => '9876543210',
            'username' => 'razorpay-user', 'password' => 'password',
            'status' => 'suspended', 'expires_at' => today()->subDay(),
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/api/v3.0/external/subscription-history')) {
                return Http::response([
                    'status' => 'success',
                    'message' => 'Histories created.',
                    'razorpay_key_id' => 'rzp_test_example',
                    'razorpay_order' => [
                        'id' => 'order_test_499',
                        'amount' => 40920,
                        'currency' => 'INR',
                        'status' => 'created',
                    ],
                    'data' => [],
                ]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/profile')) {
                return Http::response([['.id' => '*1', 'name' => 'razorpay-package']]);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/rest/ppp/active')) {
                return Http::response([]);
            }

            return Http::response([]);
        });

        $checkoutResponse = $this->actingAs($user)->postJson(route('payments.checkout'), [
            'customer_id' => $customer->id,
            'amount' => 1,
            'renew' => true,
        ])->assertOk()
            ->assertJsonPath('amount', 40920)
            ->assertJsonPath('order_id', 'order_test_499');
        $checkout = PaymentCheckout::findOrFail($checkoutResponse->json('checkout_id'));
        $this->assertSame('499.00', $checkout->package_amount);
        $this->assertSame('50.00', $checkout->ott_deduction);
        $this->assertSame('449.00', $checkout->distributable_amount);
        $this->assertSame('20.00', $checkout->operator_percentage);
        $this->assertSame('89.80', $checkout->operator_commission);
        $this->assertSame('409.20', $checkout->amount);
        $this->assertDatabaseCount('payments', 0);
        Http::assertSent(fn (Request $request) =>
            str_contains($request->url(), '/api/v3.0/external/subscription-history')
            && $request->hasHeader('X-Api-Key', 'external-api-key')
            && $request->hasHeader('X-RZ-Env', 'SANDBOX')
            && $request['phone_number'] === '9876543210'
            && (float) $request['amount'] === 409.2
        );

        $this->actingAs($user)->postJson(route('payments.razorpay.complete'), [
            'checkout_id' => $checkout->id,
            'razorpay_order_id' => 'order_test_499',
            'razorpay_payment_id' => 'pay_invalid',
            'razorpay_signature' => 'invalid-signature',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);

        $paymentId = 'pay_verified_499';
        $signature = hash_hmac('sha256', 'order_test_499|'.$paymentId, 'razorpay-secret');
        $this->actingAs($user)->postJson(route('payments.razorpay.complete'), [
            'checkout_id' => $checkout->id,
            'razorpay_order_id' => 'order_test_499',
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ])->assertOk()->assertJsonPath('message', 'Razorpay payment verified and recorded.');

        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'operator_id' => $user->id,
            'package_amount' => 499,
            'ott_deduction' => 50,
            'distributable_amount' => 449,
            'operator_percentage' => 20,
            'operator_commission' => 89.8,
            'amount' => 409.2,
            'method' => 'razorpay',
            'reference' => $paymentId,
        ]);
        $this->assertDatabaseHas('payment_checkouts', [
            'id' => $checkout->id,
            'status' => 'paid',
            'razorpay_payment_id' => $paymentId,
        ]);
        $this->assertSame('active', $customer->fresh()->status);
        $this->assertSame(today()->addDays(30)->toDateString(), $customer->fresh()->expires_at->toDateString());
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

    public function test_admin_can_import_jaze_user_session_history_for_existing_customers(): void
    {
        $user = User::factory()->create();
        $router = Router::create([
            'name' => 'Ngopa', 'host' => '10.77.0.3', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'ROOKIE', 'mikrotik_profile' => 'zostream_rookie',
            'rate_limit' => '30M/30M', 'price' => 550,
            'validity_days' => 30, 'is_active' => true,
        ]);
        Customer::create([
            'router_id' => $router->id,
            'package_id' => $package->id,
            'name' => 'Old Name',
            'username' => 'ZSNGP037',
            'password' => 'original-password',
            'status' => 'active',
            'expires_at' => today()->addMonth(),
        ]);

        $csv = implode("\n", [
            '"A/C No","Franchise Name",Branch,"Account Type",Username,Name,Mobile,"Start Time","Online Time",Download,Upload,Total,"Running Package",IpAddress,MAC,"NAS IP","Server Name","Nas Port Id",SessionId,Protocal',
            '384854,Lalrinzuala,Ngopa,Regular,ZSNGP037,"Tetei Chhimveng",8119940494,"14/07/2026 09:57","1 d 4 h 29 m 31 s","22.12GB ","1.07GB ","23.19GB ",WIS_ROOKIE_30M,20.10.11.212,28:C8:7C:C7:EE:42,103.168.75.46,service1,ether1,27b394e2bb5738f1,PPPOE',
            '384855,Lalrinzuala,Ngopa,Regular,UNKNOWN001,"Unknown User",9000000000,"15/07/2026 14:17","10 m",0.00KB,0.00KB,0.00KB,WIS_ROOKIE_30M,20.10.11.213,28:C8:7C:C7:EE:43,103.168.75.46,service1,ether2,unknown-session,PPPOE',
        ]);
        $file = UploadedFile::fake()->createWithContent('user_session_history.csv', $csv);

        $this->actingAs($user)->post(route('customers.import.store'), [
            'router_id' => $router->id,
            'file' => $file,
            'duplicate_action' => 'update',
        ])->assertRedirect(route('customers.import.create'))
            ->assertSessionHas('success', 'Session history import complete — 2 sessions imported, 1 customers created, 1 updated, 0 skipped.');

        $customer = Customer::where('username', 'ZSNGP037')->firstOrFail();
        $this->assertSame('Tetei Chhimveng', $customer->name);
        $this->assertSame('8119940494', $customer->phone);
        $this->assertSame('Ngopa', $customer->branch?->name);
        $this->assertSame('original-password', $customer->password);
        $this->assertDatabaseHas('radacct', [
            'username' => 'ZSNGP037',
            'router_id' => $router->id,
            'nasipaddress' => '103.168.75.46',
            'nasportid' => 'ether1',
            'calledstationid' => 'service1',
            'callingstationid' => '28:C8:7C:C7:EE:42',
            'framedipaddress' => '20.10.11.212',
            'acctsessiontime' => 102571,
            'class' => 'jaze-session-import',
        ]);
        $createdCustomer = Customer::where('username', 'UNKNOWN001')->firstOrFail();
        $this->assertSame('password', $createdCustomer->password);
        $this->assertSame($package->id, $createdCustomer->package_id);
        $this->assertSame('Ngopa', $createdCustomer->branch?->name);
        $this->assertSame('active', $createdCustomer->status);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'UNKNOWN001',
            'attribute' => 'Cleartext-Password',
            'value' => 'password',
        ]);
        $this->assertDatabaseHas('radacct', [
            'username' => 'UNKNOWN001',
            'nasportid' => 'ether2',
        ]);
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
            'mikrotik_id' => null,
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

    public function test_customer_and_package_changes_automatically_refresh_radius_without_manual_sync(): void
    {
        $router = Router::create([
            'name' => 'Automatic Router', 'host' => '10.77.0.2', 'port' => 80,
            'username' => 'api', 'password' => 'secret',
            'use_ssl' => false, 'verify_ssl' => false, 'is_active' => true,
        ]);
        $package = Package::create([
            'name' => 'Automatic Package', 'mikrotik_profile' => 'automatic',
            'rate_limit' => '10M/10M', 'price' => 500,
            'validity_days' => 30, 'is_active' => true,
        ]);
        $customer = Customer::create([
            'router_id' => $router->id, 'package_id' => $package->id,
            'name' => 'Automatic Customer', 'username' => 'automatic-user',
            'password' => 'automatic-password', 'status' => 'active',
            'expires_at' => today()->addMonth(),
        ]);

        $this->assertDatabaseHas('radcheck', [
            'username' => 'automatic-user', 'attribute' => 'Cleartext-Password',
            'value' => 'automatic-password',
        ]);
        $this->assertDatabaseHas('radreply', [
            'username' => 'automatic-user', 'attribute' => 'Mikrotik-Rate-Limit',
            'value' => '10M/10M',
        ]);

        $package->update(['rate_limit' => '30M/30M']);
        $this->assertDatabaseHas('radreply', [
            'username' => 'automatic-user', 'attribute' => 'Mikrotik-Rate-Limit',
            'value' => '30M/30M',
        ]);

        $customer->update(['status' => 'suspended']);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'automatic-user', 'attribute' => 'Auth-Type', 'value' => 'Reject',
        ]);
        $this->assertDatabaseMissing('radreply', ['username' => 'automatic-user']);

        $customer->delete();
        $this->assertDatabaseMissing('radcheck', ['username' => 'automatic-user']);
    }

    public function test_customer_sync_writes_radius_password_and_package_speed(): void
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

        Http::fake();

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
            ->assertSessionHas('success', 'Customer created and synced with RADIUS.');

        $customer = Customer::firstOrFail();
        $this->assertNull($customer->mikrotik_id);
        $this->assertNotNull($customer->last_synced_at);
        $this->assertDatabaseHas('radcheck', [
            'username' => 'TEST1USER', 'attribute' => 'Cleartext-Password',
            'op' => ':=', 'value' => 'customer-secret',
        ]);
        $this->assertDatabaseHas('radreply', [
            'username' => 'TEST1USER', 'attribute' => 'Mikrotik-Rate-Limit',
            'op' => ':=', 'value' => '30M/30M',
        ]);
        Http::assertNothingSent();
    }

    public function test_customer_resync_replaces_radius_credentials_without_duplicate_rows(): void
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

        Http::fake();

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
            ->assertSessionHas('success', 'Customer created and synced with RADIUS.');

        $customer = Customer::firstOrFail();
        $customer->update(['password' => 'changed-secret']);
        $this->actingAs($user)->post(route('customers.sync', $customer))
            ->assertSessionHas('success');

        $this->assertSame(1, DB::table('radcheck')->where('username', 'TESTUSER1')->count());
        $this->assertSame(2, DB::table('radreply')->where('username', 'TESTUSER1')->count());
        $this->assertDatabaseHas('radcheck', ['username' => 'TESTUSER1', 'value' => 'changed-secret']);
        Http::assertNothingSent();
    }

    public function test_radius_username_must_be_unique_across_all_routers(): void
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

        Http::fake();

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
            ->assertSessionHasErrors('username');

        $this->assertDatabaseCount('customers', 1);
        $this->actingAs($user)->post('/customers', $payload + ['router_id' => $routerOne->id])
            ->assertSessionHasErrors('username');
        $this->assertDatabaseCount('customers', 1);
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
