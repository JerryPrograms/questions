@extends('layouts.portal')
@section('title', 'Import Excel')
@section('content')
<div class="page-heading"><div><span class="eyebrow">ACCOUNTING DATA</span><h1>Import Excel</h1><p>Upload Excel or CSV transactions into your workspace.</p></div></div>
@if (session('status'))<div class="success-message" role="status">{{ session('status') }}</div>@endif
<div class="grid-two">
<section class="panel import-panel" aria-labelledby="upload-heading">
    <div class="panel-heading"><h3 id="upload-heading">Upload transactions</h3><span class="panel-note">Excel / CSV</span></div>
    <p class="panel-description">Choose an .xlsx or .csv file (up to 5 MB). All worksheets will be imported. Each sheet must have the following columns in this exact order:</p>
    <p class="panel-description"><strong>LOCATION, BANK NAME, DATE, CHECK NO, PAID TO/BY, ACCOUNT, CREDIT, DEBIT, DESCRIPTION, CLASS, TXNID</strong> <small>(TXNID is optional for older files.)</small></p>
    <form method="POST" action="{{ route('admin.imports.store') }}" enctype="multipart/form-data" class="form-stack" data-ajax>@csrf
        <div class="field"><label for="file">Excel or CSV file</label><input id="file" name="file" type="file" accept=".xlsx,.csv" required><span class="field-error" data-error-for="file" role="alert">@error('file'){{ $message }}@enderror</span></div>
        <span class="form-error" data-form-error role="alert"></span>
        <button class="button button-primary" type="submit">Import file <span aria-hidden="true">→</span></button>
    </form>
</section>
<section class="panel" aria-labelledby="recent-heading"><div class="panel-heading"><h3 id="recent-heading">Recent imports</h3><a href="{{ route('admin.imports.index') }}" class="text-link">View all files →</a></div>
    @forelse ($imports as $import)
      <a class="member-row import-row" href="{{ route('admin.imports.show', $import->id) }}"><span class="member-details"><strong>{{ $import->file_name }}</strong><small>{{ \Illuminate\Support\Carbon::parse($import->created_at)->format('M j, Y') }}</small></span><span class="count-label">{{ number_format($import->row_count) }} rows →</span></a>
    @empty <div class="empty-state"><strong>No files imported yet</strong><p>Uploaded file history will appear here.</p></div>
    @endforelse
</section>
</div>
@endsection
