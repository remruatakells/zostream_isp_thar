@extends('layouts.admin')
@section('title', 'Import from MikroTik')
@section('eyebrow', 'Subscriber management')
@section('content')
<div class="page-actions"><div><h2>Pull existing PPP Secrets</h2><p>Copy users already present in one MikroTik router into the admin database.</p></div><a class="button secondary" href="{{ route('customers.index') }}">Back to customers</a></div>

@if(session('import_errors'))
<article class="panel import-report"><div class="panel-head"><div><span>IMPORT REPORT</span><h3>Skipped or incomplete users</h3></div></div><div class="import-errors"><ul>@foreach(session('import_errors') as $error)<li>{{ $error }}</li>@endforeach</ul></div></article>
@endif

@if($routers->isEmpty() || $packages->isEmpty())
<div class="alert warning">Create at least one active router and package before importing PPP Secrets.</div>
@endif

<form class="form-card form-grid" method="POST" action="{{ route('customers.import-mikrotik.store') }}">@csrf
    <label>Source MikroTik<select name="router_id" required><option value="">Choose router</option>@foreach($routers as $router)<option value="{{ $router->id }}" @selected(old('router_id') == $router->id)>{{ $router->name }} · {{ $router->host }}</option>@endforeach</select><small class="form-help">Only PPP Secrets from this router will be imported.</small></label>
    <label>Fallback package<select name="fallback_package_id" required><option value="">Choose fallback package</option>@foreach($packages as $package)<option value="{{ $package->id }}" @selected(old('fallback_package_id') == $package->id)>{{ $package->name }} · {{ $package->mikrotik_profile }}</option>@endforeach</select><small class="form-help">Profiles matching a package are mapped automatically. Unknown profiles use this package.</small></label>
    <label>Existing admin customer<select name="duplicate_action"><option value="skip" @selected(old('duplicate_action', 'skip') === 'skip')>Skip existing customer</option><option value="update" @selected(old('duplicate_action') === 'update')>Update profile, password and status</option></select></label>
    <label>Default expiry date<input type="date" name="default_expires_at" value="{{ old('default_expires_at') }}"><small class="form-help">Leave blank to use each matched package validity starting today. MikroTik PPP Secrets do not contain billing expiry dates.</small></label>
    <div class="alert warning full">New customers require visible PPP passwords. The router REST user needs the <strong>sensitive</strong> policy. Imported data is not pushed back to MikroTik.</div>
    <div class="form-actions"><a class="button secondary" href="{{ route('customers.index') }}">Cancel</a><button class="button primary" @disabled($routers->isEmpty() || $packages->isEmpty())>Import from MikroTik</button></div>
</form>
@endsection
