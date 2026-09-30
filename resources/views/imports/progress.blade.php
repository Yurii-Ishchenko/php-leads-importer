<section id="import-progress" class="card" data-status="{{ $import?->status }}" data-status-url="{{ $import ? route('imports.show', $import) : '' }}" @if(!$import) hidden @endif>
    <h2 data-progress-title>Import @if($import)#{{ $import->id }}@endif</h2>
    <dl>
        <div><dt>Status</dt><dd data-progress-status>{{ $import?->status ?? 'queued' }}</dd></div>
        <div><dt>Rows Imported</dt><dd data-progress-processed>{{ number_format($import?->processed_rows ?? 0, 0, '.', ',') }}</dd></div>
        <div><dt>Total Rows</dt><dd data-progress-total>{{ $import?->total_rows === null ? 'Counting' : number_format($import->total_rows, 0, '.', ',') }}</dd></div>
        <div><dt>Processing Time</dt><dd data-progress-duration>{{ $import?->duration_ms === null ? '—' : number_format($import->duration_ms / 1000, 3, '.', ',').' s' }}</dd></div>
    </dl>
    <p data-progress-message role="status" aria-live="polite">{{ match ($import?->status) {
        'completed' => 'Import completed successfully.',
        'failed' => 'Import failed.',
        default => 'Progress updates automatically. You can close this page while processing continues.',
    } }}</p>
    <p data-progress-error class="error messages" role="alert" @if(!$import?->error) hidden @endif>{{ $import?->error }}</p>
    <button type="button" data-retry-poll hidden>Retry Status Check</button>
    <a data-view-results href="{{ $import ? route('imports.show', $import) : '#' }}" @if($import?->status !== 'completed') hidden @endif>View Imported Leads</a>
</section>
