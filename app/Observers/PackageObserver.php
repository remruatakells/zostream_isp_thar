<?php

namespace App\Observers;

use App\Models\Package;
use App\Services\RadiusService;

class PackageObserver
{
    public function __construct(private readonly RadiusService $radius) {}

    public function saved(Package $package): void
    {
        if (! $package->wasChanged(['rate_limit', 'is_active'])) {
            return;
        }

        $package->customers()->with(['router', 'package'])->chunkById(100, function ($customers): void {
            foreach ($customers as $customer) {
                $this->radius->syncCustomer($customer, disconnectSuspended: false);
            }
        });
    }
}
