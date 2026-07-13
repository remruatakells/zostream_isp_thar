<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="{{ route('dashboard') }}">
            <span class="brand-mark">ZS</span>
            <span><strong>ZoStream</strong><small>ISP CONTROL</small></span>
        </a>
        <nav>
            <a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><span>⌂</span>Overview</a>
            <a class="{{ request()->routeIs('customers.*') ? 'active' : '' }}" href="{{ route('customers.index') }}"><span>♙</span>Customers</a>
            <a class="{{ request()->routeIs('packages.*') ? 'active' : '' }}" href="{{ route('packages.index') }}"><span>◇</span>Packages</a>
            <a class="{{ request()->routeIs('payments.*') ? 'active' : '' }}" href="{{ route('payments.index') }}"><span>₹</span>Payments</a>
            <a class="{{ request()->routeIs('routers.*') ? 'active' : '' }}" href="{{ route('routers.index') }}"><span>⌁</span>Routers</a>
        </nav>
        <div class="sidebar-foot">
            <div class="user-chip"><span>{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></div></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit">Sign out</button></form>
        </div>
    </aside>
    <div class="backdrop" id="backdrop"></div>
    <main class="main">
        <header class="topbar">
            <button class="menu-button" id="menuButton" aria-label="Open menu">☰</button>
            <div><p>@yield('eyebrow', 'ISP Operations')</p><h1>@yield('title', 'Dashboard')</h1></div>
            <div class="live-pill"><i></i> System ready</div>
        </header>
        <div class="content">
            @foreach (['success', 'warning', 'error'] as $type)
                @if (session($type)) <div class="alert {{ $type }}">{{ session($type) }}</div> @endif
            @endforeach
            @if ($errors->any())
                <div class="alert error"><strong>Please check the form:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @yield('content')
        </div>
    </main>
</div>
<script src="{{ asset('js/admin.js') }}"></script>
@stack('scripts')
</body>
</html>
