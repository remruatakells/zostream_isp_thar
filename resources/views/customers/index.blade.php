@extends('layouts.admin')
@section('title', 'Customers')
@section('eyebrow', 'Subscriber management')
@section('content')
<div class="page-actions"><div><h2>PPPoE subscribers</h2><p>Create, renew, suspend and sync customer access.</p></div><div class="actions"><a class="button secondary" href="{{ route('customers.import-mikrotik.create') }}">Import MikroTik</a><a class="button secondary" href="{{ route('customers.import.create') }}">Import Excel</a><a class="button primary" href="{{ route('customers.create') }}">+ Add customer</a></div></div>
<div class="filter-bar"><form class="filter-form" method="GET"><input name="search" value="{{ request('search') }}" placeholder="Search name, phone or username"><select name="router_id"><option value="">All routers</option>@foreach($routers as $router)<option value="{{ $router->id }}" @selected((string) request('router_id') === (string) $router->id)>{{ $router->name }}</option>@endforeach</select><select name="status"><option value="">All statuses</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="suspended" @selected(request('status') === 'suspended')>Suspended</option></select><button class="button secondary">Filter</button></form><form class="bulk-sync-form" method="POST" action="{{ route('customers.sync-all') }}" data-confirm="Sync all {{ $customers->total() }} customers matching the current filters to MikroTik?">@csrf<input type="hidden" name="search" value="{{ request('search') }}"><input type="hidden" name="router_id" value="{{ request('router_id') }}"><input type="hidden" name="status" value="{{ request('status') }}"><button class="button primary" @disabled($customers->total() === 0)>Sync all ({{ $customers->total() }})</button></form></div>
<article class="panel"><div class="table-wrap"><table><thead><tr><th>Customer</th><th>PPPoE username</th><th>Package</th><th>Router</th><th>Expires</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($customers as $customer)<tr>
    <td><strong>{{ $customer->name }}</strong><small>{{ $customer->phone ?: 'No phone' }}</small></td><td><code>{{ $customer->username }}</code></td><td>{{ $customer->package?->name ?? '—' }}</td><td>{{ $customer->router->name }}</td>
    <td>{{ $customer->expires_at?->format('d M Y') ?? 'No expiry' }}<small>{{ $customer->last_synced_at ? 'Synced '.$customer->last_synced_at->diffForHumans() : 'Not synced' }}</small></td>
    <td><span class="badge {{ $customer->status === 'active' ? '' : 'off' }}">{{ ucfirst($customer->status) }}</span></td>
    <td><div class="actions"><a class="button small secondary" href="{{ route('payments.index', ['customer' => $customer]) }}">Pay</a><form method="POST" action="{{ route('customers.toggle', $customer) }}">@csrf<button class="icon-button">{{ $customer->status === 'active' ? 'Suspend' : 'Activate' }}</button></form><form method="POST" action="{{ route('customers.sync', $customer) }}">@csrf<button class="icon-button">Sync</button></form><a class="icon-button" href="{{ route('customers.edit', $customer) }}">Edit</a><form data-confirm="Delete this customer from both the admin panel and MikroTik PPP Secrets?" method="POST" action="{{ route('customers.destroy', $customer) }}">@csrf @method('DELETE')<button class="icon-button">Delete</button></form></div></td>
</tr>@empty<tr><td colspan="7" class="empty">No customer found.</td></tr>@endforelse
</tbody></table></div></article><div class="pagination">{{ $customers->links() }}</div>
@endsection
@push('scripts')
<script>
(() => {
    const form = document.querySelector('.bulk-sync-form');
    if (!form) return;
    const button = form.querySelector('button');
    let afterId = 0;
    let synced = 0;
    let failed = 0;
    let running = false;

    form.addEventListener('submit', async event => {
        if (event.defaultPrevented || running) return;
        event.preventDefault();
        running = true;
        button.disabled = true;

        try {
            let hasMore = true;
            while (hasMore) {
                const data = new FormData(form);
                data.set('after_id', afterId);
                data.set('synced_total', synced);
                data.set('failed_total', failed);
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: data,
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                });
                if (!response.ok) throw new Error(`Sync request failed with HTTP ${response.status}`);
                const result = await response.json();
                afterId = result.next_after_id;
                synced = result.synced_total;
                failed = result.failed_total;
                hasMore = result.has_more;
                button.textContent = `Syncing ${result.processed}/${result.total} · ${failed} failed`;
            }
            window.location.reload();
        } catch (error) {
            running = false;
            button.disabled = false;
            button.textContent = `Retry sync · ${synced} synced, ${failed} failed`;
            console.error(error);
        }
    });
})();
</script>
@endpush
