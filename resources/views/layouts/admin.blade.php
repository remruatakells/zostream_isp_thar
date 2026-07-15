<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>
    <script>try{if(localStorage.getItem('zostream.sidebar.collapsed')==='1')document.documentElement.classList.add('nav-collapsed')}catch(e){}</script>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="{{ route('dashboard') }}">
            <span class="brand-mark">ZS</span>
            <span class="brand-copy"><strong>ZoStream</strong><small>ISP CONTROL</small></span>
        </a>
        <nav>
            <a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}" title="Overview"><span class="nav-icon">⌂</span><span class="nav-label">Overview</span></a>
            <a class="{{ request()->routeIs('customers.*') ? 'active' : '' }}" href="{{ route('customers.index') }}" title="Customers"><span class="nav-icon">♙</span><span class="nav-label">Customers</span></a>
            <a class="{{ request()->routeIs('packages.*') ? 'active' : '' }}" href="{{ route('packages.index') }}" title="Packages"><span class="nav-icon">◇</span><span class="nav-label">Packages</span></a>
            <a class="{{ request()->routeIs('payments.*') ? 'active' : '' }}" href="{{ route('payments.index') }}" title="Payments"><span class="nav-icon">₹</span><span class="nav-label">Payments</span></a>
            <a class="{{ request()->routeIs('routers.*') ? 'active' : '' }}" href="{{ route('routers.index') }}" title="Routers"><span class="nav-icon">⌁</span><span class="nav-label">Routers</span></a>
        </nav>
        <div class="sidebar-foot">
            <div class="user-chip"><span>{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><div class="user-details"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></div></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit" title="Sign out"><span class="logout-icon">↪</span><span class="logout-label">Sign out</span></button></form>
        </div>
    </aside>
    <div class="backdrop" id="backdrop"></div>
    <main class="main">
        <header class="topbar">
            <button class="menu-button" id="menuButton" type="button" aria-label="Collapse navigation" aria-controls="sidebar" aria-expanded="true">←</button>
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
