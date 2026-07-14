<?php

use App\Models\Customer;
use App\Models\Router;
use App\Services\RadiusService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('isp:suspend-expired', function () {
    $radius = app(RadiusService::class);
    $synced = 0;
    $failed = 0;

    Customer::where('status', 'active')->whereDate('expires_at', '<', today())
        ->with(['router', 'package'])->chunkById(100, function ($customers) use ($radius, &$synced, &$failed) {
            foreach ($customers as $customer) {
                $customer->update(['status' => 'suspended']);
                try {
                    $radius->syncCustomer($customer);
                    $synced++;
                } catch (Throwable $e) {
                    report($e);
                    $failed++;
                }
            }
        });

    $this->info("Expired customers suspended: {$synced}; sync failures: {$failed}");
})->purpose('Suspend expired customers locally and in RADIUS');

Artisan::command('isp:radius-sync', function () {
    $radius = app(RadiusService::class);
    $synced = 0;
    $failed = 0;

    Router::query()->each(function (Router $router) use ($radius): void {
        $radius->syncRouter($router);
    });

    Customer::with(['router', 'package'])->chunkById(100, function ($customers) use ($radius, &$synced, &$failed): void {
        foreach ($customers as $customer) {
            try {
                $radius->syncCustomer($customer, disconnectSuspended: false);
                $synced++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->error("{$customer->username}: {$e->getMessage()}");
            }
        }
    });

    $this->info("RADIUS sync complete: {$synced} synced; {$failed} failed");
})->purpose('Write all routers and customers to the FreeRADIUS SQL tables');

Schedule::command('isp:suspend-expired')->hourly()->withoutOverlapping();
