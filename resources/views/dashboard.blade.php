@extends('layouts.admin')
@section('title', 'Network overview')
@section('eyebrow', now()->format('l, d F Y'))
@section('content')
<section class="hero-panel">
    <div><span class="kicker">CONTROL CENTER</span><h2>Your ISP, at a glance.</h2><p>Live PPP sessions, subscription health, collections and router reachability.</p></div>
    <div class="actions"><a href="{{ route('dashboard', ['refresh' => 1]) }}" class="button secondary">Refresh live status</a><a href="{{ route('customers.create') }}" class="button primary">+ Add customer</a></div>
</section>

<section class="stats-grid">
    <article class="stat-card mint"><span>Total customers</span><strong>{{ number_format($stats['customers']) }}</strong><small>{{ $stats['active'] }} valid active subscriptions</small></article>
    <article class="stat-card lime"><span>Online customers</span><strong>{{ number_format($stats['online']) }}</strong><small>Live in MikroTik PPP Active</small></article>
    <article class="stat-card blue"><span>Offline customers</span><strong>{{ number_format($stats['offline']) }}</strong><small>Valid users on reachable routers</small></article>
    <article class="stat-card violet"><span>Expired users</span><strong>{{ number_format($stats['expired']) }}</strong><small>Expiry date is before today</small></article>
    <article class="stat-card mint"><span>Suspended</span><strong>{{ number_format($stats['suspended']) }}</strong><small>Administratively disabled</small></article>
    <article class="stat-card lime"><span>Unknown status</span><strong>{{ number_format($stats['unknown']) }}</strong><small>Router inactive or unreachable</small></article>
    <article class="stat-card blue"><span>This month</span><strong>₹{{ number_format($stats['revenue'], 0) }}</strong><small>Payments collected</small></article>
    <article class="stat-card violet"><span>Reachable routers</span><strong>{{ $stats['reachable_routers'] }}/{{ $stats['routers'] }}</strong><small>Live REST status · cached 20 seconds</small></article>
</section>

<article class="panel overview-section">
    <div class="panel-head"><div><span>INFRASTRUCTURE</span><h3>Router health</h3></div><a href="{{ route('routers.index') }}">Manage routers</a></div>
    <div class="table-wrap"><table><thead><tr><th>Router</th><th>REST status</th><th>PPP sessions</th><th>Panel online</th><th>Valid customers</th><th>Last connected</th></tr></thead><tbody>
    @forelse($routerHealth as $health)<tr>
        <td><strong>{{ $health['router']->name }}</strong><small>{{ $health['router']->host }}:{{ $health['router']->port }}</small></td>
        <td><span class="badge {{ $health['reachable'] ? '' : 'off' }}">{{ $health['reachable'] ? 'Reachable' : 'Unreachable' }}</span>@if(!$health['reachable'])<small title="{{ $health['error'] }}">{{ Illuminate\Support\Str::limit($health['error'], 55) }}</small>@endif</td>
        <td>{{ $health['sessions'] ?? '—' }}</td><td>{{ $health['panel_online'] ?? '—' }}</td><td>{{ $health['eligible'] }}</td><td>{{ $health['router']->last_connected_at?->diffForHumans() ?? 'Never' }}</td>
    </tr>@empty<tr><td colspan="6" class="empty">No active routers configured.</td></tr>@endforelse
    </tbody></table></div>
</article>

<section class="split-grid overview-section">
    <article class="panel">
        <div class="panel-head"><div><span>LIVE STATUS</span><h3>Offline customers</h3></div><a href="{{ route('customers.index', ['status' => 'active']) }}">View customers</a></div>
        <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Router</th><th>Last sync</th><th></th></tr></thead><tbody>
        @forelse($offlineCustomers as $customer)<tr><td><strong>{{ $customer->name }}</strong><small>{{ $customer->username }}</small></td><td>{{ $customer->router->name }}</td><td>{{ $customer->last_synced_at?->diffForHumans() ?? 'Never' }}</td><td><form method="POST" action="{{ route('customers.sync', $customer) }}">@csrf<button class="icon-button">Sync</button></form></td></tr>
        @empty<tr><td colspan="4" class="empty">No offline customer on reachable routers.</td></tr>@endforelse
        </tbody></table></div>
    </article>
    <article class="panel">
        <div class="panel-head"><div><span>ACTION REQUIRED</span><h3>Expired users</h3></div><a href="{{ route('customers.index') }}">View customers</a></div>
        <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Router</th><th>Expired</th><th></th></tr></thead><tbody>
        @forelse($expiredCustomers as $customer)<tr><td><strong>{{ $customer->name }}</strong><small>{{ $customer->username }}</small></td><td>{{ $customer->router->name }}</td><td><span class="badge off">{{ $customer->expires_at->format('d M Y') }}</span></td><td><a class="text-link" href="{{ route('payments.index', ['customer' => $customer]) }}">Renew</a></td></tr>
        @empty<tr><td colspan="4" class="empty">No expired subscriptions.</td></tr>@endforelse
        </tbody></table></div>
    </article>
</section>

<section class="split-grid overview-section">
    <article class="panel">
        <div class="panel-head"><div><span>ATTENTION</span><h3>Expiring in 7 days</h3></div><a href="{{ route('customers.index') }}">View all</a></div>
        <div class="table-wrap"><table><thead><tr><th>Customer</th><th>Router</th><th>Package</th><th>Expiry</th><th></th></tr></thead><tbody>
        @forelse($expiring as $customer)<tr><td><strong>{{ $customer->name }}</strong><small>{{ $customer->username }}</small></td><td>{{ $customer->router->name }}</td><td>{{ $customer->package?->name ?? '—' }}</td><td><span class="badge warn">{{ $customer->expires_at->format('d M Y') }}</span></td><td><a class="text-link" href="{{ route('payments.index', ['customer' => $customer]) }}">Collect</a></td></tr>
        @empty<tr><td colspan="5" class="empty">No subscriptions expiring soon.</td></tr>@endforelse
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
