<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\MikroTikService;
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

    public function store(Request $request): RedirectResponse
    {
        Router::create($this->validated($request));

        return redirect()->route('routers.index')->with('success', 'Router added successfully.');
    }

    public function edit(Router $router): View
    {
        return view('routers.form', compact('router'));
    }

    public function update(Request $request, Router $router): RedirectResponse
    {
        $data = $this->validated($request, true);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $router->update($data);

        return redirect()->route('routers.index')->with('success', 'Router updated successfully.');
    }

    public function destroy(Router $router): RedirectResponse
    {
        if ($router->customers()->exists()) {
            return back()->with('error', 'This router still has customers.');
        }
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
            'use_ssl' => ['nullable', 'boolean'],
            'verify_ssl' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]) + [
            'use_ssl' => $request->boolean('use_ssl'),
            'verify_ssl' => $request->boolean('verify_ssl'),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
