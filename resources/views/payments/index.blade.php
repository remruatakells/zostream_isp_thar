@extends('layouts.admin')
@section('title', 'Payments')
@section('eyebrow', 'Collections')
@section('content')
<div class="page-actions"><div><h2>Collection ledger</h2><p>Record a payment and optionally renew the customer immediately.</p></div></div>
<section class="split-grid">
<form class="form-card form-grid" method="POST" action="{{ route('payments.store') }}">@csrf
    <label class="full">Customer<select name="customer_id" required><option value="">Choose customer</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(old('customer_id', $selectedCustomer) == $customer->id)>{{ $customer->name }} · {{ $customer->username }}</option>@endforeach</select></label>
    <label>Amount (₹)<input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" required></label>
    <label>Payment method<select name="method"><option value="cash">Cash</option><option value="upi">UPI</option><option value="bank">Bank transfer</option><option value="card">Card</option></select></label>
    <label>Paid at<input type="datetime-local" name="paid_at" value="{{ old('paid_at', now()->format('Y-m-d\TH:i')) }}" required></label>
    <label>Reference<input name="reference" value="{{ old('reference') }}" placeholder="Transaction ID"></label>
    <label class="full">Notes<textarea name="notes" placeholder="Optional note">{{ old('notes') }}</textarea></label>
    <label class="check full"><input type="checkbox" name="renew" value="1" checked> Renew using the customer's package validity and activate PPPoE</label>
    <div class="form-actions"><button class="button primary">Record payment</button></div>
</form>
<article class="panel"><div class="panel-head"><div><span>HISTORY</span><h3>Latest transactions</h3></div></div><div class="activity-list">
@forelse($payments as $payment)<div><span class="activity-icon">₹</span><p><strong>{{ $payment->customer?->name ?? 'Deleted customer' }}</strong><small>{{ ucfirst($payment->method) }} · {{ $payment->paid_at->format('d M Y') }}</small></p><b>₹{{ number_format($payment->amount, 0) }}</b><form data-confirm="Delete this payment record?" method="POST" action="{{ route('payments.destroy', $payment) }}">@csrf @method('DELETE')<button class="icon-button">×</button></form></div>@empty<div class="empty">No payment recorded.</div>@endforelse
</div><div class="pagination">{{ $payments->links() }}</div></article>
</section>
@endsection
