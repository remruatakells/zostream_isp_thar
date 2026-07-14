<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Services\MikroTikService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $customers = $this->filteredQuery($request)
            ->with(['router', 'package'])
            ->latest()->paginate(15)->withQueryString();

        return view('customers.index', [
            'customers' => $customers,
            'routers' => Router::orderBy('name')->get(),
        ]);
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

    public function syncAll(Request $request, MikroTikService $mikrotik): RedirectResponse|JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'router_id' => ['nullable', 'exists:routers,id'],
            'after_id' => ['nullable', 'integer', 'min:0'],
            'synced_total' => ['nullable', 'integer', 'min:0'],
            'failed_total' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! $request->expectsJson()) {
            return back()->with('warning', 'Bulk sync uses short background batches to prevent a gateway timeout. Refresh this page and click Sync all again.');
        }

        $query = $this->filteredQuery($request);
        $total = (clone $query)->count();
        $afterId = $request->integer('after_id');
        $customers = (clone $query)->with(['router', 'package'])
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(8)
            ->get();
        $synced = 0;
        $failed = 0;
        $errors = [];
        $preparedProfiles = [];

        foreach ($customers as $customer) {
            try {
                if ($customer->package) {
                    $profileKey = $customer->router_id.':'.$customer->package_id;
                    if (! array_key_exists($profileKey, $preparedProfiles)) {
                        try {
                            $mikrotik->ensurePackageProfileExists($customer->router, $customer->package);
                            $preparedProfiles[$profileKey] = true;
                        } catch (Throwable $e) {
                            $preparedProfiles[$profileKey] = $e->getMessage();
                        }
                    }
                    if ($preparedProfiles[$profileKey] !== true) {
                        throw new \RuntimeException('Package profile sync failed: '.$preparedProfiles[$profileKey]);
                    }
                }

                $mikrotik->syncCustomer($customer, ensureProfile: false);
                $synced++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
                if (count($errors) < 5) {
                    $errors[] = "{$customer->username}: {$e->getMessage()}";
                }
            }
        }

        $lastId = $customers->last()?->id ?? $afterId;
        $hasMore = (clone $query)->where('id', '>', $lastId)->exists();
        $syncedTotal = $request->integer('synced_total') + $synced;
        $failedTotal = $request->integer('failed_total') + $failed;
        $processed = (clone $query)->where('id', '<=', $lastId)->count();

        if (! $hasMore) {
            $type = $failedTotal > 0 ? 'warning' : 'success';
            session()->flash($type, "Bulk sync complete — {$syncedTotal} synced, {$failedTotal} failed.");
        }

        return response()->json([
            'total' => $total,
            'processed' => $processed,
            'batch_synced' => $synced,
            'batch_failed' => $failed,
            'synced_total' => $syncedTotal,
            'failed_total' => $failedTotal,
            'next_after_id' => $lastId,
            'has_more' => $hasMore,
            'errors' => $errors,
        ]);
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

    private function filteredQuery(Request $request): Builder
    {
        return Customer::query()
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('username', 'like', '%'.$request->string('search').'%')
                ->orWhere('phone', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('router_id'), fn ($q) => $q->where('router_id', $request->integer('router_id')));
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        return $request->validate([
            'router_id' => array_values(array_filter([
                'required',
                'exists:routers,id',
                $customer ? Rule::in([$customer->router_id]) : null,
            ])),
            'package_id' => ['required', 'exists:packages,id'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'username' => [
                'required',
                'string',
                'max:100',
                Rule::unique('customers')->where(
                    fn ($query) => $query->where('router_id', $request->integer('router_id'))
                )->ignore($customer),
            ],
            'password' => [$customer ? 'nullable' : 'required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'expires_at' => ['nullable', 'date'],
        ]);
    }
}
