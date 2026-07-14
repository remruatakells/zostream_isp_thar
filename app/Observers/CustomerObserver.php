<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\RadiusService;

class CustomerObserver
{
    private const RADIUS_FIELDS = [
        'router_id',
        'package_id',
        'username',
        'password',
        'status',
        'expires_at',
    ];

    public function __construct(private readonly RadiusService $radius) {}

    public function saved(Customer $customer): void
    {
        if (! $customer->wasRecentlyCreated && ! $customer->wasChanged(self::RADIUS_FIELDS)) {
            return;
        }

        if ($customer->wasChanged('username')) {
            $this->radius->deleteUsername((string) $customer->getOriginal('username'));
        }

        // Database synchronization must never depend on an administrator
        // remembering to click Sync. REST disconnection is left to the
        // explicit suspend action so imports and background writes stay fast.
        $this->radius->syncCustomer($customer, disconnectSuspended: false);
    }

    public function deleted(Customer $customer): void
    {
        $this->radius->deleteCustomer($customer);
    }
}
