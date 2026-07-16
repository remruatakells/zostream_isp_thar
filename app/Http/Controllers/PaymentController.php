<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Payment;
use App\Services\RadiusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $branchId = $request->user()->isBranchOperator() ? $request->user()->branch_id : null;

        return view('payments.index', [
            'payments' => Payment::with('customer')
                ->when($branchId, fn ($query) => $query->whereHas('customer', fn ($query) => $query->where('branch_id', $branchId)))
                ->latest('paid_at')->paginate(20),
            'customers' => Customer::when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->orderBy('name')->get(['id', 'name', 'username']),
            'selectedCustomer' => $request->integer('customer'),
        ]);
    }

    public function store(Request $request, RadiusService $radius): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'renew' => ['nullable', 'boolean'],
        ]);
        unset($data['renew']);
        $customer = Customer::findOrFail($data['customer_id']);
        $this->ensureCustomerAccess($request, $customer);
        $payment = Payment::create($data);
        $syncError = null;

        if ($request->boolean('renew')) {
            $customer = $payment->customer()->with(['package', 'router'])->first();
            $base = $customer->expires_at && $customer->expires_at->isFuture() ? $customer->expires_at : today();
            $customer->update(['expires_at' => $base->copy()->addDays($customer->package?->validity_days ?? 30), 'status' => 'active']);
            try {
                $radius->syncCustomer($customer);
            } catch (Throwable $e) {
                report($e);
                $syncError = $e->getMessage();
            }
        }

        if ($syncError) {
            return back()->with('warning', 'Payment was recorded and the customer renewed locally, but RADIUS sync failed: '.$syncError.' Use the customer Sync action to retry; do not record the payment again.');
        }

        $message = $request->boolean('renew')
            ? 'Payment recorded; customer renewed and synced with RADIUS.'
            : 'Payment recorded successfully.';

        return back()->with('success', $message);
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $this->ensureCustomerAccess(request(), $payment->customer);
        $payment->delete();

        return back()->with('success', 'Payment deleted.');
    }

    private function ensureCustomerAccess(Request $request, ?Customer $customer): void
    {
        abort_if(! $customer, 404);
        if ($request->user()?->isBranchOperator()) {
            abort_unless($customer->branch_id === $request->user()->branch_id, 403);
        }
    }
}
