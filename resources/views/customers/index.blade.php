@extends('layouts.admin')
@section('title', 'Customers')
@section('eyebrow', 'Subscriber management')

@section('content')
@php
    $visibleCustomers = $customers->getCollection();
    $filterKeys = auth()->user()->isBranchOperator()
        ? ['search', 'status']
        : ['search', 'router_id', 'branch_id', 'status'];
    $activeFilters = collect($filterKeys)
        ->filter(fn ($key) => request()->filled($key))
        ->count();
@endphp

<section class="customers-hero">
    <div class="customers-hero-copy">
        <span>SUBSCRIBER CONTROL</span>
        <h2>PPPoE subscribers</h2>
        <p>Create, renew, suspend and monitor customer access from one familiar workspace.</p>
    </div>
    <div class="customers-hero-actions">
        @if(auth()->user()->isAdmin())
            <div class="customer-import-menu">
                <a class="customer-hero-button subtle" href="{{ route('customers.import-mikrotik.create') }}">
                    <i aria-hidden="true">⇄</i>
                    <span><small>ROUTER DATA</small>Import MikroTik</span>
                </a>
                <a class="customer-hero-button subtle" href="{{ route('customers.import.create') }}">
                    <i aria-hidden="true">↥</i>
                    <span><small>SPREADSHEET</small>Import Excel</span>
                </a>
            </div>
        @endif
        <a class="customer-hero-button primary" href="{{ route('customers.create') }}">
            <i aria-hidden="true">+</i>
            <span><small>NEW SUBSCRIBER</small>Add customer</span>
        </a>
    </div>
</section>

