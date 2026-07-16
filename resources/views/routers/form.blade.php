@extends('layouts.admin')
@section('title', $router->exists ? 'Edit router' : 'Add router')
@section('eyebrow', 'Infrastructure')
@section('content')
<div class="editor-heading">
    <a href="{{ route('routers.index') }}">←</a>
    <div><span>ROUTER CONNECTION</span><h2>{{ $router->exists ? $router->name : 'Connect a MikroTik' }}</h2><p>Use a dedicated RouterOS REST API user, never the main admin account.</p></div>
</div>
<form class="module-editor" method="POST" action="{{ $router->exists ? route('routers.update', $router) : route('routers.store') }}">
    @csrf @if($router->exists) @method('PUT') @endif
    <div class="editor-section">
        <div class="editor-section-copy"><span>01</span><div><strong>Router identity</strong><small>Name the router and enter its WireGuard or reachable IP.</small></div></div>
        <div class="editor-fields three">
            <label><span>Display name</span><input name="name" value="{{ old('name', $router->name) }}" required placeholder="Main POP Router"></label>
            <label><span>Router IP / hostname</span><input name="host" value="{{ old('host', $router->host) }}" required placeholder="10.77.0.2"></label>
            <label><span>REST API port</span><input type="number" name="port" value="{{ old('port', $router->port ?: 443) }}" required></label>
        </div>
    </div>
    <div class="editor-section">
        <div class="editor-section-copy"><span>02</span><div><strong>Authentication</strong><small>REST credentials and the RADIUS shared secret.</small></div></div>
        <div class="editor-fields">
            <label><span>API username</span><input name="username" value="{{ old('username', $router->username) }}" required autocomplete="off" placeholder="isp-panel"></label>
            <label><span>API password</span><input type="password" name="password" {{ $router->exists ? '' : 'required' }} autocomplete="new-password" placeholder="{{ $router->exists ? 'Leave blank to keep current password' : 'Strong router API password' }}"></label>
            <label class="wide"><span>RADIUS shared secret</span><input type="password" name="radius_secret" autocomplete="new-password" placeholder="{{ $router->exists ? 'Leave blank to keep current shared secret' : 'Unique random secret of at least 16 characters' }}"><small>Use this exact secret in Winbox → RADIUS.</small></label>
        </div>
    </div>
    <div class="editor-section">
        <div class="editor-section-copy"><span>03</span><div><strong>Connection options</strong><small>Control RADIUS, HTTPS and router availability.</small></div></div>
        <div class="editor-toggle-grid">
            <label class="editor-toggle"><input type="checkbox" name="radius_enabled" value="1" @checked(old('radius_enabled', $router->exists ? $router->radius_enabled : true))><span></span><div><strong>RADIUS enabled</strong><small>Authenticate PPPoE customers through FreeRADIUS.</small></div></label>
            <label class="editor-toggle"><input type="checkbox" name="use_ssl" value="1" @checked(old('use_ssl', $router->exists ? $router->use_ssl : true))><span></span><div><strong>Use HTTPS</strong><small>Secure RouterOS REST requests.</small></div></label>
            <label class="editor-toggle"><input type="checkbox" name="verify_ssl" value="1" @checked(old('verify_ssl', $router->exists ? $router->verify_ssl : true))><span></span><div><strong>Verify certificate</strong><small>Recommended with a trusted TLS certificate.</small></div></label>
            <label class="editor-toggle"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $router->exists ? $router->is_active : true))><span></span><div><strong>Router active</strong><small>Available for customers, sync and health checks.</small></div></label>
        </div>
    </div>
    <div class="editor-info">The router IP must be the address from which RADIUS packets originate, such as <code>10.77.0.2</code>. TLS verification may be disabled temporarily for a self-signed REST certificate.</div>
    <div class="editor-footer actions-only"><div><a class="button secondary" href="{{ route('routers.index') }}">Cancel</a><button class="button primary">Save router</button></div></div>
</form>
@endsection
