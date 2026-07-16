@extends('layouts.admin')
@section('title', 'Admin users')
@section('eyebrow', 'Access control')
@section('content')
<div class="page-actions"><div><h2>Panel users</h2><p>Manage administrators and branch-restricted operators.</p></div><a class="button primary" href="{{ route('users.create') }}">+ Add user</a></div>
<article class="panel"><div class="table-wrap"><table><thead><tr><th>User</th><th>Role</th><th>Branch</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($users as $panelUser)<tr>
    <td><strong>{{ $panelUser->name }}</strong><small>{{ $panelUser->email }}</small></td>
    <td>{{ $panelUser->isAdmin() ? 'Administrator' : 'Branch operator' }}</td>
    <td>{{ $panelUser->branch?->name ?? 'All branches' }}</td>
    <td><span class="badge {{ $panelUser->is_active ? '' : 'off' }}">{{ $panelUser->is_active ? 'Active' : 'Disabled' }}</span></td>
    <td><div class="actions"><a class="icon-button" href="{{ route('users.edit', $panelUser) }}">Edit</a><form data-confirm="Delete this panel user?" method="POST" action="{{ route('users.destroy', $panelUser) }}">@csrf @method('DELETE')<button class="icon-button">Delete</button></form></div></td>
</tr>@empty<tr><td colspan="5" class="empty">No panel user found.</td></tr>@endforelse
</tbody></table></div></article>
@endsection