<section class="customer-filter-card">
    <div class="customer-filter-heading">
        <div>
            <span>FIND CUSTOMERS</span>
            <strong>Search and filter</strong>
        </div>
        @if($activeFilters)
            <a href="{{ route('customers.index') }}">Clear {{ $activeFilters }} {{ Str::plural('filter', $activeFilters) }}</a>
        @endif
    </div>

    <div class="customer-filter-workspace">
        <form class="customer-filter-form {{ auth()->user()->isBranchOperator() ? 'operator-filter' : '' }}" method="GET">
            <label class="customer-search-field">
                <span aria-hidden="true">⌕</span>
                <input name="search" value="{{ request('search') }}" placeholder="Search name, phone, username or branch">
            </label>
            @if(auth()->user()->isAdmin())
                <label>
                    <span>Router</span>
                    <select name="router_id">
                        <option value="">All routers</option>
                        @foreach($routers as $router)
                            <option value="{{ $router->id }}" @selected((string) request('router_id') === (string) $router->id)>{{ $router->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Branch</span>
                    <select name="branch_id">
                        <option value="">All branches</option>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) request('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label>
                <span>Status</span>
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="online" @selected(request('status') === 'online')>Online</option>
                    <option value="offline" @selected(request('status') === 'offline')>Offline</option>
                    <option value="expired" @selected(request('status') === 'expired')>Expired</option>
                    <option value="suspended" @selected(request('status') === 'suspended')>Suspended</option>
                    <option value="unknown" @selected(request('status') === 'unknown')>Unknown / router unreachable</option>
                </select>
            </label>
            <button class="customer-filter-button" type="submit">Apply filters</button>
        </form>

        <form class="bulk-sync-form" method="POST" action="{{ route('customers.sync-all') }}" data-confirm="Sync all {{ $customers->total() }} customers matching the current filters to RADIUS?">
            @csrf
            <input type="hidden" name="search" value="{{ request('search') }}">
            @if(auth()->user()->isAdmin())
                <input type="hidden" name="router_id" value="{{ request('router_id') }}">
                <input type="hidden" name="branch_id" value="{{ request('branch_id') }}">
            @endif
            <input type="hidden" name="status" value="{{ request('status') }}">
            <button class="customer-sync-all" @disabled($customers->total() === 0)>
                <i aria-hidden="true">↻</i>
                <span>Sync all <b>{{ $customers->total() }}</b></span>
            </button>
        </form>
    </div>
</section>

<div class="customer-list-heading">
    <div>
        <span>CUSTOMER DIRECTORY</span>
        <h3>{{ number_format($customers->total()) }} {{ Str::plural('customer', $customers->total()) }}</h3>
        <p>
            @if($activeFilters)
                Showing customers matching the selected filters.
            @else
                Manage subscriptions, RADIUS access and usage.
            @endif
        </p>
    </div>
    <span class="customer-page-count">Page {{ $customers->currentPage() }} of {{ max($customers->lastPage(), 1) }}</span>
</div>

<section class="customer-card-list">
    @forelse($customers as $customer)
        @php
            $isExpired = $customer->expires_at?->lt(today()) ?? false;
            $displayStatus = $customer->status === 'suspended'
                ? 'suspended'
                : ($isExpired ? 'expired' : $customer->status);
            $initials = collect(preg_split('/\s+/', trim($customer->name)))
                ->filter()
                ->take(2)
                ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                ->implode('');
        @endphp
        <article class="customer-card {{ auth()->user()->isBranchOperator() ? 'operator-customer-card' : '' }}">
            <div class="customer-main">
                <div class="customer-avatar">{{ $initials ?: '?' }}</div>
                <div class="customer-identity">
                    <div class="customer-name-line">
                        <h3>{{ $customer->name }}</h3>
                        <span class="customer-status status-{{ $displayStatus }}"><i></i>{{ ucfirst($displayStatus) }}</span>
                    </div>
                    <code>{{ $customer->username }}</code>
                    <span>{{ $customer->phone ?: 'No phone' }}</span>
                </div>
            </div>

            @if(auth()->user()->isAdmin())
                <div class="customer-plan-block">
                    <small>PACKAGE</small>
                    <strong>{{ $customer->package?->name ?? 'No package' }}</strong>
                    <span>{{ $customer->package?->rate_limit ?: 'Unlimited speed' }}</span>
                </div>

                <div class="customer-location-block">
                    <div>
                        <small>ROUTER</small>
                        <strong>{{ $customer->router?->name ?? 'Not assigned' }}</strong>
                    </div>
                    <div>
                        <small>BRANCH</small>
                        <strong>{{ $customer->branch?->name ?? 'No branch' }}</strong>
                    </div>
                </div>
            @endif

            <div class="customer-usage-block">
                <small>DATA USAGE</small>
                <div>
                    <span class="usage-download"><strong>↓ {{ \Illuminate\Support\Number::fileSize($customer->usage_download_bytes, 2) }}</strong></span>
                    <span class="usage-upload"><strong>↑ {{ \Illuminate\Support\Number::fileSize($customer->usage_upload_bytes, 2) }}</strong></span>
                </div>
                <em>{{ $customer->usage_last_at?->diffForHumans() ?? 'No accounting data' }}</em>
            </div>

            @if(auth()->user()->isAdmin())
                <div class="customer-expiry-block">
                    <small>EXPIRY</small>
                    <strong class="{{ $isExpired ? 'is-expired' : '' }}">{{ $customer->expires_at?->format('d M Y') ?? 'No expiry' }}</strong>
                    <span>{{ $customer->last_synced_at ? 'Synced '.$customer->last_synced_at->diffForHumans() : 'Not synced' }}</span>
                </div>
            @endif

            <div class="customer-actions">
                <a class="customer-action pay" href="{{ route('payments.index', ['customer' => $customer]) }}">
                    <i aria-hidden="true">₹</i><span>Pay</span>
                </a>
                <form method="POST" action="{{ route('customers.toggle', $customer) }}">
                    @csrf
                    <button class="customer-action {{ $customer->status === 'active' ? 'suspend' : 'activate' }}" type="submit">
                        <i aria-hidden="true">{{ $customer->status === 'active' ? 'Ⅱ' : '▶' }}</i>
                        <span>{{ $customer->status === 'active' ? 'Suspend' : 'Activate' }}</span>
                    </button>
                </form>
                @if(auth()->user()->isAdmin())
                    <form method="POST" action="{{ route('customers.sync', $customer) }}">
                        @csrf
                        <button class="customer-action" type="submit"><i aria-hidden="true">↻</i><span>Sync</span></button>
                    </form>
                @endif
                <a class="customer-action" href="{{ route('customers.edit', ['customer' => $customer, 'return_to' => request()->fullUrl()]) }}">
                    <i aria-hidden="true">✎</i><span>Edit</span>
                </a>
                <form data-confirm="Delete this customer from both the admin panel and RADIUS?" method="POST" action="{{ route('customers.destroy', $customer) }}">
                    @csrf
                    @method('DELETE')
                    <button class="customer-action delete" type="submit"><i aria-hidden="true">×</i><span>Delete</span></button>
                </form>
            </div>
        </article>
    @empty
        <article class="customer-empty-card">
            <span>♙</span>
            <strong>No customer found</strong>
            <p>Try clearing the filters or add a new PPPoE subscriber.</p>
            @if($activeFilters)
                <a class="button secondary" href="{{ route('customers.index') }}">Clear filters</a>
            @else
                <a class="button primary" href="{{ route('customers.create') }}">+ Add customer</a>
            @endif
        </article>
    @endforelse
</section>

<div class="pagination customer-pagination">{{ $customers->links() }}</div>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.querySelector('.bulk-sync-form');
    if (!form) return;
    const button = form.querySelector('button');
    const buttonLabel = button.querySelector('span');
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
                buttonLabel.textContent = `Syncing ${result.processed}/${result.total} · ${failed} failed`;
            }
            window.location.reload();
        } catch (error) {
            running = false;
            button.disabled = false;
            buttonLabel.textContent = `Retry sync · ${synced} synced, ${failed} failed`;
            console.error(error);
        }
    });
})();
</script>
@endpush
