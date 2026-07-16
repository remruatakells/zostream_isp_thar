@extends('layouts.admin')
@section('title', 'Payments')
@section('eyebrow', 'Collections')
@section('content')
<div class="page-actions"><div><h2>Collection ledger</h2><p>Record a payment and optionally renew the customer immediately.</p></div></div>
<section class="split-grid">
<form id="paymentForm" class="form-card form-grid" method="POST" action="{{ route('payments.store') }}" data-checkout-url="{{ route('payments.checkout') }}" data-complete-url="{{ route('payments.razorpay.complete') }}" data-ott-deduction="{{ config('services.zostream_subscription.ott_deduction', 50) }}" data-operator-percentage="{{ config('services.zostream_subscription.operator_percentage', 20) }}">@csrf
    <label class="full">Customer<select id="paymentCustomer" name="customer_id" required><option value="">Choose customer</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" data-price="{{ $customer->package?->price }}" data-package="{{ $customer->package?->name }}" @selected(old('customer_id', $selectedCustomer) == $customer->id)>{{ $customer->name }} · {{ $customer->username }}</option>@endforeach</select></label>
    <label>Package amount (₹)<input id="packageAmount" type="number" step="0.01" value="" readonly required><small class="form-help">Customer's current package price.</small></label>
    <label>OTT deduction (₹)<input id="ottDeduction" type="number" step="0.01" value="{{ config('services.zostream_subscription.ott_deduction', 50) }}" readonly></label>
    <label>Distribution balance (₹)<input id="distributableAmount" type="number" step="0.01" value="" readonly></label>
    <label>Local operator {{ number_format(config('services.zostream_subscription.operator_percentage', 20), 0) }}% (₹)<input id="operatorCommission" type="number" step="0.01" value="" readonly></label>
    <label>ZoStream WiFi {{ number_format(100 - config('services.zostream_subscription.operator_percentage', 20), 0) }}% (₹)<input id="wifiShare" type="number" step="0.01" value="" readonly></label>
    <label>Razorpay amount (₹)<input id="paymentAmount" type="number" step="0.01" value="" readonly required><small class="form-help">ZoStream WiFi share plus the OTT ₹{{ number_format(config('services.zostream_subscription.ott_deduction', 50), 0) }}.</small></label>
    <label>Payment method<select id="paymentMethod" name="method"><option value="razorpay">Razorpay online</option><option value="cash">Cash</option><option value="upi">Manual UPI</option><option value="bank">Bank transfer</option><option value="card">Manual card</option></select></label>
    <label>Reference<input name="reference" value="{{ old('reference') }}" placeholder="Transaction ID"></label>
    <label class="full">Notes<textarea name="notes" placeholder="Optional note">{{ old('notes') }}</textarea></label>
    <label class="check full"><input type="checkbox" name="renew" value="1" checked> Renew using the customer's package validity and activate PPPoE</label>
    <div class="form-actions"><button id="paymentButton" class="button primary">Pay with Razorpay</button></div>
</form>
<article class="panel"><div class="panel-head"><div><span>HISTORY</span><h3>Latest transactions</h3></div></div><div class="activity-list">
@forelse($payments as $payment)<div><span class="activity-icon">₹</span><p><strong>{{ $payment->customer?->name ?? 'Deleted customer' }}</strong><small>{{ ucfirst($payment->method) }} · {{ $payment->paid_at->format('d M Y') }} · Operator: {{ $payment->operator?->name ?? 'Unknown' }}</small><small>Package ₹{{ number_format($payment->package_amount ?? $payment->amount, 0) }} − OTT ₹{{ number_format($payment->ott_deduction ?? 0, 0) }} · Operator {{ number_format($payment->operator_percentage ?? 0, 0) }}% = ₹{{ number_format($payment->operator_commission ?? 0, 0) }}</small></p><b>₹{{ number_format($payment->amount, 0) }}</b><form data-confirm="Delete this payment record?" method="POST" action="{{ route('payments.destroy', $payment) }}">@csrf @method('DELETE')<button class="icon-button">×</button></form></div>@empty<div class="empty">No payment recorded.</div>@endforelse
</div><div class="pagination">{{ $payments->links() }}</div></article>
</section>
<dialog id="paymentConfirmation" class="confirmation-dialog">
    <form method="dialog">
        <div class="confirmation-head"><span>CONFIRM PAYMENT</span><h3>Review before continuing</h3></div>
        <div class="confirmation-customer"><strong id="confirmCustomer"></strong><small id="confirmPackage"></small></div>
        <dl class="payment-summary">
            <div><dt>Package amount</dt><dd id="confirmPackageAmount"></dd></div>
            <div><dt>OTT deduction</dt><dd id="confirmOtt" class="deduction"></dd></div>
            <div><dt>Balance after OTT</dt><dd id="confirmDistributable"></dd></div>
            <div><dt>Local operator <span id="confirmOperatorPercentage"></span>%</dt><dd id="confirmCommission"></dd></div>
            <div><dt>ZoStream WiFi <span id="confirmWifiPercentage"></span>%</dt><dd id="confirmWifiShare"></dd></div>
            <div><dt>OTT added back</dt><dd id="confirmOttAdded"></dd></div>
            <div class="total"><dt>Razorpay amount</dt><dd id="confirmPayable"></dd></div>
            <div><dt>Method</dt><dd id="confirmMethod"></dd></div>
        </dl>
        <p class="confirmation-note">OTT ₹{{ number_format(config('services.zostream_subscription.ott_deduction', 50), 0) }} is excluded while calculating the percentages, then added back to the ZoStream WiFi share for Razorpay.</p>
        <div class="confirmation-actions">
            <button class="button secondary" value="cancel">Cancel</button>
            <button id="confirmPaymentButton" class="button primary" value="default">Confirm payment</button>
        </div>
    </form>
