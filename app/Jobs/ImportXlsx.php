<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\XlsxImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportXlsx implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public int $importId) {}

    public function backoff(): array
    {
        return [5, 15];
    }

    public function handle(XlsxImporter $importer): void
    {
        $importer->run(Import::findOrFail($this->importId));
    }

    public function failed(?Throwable $exception): void
    {
        Import::whereKey($this->importId)->where('status', '!=', 'completed')->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error' => $exception ? mb_substr($exception->getMessage(), 0, 2000) : 'Queue processing failed.',
        ]);
    }
}
