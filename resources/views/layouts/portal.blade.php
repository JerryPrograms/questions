<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Portal') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="portal-shell">
        <header class="portal-navbar navbar navbar-expand-xl">
            <div class="portal-navbar-inner">
                <a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark">Q</span><span>QUESTIONS<span class="brand-subtitle">Workspace</span></span></a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#portal-navigation" aria-controls="portal-navigation" aria-expanded="false" aria-label="Toggle navigation"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
                <div class="collapse navbar-collapse" id="portal-navigation">
                <nav class="navbar-nav portal-nav" aria-label="Main navigation">
                <a class="nav-link {{ request()->routeIs('admin.dashboard', 'admin.questions.*', 'manager.dashboard', 'manager.questions.*') ? 'active' : '' }}" href="{{ route('dashboard') }}"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/></svg> Overview</a>
                @can('admin')
                <a class="nav-link {{ request()->routeIs('admin.manager-locations.*') ? 'active' : '' }}" href="{{ route('admin.manager-locations.index') }}"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M19 8v6M22 11h-6"/></svg> Manager locations</a>
                <a class="nav-link {{ request()->routeIs('admin.imports.create') ? 'active' : '' }}" href="{{ route('admin.imports.create') }}"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7 8l5-5 5 5M5 21h14a2 2 0 0 0 2-2v-4M3 15v4a2 2 0 0 0 2 2"/></svg> Import Excel</a>
                <a class="nav-link {{ request()->routeIs('admin.imports.index', 'admin.imports.show') ? 'active' : '' }}" href="{{ route('admin.imports.index') }}"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h8"/></svg> Uploaded files</a>
                <a class="nav-link {{ request()->routeIs('admin.question-options.*') ? 'active' : '' }}" href="{{ route('admin.question-options.index') }}"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10M8 3v6M16 9v6M10 15v6"/></svg> Accounts & payees</a>
                <a class="nav-link {{ request()->routeIs('admin.audit-logs.*') ? 'active' : '' }}" href="{{ route('admin.audit-logs.index') }}"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v4l3 2M21 12a9 9 0 1 1-9-9 9 9 0 0 1 9 9"/></svg> Activity logs</a>
                @can('super-admin')
                <a class="nav-link {{ request()->routeIs('admin.admins.*') ? 'active' : '' }}" href="{{ route('admin.admins.index') }}"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M19 8v6M22 11h-6"/></svg> Admins</a>
                @if(config('app.devoption'))<a class="nav-link {{ request()->routeIs('admin.developer-options.*') ? 'active' : '' }}" href="{{ route('admin.developer-options.index') }}"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a4 4 0 0 0-5 5L3 18v3h3l6.7-6.7a4 4 0 0 0 5-5l-2.4 2.4-3-3z"/></svg> Developer options</a>@endif
                @endcan
                @endcan
                </nav>
                <div class="navbar-account">
                    <span class="navbar-user"><span><strong>{{ auth()->user()->name }}</strong><small>{{ ucfirst(str_replace('_', ' ', auth()->user()->role)) }} access</small></span><span class="navbar-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span></span>
                    <form method="POST" action="{{ route('logout') }}" data-ajax>@csrf<button class="sign-out" type="submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg> Sign out</button><span class="form-error" data-form-error role="alert"></span></form>
                </div>
                </div>
            </div>
        </header>
        <main class="main-content">
            <div class="content-wrap @yield('content-class')">@yield('content')</div>
        </main>
    </div>
</body>
</html>
