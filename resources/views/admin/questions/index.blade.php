@php($locationTitle = $allLocations ? 'All locations' : (count($locations) === 1 ? $locations[0] : count($locations).' locations'))
@php($selection = $allLocations ? ['all' => 1] : ['locations' => $locations])
@php($isAdmin = auth()->user()->can('admin'))
@extends('layouts.portal')

@section('title', 'Questions — '.$locationTitle)
@section('content-class', 'content-wide questions-content')

@section('content')
    <div class="page-heading questions-heading"><div><span class="eyebrow">LOCATION QUESTIONS</span><div class="questions-title"><h1>{{ $locationTitle }}</h1><span class="location-tags page-location-tags">@foreach($locations as $location)<button type="button" class="location-tag" data-location-filter="{{ $location }}" aria-pressed="false">{{ $location }}</button>@endforeach</span></div></div><div class="page-actions">@if($isAdmin)<a class="button" href="{{ route('admin.questions.export-answered') }}">Export all answered</a>@endif<a class="button button-primary" href="{{ route($isAdmin ? 'admin.dashboard' : 'manager.dashboard') }}">← Overview</a></div></div>
    @if (session('status'))<div class="success-message" role="status">{{ session('status') }}</div>@endif
    <div class="question-toolbar">
        <div class="question-filters" role="group" aria-label="Filter questions by status">
            <button type="button" data-question-filter="all" aria-pressed="false">All</button>
            <button type="button" data-question-filter="answered" aria-pressed="false">Answered</button>
            <button type="button" data-question-filter="pending" class="active" aria-pressed="true">Pending</button>
            @if($isAdmin)<button type="button" data-question-filter="archived" aria-pressed="false">Archived</button>@endif
        </div>
        <div class="question-status-legend" data-question-status-legend hidden aria-label="Question status legend">
            <span><i class="answered" aria-hidden="true"></i>Answered</span>
            <span><i class="pending" aria-hidden="true"></i>Pending</span>
            <span><i class="archived" aria-hidden="true"></i>Archived</span>
        </div>
        <form class="bulk-answer-form" method="POST" action="{{ route($isAdmin ? 'admin.questions.bulk-answer' : 'manager.questions.bulk-answer') }}" data-bulk-answer-form hidden>
            @csrf
            @if($allLocations)<input type="hidden" name="all" value="1">@else @foreach($locations as $location)<input type="hidden" name="locations[]" value="{{ $location }}">@endforeach @endif
            <label class="sr-only" for="bulk-answer">Answer selected questions</label>
            <input id="bulk-answer" type="text" name="answer" maxlength="10000" autocomplete="off" placeholder="Answer selected questions" required>
            <button class="button button-primary" type="submit">Apply to selected</button>
            <span class="form-error" data-form-error role="alert"></span>
            <span class="bulk-success" data-bulk-success role="status"></span>
        </form>
        @if($isAdmin)
        <section class="archive-actions-panel">
            <form method="POST" action="{{ route('admin.questions.bulk-archive') }}" data-bulk-archive-form>
                @csrf
                @if($allLocations)<input type="hidden" name="all" value="1">@else @foreach($locations as $location)<input type="hidden" name="locations[]" value="{{ $location }}">@endforeach @endif
                <button class="button archive-button" type="submit" name="scope" value="selected">Archive selected answered</button>
                <button class="button archive-button" type="submit" name="scope" value="all_answered">Archive all answered</button>
                <span class="form-error" data-form-error role="alert"></span>
                <span class="bulk-success" data-bulk-success role="status"></span>
            </form>
        </section>
        @endif
    </div>
    <section class="panel data-panel data-table-panel">
        <div class="table-scroll"><table class="data-table display" data-server-table="questions" data-status="pending" data-url="{{ route($isAdmin ? 'admin.questions.data' : 'manager.questions.data', $selection) }}" data-answer-url="{{ route($isAdmin ? 'admin.questions.answer' : 'manager.questions.answer', '__id__') }}" data-account-url="{{ route($isAdmin ? 'admin.questions.account' : 'manager.questions.account', '__id__') }}" data-paid-to-by-url="{{ route($isAdmin ? 'admin.questions.paid-to-by' : 'manager.questions.paid-to-by', '__id__') }}" @if($isAdmin)data-detail-url="{{ route('admin.questions.detail', '__id__') }}"@endif data-detail-view-url="{{ route($isAdmin ? 'admin.questions.detail.view' : 'manager.questions.detail.view', '__id__') }}" @if($isAdmin)data-archive-url="{{ route('admin.questions.archive', '__id__') }}" data-status-url="{{ route('admin.questions.status', '__id__') }}"@endif data-csrf="{{ csrf_token() }}"><thead><tr><th><label class="checkbox-hit"><input type="checkbox" data-select-page aria-label="Select all questions on this page"></label></th><th>Location</th><th>Amount</th><th>Credit/Debit</th><th>Paid to/by</th><th>Account</th><th>Detail</th><th>Status</th><th>Answer</th><th>Actions</th></tr></thead><tbody></tbody></table></div>
    </section>
    <script type="application/json" data-question-options>@json($questionOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
    <div class="modal fade answer-history-modal" id="answer-history-modal" tabindex="-1" aria-labelledby="answer-history-title" aria-hidden="true" data-answer-history-modal>
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div><h2 class="modal-title fs-5" id="answer-history-title">Answer history</h2><p class="answer-history-context" data-answer-history-context></p></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body"><div class="answer-history-list" data-answer-history-list></div></div>
            </div>
        </div>
    </div>
    <div class="modal fade detail-preview-modal" id="detail-preview-modal" tabindex="-1" aria-labelledby="detail-preview-title" aria-hidden="true" data-detail-modal>
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div><h2 class="modal-title fs-5" id="detail-preview-title">Detail preview</h2><dl class="detail-preview-meta"><div><dt>Location</dt><dd data-detail-location></dd></div><div><dt>Amount</dt><dd data-detail-amount></dd></div></dl></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body"><iframe title="Question detail preview" data-detail-preview></iframe></div>
            </div>
        </div>
    </div>
@endsection
