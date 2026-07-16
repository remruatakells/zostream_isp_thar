@extends('layouts.admin')
@section('title', 'Admin users')
@section('eyebrow', 'Access control')

@section('content')
<section class="catalog-hero users-hero">
    <div>
        <span>ACCESS CONTROL</span>
        <h2>Panel users</h2>
        <p>Manage administrators and branch-restricted operators securely.</p>
    </div>
    <div class="catalog-hero-side">
        <div><small>USERS</small><strong>{{ $users->count() }}</strong></div>
        <div><small>ACTIVE</small><strong>{{ $users->where('is_active', true)->count() }}</strong></div>
        <a class="module-add-button" href="{{ route('users.create') }}"><i>+</i><span>Add user</span></a>
    </div>
</section>

<div class="module-list-heading">
    <div><span>TEAM DIRECTORY</span><h3>Panel access</h3><p>Roles, branch scope and sign-in status.</p></div>
    <span>{{ $users->where('role', 'branch_operator')->count() }} operators</span>
</div>

<section class="user-card-grid">
    @forelse($users as $panelUser)
        @php
            $initials = collect(preg_split('/\s+/', trim($panelUser->name)))->filter()->take(2)
                ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
        @endphp
        <article class="user-modern-card">
            <header>
                <div class="panel-user-avatar">{{ $initials ?: '?' }}</div>
                <div class="panel-user-identity"><h3>{{ $panelUser->name }}</h3><span>{{ $panelUser->email }}</span></div>
                <span class="module-state {{ $panelUser->is_active ? '' : 'disabled' }}"><i></i>{{ $panelUser->is_active ? 'Active' : 'Disabled' }}</span>
            </header>
            <div class="user-access-summary">
                <div><small>ROLE</small><strong>{{ $panelUser->isAdmin() ? 'Administrator' : 'Branch operator' }}</strong></div>
                <div><small>ACCESS SCOPE</small><strong>{{ $panelUser->branch?->name ?? 'All branches' }}</strong></div>
            </div>
            <div class="user-role-note">
                <i>{{ $panelUser->isAdmin() ? '♚' : '⌖' }}</i>
                <p>{{ $panelUser->isAdmin() ? 'Full access to customers, packages, routers, branches and panel users.' : 'Restricted to customers and payments within the assigned branch.' }}</p>
            </div>
            <footer class="module-card-footer user-footer">
                <span>{{ $panelUser->is_active ? 'Sign-in allowed' : 'Sign-in blocked' }}</span>
                <div class="module-actions">
                    <a href="{{ route('users.edit', $panelUser) }}">Edit</a>
                    <form data-confirm="Delete this panel user?" method="POST" action="{{ route('users.destroy', $panelUser) }}">@csrf @method('DELETE')<button type="submit">Delete</button></form>
                </div>
            </footer>
        </article>
    @empty
        <article class="module-empty-card"><span>♚</span><strong>No panel user found</strong><p>Add an administrator or branch operator.</p><a class="button primary" href="{{ route('users.create') }}">+ Add user</a></article>
    @endforelse
</section>
@endsection
