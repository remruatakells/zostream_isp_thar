@extends('layouts.admin')
@section('title', 'Network overview')
@section('eyebrow', now()->format('l, d F Y'))
@section('content')
<section class="hero-panel">
    <div><span class="kicker">CONTROL CENTER</span><h2>Your ISP, at a glance.</h2><p>Track subscriptions, collections and router activity from one place.</p></div>
    <a href="{{ route('customers.create') }}" class="button primary">+ Add customer</a>
</section>
<section class="stats-grid">
    <article class="stat-card mint"><span>Total customers</span><strong>{{ number_format($stats['customers']) }}</strong><small>{{ $stats['active'] }} currently active</small></article>
    <article class="stat-card lime"><span>Active services</span><strong>{{ number_format($stats['active']) }}</strong><small>{{ $stats['suspended'] }} suspended</small></article>
    <article class="stat-card blue"><span>This month</span><strong>₹{{ number_format($stats['revenue'], 0) }}</strong><small>Payments collected</small></article>
    <article class="stat-card violet"><span>Online routers</span><strong>{{ $stats['routers'] }}</strong><small>Configured as active</small></article>
</section>
<section class="split-grid">
    <article class="panel">
        <div class="panel-head"><div><span>ATTENTION</span><h3>Expiring in 7 days</h3></div><a href="{{ route('customers.index') }}">View all</a></div>
        <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Package</th><th>Expiry</th><th></th></tr></thead><tbody>
        @forelse($expiring as $customer)<tr><td><strong>{{ $customer->name }}</strong><small>{{ $customer->username }}</small></td><td>{{ $customer->package?->name ?? '—' }}</td><td><span class="badge warn">{{ $customer->expires_at->diffForHumans() }}</span></td><td><a class="text-link" href="{{ route('payments.index', ['customer' => $customer]) }}">Collect</a></td></tr>
        @empty<tr><td colspan="4" class="empty">No subscriptions expiring soon.</td></tr>@endforelse
        </tbody></table></div>
    </article>
    <article class="panel">
        <div class="panel-head"><div><span>COLLECTIONS</span><h3>Recent payments</h3></div><a href="{{ route('payments.index') }}">View ledger</a></div>
        <div class="activity-list">
        @forelse($payments as $payment)<div><span class="activity-icon">₹</span><p><strong>{{ $payment->customer?->name ?? 'Deleted customer' }}</strong><small>{{ $payment->method }} · {{ $payment->paid_at->format('d M, h:i A') }}</small></p><b>₹{{ number_format($payment->amount, 0) }}</b></div>
        @empty<div class="empty">No payment has been recorded yet.</div>@endforelse
        </div>
    </article>
</section>
@endsection
