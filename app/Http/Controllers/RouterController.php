<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\MikroTikService;
use App\Services\RadiusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class RouterController extends Controller
{
    public function index(): View
    {
        return view('routers.index', ['routers' => Router::withCount('customers')->latest()->get()]);
    }

    public function create(): View
    {
        return view('routers.form', ['router' => new Router]);
    }

    public function store(Request $request, RadiusService $radius): RedirectResponse
    {
        $router = Router::create($this->validated($request));
        $radiusRegistered = $radius->syncRouter($router);

        $message = $radiusRegistered
            ? 'Router added and registered as a RADIUS NAS client.'
            : 'Router added. Add a shared secret and enable RADIUS before customer cutover.';

        return redirect()->route('routers.index')->with('success', $message);
    }

    public function edit(Router $router): View
    {
        return view('routers.form', compact('router'));
    }

    public function update(Request $request, Router $router, RadiusService $radius): RedirectResponse
    {
        $data = $this->validated($request, true);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        if (blank($data['radius_secret'] ?? null)) {
            unset($data['radius_secret']);
        }
        if (($data['radius_enabled'] ?? false) && blank($data['radius_secret'] ?? $router->radius_secret)) {
            return back()->withInput()->withErrors(['radius_secret' => 'A RADIUS shared secret is required when RADIUS is enabled.']);
        }
        $oldHost = $router->host;
        $router->update($data);
        $radius->syncRouter($router, $oldHost);

        return redirect()->route('routers.index')->with('success', 'Router and RADIUS NAS settings updated successfully.');
    }

    public function destroy(Router $router, RadiusService $radius): RedirectResponse
    {
        if ($router->customers()->exists()) {
            return back()->with('error', 'This router still has customers.');
        }
        $radius->deleteRouter($router);
        $router->delete();

        return back()->with('success', 'Router deleted.');
    }

    public function test(Router $router, MikroTikService $mikrotik): RedirectResponse
    {
        try {
            $resource = $mikrotik->test($router);

            return back()->with('success', 'Connected to '.($resource['board-name'] ?? $router->name).' (RouterOS '.($resource['version'] ?? 'unknown').').');
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Connection failed: '.$e->getMessage());
        }
    }

    private function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:100'],
            'password' => [$updating ? 'nullable' : 'required', 'string', 'max:255'],
            'radius_secret' => [$updating ? 'nullable' : 'required_if:radius_enabled,1', 'nullable', 'string', 'min:16', 'max:60'],
            'radius_enabled' => ['nullable', 'boolean'],
            'use_ssl' => ['nullable', 'boolean'],
            'verify_ssl' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]) + [
            'use_ssl' => $request->boolean('use_ssl'),
            'radius_enabled' => $request->boolean('radius_enabled'),
            'verify_ssl' => $request->boolean('verify_ssl'),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
