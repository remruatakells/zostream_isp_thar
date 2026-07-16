@extends('layouts.admin')
@section('title', 'Payments')
@section('eyebrow', 'Collections')
@section('content')
<div class="page-actions"><div><h2>Collection ledger</h2><p>Record a payment and optionally renew the customer immediately.</p></div></div>
<section class="split-grid">
<form id="paymentForm" class="form-card form-grid" method="POST" action="{{ route('payments.store') }}" data-checkout-url="{{ route('payments.checkout') }}" data-complete-url="{{ route('payments.razorpay.complete') }}">@csrf
    <label class="full">Customer<select id="paymentCustomer" name="customer_id" required><option value="">Choose customer</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" data-price="{{ $customer->package?->price }}" data-package="{{ $customer->package?->name }}" @selected(old('customer_id', $selectedCustomer) == $customer->id)>{{ $customer->name }} · {{ $customer->username }}</option>@endforeach</select></label>
    <label>Package amount (₹)<input id="paymentAmount" type="number" step="0.01" value="" readonly required><small class="form-help">Automatically taken from the customer's current package.</small></label>
    <label>Payment method<select id="paymentMethod" name="method"><option value="razorpay">Razorpay online</option><option value="cash">Cash</option><option value="upi">Manual UPI</option><option value="bank">Bank transfer</option><option value="card">Manual card</option></select></label>
    <label>Reference<input name="reference" value="{{ old('reference') }}" placeholder="Transaction ID"></label>
    <label class="full">Notes<textarea name="notes" placeholder="Optional note">{{ old('notes') }}</textarea></label>
    <label class="check full"><input type="checkbox" name="renew" value="1" checked> Renew using the customer's package validity and activate PPPoE</label>
    <div class="form-actions"><button id="paymentButton" class="button primary">Pay with Razorpay</button></div>
</form>
<article class="panel"><div class="panel-head"><div><span>HISTORY</span><h3>Latest transactions</h3></div></div><div class="activity-list">
@forelse($payments as $payment)<div><span class="activity-icon">₹</span><p><strong>{{ $payment->customer?->name ?? 'Deleted customer' }}</strong><small>{{ ucfirst($payment->method) }} · {{ $payment->paid_at->format('d M Y') }}</small></p><b>₹{{ number_format($payment->amount, 0) }}</b><form data-confirm="Delete this payment record?" method="POST" action="{{ route('payments.destroy', $payment) }}">@csrf @method('DELETE')<button class="icon-button">×</button></form></div>@empty<div class="empty">No payment recorded.</div>@endforelse
</div><div class="pagination">{{ $payments->links() }}</div></article>
</section>
@endsection
@push('scripts')
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(() => {
    const form = document.getElementById('paymentForm');
    const customer = document.getElementById('paymentCustomer');
    const amount = document.getElementById('paymentAmount');
    const method = document.getElementById('paymentMethod');
    const button = document.getElementById('paymentButton');
    const csrf = form.querySelector('input[name="_token"]').value;
    let busy = false;

    const refresh = () => {
        const option = customer.selectedOptions[0];
        amount.value = option?.dataset.price || '';
        button.textContent = method.value === 'razorpay' ? 'Pay with Razorpay' : 'Record payment';
    };
    customer.addEventListener('change', refresh);
    method.addEventListener('change', refresh);
    refresh();

    form.addEventListener('submit', async event => {
        if (method.value !== 'razorpay' || busy) return;
        event.preventDefault();
        if (typeof Razorpay === 'undefined') {
            alert('Razorpay Checkout could not be loaded. Check the internet connection and retry.');
            return;
        }

        busy = true;
        button.disabled = true;
        button.textContent = 'Creating secure order…';
        try {
            const response = await fetch(form.dataset.checkoutUrl, {
                method: 'POST',
                body: new FormData(form),
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            const checkout = await response.json();
            if (!response.ok) throw new Error(checkout.message || 'Unable to create Razorpay order.');

            const razorpay = new Razorpay({
                key: checkout.key,
                amount: checkout.amount,
                currency: checkout.currency,
                order_id: checkout.order_id,
                name: checkout.name,
                description: checkout.description,
                prefill: checkout.prefill,
                theme: {color: '#0c7253'},
                handler: async payment => {
                    button.textContent = 'Verifying payment…';
                    const verified = await fetch(form.dataset.completeUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            checkout_id: checkout.checkout_id,
                            razorpay_order_id: payment.razorpay_order_id,
                            razorpay_payment_id: payment.razorpay_payment_id,
                            razorpay_signature: payment.razorpay_signature,
                        }),
                    });
                    const result = await verified.json();
                    if (!verified.ok) {
                        busy = false;
                        button.disabled = false;
                        refresh();
                        alert(result.message || 'Payment verification failed.');
                        return;
                    }
                    window.location.href = result.redirect;
                },
                modal: {
                    ondismiss: () => {
                        busy = false;
                        button.disabled = false;
                        refresh();
                    },
                },
            });
            razorpay.on('payment.failed', response => {
                alert(response.error?.description || 'Razorpay payment failed.');
            });
            razorpay.open();
        } catch (error) {
            busy = false;
            button.disabled = false;
            refresh();
            alert(error.message);
        }
    });
})();
</script>
@endpush
