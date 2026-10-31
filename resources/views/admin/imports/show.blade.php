@extends('layouts.portal')
@section('title', $uploadedFile->file_name)
@section('content-class', 'content-wide')
@section('content')
<div class="page-heading"><div><span class="eyebrow">UPLOADED FILE</span><h1>{{ $uploadedFile->file_name }}</h1><p>{{ number_format($uploadedFile->row_count) }} imported rows · {{ \Illuminate\Support\Carbon::parse($uploadedFile->created_at)->format('M j, Y g:i A') }}</p></div><div class="page-actions"><a class="button" href="{{ route('admin.imports.export', $uploadedFile->id) }}">Export</a><a class="button button-primary" href="{{ route('admin.imports.index') }}">← All files</a></div></div>
@if (session('status'))<div class="success-message" role="status">{{ session('status') }}</div>@endif
<section class="panel data-panel data-table-panel">
<div class="table-scroll"><table class="data-table display" data-server-table="entries" data-url="{{ route('admin.imports.show-data', $uploadedFile->id) }}"><thead><tr><th>Date</th><th>Bank name</th><th>Check no.</th><th>Paid to/by</th><th>Account</th><th>Credit</th><th>Debit</th><th>Description</th></tr></thead><tbody></tbody></table></div>
</section>
@endsection
