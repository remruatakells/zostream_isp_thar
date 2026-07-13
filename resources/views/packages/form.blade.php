@extends('layouts.admin')
@section('title', $package->exists ? 'Edit package' : 'New package')
@section('eyebrow', 'Product catalog')
@section('content')
<div class="page-actions"><div><h2>{{ $package->exists ? $package->name : 'Create an internet plan' }}</h2><p>The profile will be created or updated on active routers when you click Sync.</p></div></div>
<form class="form-card form-grid" method="POST" action="{{ $package->exists ? route('packages.update', $package) : route('packages.store') }}">@csrf @if($package->exists) @method('PUT') @endif
    <label>Package name<input name="name" value="{{ old('name', $package->name) }}" required placeholder="Home 30 Mbps"></label>
    <label>MikroTik profile<input name="mikrotik_profile" value="{{ old('mikrotik_profile', $package->mikrotik_profile) }}" required placeholder="home-30m"></label>
    <label>Rate limit<input name="rate_limit" value="{{ old('rate_limit', $package->rate_limit) }}" placeholder="30M/30M"><small class="form-help">RouterOS upload/download format.</small></label>
    <label>Price (₹)<input type="number" step="0.01" min="0" name="price" value="{{ old('price', $package->price ?? 0) }}" required></label>
    <label>Validity in days<input type="number" min="1" name="validity_days" value="{{ old('validity_days', $package->validity_days ?: 30) }}" required></label>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $package->exists ? $package->is_active : true))> Available for customers</label>
    <div class="form-actions"><a class="button secondary" href="{{ route('packages.index') }}">Cancel</a><button class="button primary">Save package</button></div>
</form>
@endsection
