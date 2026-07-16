@extends('layouts.admin')
@section('title', 'Branches')
@section('eyebrow', 'Subscriber organization')
@section('content')
<div class="page-actions"><div><h2>Customer branches</h2><p>Create the branch choices used when adding or editing customers.</p></div></div>
<form class="form-card form-grid" method="POST" action="{{ route('branches.store') }}" style="margin-bottom:20px">@csrf
    <label>Branch name<input name="name" value="{{ old('name') }}" required maxlength="100" placeholder="e.g. Ngopa"></label>
    <label>Default router<select name="router_id"><option value="">Choose router</option>@foreach($routers as $router)<option value="{{ $router->id }}" @selected(old('router_id') == $router->id)>{{ $router->name }}</option>@endforeach</select><small class="form-help">Branch operators' new customers will use this router automatically.</small></label>
    <label>Operator share (%)<input type="number" name="operator_percentage" value="{{ old('operator_percentage') }}" min="0" max="100" step="0.01" placeholder="Default: {{ config('services.zostream_subscription.operator_percentage', 20) }}%"><small class="form-help">Leave blank to use the global percentage.</small></label>
    <label>OTT deduction (₹)<input type="number" name="ott_deduction" value="{{ old('ott_deduction') }}" min="0" step="0.01" placeholder="No deduction"><small class="form-help">Leave blank for ₹0 deduction. Only this branch's value will be used.</small></label>
    <fieldset class="branch-package-picker full"><legend>Available packages</legend><div>@foreach($packages as $package)<label class="check"><input type="checkbox" name="package_ids[]" value="{{ $package->id }}" @checked(in_array($package->id, old('package_ids', [])))> {{ $package->name }}</label>@endforeach</div><small class="form-help">Select multiple packages. Leave all unchecked to allow every active package.</small></fieldset>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> Available for customer selection</label>
    <div class="form-actions"><button class="button primary">+ Add branch</button></div>
</form>
<article class="panel"><div class="table-wrap"><table><thead><tr><th>Branch</th><th>Default router</th><th>Operator share</th><th>OTT deduction</th><th>Packages</th><th>Status</th><th>Customers</th><th></th></tr></thead><tbody>
@forelse($branches as $branch)<tr>
    <td><form id="branch-update-{{ $branch->id }}" method="POST" action="{{ route('branches.update', $branch) }}">@csrf @method('PUT')</form><input form="branch-update-{{ $branch->id }}" name="name" value="{{ $branch->name }}" required maxlength="100" aria-label="Branch name"></td>
    <td><select form="branch-update-{{ $branch->id }}" name="router_id" aria-label="Default router"><option value="">Not assigned</option>@foreach($routers as $router)<option value="{{ $router->id }}" @selected($branch->router_id === $router->id)>{{ $router->name }}</option>@endforeach</select></td>
    <td><input form="branch-update-{{ $branch->id }}" type="number" name="operator_percentage" value="{{ $branch->operator_percentage }}" min="0" max="100" step="0.01" placeholder="{{ config('services.zostream_subscription.operator_percentage', 20) }}" aria-label="Operator percentage">%</td>
    <td>₹<input form="branch-update-{{ $branch->id }}" type="number" name="ott_deduction" value="{{ $branch->ott_deduction }}" min="0" step="0.01" placeholder="0" aria-label="OTT deduction"></td>
    <td><div class="branch-package-inline">@foreach($packages as $package)<label><input form="branch-update-{{ $branch->id }}" type="checkbox" name="package_ids[]" value="{{ $package->id }}" @checked($branch->packages->contains($package->id))> {{ $package->name }}</label>@endforeach</div><small>{{ $branch->packages->isEmpty() ? 'All active packages' : $branch->packages->count().' selected' }}</small></td>
    <td><label class="check"><input form="branch-update-{{ $branch->id }}" type="checkbox" name="is_active" value="1" @checked($branch->is_active)> {{ $branch->is_active ? 'Active' : 'Hidden' }}</label></td>
    <td>{{ $branch->customers_count }}</td>
    <td><div class="actions"><button form="branch-update-{{ $branch->id }}" class="icon-button">Save</button><form data-confirm="Delete this branch?" method="POST" action="{{ route('branches.destroy', $branch) }}">@csrf @method('DELETE')<button class="icon-button">Delete</button></form></div></td>
</tr>@empty<tr><td colspan="8" class="empty">No branch created yet.</td></tr>@endforelse
</tbody></table></div></article>
@endsection
