<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Router;
use App\Services\MikroTikService;
use App\Services\RadiusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IspController extends Controller
{
    public function dashboard(): JsonResponse
    {
        return response()->json(['data' => [
            'customers' => Customer::count(),
            'active_customers' => Customer::where('status', 'active')->count(),
            'suspended_customers' => Customer::where('status', 'suspended')->count(),
            'active_routers' => Router::where('is_active', true)->count(),
            'monthly_revenue' => Payment::whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'),
        ]]);
    }

    public function customers(): JsonResponse
    {
        return response()->json(Customer::with(['router:id,name,host', 'package:id,name,rate_limit,price', 'branch:id,name'])->latest()->paginate(50));
    }

    public function storeCustomer(Request $request, RadiusService $radius): JsonResponse
    {
        $data = $request->validate($this->customerRules($request));
        $customer = Customer::create($data);
        $radius->syncCustomer($customer);

        return response()->json(['data' => $customer->fresh(['router', 'package', 'branch'])], 201);
    }

    public function updateCustomer(Request $request, Customer $customer, RadiusService $radius): JsonResponse
    {
        $rules = $this->customerRules($request, $customer);
        $rules['password'] = ['nullable', 'string', 'max:255'];
        $data = $request->validate($rules);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $customer->update($data);
        $radius->syncCustomer($customer);

        return response()->json(['data' => $customer->fresh(['router', 'package', 'branch'])]);
    }

    public function syncCustomer(Customer $customer, RadiusService $radius): JsonResponse
    {
        $radius->syncCustomer($customer);

        return response()->json(['message' => 'Customer synced.', 'data' => $customer->fresh()]);
    }

    public function toggleCustomer(Customer $customer, RadiusService $radius): JsonResponse
    {
        $customer->update(['status' => $customer->status === 'active' ? 'suspended' : 'active']);
        $radius->syncCustomer($customer);

        return response()->json(['message' => 'Customer '.$customer->status.'.', 'data' => $customer]);
    }

    public function packages(): JsonResponse
    {
        return response()->json(['data' => Package::withCount('customers')->get()]);
    }

    public function routers(): JsonResponse
    {
        return response()->json(['data' => Router::withCount('customers')->get()]);
    }

    public function testRouter(Router $router, MikroTikService $mikrotik): JsonResponse
    {
        return response()->json(['message' => 'Connected.', 'data' => $mikrotik->test($router)]);
    }

    private function customerRules(Request $request, ?Customer $customer = null): array
    {
        return [
            'router_id' => array_values(array_filter([
                'required',
                'exists:routers,id',
                $customer ? Rule::in([$customer->router_id]) : null,
            ])),
            'package_id' => ['required', 'exists:packages,id'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'address' => ['nullable', 'string', 'max:1000'],
            'username' => [
                'required',
                'string',
                'max:64',
                Rule::unique('customers', 'username')->ignore($customer),
            ],
            'password' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
