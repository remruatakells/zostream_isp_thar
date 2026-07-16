@extends('layouts.admin')
@section('title', $customer->exists ? 'Edit customer' : 'Add customer')
@section('eyebrow', 'Subscriber management')
@section('content')
<div class="page-actions"><div><h2>{{ $customer->exists ? $customer->name : 'New PPPoE subscriber' }}</h2><p>Saving will also create or update the MikroTik PPP secret.</p></div></div>
@if($routers->isEmpty() || $packages->isEmpty())<div class="alert warning">You need at least one active router and one active package before adding a customer.</div>@endif
<form class="form-card form-grid" method="POST" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}">@csrf @if($customer->exists) @method('PUT') @endif
    @if($customer->exists)<input type="hidden" name="return_to" value="{{ old('return_to', $returnTo ?? route('customers.index')) }}">@endif
    <label>Full name<input name="name" value="{{ old('name', $customer->name) }}" required placeholder="Customer name"></label>
    <label>Phone<input name="phone" value="{{ old('phone', $customer->phone) }}" placeholder="+91..."></label>
    <label>Branch<select name="branch_id" @disabled(auth()->user()->isBranchOperator())><option value="">No branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) old('branch_id', $customer->branch_id ?: auth()->user()->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select>@if(auth()->user()->isBranchOperator())<input type="hidden" name="branch_id" value="{{ auth()->user()->branch_id }}"><small class="form-help">Your account is restricted to this branch.</small>@else<small class="form-help">Add or manage choices from the Branches menu.</small>@endif</label>
    @if($customer->exists)
        <label>Router<select disabled><option>{{ $customer->router->name }}</option></select><input type="hidden" name="router_id" value="{{ $customer->router_id }}"><small class="form-help">A synced customer cannot be moved silently to another router.</small></label>
    @else
        <label>Router<select name="router_id" required><option value="">Choose router</option>@foreach($routers as $router)<option value="{{ $router->id }}" @selected(old('router_id') == $router->id)>{{ $router->name }}</option>@endforeach</select></label>
    @endif
    <label>Package<select name="package_id" required><option value="">Choose package</option>@foreach($packages as $package)<option value="{{ $package->id }}" @selected(old('package_id', $customer->package_id) == $package->id)>{{ $package->name }} · ₹{{ number_format($package->price, 0) }}</option>@endforeach</select></label>
    @if($customer->exists)
        <label>PPPoE username<input value="{{ $customer->username }}" disabled><input type="hidden" name="username" value="{{ $customer->username }}"><small class="form-help">The PPPoE identity is locked to prevent an orphan secret on MikroTik. Delete and recreate the customer to change it.</small></label>
    @else
        <label>PPPoE username<input name="username" value="{{ old('username', $customer->username) }}" required autocomplete="off" placeholder="customer001"></label>
    @endif
    <label>PPPoE password<input type="password" name="password" {{ $customer->exists ? '' : 'required' }} autocomplete="new-password" placeholder="{{ $customer->exists ? 'Leave blank to keep current password' : 'PPPoE password' }}"></label>
    <label>Status<select name="status"><option value="active" @selected(old('status', $customer->status ?: 'active') === 'active')>Active</option><option value="suspended" @selected(old('status', $customer->status) === 'suspended')>Suspended</option></select></label>
    <label>Expiry date<input type="date" name="expires_at" value="{{ old('expires_at', $customer->expires_at?->format('Y-m-d')) }}"></label>
    <label class="full">Installation address<textarea name="address" placeholder="House, locality, landmark">{{ old('address', $customer->address) }}</textarea></label>
    <div class="form-actions"><a class="button secondary" href="{{ $customer->exists ? ($returnTo ?? route('customers.index')) : route('customers.index') }}">Cancel</a><button class="button primary" @disabled($routers->isEmpty() || $packages->isEmpty())>Save & sync</button></div>
</form>
@endsection
