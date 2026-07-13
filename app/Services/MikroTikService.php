<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class MikroTikService
{
    private function client(Router $router): PendingRequest
    {
        $scheme = $router->use_ssl ? 'https' : 'http';

        return Http::withBasicAuth($router->username, $router->password)
            ->acceptJson()
            ->asJson()
            ->timeout(12)
            ->connectTimeout(5)
            ->withOptions(['verify' => $router->verify_ssl])
            ->baseUrl("{$scheme}://{$router->host}:{$router->port}/rest");
    }

    public function test(Router $router): array
    {
        $resource = $this->client($router)->get('/system/resource')->throw()->json();
        $router->forceFill(['last_connected_at' => now()])->save();

        return is_array($resource) && array_is_list($resource) ? ($resource[0] ?? []) : $resource;
    }

    public function syncPackage(Router $router, Package $package): array
    {
        $profiles = $this->client($router)->get('/ppp/profile')->throw()->json();
        $existing = collect($profiles)->firstWhere('name', $package->mikrotik_profile);
        $payload = array_filter([
            'name' => $package->mikrotik_profile,
            'rate-limit' => $package->rate_limit,
            'comment' => "ZoStream ISP package: {$package->name}",
        ], fn ($value) => $value !== null && $value !== '');

        if ($existing && isset($existing['.id'])) {
            return $this->client($router)
                ->patch('/ppp/profile/'.rawurlencode($existing['.id']), $payload)
                ->throw()->json() ?? [];
        }

        return $this->client($router)->put('/ppp/profile', $payload)->throw()->json() ?? [];
    }

    public function syncCustomer(Customer $customer): array
    {
        $customer->loadMissing(['router', 'package']);

        // A PPP secret can only reference a profile that already exists on the
        // selected router. Ensure the package profile is present before the
        // customer is created or updated so first-time syncs cannot fail with
        // RouterOS' "input does not match any value of profile" response.
        if ($customer->package) {
            $this->syncPackage($customer->router, $customer->package);
        }

        $secrets = $this->client($customer->router)->get('/ppp/secret')->throw()->json();
        $existing = collect($secrets)->firstWhere('name', $customer->username);
        $disabled = $customer->status !== 'active' || ($customer->expires_at && $customer->expires_at->isPast());
        $payload = [
            'name' => $customer->username,
            'password' => $customer->password,
            'service' => 'pppoe',
            'profile' => $customer->package?->mikrotik_profile ?? 'default',
            'disabled' => $disabled ? 'true' : 'false',
            'comment' => "ZoStream ISP #{$customer->id} - {$customer->name}",
        ];

        if ($existing && isset($existing['.id'])) {
            $result = $this->client($customer->router)
                ->patch('/ppp/secret/'.rawurlencode($existing['.id']), $payload)
                ->throw()->json() ?? [];
            $mikrotikId = $existing['.id'];
        } else {
            $result = $this->client($customer->router)->put('/ppp/secret', $payload)->throw()->json() ?? [];
            $mikrotikId = $result['.id'] ?? null;
        }

        $customer->forceFill(['mikrotik_id' => $mikrotikId, 'last_synced_at' => now()])->save();

        return $result;
    }
}
