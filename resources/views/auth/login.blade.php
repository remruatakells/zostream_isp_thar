<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · {{ config('app.name') }}</title><link rel="icon" type="image/jpeg" href="{{ asset('images/favicon.jpeg') }}"><link rel="apple-touch-icon" href="{{ asset('images/zostream-logo.jpeg') }}"><link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}"></head>
<body class="login-page">
<main class="login-card">
    <div class="login-brand"><img class="brand-logo" src="{{ asset('images/zostream-logo.jpeg') }}" alt="ZoStream logo"><div><strong>ZoStream ISP</strong><small>NETWORK OPERATIONS</small></div></div>
    <div class="login-copy"><span>SECURE ADMIN</span><h1>Welcome back.</h1><p>Sign in to manage customers, packages, collections and MikroTik routers.</p></div>
    @if ($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('login.store') }}" class="form-stack">@csrf
        <label>Email address<input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="admin@example.com"></label>
        <label>Password<input type="password" name="password" required autocomplete="current-password" placeholder="••••••••••••"></label>
        <label class="check"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
        <button class="button primary wide" type="submit">Sign in to dashboard <span>→</span></button>
    </form>
    <p class="login-foot">Credentials stay on your server. Router passwords are encrypted.</p>
</main>
</body></html>
