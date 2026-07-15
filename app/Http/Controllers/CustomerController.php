<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Services\RadiusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $customers = $this->filteredQuery($request)
            ->addSelect([
                'connection_port' => DB::table('radacct')
                    ->select('nasportid')
                    ->whereColumn('radacct.username', 'customers.username')
                    ->whereNull('radacct.acctstoptime')
                    ->orderByDesc('radacct.radacctid')
                    ->limit(1),
                'connection_service' => DB::table('radacct')
                    ->select('calledstationid')
                    ->whereColumn('radacct.username', 'customers.username')
                    ->whereNull('radacct.acctstoptime')
                    ->orderByDesc('radacct.radacctid')
                    ->limit(1),
            ])
            ->with(['router', 'package'])
            ->orderBy('username')->paginate(15)->withQueryString();

        $connectionPorts = DB::table('radacct')
            ->join('customers', 'customers.username', '=', 'radacct.username')
            ->whereNull('radacct.acctstoptime')
            ->whereNotNull('radacct.nasportid')
            ->where('radacct.nasportid', '!=', '')
            ->when($request->filled('router_id'), fn ($query) => $query
                ->where('customers.router_id', $request->integer('router_id')))
            ->distinct()
            ->orderBy('radacct.nasportid')
            ->pluck('radacct.nasportid');

        return view('customers.index', [
            'customers' => $customers,
            'routers' => Router::orderBy('name')->get(),
            'connectionPorts' => $connectionPorts,
        ]);
    }

    public function create(): View
    {
        return view('customers.form', ['customer' => new Customer, 'routers' => Router::where('is_active', true)->get(), 'packages' => Package::where('is_active', true)->get()]);
    }

    public function store(Request $request, RadiusService $radius): RedirectResponse
    {
        $customer = Customer::create($this->validated($request));

        return $this->syncAndRedirect($customer, $radius, 'Customer created');
    }

    public function edit(Request $request, Customer $customer): View
    {
        return view('customers.form', [
            'customer' => $customer,
            'routers' => Router::where('is_active', true)->get(),
            'packages' => Package::where('is_active', true)->get(),
            'returnTo' => $this->customerIndexReturnUrl($request),
        ]);
    }

    public function update(Request $request, Customer $customer, RadiusService $radius): RedirectResponse
    {
        $data = $this->validated($request, $customer);
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $customer->update($data);

        return $this->syncAndRedirect(
            $customer,
            $radius,
            'Customer updated',
            $this->customerIndexReturnUrl($request),
        );
    }

    public function destroy(Customer $customer, RadiusService $radius): RedirectResponse
    {
        try {
            $removed = $radius->deleteCustomer($customer);
            try {
                $radius->disconnect($customer);
            } catch (Throwable $e) {
                report($e);
            }
            $customer->delete();

            $message = $removed
                ? 'Customer deleted from the admin panel and RADIUS.'
                : 'Customer deleted from the admin panel; no matching RADIUS credentials existed.';

            return back()->with('success', $message);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Customer was not deleted because RADIUS cleanup failed: '.$e->getMessage());
        }
    }

    public function sync(Customer $customer, RadiusService $radius): RedirectResponse
    {
        return $this->syncAndRedirect($customer, $radius, 'Customer');
    }

    public function syncAll(Request $request, RadiusService $radius): RedirectResponse|JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'router_id' => ['nullable', 'exists:routers,id'],
            'connection_port' => ['nullable', 'string', 'max:32'],
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
        foreach ($customers as $customer) {
            try {
                $radius->syncCustomer($customer);
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

    public function toggle(Customer $customer, RadiusService $radius): RedirectResponse
    {
        if ($customer->status === 'suspended' && $customer->expires_at?->lt(today())) {
            return redirect()->route('customers.index')->with(
                'warning',
                'This customer is expired. Record a payment/renewal or move the expiry date before activating PPPoE.'
            );
        }

        $customer->update(['status' => $customer->status === 'active' ? 'suspended' : 'active']);

        return $this->syncAndRedirect($customer, $radius, ucfirst($customer->status));
    }

    private function syncAndRedirect(
        Customer $customer,
        RadiusService $radius,
        string $message,
        ?string $redirectTo = null,
    ): RedirectResponse
    {
        $redirectTo ??= route('customers.index');

        try {
            $radius->syncCustomer($customer);

            return redirect()->to($redirectTo)->with('success', $message.' and synced with RADIUS.');
        } catch (Throwable $e) {
            report($e);

            return redirect()->to($redirectTo)->with('warning', $message.' locally, but RADIUS sync failed: '.$e->getMessage());
        }
    }

    private function customerIndexReturnUrl(Request $request): string
    {
        $fallback = route('customers.index');
        $candidate = trim((string) $request->input('return_to', ''));
        if ($candidate === '') {
            return $fallback;
        }

        $parts = parse_url($candidate);
        $customerIndexPath = parse_url($fallback, PHP_URL_PATH);
        if ($parts === false || ($parts['path'] ?? '') !== $customerIndexPath) {
            return $fallback;
        }
        if (isset($parts['host']) && ! hash_equals(Str::lower($request->getHost()), Str::lower($parts['host']))) {
            return $fallback;
        }

        return $candidate;
    }

    private function filteredQuery(Request $request): Builder
    {
        return Customer::query()
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('username', 'like', '%'.$request->string('search').'%')
                ->orWhere('phone', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('router_id'), fn ($q) => $q->where('router_id', $request->integer('router_id')))
            ->when($request->filled('connection_port'), fn ($q) => $q->whereExists(fn ($accounting) => $accounting
                ->selectRaw('1')
                ->from('radacct')
                ->whereColumn('radacct.username', 'customers.username')
                ->whereNull('radacct.acctstoptime')
                ->where('radacct.nasportid', (string) $request->input('connection_port'))));
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
                'max:64',
                Rule::unique('customers', 'username')->ignore($customer),
            ],
            'password' => [$customer ? 'nullable' : 'required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'expires_at' => ['nullable', 'date'],
        ]);
    }
}
