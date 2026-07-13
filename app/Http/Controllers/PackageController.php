<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\Router;
use App\Services\MikroTikService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class PackageController extends Controller
{
    public function index(): View
    {
        return view('packages.index', [
            'packages' => Package::withCount('customers')->latest()->get(),
            'routers' => Router::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('packages.form', ['package' => new Package]);
    }

    public function store(Request $request): RedirectResponse
    {
        Package::create($this->validated($request));

        return redirect()->route('packages.index')->with('success', 'Package created successfully.');
    }

    public function edit(Package $package): View
    {
        return view('packages.form', compact('package'));
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $package->update($this->validated($request, $package));

        return redirect()->route('packages.index')->with('success', 'Package updated successfully.');
    }

    public function destroy(Package $package): RedirectResponse
    {
        if ($package->customers()->exists()) {
            return back()->with('error', 'This package is assigned to customers.');
        }
        $package->delete();

        return back()->with('success', 'Package deleted.');
    }

    public function sync(Request $request, Package $package, MikroTikService $mikrotik): RedirectResponse
    {
        $data = $request->validate([
            'router_id' => [
                'nullable',
                Rule::exists('routers', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
        ]);
        $routers = filled($data['router_id'] ?? null)
            ? Router::whereKey($data['router_id'])->get()
            : Router::where('is_active', true)->get();
        $success = 0;
        $errors = [];
        foreach ($routers as $router) {
            try {
                $mikrotik->syncPackage($router, $package);
                $success++;
            } catch (Throwable $e) {
                report($e);
                $errors[] = "{$router->name}: {$e->getMessage()}";
            }
        }
        if ($errors) {
            return back()->with('error', "Synced {$success}; failed — ".implode(' | ', $errors));
        }

        $target = $routers->count() === 1 ? $routers->first()->name : "{$success} routers";

        return back()->with('success', "Package synced to {$target}.");
    }

    private function validated(Request $request, ?Package $package = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'mikrotik_profile' => ['required', 'string', 'max:100', Rule::unique('packages')->ignore($package)],
            'rate_limit' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'validity_days' => ['required', 'integer', 'between:1,3650'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
