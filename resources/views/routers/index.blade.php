@extends('layouts.admin')
@section('title', 'Routers')
@section('eyebrow', 'Infrastructure')
@section('content')
<div class="page-actions"><div><h2>MikroTik routers</h2><p>Central RADIUS authentication with REST used for health checks and session disconnects.</p></div><a class="button primary" href="{{ route('routers.create') }}">+ Add router</a></div>
<article class="panel"><div class="table-wrap"><table><thead><tr><th>Router</th><th>Endpoint</th><th>Customers</th><th>Last connection</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($routers as $router)<tr>
    <td><strong>{{ $router->name }}</strong><small>{{ $router->username }}</small></td>
    <td>{{ $router->use_ssl ? 'https' : 'http' }}://{{ $router->host }}:{{ $router->port }}</td>
    <td>{{ $router->customers_count }}</td><td>{{ $router->last_connected_at?->diffForHumans() ?? 'Never tested' }}</td>
    <td><span class="badge {{ $router->is_active ? '' : 'off' }}">{{ $router->is_active ? 'Active' : 'Disabled' }}</span><small>RADIUS: {{ $router->radius_enabled ? 'On' : 'Off' }}</small></td>
    <td><div class="actions"><form method="POST" action="{{ route('routers.test', $router) }}">@csrf<button class="button small secondary">Test</button></form><a class="icon-button" href="{{ route('routers.edit', $router) }}">Edit</a><form class="inline" data-confirm="Delete this router?" method="POST" action="{{ route('routers.destroy', $router) }}">@csrf @method('DELETE')<button class="icon-button">Delete</button></form></div></td>
</tr>@empty<tr><td colspan="6" class="empty">No router configured. Add your first MikroTik router.</td></tr>@endforelse
</tbody></table></div></article>
@endsection
