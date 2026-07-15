<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
        $resource = $this->request(
            'Router connection test',
            fn () => $this->client($router)->get('/system/resource'),
        );
        $router->forceFill(['last_connected_at' => now()])->save();

        return is_array($resource) && array_is_list($resource) ? ($resource[0] ?? []) : $resource;
    }

    public function activePppUsers(Router $router): array
    {
        $sessions = $this->request(
            'PPP active sessions',
            fn () => $this->client($router)->get('/ppp/active', [
                '.proplist' => '.id,name,address,uptime,bytes,service',
            ]),
        );
        $router->forceFill(['last_connected_at' => now()])->save();

        return array_is_list($sessions) ? $sessions : [];
    }

    public function pppSecrets(Router $router): array
    {
        $secrets = $this->request(
            'PPP secrets download',
            fn () => $this->client($router)->get('/ppp/secret', [
                '.proplist' => '.id,name,password,profile,disabled,comment',
            ]),
        );
        $router->forceFill(['last_connected_at' => now()])->save();

        return array_is_list($secrets) ? $secrets : [];
    }

    public function syncPackage(Router $router, Package $package): array
    {
        $profiles = $this->request(
            'PPP profile lookup',
            fn () => $this->client($router)->get('/ppp/profile', [
                'name' => $package->mikrotik_profile,
                '.proplist' => '.id,name',
            ]),
        );
        $existing = collect($profiles)->firstWhere('name', $package->mikrotik_profile);
        $payload = array_filter([
            'name' => $package->mikrotik_profile,
            'rate-limit' => $package->rate_limit,
            'comment' => "ZoStream ISP package: {$package->name}",
        ], fn ($value) => $value !== null && $value !== '');

        if ($existing && isset($existing['.id'])) {
            $updatePayload = $payload;
            unset($updatePayload['name']);

            return $this->request(
                'PPP profile update',
                fn () => $this->client($router)
                    ->patch($this->resourcePath('/ppp/profile', $existing['.id']), $updatePayload),
            );
        }

        return $this->request(
            'PPP profile creation',
            fn () => $this->client($router)->put('/ppp/profile', $payload),
        );
    }

    public function syncCustomer(Customer $customer, bool $ensureProfile = true): array
    {
        $customer->loadMissing(['router', 'package']);

        // A PPP secret can only reference a profile that already exists on the
        // selected router. Ensure the package profile is present before the
        // customer is created or updated so first-time syncs cannot fail with
        // RouterOS' "input does not match any value of profile" response.
        if ($ensureProfile && $customer->package) {
            $this->ensurePackageProfileExists($customer->router, $customer->package);
        }

        // Fetch only the two non-sensitive fields needed for matching. Asking
        // RouterOS to serialize every PPP secret (including password fields)
        // is unnecessary and can cause an internal REST error on some builds.
        $secrets = $this->request(
            'PPP secret lookup',
            fn () => $this->client($customer->router)->get('/ppp/secret', [
                'name' => $customer->username,
                '.proplist' => '.id,name',
            ]),
        );
        $existing = collect($secrets)->firstWhere('name', $customer->username);
        $expired = $customer->expires_at && $customer->expires_at->lt(today());
        if ($expired && $customer->status === 'active') {
            $customer->forceFill(['status' => 'suspended'])->save();
        }
        $disabled = $customer->status !== 'active' || $expired;
        $payload = [
            'name' => $customer->username,
            'password' => $customer->password,
            'service' => 'pppoe',
            'profile' => $customer->package?->mikrotik_profile ?? 'default',
            'disabled' => $disabled ? 'true' : 'false',
            'comment' => "ZoStream ISP #{$customer->id} - {$customer->name}",
        ];

        if ($existing && isset($existing['.id'])) {
            $updatePayload = $payload;
            unset($updatePayload['name']);
            $result = $this->request(
                'PPP secret update',
                fn () => $this->client($customer->router)
                    ->patch($this->resourcePath('/ppp/secret', $existing['.id']), $updatePayload),
            );
            $mikrotikId = $existing['.id'];
        } else {
            $result = $this->request(
                'PPP secret creation',
                fn () => $this->client($customer->router)->put('/ppp/secret', $payload),
            );
            $mikrotikId = $result['.id'] ?? null;
        }

        $customer->forceFill(['mikrotik_id' => $mikrotikId, 'last_synced_at' => now()])->save();

        if ($disabled) {
            $this->disconnectPppUser($customer->router, $customer->username);
        }

        return $result;
    }

    public function deleteCustomer(Customer $customer): bool
    {
        $customer->loadMissing('router');
        $secrets = $this->request(
            'PPP secret lookup for deletion',
            fn () => $this->client($customer->router)->get('/ppp/secret', [
                'name' => $customer->username,
                '.proplist' => '.id,name',
            ]),
        );
        $existing = collect($secrets)->firstWhere('name', $customer->username);
        $removed = (bool) ($existing && isset($existing['.id']));
        if ($removed) {
            $this->request(
                'PPP secret deletion',
                fn () => $this->client($customer->router)
                    ->delete($this->resourcePath('/ppp/secret', $existing['.id'])),
            );
        }

        $this->disconnectPppUser($customer->router, $customer->username);

        return $removed;
    }

    public function disconnectPppUser(Router $router, string $username): int
    {
        $sessions = $this->request(
            'PPP active session lookup',
            fn () => $this->client($router)->get('/ppp/active', [
                'name' => $username,
                '.proplist' => '.id,name',
            ]),
        );
        $matching = collect($sessions)->where('name', $username)->filter(fn ($session) => isset($session['.id']));

        foreach ($matching as $session) {
            $this->request(
                'PPP active session disconnect',
                fn () => $this->client($router)
                    ->delete($this->resourcePath('/ppp/active', $session['.id'])),
            );
        }

        return $matching->count();
    }

    private function resourcePath(string $menu, mixed $id): string
    {
        $id = (string) $id;
        if (! preg_match('/^\*[0-9A-F]+$/i', $id)) {
            throw new RuntimeException("RouterOS returned an invalid resource identifier: {$id}");
        }

        // RouterOS REST expects its internal ID literally (for example *3).
        // Encoding the asterisk as %2A causes RouterOS to reject the resource.
        return rtrim($menu, '/').'/'.$id;
    }

    public function ensurePackageProfileExists(Router $router, Package $package): void
    {
        $profiles = $this->request(
            'PPP profile lookup',
            fn () => $this->client($router)->get('/ppp/profile', [
                'name' => $package->mikrotik_profile,
                '.proplist' => '.id,name',
            ]),
        );

        if (collect($profiles)->contains('name', $package->mikrotik_profile)) {
            return;
        }

        $payload = array_filter([
            'name' => $package->mikrotik_profile,
            'rate-limit' => $package->rate_limit,
            'comment' => "ZoStream ISP package: {$package->name}",
        ], fn ($value) => $value !== null && $value !== '');

        $this->request(
            'PPP profile creation',
            fn () => $this->client($router)->put('/ppp/profile', $payload),
        );
    }

    private function request(string $operation, callable $send): array
    {
        try {
            $json = $send()->throw()->json();

            return is_array($json) ? $json : [];
        } catch (RequestException $e) {
            $response = $e->response;
            $detail = $response?->json('detail') ?: $response?->json('message') ?: 'RouterOS request failed';
            $status = $response?->status() ?? 0;

            throw new RuntimeException("{$operation} failed (RouterOS HTTP {$status}): {$detail}", previous: $e);
        }
    }
}
