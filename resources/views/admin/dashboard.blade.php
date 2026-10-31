@extends('layouts.portal')
@php($isAdmin = auth()->user()->role === 'admin')

@section('title', 'Overview')
@section('content-class', 'content-wide')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">QUESTIONS</span><h1>Overview</h1><p>Review the number of imported questions available at each location.</p></div></div>
    <div class="stats-grid" aria-label="Question totals">
        <section class="stat-card"><span>Total locations</span><strong data-stat-value="locations">{{ number_format($stats->total_locations) }}</strong></section>
        <section class="stat-card"><span>Total questions</span><strong data-stat-value="questions">{{ number_format($stats->total_questions) }}</strong></section>
        <section class="stat-card"><span>Answered</span><strong data-stat-value="answered">{{ number_format($stats->answered_questions ?? 0) }}</strong></section>
        <section class="stat-card"><span>Pending</span><strong data-stat-value="pending">{{ number_format($stats->pending_questions ?? 0) }}</strong></section>
    </div>
    <div class="location-answer-actions">
        <button class="button button-primary" type="button" data-answer-selected-locations disabled>Answer selected locations</button>
        <a class="button button-primary" href="{{ route($isAdmin ? 'admin.questions.index' : 'manager.questions.index', ['all' => 1]) }}">Answer all locations</a>
        <span data-selected-location-count>0 locations selected</span>
    </div>
    <section class="panel data-panel data-table-panel">
        <div class="table-scroll"><table class="data-table display" data-server-table="dashboard-locations" data-url="{{ route($isAdmin ? 'admin.dashboard.data' : 'manager.dashboard.data') }}" data-answer-url="{{ route($isAdmin ? 'admin.questions.index' : 'manager.questions.index') }}"><thead><tr><th><span class="sr-only">Select</span><input type="checkbox" data-select-location-page aria-label="Select all locations on this page"></th><th>Location</th><th>Total</th><th>Answered</th><th>Pending</th><th>Action</th></tr></thead><tbody></tbody></table></div>
    </section>
@endsection
