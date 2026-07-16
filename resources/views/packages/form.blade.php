@extends('layouts.admin')
@section('title', $package->exists ? 'Edit package' : 'New package')
@section('eyebrow', 'Product catalog')
@section('content')
<div class="editor-heading">
    <a href="{{ route('packages.index') }}">←</a>
    <div><span>PACKAGE SETTINGS</span><h2>{{ $package->exists ? $package->name : 'Create an internet plan' }}</h2><p>Configure billing, speed and the matching MikroTik PPP profile.</p></div>
</div>
<form class="module-editor" method="POST" action="{{ $package->exists ? route('packages.update', $package) : route('packages.store') }}">
    @csrf @if($package->exists) @method('PUT') @endif
    <div class="editor-section">
        <div class="editor-section-copy"><span>01</span><div><strong>Plan identity</strong><small>Name the package and map its router profile.</small></div></div>
        <div class="editor-fields">
            <label><span>Package name</span><input name="name" value="{{ old('name', $package->name) }}" required placeholder="Home 30 Mbps"></label>
            <label><span>MikroTik profile</span><input name="mikrotik_profile" value="{{ old('mikrotik_profile', $package->mikrotik_profile) }}" required placeholder="home-30m"><small>Must match the profile identity used for router sync.</small></label>
        </div>
    </div>
    <div class="editor-section">
        <div class="editor-section-copy"><span>02</span><div><strong>Speed and billing</strong><small>Set the rate, customer price and renewal period.</small></div></div>
        <div class="editor-fields three">
            <label><span>Rate limit</span><input name="rate_limit" value="{{ old('rate_limit', $package->rate_limit) }}" placeholder="30M/30M"><small>RouterOS upload/download format.</small></label>
            <label><span>Price</span><div class="editor-input-prefix"><b>₹</b><input type="number" step="0.01" min="0" name="price" value="{{ old('price', $package->price ?? 0) }}" required></div></label>
            <label><span>Validity</span><div class="editor-input-suffix"><input type="number" min="1" name="validity_days" value="{{ old('validity_days', $package->validity_days ?: 30) }}" required><b>days</b></div></label>
        </div>
    </div>
    <div class="editor-footer">
        <label class="editor-toggle"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $package->exists ? $package->is_active : true))><span></span><div><strong>Package active</strong><small>Available when adding or editing customers.</small></div></label>
        <div><a class="button secondary" href="{{ route('packages.index') }}">Cancel</a><button class="button primary">Save package</button></div>
    </div>
</form>
@endsection
