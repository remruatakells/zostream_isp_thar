@extends('layouts.admin')
@section('title', 'Customers')
@section('eyebrow', 'Subscriber management')
@section('content')
<div class="page-actions"><div><h2>PPPoE subscribers</h2><p>Create, renew, suspend and sync customer access.</p></div><a class="button primary" href="{{ route('customers.create') }}">+ Add customer</a></div>
<form class="filter-bar" method="GET"><input name="search" value="{{ request('search') }}" placeholder="Search name, phone or username"><select name="status"><option value="">All statuses</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="suspended" @selected(request('status') === 'suspended')>Suspended</option></select><button class="button secondary">Filter</button></form>
<article class="panel"><div class="table-wrap"><table><thead><tr><th>Customer</th><th>PPPoE username</th><th>Package</th><th>Router</th><th>Expires</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($customers as $customer)<tr>
    <td><strong>{{ $customer->name }}</strong><small>{{ $customer->phone ?: 'No phone' }}</small></td><td><code>{{ $customer->username }}</code></td><td>{{ $customer->package?->name ?? '—' }}</td><td>{{ $customer->router->name }}</td>
    <td>{{ $customer->expires_at?->format('d M Y') ?? 'No expiry' }}<small>{{ $customer->last_synced_at ? 'Synced '.$customer->last_synced_at->diffForHumans() : 'Not synced' }}</small></td>
    <td><span class="badge {{ $customer->status === 'active' ? '' : 'off' }}">{{ ucfirst($customer->status) }}</span></td>
    <td><div class="actions"><a class="button small secondary" href="{{ route('payments.index', ['customer' => $customer]) }}">Pay</a><form method="POST" action="{{ route('customers.toggle', $customer) }}">@csrf<button class="icon-button">{{ $customer->status === 'active' ? 'Suspend' : 'Activate' }}</button></form><form method="POST" action="{{ route('customers.sync', $customer) }}">@csrf<button class="icon-button">Sync</button></form><a class="icon-button" href="{{ route('customers.edit', $customer) }}">Edit</a><form data-confirm="Remove this customer from the panel?" method="POST" action="{{ route('customers.destroy', $customer) }}">@csrf @method('DELETE')<button class="icon-button">Delete</button></form></div></td>
</tr>@empty<tr><td colspan="7" class="empty">No customer found.</td></tr>@endforelse
</tbody></table></div></article><div class="pagination">{{ $customers->links() }}</div>
@endsection
