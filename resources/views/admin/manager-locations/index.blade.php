@extends('layouts.portal')

@section('title', 'Manager locations')
@section('content-class', 'content-wide')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">ACCESS CONTROL</span><h1>Manager locations</h1><p>Select a manager, then choose which imported locations they can access.</p></div></div>
    @if (session('status'))<div class="success-message" role="status">{{ session('status') }}</div>@endif

    <section class="panel manager-picker">
        <form method="GET" action="{{ route('admin.manager-locations.index') }}" class="form-stack">
            <div class="field"><label for="manager">Manager</label><select id="manager" name="manager" required data-manager-select><option value="">Choose a manager</option>@foreach ($managers as $manager)<option value="{{ $manager->id }}" @selected($selectedManager?->is($manager))>{{ $manager->name }} — {{ $manager->email }}</option>@endforeach</select></div>
            <button class="button button-primary" type="submit">Show locations</button>
        </form>
    </section>

    @if ($selectedManager)
        <section class="panel location-assignment-panel" data-location-form>
            <div class="panel-heading"><div><span class="eyebrow">SELECTED MANAGER</span><h3>{{ $selectedManager->name }}</h3><p class="muted">{{ $selectedManager->email }}</p></div><span class="status-pill">{{ $assignedLocations->count() }} assigned</span></div>
            <form method="POST" action="{{ route('admin.managers.locations.update', $selectedManager) }}" class="form-stack">
                @csrf
                <div class="field"><label for="location-search">Search locations</label><input id="location-search" type="search" placeholder="Type a location name..." autocomplete="off" data-location-search></div>
                <fieldset class="location-options"><legend>Allowed locations</legend>
                    @forelse ($locations as $location)
                        <label data-location-option><input type="checkbox" name="locations[]" value="{{ $location }}" @checked($assignedLocations->contains($location))> <span>{{ $location }}</span></label>
                    @empty
                        <p class="muted">Import accounting data before assigning locations.</p>
                    @endforelse
                    <p class="muted" data-location-empty hidden>No locations match your search.</p>
                </fieldset>
                @error('locations.*')<span class="field-error" role="alert">{{ $message }}</span>@enderror
                <button class="button button-primary" type="submit">Save locations</button>
            </form>
        </section>
    @else
        <section class="panel"><div class="empty-state"><strong>Select a manager</strong><p>The manager's available locations will appear here.</p></div></section>
    @endif

    <div class="section-heading assignment-summary-heading"><div><span class="eyebrow">CURRENT ACCESS</span><h2>Location assignments</h2></div></div>
    <section class="panel data-panel data-table-panel">
        <div class="table-scroll"><table class="data-table display" data-server-table="location-assignments" data-url="{{ route('admin.manager-locations.data') }}"><thead><tr><th>Manager</th><th>Email</th><th>Locations</th></tr></thead><tbody></tbody></table></div>
    </section>

    <div class="section-heading assignment-summary-heading"><div><span class="eyebrow">TEAM</span><h2>Create manager</h2></div></div>
    <section class="panel create-panel manager-create-panel"><div class="panel-heading"><div><h3>New manager</h3></div></div><p class="panel-description">Create an account, then assign its locations above.</p>
        <form method="POST" action="{{ route('admin.managers.store') }}" class="form-stack" data-ajax>@csrf
            <div class="field"><label for="name">Full name</label><input id="name" name="name" type="text" autocomplete="name" value="{{ old('name') }}" required><span class="field-error" data-error-for="name" role="alert">@error('name'){{ $message }}@enderror</span></div>
            <div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="off" value="{{ old('email') }}" required><span class="field-error" data-error-for="email" role="alert">@error('email'){{ $message }}@enderror</span></div>
            <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required><span class="field-error" data-error-for="password" role="alert">@error('password'){{ $message }}@enderror</span></div>
            <div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required></div>
            <span class="form-error" data-form-error role="alert"></span>
            <button class="button button-primary" type="submit">Create manager <span aria-hidden="true">→</span></button>
        </form>
    </section>

@endsection
