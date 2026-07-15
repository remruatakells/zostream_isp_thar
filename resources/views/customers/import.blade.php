@extends('layouts.admin')
@section('title', 'Import customers')
@section('eyebrow', 'Subscriber management')
@section('content')
<div class="page-actions"><div><h2>Import Excel or Jaze data</h2><p>Upload customer data or a Jaze user_session_history CSV for the selected router.</p></div><a class="button secondary" href="{{ route('customers.import.template') }}">Download template</a></div>

@if(session('import_errors'))
<article class="panel import-report"><div class="panel-head"><div><span>ROW REPORT</span><h3>Skipped or failed rows</h3></div></div><div class="import-errors"><ul>@foreach(session('import_errors') as $error)<li>{{ $error }}</li>@endforeach</ul></div></article>
@endif

@if($routers->isEmpty() || $packages->isEmpty())
<div class="alert warning">Create at least one active router and one active package before importing customers.</div>
@endif

<form class="form-card form-grid" method="POST" action="{{ route('customers.import.store') }}" enctype="multipart/form-data">@csrf
    <label>Target router<select name="router_id" required><option value="">Choose router</option>@foreach($routers as $router)<option value="{{ $router->id }}" @selected(old('router_id') == $router->id)>{{ $router->name }} · {{ $router->host }}</option>@endforeach</select><small class="form-help">Every row in this upload will belong to this router.</small></label>
    <label>Default package<select name="package_id"><option value="">Automatic from CSV</option>@foreach($packages as $package)<option value="{{ $package->id }}" @selected(old('package_id') == $package->id)>{{ $package->name }} · {{ $package->rate_limit ?: 'Unlimited' }}</option>@endforeach</select><small class="form-help">Session history matches Running Package to the admin package name. Choose a fallback here when the CSV plan name is different.</small></label>
    <label class="full">Excel or CSV file<input type="file" name="file" required accept=".xlsx,.xls,.csv"><small class="form-help">Maximum 10 MB and 1,000 rows. user_session_history.csv imports customers plus NAS port, session, IP, MAC and traffic data. A missing password is saved as "password" for newly created customers; existing passwords are unchanged.</small></label>
    <label>When username already exists<select name="duplicate_action"><option value="skip" @selected(old('duplicate_action', 'skip') === 'skip')>Keep existing customer details</option><option value="update" @selected(old('duplicate_action') === 'update')>Update existing customer details</option></select><small class="form-help">Session rows and Branch are imported in either mode; Update also refreshes customer name and mobile.</small></label>
    <p class="form-help full">Customer imports are written to RADIUS automatically. Session-history customers are created as active and expire after the matched package validity period. A new session-history snapshot replaces the previous imported live snapshot for that router.</p>
    <div class="form-actions"><a class="button secondary" href="{{ route('customers.index') }}">Cancel</a><button class="button primary" @disabled($routers->isEmpty() || $packages->isEmpty())>Import customers</button></div>
</form>
@endsection
