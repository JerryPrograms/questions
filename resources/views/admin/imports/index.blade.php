@extends('layouts.portal')
@section('title', 'Uploaded files')
@section('content-class', 'content-wide')
@section('content')
<div class="page-heading"><div><span class="eyebrow">ACCOUNTING DATA</span><h1>Uploaded files</h1><p>Select an import to review its data.</p></div><a class="button button-primary" href="{{ route('admin.imports.create') }}">+ Import Excel</a></div>
<section class="panel data-panel data-table-panel">
<div class="table-scroll"><table class="data-table display" data-server-table="imports" data-url="{{ route('admin.imports.data') }}" data-show-url="{{ route('admin.imports.show', '__id__') }}" data-export-url="{{ route('admin.imports.export', '__id__') }}"><thead><tr><th>File name</th><th>Rows</th><th>Uploaded</th><th>Action</th></tr></thead><tbody></tbody></table></div>
</section>
@endsection
