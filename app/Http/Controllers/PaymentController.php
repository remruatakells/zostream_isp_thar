<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentCheckout;
use App\Models\Package;
use App\Services\RadiusService;
use App\Services\PaymentInvoicePdf;
use App\Services\ZoStreamSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use RuntimeException;
use Throwable;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $branchId = $request->user()->isBranchOperator() ? $request->user()->branch_id : null;

        return view('payments.index', [
            'payments' => Payment::with(['customer', 'operator', 'package'])
                ->when($branchId, fn ($query) => $query->whereHas('customer', fn ($query) => $query->where('branch_id', $branchId)))
                ->latest('paid_at')->paginate(20),
            'customers' => Customer::when($branchId, fn ($query) => $query->where('branch_id', $branchId))
                ->with(['package:id,name,price,validity_days', 'branch:id,name,operator_percentage,ott_deduction', 'branch.packages:id'])
                ->orderBy('name')->get(['id', 'package_id', 'branch_id', 'name', 'phone', 'username']),
            'packages' => Package::where('is_active', true)->orderBy('name')->get(),
            'selectedCustomer' => $request->integer('customer'),
        ]);
    }

    public function store(Request $request, RadiusService $radius): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'package_id' => ['nullable', 'exists:packages,id'],
            'method' => ['required', Rule::in(['cash', 'upi', 'bank', 'card'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'renew' => ['nullable', 'boolean'],
        ]);
        $customer = Customer::with(['package', 'router', 'branch'])->findOrFail($data['customer_id']);
        $this->ensureCustomerAccess($request, $customer);
        $package = $this->selectedPackage($customer, $data['package_id'] ?? null);
        if ((float) $package->price <= 0) {
            return back()->withInput()->with('error', 'The selected customer does not have a payable package.');
        }
        try {
            $amounts = $this->paymentAmounts($customer, $package);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $payment = Payment::create([
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'operator_id' => $request->user()->id,
            'package_amount' => $amounts['package'],
            'ott_deduction' => $amounts['ott'],
            'distributable_amount' => $amounts['distributable'],
            'operator_percentage' => $amounts['operator_percentage'],
            'operator_commission' => $amounts['commission'],
            'amount' => $amounts['payable'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'paid_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);
        $syncError = $request->boolean('renew') ? $this->renewCustomer($customer, $radius, $package) : null;

        if ($syncError) {
            return back()->with('warning', 'Payment was recorded and the customer renewed locally, but RADIUS sync failed: '.$syncError.' Use the customer Sync action to retry; do not record the payment again.');
        }

        $message = $request->boolean('renew')
            ? 'Payment recorded; customer renewed and synced with RADIUS.'
            : 'Payment recorded successfully.';

        return back()->with('success', $message);
    }

    public function checkout(Request $request, ZoStreamSubscriptionService $subscriptions): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'package_id' => ['nullable', 'exists:packages,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'renew' => ['nullable', 'boolean'],
        ]);
        $customer = Customer::with(['package', 'router', 'branch'])->findOrFail($data['customer_id']);
        $this->ensureCustomerAccess($request, $customer);
        $package = $this->selectedPackage($customer, $data['package_id'] ?? null);

        try {
            $amounts = $this->paymentAmounts($customer, $package);
            $external = $subscriptions->createOrder($customer, $package);
            $order = $external['razorpay_order'];
            $checkout = PaymentCheckout::create([
                'user_id' => $request->user()->id,
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'external_order_id' => $order['id'],
                'razorpay_key_id' => $external['razorpay_key_id'],
                'package_amount' => $amounts['package'],
                'ott_deduction' => $amounts['ott'],
                'distributable_amount' => $amounts['distributable'],
                'operator_percentage' => $amounts['operator_percentage'],
                'operator_commission' => $amounts['commission'],
                'amount' => $amounts['payable'],
                'currency' => 'INR',
                'renew' => $request->boolean('renew'),
                'notes' => $data['notes'] ?? null,
                'external_response' => $external,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'checkout_id' => $checkout->id,
            'key' => $checkout->razorpay_key_id,
            'order_id' => $checkout->external_order_id,
            'amount' => (int) round((float) $checkout->amount * 100),
            'currency' => $checkout->currency,
            'name' => config('app.name'),
            'description' => $package->name.' renewal for '.$customer->username,
            'prefill' => [
                'name' => $customer->name,
                'contact' => $customer->phone,
            ],
        ]);
    }

    public function completeRazorpay(Request $request, RadiusService $radius): JsonResponse
    {
        $data = $request->validate([
            'checkout_id' => ['required', 'integer', 'exists:payment_checkouts,id'],
            'razorpay_order_id' => ['required', 'string', 'max:100'],
            'razorpay_payment_id' => ['required', 'string', 'max:100'],
            'razorpay_signature' => ['required', 'string', 'max:255'],
        ]);
        $secret = (string) config('services.zostream_subscription.razorpay_secret');
        if ($secret === '') {
            return response()->json(['message' => 'ZOSTREAM_RAZORPAY_KEY_SECRET is not configured.'], 422);
        }

        $checkout = PaymentCheckout::with(['customer.package', 'customer.router', 'customer.branch', 'package'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($data['checkout_id']);
        $this->ensureCustomerAccess($request, $checkout->customer);
        if (! hash_equals($checkout->external_order_id, $data['razorpay_order_id'])) {
            return response()->json(['message' => 'The Razorpay order does not match this checkout.'], 422);
        }
        $expectedSignature = hash_hmac(
            'sha256',
            $data['razorpay_order_id'].'|'.$data['razorpay_payment_id'],
            $secret,
        );
        if (! hash_equals($expectedSignature, $data['razorpay_signature'])) {
            return response()->json(['message' => 'Razorpay signature verification failed.'], 422);
        }

        try {
            [$payment, $created] = DB::transaction(function () use ($checkout, $data): array {
                $locked = PaymentCheckout::lockForUpdate()->findOrFail($checkout->id);
                if ($locked->status === 'paid') {
                    if (! hash_equals((string) $locked->razorpay_payment_id, $data['razorpay_payment_id'])) {
                        throw new RuntimeException('This checkout has already been completed by another payment.');
                    }

                    return [$locked->payment, false];
                }

                $payment = Payment::create([
                    'customer_id' => $locked->customer_id,
                    'package_id' => $locked->package_id,
                    'operator_id' => $locked->user_id,
                    'package_amount' => $locked->package_amount,
                    'ott_deduction' => $locked->ott_deduction,
                    'distributable_amount' => $locked->distributable_amount,
                    'operator_percentage' => $locked->operator_percentage,
                    'operator_commission' => $locked->operator_commission,
                    'amount' => $locked->amount,
                    'method' => 'razorpay',
                    'reference' => $data['razorpay_payment_id'],
                    'paid_at' => now(),
                    'notes' => $locked->notes,
                ]);
                $locked->update([
                    'payment_id' => $payment->id,
                    'status' => 'paid',
                    'razorpay_payment_id' => $data['razorpay_payment_id'],
                    'razorpay_signature' => $data['razorpay_signature'],
                    'paid_at' => now(),
                ]);

                return [$payment, true];
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $syncError = null;
        if ($created && $checkout->renew) {
            $syncError = $this->renewCustomer($checkout->customer, $radius, $checkout->package);
        }
        $message = $created ? 'Razorpay payment verified and recorded.' : 'Payment was already recorded.';
        if ($syncError) {
            session()->flash('warning', $message.' Customer renewed locally, but RADIUS sync failed: '.$syncError);
        } else {
            session()->flash('success', $checkout->renew ? $message.' Customer renewed and synced with RADIUS.' : $message);
        }

        return response()->json([
            'message' => $message,
            'payment_id' => $payment?->id,
            'redirect' => route('payments.index'),
        ]);
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $this->ensureCustomerAccess(request(), $payment->customer);
        $payment->delete();

        return back()->with('success', 'Payment deleted.');
    }

    public function invoice(Payment $payment, PaymentInvoicePdf $invoice): Response
    {
        $payment->loadMissing('customer');
        $this->ensureCustomerAccess(request(), $payment->customer);

        $filename = sprintf('zostream-invoice-%06d.pdf', $payment->id);

        return response($invoice->render($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function ensureCustomerAccess(Request $request, ?Customer $customer): void
    {
        abort_if(! $customer, 404);
        if ($request->user()?->isBranchOperator()) {
            abort_unless($customer->branch_id === $request->user()->branch_id, 403);
        }
    }

    private function renewCustomer(Customer $customer, RadiusService $radius, ?Package $package): ?string
    {
        if (! $package) {
            return 'The package selected during checkout no longer exists.';
        }

        $base = $customer->expires_at && $customer->expires_at->isFuture() ? $customer->expires_at : today();
        $customer->setRelation('package', $package);
        $customer->update([
            'package_id' => $package->id,
            'expires_at' => $base->copy()->addDays($package->validity_days),
            'status' => 'active',
        ]);
        try {
            $radius->syncCustomer($customer);
        } catch (Throwable $e) {
            report($e);

            return $e->getMessage();
        }

        return null;
    }

    private function paymentAmounts(Customer $customer, Package $package): array
    {
        $packageAmount = round((float) $package->price, 2);
        $ottDeduction = round(max(0, (float) ($customer->branch?->ott_deduction ?? 0)), 2);
        $operatorPercentage = round(min(100, max(0, (float) ($customer->branch?->operator_percentage
            ?? config('services.zostream_subscription.operator_percentage', 20)))), 2);
        $distributableAmount = round($packageAmount - $ottDeduction, 2);
        $commission = round($distributableAmount * ($operatorPercentage / 100), 2);
        $wifiShare = round($distributableAmount - $commission, 2);
        $payableAmount = round($wifiShare + $ottDeduction, 2);

        if ($packageAmount <= 0 || $payableAmount <= 0) {
            throw new RuntimeException('The package amount must be greater than the OTT deduction.');
        }

        return [
            'package' => $packageAmount,
            'ott' => $ottDeduction,
            'distributable' => $distributableAmount,
            'operator_percentage' => $operatorPercentage,
            'commission' => $commission,
            'wifi_share' => $wifiShare,
            'payable' => $payableAmount,
        ];
    }

    private function selectedPackage(Customer $customer, mixed $packageId): Package
    {
        $packageId = $packageId ?: $customer->package_id;
        $package = Package::where('is_active', true)->find($packageId);
        if (! $package) {
            throw ValidationException::withMessages([
                'package_id' => 'Select an active package for this payment.',
            ]);
        }

        $customer->loadMissing('branch.packages');
        if ($customer->branch && $customer->branch->packages->isNotEmpty()
            && ! $customer->branch->packages->contains('id', $package->id)) {
            throw ValidationException::withMessages([
                'package_id' => 'The selected package is not available for this customer branch.',
            ]);
        }

        return $package;
    }
}
