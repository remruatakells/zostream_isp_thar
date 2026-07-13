<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Services\MikroTikService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $customers = Customer::with(['router', 'package'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('username', 'like', '%'.$request->string('search').'%')
                ->orWhere('phone', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest()->paginate(15)->withQueryString();

        return view('customers.index', compact('customers'));
    }

    public function create(): View
    {
        return view('customers.form', ['customer' => new Customer, 'routers' => Router::where('is_active', true)->get(), 'packages' => Package::where('is_active', true)->get()]);
    }

    public function store(Request $request, MikroTikService $mikrotik): RedirectResponse
    {
        $customer = Customer::create($this->validated($request));

        return $this->syncAndRedirect($customer, $mikrotik, 'Customer created');
    }

    public function edit(Customer $customer): View
    {
        return view('customers.form', ['customer' => $customer, 'routers' => Router::where('is_active', true)->get(), 'packages' => Package::where('is_active', true)->get()]);
    }

    public function update(Request $request, Customer $customer, MikroTikService $mikrotik): RedirectResponse
    {
        $data = $this->validated($request, $customer);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $customer->update($data);

        return $this->syncAndRedirect($customer, $mikrotik, 'Customer updated');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();

        return back()->with('success', 'Customer removed from the panel. MikroTik secret was not deleted.');
    }

    public function sync(Customer $customer, MikroTikService $mikrotik): RedirectResponse
    {
        return $this->syncAndRedirect($customer, $mikrotik, 'Customer');
    }

    public function toggle(Customer $customer, MikroTikService $mikrotik): RedirectResponse
    {
        $customer->update(['status' => $customer->status === 'active' ? 'suspended' : 'active']);

        return $this->syncAndRedirect($customer, $mikrotik, ucfirst($customer->status));
    }

    private function syncAndRedirect(Customer $customer, MikroTikService $mikrotik, string $message): RedirectResponse
    {
        try {
            $mikrotik->syncCustomer($customer);

            return redirect()->route('customers.index')->with('success', $message.' and synced with MikroTik.');
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('customers.index')->with('warning', $message.' locally, but MikroTik sync failed: '.$e->getMessage());
        }
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        return $request->validate([
            'router_id' => ['required', 'exists:routers,id'],
            'package_id' => ['required', 'exists:packages,id'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'username' => ['required', 'string', 'max:100', Rule::unique('customers')->ignore($customer)],
            'password' => [$customer ? 'nullable' : 'required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'expires_at' => ['nullable', 'date'],
        ]);
    }
}
