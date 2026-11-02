@extends('layouts.portal')
@section('title', 'Activity logs')
@section('content-class', 'content-wide')
@section('content')
<div class="page-heading audit-heading"><div><span class="eyebrow">AUDIT TRAIL</span><h1>Activity logs</h1><p>Trace important changes, access events, and question activity.</p></div><span class="audit-total">{{ number_format($logs->total()) }} events</span></div>

<section class="panel audit-filter-panel" aria-labelledby="audit-filter-heading">
    <div class="panel-heading"><h2 id="audit-filter-heading">Filter the evidence trail</h2><a class="text-link" href="{{ route('admin.audit-logs.index') }}">Clear filters</a></div>
    <form class="audit-filters" method="GET" action="{{ route('admin.audit-logs.index') }}">
        <div class="field audit-search"><label for="search">Search all details</label><input id="search" name="search" type="search" value="{{ request('search') }}" placeholder="Event, person, value, IP…"></div>
        <div class="field"><label for="action">Event</label><select id="action" name="action" data-audit-filter-select><option value="">All events</option>@foreach($actions as $action)<option value="{{ $action }}" @selected(request('action') === $action)>{{ str($action)->replace('.', ' ')->title() }}</option>@endforeach</select></div>
        <div class="field"><label for="actor">Performed by</label><select id="actor" name="actor" data-audit-filter-select><option value="">Anyone</option>@foreach($actors as $actor)<option value="{{ $actor->actor_id }}" @selected((string) request('actor') === (string) $actor->actor_id)>{{ $actor->actor_name }}</option>@endforeach</select></div>
        <div class="field"><label for="role">Role</label><select id="role" name="role" data-audit-filter-select><option value="">All roles</option><option value="admin" @selected(request('role') === 'admin')>Admin</option><option value="manager" @selected(request('role') === 'manager')>Manager</option></select></div>
        <div class="field"><label for="location">Location</label><select id="location" name="location" data-audit-filter-select><option value="">All locations</option>@foreach($locations as $location)<option value="{{ $location }}" @selected(request('location') === $location)>{{ $location }}</option>@endforeach</select></div>
        <div class="field"><label for="subject_type">Record type</label><select id="subject_type" name="subject_type" data-audit-filter-select><option value="">All records</option>@foreach($subjectTypes as $subjectType)<option value="{{ $subjectType }}" @selected(request('subject_type') === $subjectType)>{{ str($subjectType)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
        <div class="field"><label for="subject_id">Record ID</label><input id="subject_id" name="subject_id" type="number" min="1" value="{{ request('subject_id') }}" placeholder="Any ID"></div>
        <div class="field"><label for="from">From</label><input id="from" name="from" type="date" value="{{ request('from') }}"></div>
        <div class="field"><label for="to">To</label><input id="to" name="to" type="date" value="{{ request('to') }}"></div>
        <button class="button button-primary" type="submit">Apply filters</button>
    </form>
</section>

<section class="panel audit-table-panel" aria-label="Audit events">
    <div class="table-scroll">
        <table class="data-table audit-table">
            <thead><tr><th>When</th><th>Event</th><th>Performed by</th><th>Record</th><th>Details</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td class="audit-time"><time datetime="{{ $log->created_at }}">{{ \Illuminate\Support\Carbon::parse($log->created_at)->format('M j, Y') }}<small>{{ \Illuminate\Support\Carbon::parse($log->created_at)->format('g:i:s A') }}</small></time></td>
                    <td><span class="status-pill audit-event">{{ str($log->action)->after('.')->replace('_', ' ')->title() }}</span><small>{{ str($log->action)->before('.')->title() }}</small></td>
                    <td><strong>{{ $log->actor_name ?? 'System / unknown' }}</strong><small>{{ $log->actor_email ?? 'No authenticated user' }}@if($log->actor_role) · {{ ucfirst($log->actor_role) }}@endif</small></td>
                    <td><strong>{{ $log->subject_type ? str($log->subject_type)->replace('_', ' ')->title() : '—' }}{{ $log->subject_id ? ' #'.$log->subject_id : '' }}</strong>@if($log->location)<small>{{ $log->location }}</small>@endif</td>
                    <td class="audit-details"><strong>{{ $log->summary }}</strong>
                        @if($log->old_values || $log->new_values || $log->ip_address || $log->user_agent)
                        <details><summary>View technical details</summary>
                            @if($log->old_values)<div><span>Before</span><pre>{{ json_encode(json_decode($log->old_values, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>@endif
                            @if($log->new_values)<div><span>After</span><pre>{{ json_encode(json_decode($log->new_values, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>@endif
                            <p>{{ $log->ip_address ?? 'Unknown IP' }}@if($log->user_agent) · {{ $log->user_agent }}@endif</p>
                        </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="empty-cell" colspan="5"><strong>No activity matches these filters.</strong><small>Clear one or more filters and try again.</small></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())
    <nav class="audit-pagination" aria-label="Activity log pages"><span>Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span><div>@if($logs->onFirstPage())<span class="button" aria-disabled="true">Previous</span>@else<a class="button" href="{{ $logs->previousPageUrl() }}">Previous</a>@endif @if($logs->hasMorePages())<a class="button button-primary" href="{{ $logs->nextPageUrl() }}">Next</a>@else<span class="button" aria-disabled="true">Next</span>@endif</div></nav>
    @endif
</section>
@endsection
