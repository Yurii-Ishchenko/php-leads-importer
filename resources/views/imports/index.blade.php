@extends('layout')
@section('content')
    <h1>Lead Import</h1>
    <p class="muted">Upload an XLSX file. Processing runs in the background, and progress updates here automatically.</p>
    <section class="card">
        <form id="import-form" method="post" action="{{ route('imports.store') }}" enctype="multipart/form-data">
            @csrf
            <label for="file">XLSX file, up to 32 MB</label>
            <input type="file" name="file" id="file" accept=".xlsx" required>
            <p id="upload-errors" class="error messages" role="alert" hidden></p>
            <div><button type="submit">Upload and Import</button></div>
        </form>
        <details style="margin-top: 20px"><summary>Spreadsheet format</summary>
            <p>Data is read from the first worksheet. The first row must contain these columns in this order:</p>
            <code>{{ implode(', ', \App\Services\XlsxImporter::COLUMNS) }}</code>
            <p>Each row is a separate lead. Duplicate external_id values are preserved. Empty values are allowed except for external_id.</p>
        </details>
    </section>
    @include('imports.progress', ['import' => null])
    <h2>Import History</h2>
    <div class="card scroll">
        <table><thead><tr><th>File</th><th>Status</th><th>Rows Imported</th><th>Uploaded At</th></tr></thead><tbody data-import-history>
        @forelse($imports as $import)
            <tr data-import-id="{{ $import->id }}"><td><a href="{{ route('imports.show', $import) }}">{{ $import->original_name }}</a></td><td data-history-status>{{ $import->status }}</td><td data-history-processed>{{ number_format($import->processed_rows, 0, '.', ',') }}</td><td>{{ $import->created_at }}</td></tr>
        @empty
            <tr data-empty-history><td colspan="4">No imports yet. Upload your first file.</td></tr>
        @endforelse
        </tbody></table>
        {{ $imports->links() }}
    </div>
@endsection
