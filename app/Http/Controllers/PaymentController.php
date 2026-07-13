<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Payment;
use App\Services\MikroTikService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        return view('payments.index', [
            'payments' => Payment::with('customer')->latest('paid_at')->paginate(20),
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'username']),
            'selectedCustomer' => $request->integer('customer'),
        ]);
    }

    public function store(Request $request, MikroTikService $mikrotik): RedirectResponse
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
        $payment = Payment::create($data);

        if ($request->boolean('renew')) {
            $customer = $payment->customer()->with(['package', 'router'])->first();
            $base = $customer->expires_at && $customer->expires_at->isFuture() ? $customer->expires_at : today();
            $customer->update(['expires_at' => $base->copy()->addDays($customer->package?->validity_days ?? 30), 'status' => 'active']);
            try {
                $mikrotik->syncCustomer($customer);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return back()->with('success', 'Payment recorded successfully.');
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $payment->delete();

        return back()->with('success', 'Payment deleted.');
    }
}
