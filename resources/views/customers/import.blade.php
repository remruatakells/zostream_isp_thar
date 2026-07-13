@extends('layouts.admin')
@section('title', 'Import customers')
@section('eyebrow', 'Subscriber management')
@section('content')
<div class="page-actions"><div><h2>Import Excel customer data</h2><p>Select one target router and package, then upload an Excel or CSV file.</p></div><a class="button secondary" href="{{ route('customers.import.template') }}">Download template</a></div>

@if(session('import_errors'))
<article class="panel import-report"><div class="panel-head"><div><span>ROW REPORT</span><h3>Skipped or failed rows</h3></div></div><div class="import-errors"><ul>@foreach(session('import_errors') as $error)<li>{{ $error }}</li>@endforeach</ul></div></article>
@endif

@if($routers->isEmpty() || $packages->isEmpty())
<div class="alert warning">Create at least one active router and one active package before importing customers.</div>
@endif

<form class="form-card form-grid" method="POST" action="{{ route('customers.import.store') }}" enctype="multipart/form-data">@csrf
    <label>Target router<select name="router_id" required><option value="">Choose router</option>@foreach($routers as $router)<option value="{{ $router->id }}" @selected(old('router_id') == $router->id)>{{ $router->name }} · {{ $router->host }}</option>@endforeach</select><small class="form-help">Every row in this upload will belong to this router.</small></label>
    <label>Package<select name="package_id" required><option value="">Choose package</option>@foreach($packages as $package)<option value="{{ $package->id }}" @selected(old('package_id') == $package->id)>{{ $package->name }} · {{ $package->rate_limit ?: 'Unlimited' }}</option>@endforeach</select></label>
    <label class="full">Excel or CSV file<input type="file" name="file" required accept=".xlsx,.xls,.csv"><small class="form-help">Maximum 10 MB and 1,000 data rows. Required columns: username and password. Supported aliases include full name, mobile, PPPoE username/password and expiry date.</small></label>
    <label>When username already exists<select name="duplicate_action"><option value="skip" @selected(old('duplicate_action', 'skip') === 'skip')>Skip existing customer</option><option value="update" @selected(old('duplicate_action') === 'update')>Update existing customer</option></select><small class="form-help">Duplicate checking is limited to the selected router.</small></label>
    <label class="check"><input type="checkbox" name="sync_to_mikrotik" value="1" @checked(old('sync_to_mikrotik'))> Sync each imported row to MikroTik immediately</label>
    <p class="form-help full">For a large file, leave immediate sync off to finish the database import quickly. Excel files contain PPPoE passwords; keep them protected and delete them after use.</p>
    <div class="form-actions"><a class="button secondary" href="{{ route('customers.index') }}">Cancel</a><button class="button primary" @disabled($routers->isEmpty() || $packages->isEmpty())>Import customers</button></div>
</form>
@endsection
