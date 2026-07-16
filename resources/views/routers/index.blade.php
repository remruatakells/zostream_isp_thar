@extends('layouts.admin')
@section('title', 'Routers')
@section('eyebrow', 'Infrastructure')

@section('content')
<section class="catalog-hero router-hero">
    <div>
        <span>NETWORK INFRASTRUCTURE</span>
        <h2>MikroTik routers</h2>
        <p>Central RADIUS authentication with REST health checks and session control.</p>
    </div>
    <div class="catalog-hero-side">
        <div><small>ROUTERS</small><strong>{{ $routers->count() }}</strong></div>
        <div><small>ACTIVE</small><strong>{{ $routers->where('is_active', true)->count() }}</strong></div>
        <a class="module-add-button" href="{{ route('routers.create') }}"><i>+</i><span>Add router</span></a>
    </div>
</section>

<div class="module-list-heading">
    <div><span>ROUTER DIRECTORY</span><h3>Connected infrastructure</h3><p>REST endpoint, RADIUS state and assigned customers.</p></div>
    <span>{{ $routers->sum('customers_count') }} customers</span>
</div>

<section class="router-card-grid">
    @forelse($routers as $router)
        <article class="router-modern-card">
            <header>
                <div class="module-avatar router-avatar">⌁</div>
                <div class="router-title">
                    <h3>{{ $router->name }}</h3>
                    <span>{{ $router->username }}</span>
                </div>
                <span class="module-state {{ $router->is_active ? '' : 'disabled' }}"><i></i>{{ $router->is_active ? 'Active' : 'Disabled' }}</span>
            </header>
            <div class="router-endpoint">
                <small>REST ENDPOINT</small>
                <code>{{ $router->use_ssl ? 'https' : 'http' }}://{{ $router->host }}:{{ $router->port }}</code>
            </div>
            <div class="router-metrics">
                <div><small>CUSTOMERS</small><strong>{{ $router->customers_count }}</strong></div>
                <div><small>RADIUS</small><strong class="{{ $router->radius_enabled ? 'positive' : 'negative' }}">{{ $router->radius_enabled ? 'Enabled' : 'Disabled' }}</strong></div>
                <div><small>LAST CONNECTION</small><strong>{{ $router->last_connected_at?->diffForHumans() ?? 'Never tested' }}</strong></div>
            </div>
            <footer class="module-card-footer">
                <form method="POST" action="{{ route('routers.test', $router) }}">@csrf<button class="module-test-button" type="submit"><i>⌁</i> Test connection</button></form>
                <div class="module-actions">
                    <a href="{{ route('routers.edit', $router) }}">Edit</a>
                    <form data-confirm="Delete this router?" method="POST" action="{{ route('routers.destroy', $router) }}">@csrf @method('DELETE')<button type="submit">Delete</button></form>
                </div>
            </footer>
        </article>
    @empty
        <article class="module-empty-card"><span>⌁</span><strong>No router configured</strong><p>Add your first MikroTik router to begin.</p><a class="button primary" href="{{ route('routers.create') }}">+ Add router</a></article>
    @endforelse
</section>
@endsection
