@extends('layouts.admin')
@section('title', 'Internet packages')
@section('eyebrow', 'Product catalog')
@section('content')
<div class="page-actions"><div><h2>Speed plans</h2><p>Map billing plans to MikroTik PPP profiles.</p></div><a class="button primary" href="{{ route('packages.create') }}">+ New package</a></div>
<article class="panel"><div class="table-wrap"><table><thead><tr><th>Package</th><th>Profile</th><th>Speed</th><th>Price</th><th>Validity</th><th>Subscribers</th><th></th></tr></thead><tbody>
@forelse($packages as $package)<tr>
    <td><strong>{{ $package->name }}</strong><small><span class="badge {{ $package->is_active ? '' : 'off' }}">{{ $package->is_active ? 'Active' : 'Hidden' }}</span></small></td>
    <td><code>{{ $package->mikrotik_profile }}</code></td><td>{{ $package->rate_limit ?: 'Unlimited' }}</td><td>₹{{ number_format($package->price, 0) }}</td><td>{{ $package->validity_days }} days</td><td>{{ $package->customers_count }}</td>
    <td><div class="actions"><form method="POST" action="{{ route('packages.sync', $package) }}">@csrf<button class="button small secondary">Sync</button></form><a class="icon-button" href="{{ route('packages.edit', $package) }}">Edit</a><form data-confirm="Delete this package?" method="POST" action="{{ route('packages.destroy', $package) }}">@csrf @method('DELETE')<button class="icon-button">Delete</button></form></div></td>
</tr>@empty<tr><td colspan="7" class="empty">No package created yet.</td></tr>@endforelse
</tbody></table></div></article>
@endsection