</dialog>
@endsection
@push('scripts')
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(() => {
    const form = document.getElementById('paymentForm');
    const customer = document.getElementById('paymentCustomer');
    const packageAmount = document.getElementById('packageAmount');
    const ottInput = document.getElementById('ottDeduction');
    const distributableInput = document.getElementById('distributableAmount');
    const commissionInput = document.getElementById('operatorCommission');
    const wifiShareInput = document.getElementById('wifiShare');
    const amount = document.getElementById('paymentAmount');
    const method = document.getElementById('paymentMethod');
    const button = document.getElementById('paymentButton');
    const dialog = document.getElementById('paymentConfirmation');
    const confirmButton = document.getElementById('confirmPaymentButton');
    const csrf = form.querySelector('input[name="_token"]').value;
    const ottDeduction = Number(form.dataset.ottDeduction || 50);
    const operatorPercentage = Number(form.dataset.operatorPercentage || 20);
    const wifiPercentage = 100 - operatorPercentage;
    let busy = false;

    const refresh = () => {
        const option = customer.selectedOptions[0];
        const price = Number(option?.dataset.price || 0);
        const distributable = Math.max(0, price - ottDeduction);
        const commission = distributable * (operatorPercentage / 100);
        const wifiShare = distributable - commission;
        const razorpayAmount = wifiShare + ottDeduction;
        packageAmount.value = price > 0 ? price.toFixed(2) : '';
        ottInput.value = ottDeduction.toFixed(2);
        distributableInput.value = price > ottDeduction ? distributable.toFixed(2) : '';
        commissionInput.value = price > ottDeduction ? commission.toFixed(2) : '';
        wifiShareInput.value = price > ottDeduction ? wifiShare.toFixed(2) : '';
        amount.value = price > ottDeduction ? razorpayAmount.toFixed(2) : '';
        button.textContent = method.value === 'razorpay' ? 'Pay with Razorpay' : 'Record payment';
    };
    customer.addEventListener('change', refresh);
    method.addEventListener('change', refresh);
    refresh();

    const startRazorpay = async () => {
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
    };

    form.addEventListener('submit', event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        if (!amount.value || Number(amount.value) <= 0) {
            alert('Package amount must be greater than the operator wage.');
            return;
        }
        const option = customer.selectedOptions[0];
        document.getElementById('confirmCustomer').textContent = option.textContent.trim();
        document.getElementById('confirmPackage').textContent = option.dataset.package || 'No package';
        document.getElementById('confirmPackageAmount').textContent = `₹${Number(packageAmount.value).toLocaleString('en-IN')}`;
        document.getElementById('confirmOtt').textContent = `− ₹${ottDeduction.toLocaleString('en-IN')}`;
        document.getElementById('confirmDistributable').textContent = `₹${Number(distributableInput.value).toLocaleString('en-IN')}`;
        document.getElementById('confirmOperatorPercentage').textContent = operatorPercentage.toLocaleString('en-IN');
        document.getElementById('confirmWifiPercentage').textContent = wifiPercentage.toLocaleString('en-IN');
        document.getElementById('confirmCommission').textContent = `₹${Number(commissionInput.value).toLocaleString('en-IN')}`;
        document.getElementById('confirmWifiShare').textContent = `₹${Number(wifiShareInput.value).toLocaleString('en-IN')}`;
        document.getElementById('confirmOttAdded').textContent = `+ ₹${ottDeduction.toLocaleString('en-IN')}`;
        document.getElementById('confirmPayable').textContent = `₹${Number(amount.value).toLocaleString('en-IN')}`;
        document.getElementById('confirmMethod').textContent = method.selectedOptions[0].textContent;
        dialog.showModal();
    });

    confirmButton.addEventListener('click', event => {
        event.preventDefault();
        dialog.close();
        if (method.value === 'razorpay') {
            startRazorpay();
            return;
        }
        HTMLFormElement.prototype.submit.call(form);
    });
})();
</script>
@endpush
