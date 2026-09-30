@extends('layout')
@section('content')
    <a href="{{ route('imports.index') }}">← All Imports</a>
    <h1>Import #{{ $import->id }}</h1>
    <p class="muted filename">{{ $import->original_name }}</p>
    @include('imports.progress')
    <h2>Leads</h2>
    <div class="card scroll">
        <table><thead><tr><th>XLSX Row</th><th>External ID</th><th>First Name</th><th>Last Name</th><th>Phone</th><th>Email</th><th>Budget, UAH</th><th>Status</th></tr></thead><tbody>
        @forelse($leads as $lead)
            <tr><td>{{ $lead->source_row }}</td><td>{{ $lead->external_id }}</td><td>{{ $lead->first_name ?? '—' }}</td><td>{{ $lead->last_name ?? '—' }}</td><td>{{ $lead->phone ?? '—' }}</td><td>{{ $lead->email ?? '—' }}</td><td>{{ $lead->budget_uah ?? '—' }}</td><td>{{ $lead->status ?? '—' }}</td></tr>
        @empty
            <tr><td colspan="8">Leads will appear once processing starts.</td></tr>
        @endforelse
        </tbody></table>
        {{ $leads->links() }}
    </div>
@endsection
