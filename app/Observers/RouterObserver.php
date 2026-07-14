<?php

namespace App\Observers;

use App\Models\Router;
use App\Services\RadiusService;

class RouterObserver
{
    public function __construct(private readonly RadiusService $radius) {}

    public function saved(Router $router): void
    {
        if (! $router->wasRecentlyCreated && ! $router->wasChanged(['host', 'name', 'radius_secret', 'radius_enabled'])) {
            return;
        }

        $oldHost = $router->wasChanged('host') ? (string) $router->getOriginal('host') : null;
        $this->radius->syncRouter($router, $oldHost);
    }

    public function deleted(Router $router): void
    {
        $this->radius->deleteRouter($router);
    }
}
