<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Router;
use App\Services\MikroTikService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MikroTikService $mikrotik): View
    {
        $customers = Customer::with(['package', 'router'])->get();
        $routers = Router::where('is_active', true)->withCount('customers')->orderBy('name')->get();
        $expired = $customers->filter(fn (Customer $customer) =>
            $customer->expires_at?->lt(today()) ?? false
        );
        $validActive = $customers->filter(fn (Customer $customer) =>
            $customer->status === 'active' && ! ($customer->expires_at?->lt(today()) ?? false)
        );
        $onlineIds = collect();
        $offlineIds = collect();
        $unknownIds = $validActive
            ->whereNotIn('router_id', $routers->pluck('id'))
            ->pluck('id');
        $routerHealth = collect();

        foreach ($routers as $router) {
            $cacheKey = "dashboard.router.{$router->id}.ppp-active";
            if ($request->boolean('refresh')) {
                Cache::forget($cacheKey);
            }

            $routerCustomers = $validActive->where('router_id', $router->id);
            try {
                $sessions = Cache::remember(
                    $cacheKey,
                    now()->addSeconds(20),
                    fn () => $mikrotik->activePppUsers($router),
                );
                $activeNames = collect($sessions)
                    ->pluck('name')
                    ->filter()
                    ->map(fn ($name) => mb_strtolower((string) $name));
                $routerOnlineIds = $routerCustomers
                    ->filter(fn (Customer $customer) => $activeNames->contains(mb_strtolower($customer->username)))
                    ->pluck('id');

                $onlineIds = $onlineIds->merge($routerOnlineIds);
                $offlineIds = $offlineIds->merge($routerCustomers->pluck('id')->diff($routerOnlineIds));
                $routerHealth->push([
                    'router' => $router,
                    'reachable' => true,
                    'sessions' => count($sessions),
                    'panel_online' => $routerOnlineIds->count(),
                    'eligible' => $routerCustomers->count(),
                    'error' => null,
                ]);
            } catch (Throwable $e) {
                report($e);
                $unknownIds = $unknownIds->merge($routerCustomers->pluck('id'));
                $routerHealth->push([
                    'router' => $router,
                    'reachable' => false,
                    'sessions' => null,
                    'panel_online' => null,
                    'eligible' => $routerCustomers->count(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $onlineIds = $onlineIds->unique();
        $offlineIds = $offlineIds->unique();
        $unknownIds = $unknownIds->unique();

        return view('dashboard', [
            'stats' => [
                'customers' => $customers->count(),
                'active' => $validActive->count(),
                'online' => $onlineIds->count(),
                'offline' => $offlineIds->count(),
                'unknown' => $unknownIds->count(),
                'expired' => $expired->count(),
                'suspended' => $customers->where('status', 'suspended')->count(),
                'routers' => $routers->count(),
                'reachable_routers' => $routerHealth->where('reachable', true)->count(),
                'revenue' => Payment::whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'),
            ],
            'expiredCustomers' => $expired->sortBy('expires_at')->take(8)->values(),
            'offlineCustomers' => $customers->whereIn('id', $offlineIds)->sortBy('name')->take(8)->values(),
            'expiring' => $customers
                ->filter(fn (Customer $customer) => $customer->expires_at?->between(today(), today()->addDays(7)) ?? false)
                ->sortBy('expires_at')->take(8)->values(),
            'routerHealth' => $routerHealth,
            'payments' => Payment::with('customer')->latest('paid_at')->limit(6)->get(),
        ]);
    }
}
