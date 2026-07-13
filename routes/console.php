<?php

use App\Models\Customer;
use App\Services\MikroTikService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('isp:suspend-expired', function () {
    $mikrotik = app(MikroTikService::class);
    $synced = 0;
    $failed = 0;

    Customer::where('status', 'active')->whereDate('expires_at', '<', today())
        ->with(['router', 'package'])->chunkById(100, function ($customers) use ($mikrotik, &$synced, &$failed) {
            foreach ($customers as $customer) {
                $customer->update(['status' => 'suspended']);
                try {
                    $mikrotik->syncCustomer($customer);
                    $synced++;
                } catch (Throwable $e) {
                    report($e);
                    $failed++;
                }
            }
        });

    $this->info("Expired customers suspended: {$synced}; sync failures: {$failed}");
})->purpose('Suspend expired customers locally and on MikroTik');

Schedule::command('isp:suspend-expired')->hourly()->withoutOverlapping();
