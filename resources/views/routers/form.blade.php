@extends('layouts.admin')
@section('title', $router->exists ? 'Edit router' : 'Add router')
@section('eyebrow', 'Infrastructure')
@section('content')
<div class="page-actions"><div><h2>{{ $router->exists ? $router->name : 'Connect a MikroTik' }}</h2><p>Use a dedicated RouterOS REST API user, never the main admin account.</p></div></div>
<form class="form-card form-grid" method="POST" action="{{ $router->exists ? route('routers.update', $router) : route('routers.store') }}">@csrf @if($router->exists) @method('PUT') @endif
    <label>Display name<input name="name" value="{{ old('name', $router->name) }}" required placeholder="Main POP Router"></label>
    <label>Router IP / hostname<input name="host" value="{{ old('host', $router->host) }}" required placeholder="192.168.88.1"></label>
    <label>REST API port<input type="number" name="port" value="{{ old('port', $router->port ?: 443) }}" required></label>
    <label>API username<input name="username" value="{{ old('username', $router->username) }}" required autocomplete="off" placeholder="isp-panel"></label>
    <label class="full">API password<input type="password" name="password" {{ $router->exists ? '' : 'required' }} autocomplete="new-password" placeholder="{{ $router->exists ? 'Leave blank to keep the current password' : 'Strong router API password' }}"></label>
    <label class="check"><input type="checkbox" name="use_ssl" value="1" @checked(old('use_ssl', $router->exists ? $router->use_ssl : true))> Use HTTPS</label>
    <label class="check"><input type="checkbox" name="verify_ssl" value="1" @checked(old('verify_ssl', $router->exists ? $router->verify_ssl : true))> Verify TLS certificate</label>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $router->exists ? $router->is_active : true))> Router is active</label>
    <p class="form-help full">For a self-signed test certificate, TLS verification may be disabled temporarily. Import a trusted certificate before production.</p>
    <div class="form-actions"><a class="button secondary" href="{{ route('routers.index') }}">Cancel</a><button class="button primary">Save router</button></div>
</form>
@endsection
