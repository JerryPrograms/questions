@extends('layouts.portal')

@section('title', 'Accounts & payees')
@section('content-class', 'content-wide')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">LOCATION CODING DATA</span><h1>Accounts & payees</h1><p>Upload the account and paid to/by choices available when answering each location's questions.</p></div></div>
    @if (session('status'))<div class="success-message" role="status">{{ session('status') }}</div>@endif

    <div class="option-management-grid">
        <section class="panel import-panel option-upload-panel" aria-labelledby="option-management-heading" data-location-form>
            <div class="panel-heading"><h3 id="option-management-heading">Create one choice</h3><span class="panel-note">Account / payee</span></div>
            <form method="POST" action="{{ route('admin.question-options.single') }}" class="form-stack single-option-form" data-ajax>
                @csrf
                <div class="field"><label for="single-option-location">Location</label><select id="single-option-location" name="location" required data-location-select2><option value="">Select a location</option>@foreach($locations as $location)<option value="{{ $location }}">{{ $location }}</option>@endforeach</select><span class="field-error" data-error-for="location" role="alert"></span></div>
                <div class="field"><label for="single-option-type">Choice type</label><select id="single-option-type" name="type" required><option value="account">Account</option><option value="paid_to_by">Paid to/by</option></select><span class="field-error" data-error-for="type" role="alert"></span></div>
                <div class="field"><label for="single-option-value">Name</label><input id="single-option-value" name="value" maxlength="255" required><span class="field-error" data-error-for="value" role="alert"></span></div>
                <span class="form-error" data-form-error role="alert"></span>
                <button class="button button-primary" type="submit" @disabled($locations->isEmpty())>Create choice <span aria-hidden="true">→</span></button>
            </form>
            <div class="panel-heading"><h3>Upload location choices</h3><span class="panel-note">Excel / CSV</span></div>
            <p class="panel-description">Use two columns in this exact order. Either value may be blank, but every row must contain at least one value:</p>
            <p class="panel-description"><strong>ACCOUNT, PAID TO/BY</strong></p>
            <form method="POST" action="{{ route('admin.question-options.store') }}" enctype="multipart/form-data" class="form-stack" data-ajax>
                @csrf
                <div class="field"><label for="option-location-search">Search locations</label><input id="option-location-search" type="search" placeholder="Type a location name..." autocomplete="off" data-location-search></div>
                <fieldset class="location-options"><legend>Apply uploaded values to</legend>
                    @forelse($locations as $location)
                        <label data-location-option><input type="checkbox" name="locations[]" value="{{ $location }}"> <span>{{ $location }}</span></label>
                    @empty
                        <p class="muted">Import accounting data before uploading location choices.</p>
                    @endforelse
                    <p class="muted" data-location-empty hidden>No locations match your search.</p>
                </fieldset>
                <span class="field-error" data-error-for="locations" role="alert"></span>
                <div class="field"><label for="option-file">Excel or CSV file</label><input id="option-file" name="file" type="file" accept=".xlsx,.csv" required><span class="field-error" data-error-for="file" role="alert"></span></div>
                <span class="form-error" data-form-error role="alert"></span>
                <button class="button button-primary" type="submit" @disabled($locations->isEmpty())>Upload choices <span aria-hidden="true">→</span></button>
            </form>
        </section>

        <section class="panel option-coverage-panel" aria-labelledby="option-coverage-heading">
            <div class="panel-heading"><h3 id="option-coverage-heading">Location coverage</h3><span class="panel-note">{{ $locations->count() }} {{ str('location')->plural($locations->count()) }}</span></div>
            @forelse($locations as $location)
                @php($locationOptions = $options->get($location, collect()))
                @php($accounts = $locationOptions->where('type', 'account'))
                @php($paidToBy = $locationOptions->where('type', 'paid_to_by'))
                <details class="option-location">
                    <summary><span><strong>{{ $location }}</strong><small>{{ $accounts->count() }} accounts · {{ $paidToBy->count() }} paid to/by</small></span><span aria-hidden="true">⌄</span></summary>
                    <div class="option-value-columns">
                        <div><h4>Accounts</h4><div class="option-values">@forelse($accounts as $option)
                            <div class="option-value-row">
                                <form method="POST" action="{{ route('admin.question-options.update', $option->id) }}" data-ajax>@csrf @method('PATCH')<label class="sr-only" for="option-{{ $option->id }}">Account for {{ $location }}</label><input id="option-{{ $option->id }}" name="value" value="{{ $option->value }}" maxlength="255" required><button class="button" type="submit">Save</button><span class="field-error" data-error-for="value" role="alert"></span><span class="form-error" data-form-error role="alert"></span></form>
                                <form method="POST" action="{{ route('admin.question-options.destroy', $option->id) }}" data-ajax data-confirm="Delete this account from this location?">@csrf @method('DELETE')<button class="button option-delete-button" type="submit">Delete</button><span class="form-error" data-form-error role="alert"></span></form>
                            </div>
                        @empty<p>No accounts uploaded.</p>@endforelse</div></div>
                        <div><h4>Paid to/by</h4><div class="option-values">@forelse($paidToBy as $option)
                            <div class="option-value-row">
                                <form method="POST" action="{{ route('admin.question-options.update', $option->id) }}" data-ajax>@csrf @method('PATCH')<label class="sr-only" for="option-{{ $option->id }}">Paid to/by for {{ $location }}</label><input id="option-{{ $option->id }}" name="value" value="{{ $option->value }}" maxlength="255" required><button class="button" type="submit">Save</button><span class="field-error" data-error-for="value" role="alert"></span><span class="form-error" data-form-error role="alert"></span></form>
                                <form method="POST" action="{{ route('admin.question-options.destroy', $option->id) }}" data-ajax data-confirm="Delete this paid to/by value from this location?">@csrf @method('DELETE')<button class="button option-delete-button" type="submit">Delete</button><span class="form-error" data-form-error role="alert"></span></form>
                            </div>
                        @empty<p>No paid to/by values uploaded.</p>@endforelse</div></div>
                    </div>
                </details>
            @empty
                <div class="empty-state"><strong>No locations available</strong><p>Import accounting data before assigning account and paid to/by choices.</p></div>
            @endforelse
        </section>
    </div>
@endsection
