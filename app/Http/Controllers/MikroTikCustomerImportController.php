<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Services\MikroTikService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class MikroTikCustomerImportController extends Controller
{
    private const MAX_SECRETS = 2000;

    public function create(): View
    {
        return view('customers.import-mikrotik', [
            'routers' => Router::where('is_active', true)->orderBy('name')->get(),
            'packages' => Package::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, MikroTikService $mikrotik): RedirectResponse
    {
        $data = $request->validate([
            'router_id' => [
                'required',
                Rule::exists('routers', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'fallback_package_id' => [
                'required',
                Rule::exists('packages', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'duplicate_action' => ['required', Rule::in(['skip', 'update'])],
            'default_expires_at' => ['nullable', 'date'],
        ]);

        $router = Router::findOrFail($data['router_id']);
        $fallbackPackage = Package::findOrFail($data['fallback_package_id']);

        try {
            $secrets = $mikrotik->pppSecrets($router);
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Could not read PPP Secrets from '.$router->name.': '.$e->getMessage());
        }

        $packagesByProfile = Package::all()->keyBy(
            fn (Package $package) => Str::lower($package->mikrotik_profile)
        );
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach (array_slice($secrets, 0, self::MAX_SECRETS) as $index => $secret) {
            $username = trim((string) ($secret['name'] ?? ''));
            if ($username === '') {
                $skipped++;
                $errors[] = 'Secret '.($index + 1).': missing username; skipped.';
                continue;
            }

            $password = (string) ($secret['password'] ?? '');
            $passwordVisible = $password !== '' && ! preg_match('/^\*+$/', $password);
            $profile = Str::lower(trim((string) ($secret['profile'] ?? 'default')));
            $package = $packagesByProfile->get($profile, $fallbackPackage);
            $customer = Customer::where('router_id', $router->id)
                ->where('username', $username)
                ->first();

            if ($customer && $data['duplicate_action'] === 'skip') {
                $skipped++;
                continue;
            }
            if (! $customer && ! $passwordVisible) {
                $skipped++;
                $errors[] = "{$username}: password is hidden. Add sensitive policy to the REST user, then import again.";
                continue;
            }

            $disabled = in_array(Str::lower((string) ($secret['disabled'] ?? 'false')), ['true', 'yes', '1'], true);
            $attributes = [
                'router_id' => $router->id,
                'package_id' => $package->id,
                'status' => $disabled ? 'suspended' : 'active',
                'mikrotik_id' => $secret['.id'] ?? null,
                'last_synced_at' => now(),
            ];

            if ($passwordVisible) {
                $attributes['password'] = $password;
            }

            if ($customer) {
                $customer->update($attributes);
                $updated++;
                continue;
            }

            $attributes += [
                'name' => $this->customerName($secret, $username),
                'phone' => null,
                'address' => null,
                'username' => $username,
                'expires_at' => filled($data['default_expires_at'] ?? null)
                    ? $data['default_expires_at']
                    : today()->addDays($package->validity_days)->toDateString(),
            ];
            Customer::create($attributes);
            $created++;
        }

        if (count($secrets) > self::MAX_SECRETS) {
            $errors[] = 'Only the first '.self::MAX_SECRETS.' PPP Secrets were processed in this import.';
        }

        $summary = "MikroTik import complete — {$created} created, {$updated} updated, {$skipped} skipped from {$router->name}.";

        return redirect()->route('customers.import-mikrotik.create')
            ->with($errors ? 'warning' : 'success', $summary)
            ->with('import_errors', array_slice($errors, 0, 50));
    }

    private function customerName(array $secret, string $username): string
    {
        $comment = trim((string) ($secret['comment'] ?? ''));
        if (preg_match('/^ZoStream ISP #\d+\s+-\s+(.+)$/i', $comment, $matches)) {
            return trim($matches[1]);
        }

        return $username;
    }
}
