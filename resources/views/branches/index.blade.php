@extends('layouts.admin')
@section('title', 'Branches')
@section('eyebrow', 'Subscriber organization')
@section('content')
<div class="page-actions"><div><h2>Customer branches</h2><p>Create the branch choices used when adding or editing customers.</p></div></div>
<form class="form-card form-grid" method="POST" action="{{ route('branches.store') }}" style="margin-bottom:20px">@csrf
    <label>Branch name<input name="name" value="{{ old('name') }}" required maxlength="100" placeholder="e.g. Ngopa"></label>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))> Available for customer selection</label>
    <div class="form-actions"><button class="button primary">+ Add branch</button></div>
</form>
<article class="panel"><div class="table-wrap"><table><thead><tr><th>Branch</th><th>Status</th><th>Customers</th><th></th></tr></thead><tbody>
@forelse($branches as $branch)<tr>
    <td><form id="branch-update-{{ $branch->id }}" method="POST" action="{{ route('branches.update', $branch) }}">@csrf @method('PUT')</form><input form="branch-update-{{ $branch->id }}" name="name" value="{{ $branch->name }}" required maxlength="100" aria-label="Branch name"></td>
    <td><label class="check"><input form="branch-update-{{ $branch->id }}" type="checkbox" name="is_active" value="1" @checked($branch->is_active)> {{ $branch->is_active ? 'Active' : 'Hidden' }}</label></td>
    <td>{{ $branch->customers_count }}</td>
    <td><div class="actions"><button form="branch-update-{{ $branch->id }}" class="icon-button">Save</button><form data-confirm="Delete this branch?" method="POST" action="{{ route('branches.destroy', $branch) }}">@csrf @method('DELETE')<button class="icon-button">Delete</button></form></div></td>
</tr>@empty<tr><td colspan="4" class="empty">No branch created yet.</td></tr>@endforelse
</tbody></table></div></article>
@endsection
