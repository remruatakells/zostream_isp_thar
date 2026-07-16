@extends('layouts.admin')
@section('title', 'Internet packages')
@section('eyebrow', 'Product catalog')

@section('content')
<section class="catalog-hero">
    <div>
        <span>PRODUCT CATALOG</span>
        <h2>Speed plans</h2>
        <p>Build billing plans and map every package to its MikroTik PPP profile.</p>
    </div>
    <div class="catalog-hero-side">
        <div><small>PACKAGES</small><strong>{{ $packages->count() }}</strong></div>
        <div><small>SUBSCRIBERS</small><strong>{{ $packages->sum('customers_count') }}</strong></div>
        <a class="module-add-button" href="{{ route('packages.create') }}"><i>+</i><span>New package</span></a>
    </div>
</section>

<div class="module-list-heading">
    <div><span>AVAILABLE PLANS</span><h3>Internet packages</h3><p>Pricing, speed and router profile settings.</p></div>
    <span>{{ $packages->where('is_active', true)->count() }} active</span>
</div>

<section class="package-card-grid">
    @forelse($packages as $package)
        <article class="package-modern-card">
            <header>
                <div class="module-avatar package-avatar">◇</div>
                <div>
                    <h3>{{ $package->name }}</h3>
                    <code>{{ $package->mikrotik_profile }}</code>
                </div>
                <span class="module-state {{ $package->is_active ? '' : 'disabled' }}"><i></i>{{ $package->is_active ? 'Active' : 'Hidden' }}</span>
            </header>
            <div class="package-speed"><small>RATE LIMIT</small><strong>{{ $package->rate_limit ?: 'Unlimited' }}</strong></div>
            <div class="package-metrics">
                <div><small>PRICE</small><strong>₹{{ number_format($package->price, 0) }}</strong></div>
                <div><small>VALIDITY</small><strong>{{ $package->validity_days }} days</strong></div>
                <div><small>SUBSCRIBERS</small><strong>{{ $package->customers_count }}</strong></div>
            </div>
            <footer class="module-card-footer">
                <form class="module-sync-form" method="POST" action="{{ route('packages.sync', $package) }}">
                    @csrf
                    <select name="router_id" aria-label="Router to sync">
                        <option value="">All active routers</option>
                        @foreach($routers as $router)<option value="{{ $router->id }}">{{ $router->name }}</option>@endforeach
                    </select>
                    <button type="submit"><i>↻</i> Sync</button>
                </form>
                <div class="module-actions">
                    <a href="{{ route('packages.edit', $package) }}">Edit</a>
                    <form data-confirm="Delete this package?" method="POST" action="{{ route('packages.destroy', $package) }}">
                        @csrf @method('DELETE')
                        <button type="submit">Delete</button>
                    </form>
                </div>
            </footer>
        </article>
    @empty
        <article class="module-empty-card"><span>◇</span><strong>No package created yet</strong><p>Create your first internet speed plan.</p><a class="button primary" href="{{ route('packages.create') }}">+ New package</a></article>
    @endforelse
</section>
@endsection
