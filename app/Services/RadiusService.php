<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Router;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RadiusService
{
    private const CHECK_ATTRIBUTES = ['Cleartext-Password', 'Auth-Type'];

    private const REPLY_ATTRIBUTES = ['Mikrotik-Rate-Limit', 'Acct-Interim-Interval'];

    public function __construct(private readonly MikroTikService $mikrotik) {}

    public function syncCustomer(Customer $customer, bool $disconnectSuspended = true): array
    {
        $customer->loadMissing(['router', 'package']);

        if (Customer::where('username', $customer->username)->where('id', '!=', $customer->id)->exists()) {
            throw new RuntimeException("RADIUS username {$customer->username} is already used by another router. RADIUS usernames must be globally unique.");
        }

        $expired = $customer->expires_at?->lt(today()) ?? false;
        if ($expired && $customer->status === 'active') {
            $customer->forceFill(['status' => 'suspended'])->saveQuietly();
        }
        $active = $customer->status === 'active' && ! $expired;

        DB::transaction(function () use ($customer, $active): void {
            DB::table('radcheck')->where('username', $customer->username)
                ->whereIn('attribute', self::CHECK_ATTRIBUTES)->delete();
            DB::table('radreply')->where('username', $customer->username)
                ->whereIn('attribute', self::REPLY_ATTRIBUTES)->delete();

            DB::table('radcheck')->insert([
                'username' => $customer->username,
                'attribute' => $active ? 'Cleartext-Password' : 'Auth-Type',
                'op' => ':=',
                'value' => $active ? $customer->password : 'Reject',
            ]);

            if ($active) {
                if (filled($customer->package?->rate_limit)) {
                    DB::table('radreply')->insert([
                        'username' => $customer->username,
                        'attribute' => 'Mikrotik-Rate-Limit',
                        'op' => ':=',
                        'value' => $customer->package->rate_limit,
                    ]);
                }
                DB::table('radreply')->insert([
                    'username' => $customer->username,
                    'attribute' => 'Acct-Interim-Interval',
                    'op' => ':=',
                    'value' => '300',
                ]);
            }

            $customer->forceFill(['last_synced_at' => now(), 'mikrotik_id' => null])->saveQuietly();
        });

        $disconnected = 0;
        if (! $active && $disconnectSuspended && $customer->router) {
            $disconnected = $this->mikrotik->disconnectPppUser($customer->router, $customer->username);
        }

        return ['active' => $active, 'disconnected' => $disconnected];
    }

    public function deleteCustomer(Customer $customer): int
    {
        return $this->deleteUsername($customer->username);
    }

    public function deleteUsername(string $username): int
    {
        if (blank($username)) {
            return 0;
        }

        return DB::transaction(function () use ($username): int {
            $deleted = DB::table('radcheck')->where('username', $username)
                ->whereIn('attribute', self::CHECK_ATTRIBUTES)->delete();
            $deleted += DB::table('radreply')->where('username', $username)
                ->whereIn('attribute', self::REPLY_ATTRIBUTES)->delete();

            return $deleted;
        });
    }

    public function disconnect(Customer $customer): int
    {
        $customer->loadMissing('router');

        return $customer->router
            ? $this->mikrotik->disconnectPppUser($customer->router, $customer->username)
            : 0;
    }

    public function syncRouter(Router $router, ?string $oldHost = null): bool
    {
        $shortname = 'zostream-router-'.$router->id;
        DB::table('nas')->where('shortname', $shortname)
            ->when($oldHost, fn ($query) => $query->where('nasname', '!=', $router->host))
            ->delete();

        if (! $router->radius_enabled || blank($router->radius_secret)) {
            DB::table('nas')->where('shortname', $shortname)->delete();

            return false;
        }

        DB::table('nas')->updateOrInsert(
            ['nasname' => $router->host],
            [
                'shortname' => $shortname,
                'type' => 'other',
                'ports' => null,
                'secret' => $router->radius_secret,
                'server' => null,
                'community' => null,
                'description' => $router->name,
            ],
        );

        return true;
    }

    public function deleteRouter(Router $router): void
    {
        DB::table('nas')->where('shortname', 'zostream-router-'.$router->id)->delete();
    }
}
