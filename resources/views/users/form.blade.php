@extends('layouts.admin')
@section('title', $panelUser->exists ? 'Edit panel user' : 'Add panel user')
@section('eyebrow', 'Access control')
@section('content')
<div class="editor-heading">
    <a href="{{ route('users.index') }}">←</a>
    <div><span>PANEL ACCESS</span><h2>{{ $panelUser->exists ? $panelUser->name : 'New panel user' }}</h2><p>Create an administrator or restrict an operator to one branch.</p></div>
</div>
<form class="module-editor" method="POST" action="{{ $panelUser->exists ? route('users.update', $panelUser) : route('users.store') }}">
    @csrf @if($panelUser->exists) @method('PUT') @endif
    <div class="editor-section">
        <div class="editor-section-copy"><span>01</span><div><strong>User identity</strong><small>Name and email used to sign in.</small></div></div>
        <div class="editor-fields">
            <label><span>Full name</span><input name="name" value="{{ old('name', $panelUser->name) }}" required maxlength="150"></label>
            <label><span>Email address</span><input type="email" name="email" value="{{ old('email', $panelUser->email) }}" required></label>
        </div>
    </div>
    <div class="editor-section">
        <div class="editor-section-copy"><span>02</span><div><strong>Role and scope</strong><small>Choose full admin access or one branch only.</small></div></div>
        <div class="editor-fields">
            <label><span>Role</span><select id="role" name="role" required><option value="admin" @selected(old('role', $panelUser->role ?: 'branch_operator') === 'admin')>Administrator</option><option value="branch_operator" @selected(old('role', $panelUser->role ?: 'branch_operator') === 'branch_operator')>Branch operator</option></select></label>
            <label id="branchField"><span>Assigned branch</span><select name="branch_id"><option value="">Choose branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) old('branch_id', $panelUser->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select><small>Operators see only customers and payments in this branch.</small></label>
        </div>
    </div>
    <div class="editor-section">
        <div class="editor-section-copy"><span>03</span><div><strong>Sign-in security</strong><small>{{ $panelUser->exists ? 'Leave both password fields blank to keep the current password.' : 'Use at least eight characters.' }}</small></div></div>
        <div class="editor-fields">
            <label><span>Password</span><input type="password" name="password" {{ $panelUser->exists ? '' : 'required' }} minlength="8" autocomplete="new-password" placeholder="{{ $panelUser->exists ? 'Keep current password' : 'At least 8 characters' }}"></label>
            <label><span>Confirm password</span><input type="password" name="password_confirmation" {{ $panelUser->exists ? '' : 'required' }} minlength="8" autocomplete="new-password"></label>
        </div>
    </div>
    <div class="editor-footer">
        <label class="editor-toggle"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $panelUser->exists ? $panelUser->is_active : true))><span></span><div><strong>Sign-in allowed</strong><small>Disable to block this account without deleting it.</small></div></label>
        <div><a class="button secondary" href="{{ route('users.index') }}">Cancel</a><button class="button primary">Save user</button></div>
    </div>
</form>
@endsection
@push('scripts')
<script>
(() => {
    const role = document.getElementById('role');
    const branch = document.getElementById('branchField');
    const update = () => branch.hidden = role.value !== 'branch_operator';
    role.addEventListener('change', update);
    update();
})();
</script>
@endpush
