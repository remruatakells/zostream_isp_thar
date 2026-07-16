@extends('layouts.admin')
@section('title', 'Payments')
@section('eyebrow', 'Collections')
@section('content')
<section class="payment-hero">
    <div><span>SMART COLLECTION</span><h2>Collect, split and activate.</h2><p>Select a customer and the panel will calculate every share before payment.</p></div>
    <div class="payment-hero-badge"><i>✓</i><span><strong>Secure checkout</strong><small>Server-verified Razorpay payment</small></span></div>
</section>

<form id="paymentForm" class="payment-workspace" method="POST" action="{{ route('payments.store') }}" data-checkout-url="{{ route('payments.checkout') }}" data-complete-url="{{ route('payments.razorpay.complete') }}" data-ott-deduction="{{ config('services.zostream_subscription.ott_deduction', 50) }}" data-operator-percentage="{{ config('services.zostream_subscription.operator_percentage', 20) }}">@csrf
    <section class="payment-entry-card">
        <div class="payment-section-head"><span>01</span><div><h3>Customer & payment</h3><p>Choose the subscriber and how the payment was received.</p></div></div>
        <div class="payment-fields">
            <label class="payment-field full"><span>Customer</span><select id="paymentCustomer" name="customer_id" required><option value="">Search or choose a customer</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" data-price="{{ $customer->package?->price }}" data-package="{{ $customer->package?->name }}" @selected(old('customer_id', $selectedCustomer) == $customer->id)>{{ $customer->name }} · {{ $customer->username }}</option>@endforeach</select></label>
            <label class="payment-field"><span>Payment method</span><select id="paymentMethod" name="method"><option value="razorpay">Razorpay online</option><option value="cash">Cash</option><option value="upi">Manual UPI</option><option value="bank">Bank transfer</option><option value="card">Manual card</option></select></label>
            <label class="payment-field"><span>Reference</span><input name="reference" value="{{ old('reference') }}" placeholder="Optional transaction ID"></label>
            <label class="payment-field full"><span>Notes</span><textarea name="notes" placeholder="Add an optional note for this collection">{{ old('notes') }}</textarea></label>
        </div>
        <label class="renew-card"><input type="checkbox" name="renew" value="1" checked><i>↻</i><span><strong>Renew and activate internet</strong><small>Extend package validity, activate the customer and sync with RADIUS after successful payment.</small></span></label>
    </section>

    <aside class="payment-calculation-card">
        <div class="payment-section-head compact"><span>02</span><div><h3>Payment breakdown</h3><p>Calculated automatically.</p></div></div>
        <input id="packageAmount" type="hidden">
        <input id="ottDeduction" type="hidden">
        <input id="distributableAmount" type="hidden">
        <input id="operatorCommission" type="hidden">
        <input id="wifiShare" type="hidden">
        <input id="paymentAmount" type="hidden">
        <div id="paymentEmptyState" class="payment-empty-state"><i>₹</i><strong>Select a customer</strong><small>The package and payment split will appear here.</small></div>
        <div id="paymentBreakdown" class="payment-breakdown" hidden>
            <div class="selected-package"><span><small id="summaryPackageName">PACKAGE</small><strong id="summaryCustomerName">Customer</strong></span><b id="summaryPackageAmount">₹0</b></div>
            <div class="split-line"><span>OTT reserved</span><strong id="summaryOtt">− ₹0</strong></div>
            <div class="split-line muted"><span>Percentage base</span><strong id="summaryDistributable">₹0</strong></div>
            <div class="share-grid">
                <div><i>OPERATOR</i><strong id="summaryOperator">₹0</strong><small>{{ number_format(config('services.zostream_subscription.operator_percentage', 20), 0) }}% share</small></div>
                <div><i>ZOSTREAM WIFI</i><strong id="summaryWifi">₹0</strong><small>{{ number_format(100 - config('services.zostream_subscription.operator_percentage', 20), 0) }}% share</small></div>
            </div>
            <div class="razorpay-total"><span><small>AMOUNT TO COLLECT</small><strong id="summaryPayable">₹0</strong></span><em>WiFi share + OTT ₹{{ number_format(config('services.zostream_subscription.ott_deduction', 50), 0) }}</em></div>
        </div>
        <button id="paymentButton" class="payment-submit" type="submit"><span>Pay with Razorpay</span><i>→</i></button>
        <small class="payment-security">🔒 Amount is recalculated and verified by the server.</small>
    </aside>
</form>

<section class="payment-history panel">
    <div class="panel-head"><div><span>COLLECTION HISTORY</span><h3>Latest transactions</h3></div><small>{{ $payments->total() }} records</small></div>
    <div class="payment-history-list">
    @forelse($payments as $payment)
        <article class="payment-history-item">
            <span class="payment-method-icon">{{ $payment->method === 'razorpay' ? 'R' : '₹' }}</span>
            <div class="payment-history-copy"><strong>{{ $payment->customer?->name ?? 'Deleted customer' }}</strong><small>{{ ucfirst($payment->method) }} · {{ $payment->paid_at->format('d M Y, h:i A') }} · {{ $payment->operator?->name ?? 'Unknown operator' }}</small></div>
            <div class="payment-history-split"><span>Package ₹{{ number_format($payment->package_amount ?? $payment->amount, 0) }}</span><span>Operator ₹{{ number_format($payment->operator_commission ?? 0, 0) }}</span></div>
            <strong class="payment-history-amount">₹{{ number_format($payment->amount, 2) }}</strong>
            <form data-confirm="Delete this payment record?" method="POST" action="{{ route('payments.destroy', $payment) }}">@csrf @method('DELETE')<button class="payment-delete" aria-label="Delete payment">×</button></form>
        </article>
    @empty
        <div class="payment-history-empty"><i>₹</i><strong>No payments yet</strong><small>Completed transactions will appear here.</small></div>
    @endforelse
    </div>
    <div class="pagination">{{ $payments->links() }}</div>
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
    const emptyState = document.getElementById('paymentEmptyState');
    const breakdown = document.getElementById('paymentBreakdown');
    const dialog = document.getElementById('paymentConfirmation');
    const confirmButton = document.getElementById('confirmPaymentButton');
    const csrf = form.querySelector('input[name="_token"]').value;
    const ottDeduction = Number(form.dataset.ottDeduction || 50);
    const operatorPercentage = Number(form.dataset.operatorPercentage || 20);
    const wifiPercentage = 100 - operatorPercentage;
    let busy = false;
    const money = value => `₹${Number(value).toLocaleString('en-IN', {minimumFractionDigits: 0, maximumFractionDigits: 2})}`;

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
        emptyState.hidden = price > 0;
        breakdown.hidden = price <= 0;
        if (price > 0) {
            document.getElementById('summaryPackageName').textContent = option.dataset.package || 'PACKAGE';
            document.getElementById('summaryCustomerName').textContent = option.textContent.trim();
            document.getElementById('summaryPackageAmount').textContent = money(price);
            document.getElementById('summaryOtt').textContent = `− ${money(ottDeduction)}`;
            document.getElementById('summaryDistributable').textContent = money(distributable);
            document.getElementById('summaryOperator').textContent = money(commission);
            document.getElementById('summaryWifi').textContent = money(wifiShare);
            document.getElementById('summaryPayable').textContent = money(razorpayAmount);
        }
        button.querySelector('span').textContent = method.value === 'razorpay' ? 'Pay with Razorpay' : 'Record payment';
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
        button.querySelector('span').textContent = 'Creating secure order…';
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
                    button.querySelector('span').textContent = 'Verifying payment…';
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
            alert('Package amount must be greater than the OTT deduction.');
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
