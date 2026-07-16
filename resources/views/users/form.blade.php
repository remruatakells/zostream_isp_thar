@extends('layouts.admin')
@section('title', $panelUser->exists ? 'Edit panel user' : 'Add panel user')
@section('eyebrow', 'Access control')
@section('content')
<div class="page-actions"><div><h2>{{ $panelUser->exists ? $panelUser->name : 'New panel user' }}</h2><p>Branch operators can only work with customers and payments in their assigned branch.</p></div></div>
<form class="form-card form-grid" method="POST" action="{{ $panelUser->exists ? route('users.update', $panelUser) : route('users.store') }}">@csrf @if($panelUser->exists) @method('PUT') @endif
    <label>Full name<input name="name" value="{{ old('name', $panelUser->name) }}" required maxlength="150"></label>
    <label>Email<input type="email" name="email" value="{{ old('email', $panelUser->email) }}" required></label>
    <label>Role<select id="role" name="role" required><option value="admin" @selected(old('role', $panelUser->role ?: 'branch_operator') === 'admin')>Administrator</option><option value="branch_operator" @selected(old('role', $panelUser->role ?: 'branch_operator') === 'branch_operator')>Branch operator</option></select></label>
    <label id="branchField">Branch<select name="branch_id"><option value="">Choose branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string) old('branch_id', $panelUser->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
    <label>Password<input type="password" name="password" {{ $panelUser->exists ? '' : 'required' }} minlength="8" autocomplete="new-password" placeholder="{{ $panelUser->exists ? 'Leave blank to keep current password' : 'At least 8 characters' }}"></label>
    <label>Confirm password<input type="password" name="password_confirmation" {{ $panelUser->exists ? '' : 'required' }} minlength="8" autocomplete="new-password"></label>
    <label class="check full"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $panelUser->exists ? $panelUser->is_active : true))> Allow this user to sign in</label>
    <div class="form-actions"><a class="button secondary" href="{{ route('users.index') }}">Cancel</a><button class="button primary">Save user</button></div>
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
