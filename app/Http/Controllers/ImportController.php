<?php

namespace App\Http\Controllers;

use App\Jobs\ImportXlsx;
use App\Models\Import;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class ImportController extends Controller
{
    public function index(): View
    {
        return view('imports.index', ['imports' => Import::latest()->paginate(10)]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:32768'],
        ]);

        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        abort_unless($path, 500, 'Unable to save the uploaded file.');

        try {
            $import = DB::transaction(function () use ($file, $path) {
                $import = Import::create([
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'path' => $path,
                ]);
                // Commit the database queue record and import together.
                ImportXlsx::dispatch($import->id)->onConnection('database')->onQueue('imports');

                return $import;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json($this->statusPayload($import->refresh()), 202);
    }

    public function show(Request $request, Import $import): View|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json($this->statusPayload($import))
                ->header('Cache-Control', 'no-store');
        }

        return view('imports.show', ['import' => $import, 'leads' => $import->leads()->orderBy('source_row')->paginate(25)]);
    }

    private function statusPayload(Import $import): array
    {
        return [
            'id' => $import->id,
            'status' => $import->status,
            'processed_rows' => $import->processed_rows,
            'total_rows' => $import->total_rows,
            'duration_ms' => $import->duration_ms,
            'error' => $import->error,
            'original_name' => $import->original_name,
            'created_at' => $import->created_at->toDateTimeString(),
            'status_url' => route('imports.show', $import),
        ];
    }
}
