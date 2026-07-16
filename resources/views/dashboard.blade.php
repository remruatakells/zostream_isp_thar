@extends('layouts.admin')
@section('title', 'Network overview')
@section('eyebrow', now()->format('l, d F Y'))

@section('content')
@php
    $chartSum = array_sum($statusChart);
    $chartTotal = max($chartSum, 1);
    $chartColors = [
        'online' => '#46bc88',
        'offline' => '#6faed7',
        'expired' => '#9b83d7',
        'suspended' => '#ef8b7e',
        'unknown' => '#e4bd55',
    ];
    $chartLabels = [
        'online' => 'Online',
        'offline' => 'Offline',
        'expired' => 'Expired',
        'suspended' => 'Suspended',
        'unknown' => 'Unknown',
    ];
    $chartFilters = [
        'online' => route('customers.index', ['status' => 'online']),
        'offline' => route('customers.index', ['status' => 'offline']),
        'expired' => route('customers.index', ['status' => 'expired']),
        'suspended' => route('customers.index', ['status' => 'suspended']),
        'unknown' => route('customers.index', ['status' => 'unknown']),
    ];
    $chartStops = [];
    $chartCursor = 0;
    foreach ($statusChart as $key => $value) {
        $start = $chartCursor;
        $chartCursor += ($value / $chartTotal) * 100;
        $chartStops[] = $chartColors[$key].' '.$start.'% '.$chartCursor.'%';
    }
    if ($chartSum === 0) {
        $chartStops = ['#e8eeeb 0% 100%'];
    }
@endphp

<section class="hero-panel overview-hero">
    <div>
        <span class="kicker">CONTROL CENTER</span>
        <h2>Your ISP, at a glance.</h2>
        <p>Live PPP sessions, subscription health, collections and router reachability.</p>
    </div>
    <div class="actions">
        <a href="{{ route('dashboard', ['refresh' => 1]) }}" class="button secondary">Refresh live status</a>
        <a href="{{ route('customers.create') }}" class="button primary">+ Add customer</a>
    </div>
</section>

<section class="stats-grid overview-clickable-stats">
    <a class="stat-card mint" href="{{ route('customers.index') }}">
        <span>Total customers</span><strong>{{ number_format($stats['customers']) }}</strong>
        <small>{{ $stats['active'] }} valid active subscriptions</small><i>→</i>
    </a>
    <a class="stat-card lime" href="{{ route('customers.index', ['status' => 'online']) }}">
        <span>Online customers</span><strong>{{ number_format($stats['online']) }}</strong>
        <small>Live in MikroTik PPP Active</small><i>→</i>
    </a>
    <a class="stat-card blue" href="{{ route('customers.index', ['status' => 'offline']) }}">
        <span>Offline customers</span><strong>{{ number_format($stats['offline']) }}</strong>
        <small>Valid users on reachable routers</small><i>→</i>
    </a>
    <a class="stat-card violet" href="{{ route('customers.index', ['status' => 'expired']) }}">
        <span>Expired users</span><strong>{{ number_format($stats['expired']) }}</strong>
        <small>Expiry date is before today</small><i>→</i>
    </a>
    <a class="stat-card mint" href="{{ route('customers.index', ['status' => 'suspended']) }}">
        <span>Suspended</span><strong>{{ number_format($stats['suspended']) }}</strong>
        <small>Administratively disabled</small><i>→</i>
    </a>
    <a class="stat-card lime" href="{{ route('customers.index', ['status' => 'unknown']) }}">
        <span>Unknown status</span><strong>{{ number_format($stats['unknown']) }}</strong>
        <small>Router inactive or unreachable</small><i>→</i>
    </a>
    <a class="stat-card blue" href="{{ route('payments.index') }}">
        <span>This month</span><strong>₹{{ number_format($stats['revenue'], 0) }}</strong>
        <small>Payments collected</small><i>→</i>
    </a>
    <a class="stat-card violet" href="{{ auth()->user()->isAdmin() ? route('routers.index') : route('customers.index', ['status' => 'unknown']) }}">
        <span>Reachable routers</span><strong>{{ $stats['reachable_routers'] }}/{{ $stats['routers'] }}</strong>
        <small>Live REST status · cached 20 seconds</small><i>→</i>
    </a>
</section>

<section class="overview-chart-card">
    <div class="overview-chart-copy">
        <span>SUBSCRIBER DISTRIBUTION</span>
        <h3>Customer status</h3>
        <p>Click a status to open the matching customer list.</p>
        <div class="overview-chart-legend">
            @foreach($statusChart as $key => $value)
                <a href="{{ $chartFilters[$key] }}">
                    <i style="--legend-color: {{ $chartColors[$key] }}"></i>
                    <span>{{ $chartLabels[$key] }}</span>
                    <strong>{{ number_format($value) }}</strong>
                    <small>{{ number_format(($value / $chartTotal) * 100, 1) }}%</small>
                </a>
            @endforeach
        </div>
    </div>
    <div class="overview-donut-wrap">
        <div class="overview-donut" style="--donut-segments: {{ implode(', ', $chartStops) }}">
            <div><strong>{{ number_format($chartSum) }}</strong><span>Customers</span></div>
        </div>
    </div>
</section>
@endsection
