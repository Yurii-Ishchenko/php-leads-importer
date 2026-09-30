<?php

namespace App\Services;

use App\Models\Import;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

class XlsxImporter
{
    public const COLUMNS = [
        'external_id', 'created_at', 'first_name', 'last_name', 'phone', 'email',
        'city', 'source', 'utm_campaign', 'product', 'budget_uah', 'status',
        'manager', 'comment', 'next_contact_at',
    ];

    private const BATCH_SIZE = 500;

    public function run(Import $import): void
    {
        if ($import->status === 'completed') {
            return;
        }

        $started = hrtime(true);
        $import->update(['status' => 'processing', 'started_at' => $import->started_at ?? now(), 'error' => null]);
        $reader = new Reader(cachingStrategyFactory: new SharedStringsCacheFactory);
        $reader->open(Storage::disk('local')->path($import->path));
        $batch = [];
        $count = 0;
        $headerFound = false;

        try {
            // The upload format uses the first worksheet.
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    $values = array_map(
                        fn (Cell $cell) => $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue(),
                        $row->getCells(),
                    );
                    if (! $headerFound) {
                        $headers = array_map(fn ($v) => trim((string) $v), $values);
                        if ($headers !== self::COLUMNS) {
                            throw new RuntimeException('Invalid column headers in the first row of the XLSX file.');
                        }
                        $headerFound = true;

                        continue;
                    }
                    if (! array_filter($values, fn ($v) => $v !== null && $v !== '')) {
                        continue;
                    }
                    $batch[] = $this->mapRow($values, $import->id, $rowNumber);
                    $count++;
                    if (count($batch) === self::BATCH_SIZE) {
                        $this->persist($import, $batch, $count);
                        $batch = [];
                    }
                }
                break;
            }
            if (! $headerFound) {
                throw new RuntimeException('The XLSX file does not contain a header row.');
            }
            if ($batch) {
                $this->persist($import, $batch, $count);
            }
            $import->update([
                'status' => 'completed',
                'processed_rows' => $count,
                'total_rows' => $count,
                'finished_at' => now(),
                'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            ]);
        } finally {
            $reader->close();
        }
    }

    private function persist(Import $import, array $batch, int $count): void
    {
        DB::transaction(function () use ($import, $batch, $count) {
            // Retries update the same source row; external_id deliberately is not unique.
            DB::table('leads')->upsert($batch, ['import_id', 'source_row'], self::COLUMNS);
            $import->update(['processed_rows' => max($import->processed_rows, $count)]);
        });
    }

    private function mapRow(array $values, int $importId, int $rowNumber): array
    {
        if (count($values) > count(self::COLUMNS)) {
            throw new RuntimeException("Unexpected extra columns in row {$rowNumber}.");
        }
        $data = ['import_id' => $importId, 'source_row' => $rowNumber];
        foreach (self::COLUMNS as $i => $column) {
            $value = $values[$i] ?? null;
            if ($value === null || $value === '') {
                $data[$column] = null;
            } elseif (in_array($column, ['created_at', 'next_contact_at'], true)) {
                $data[$column] = $this->date($value, $rowNumber);
            } elseif ($column === 'budget_uah') {
                if (! is_numeric($value) || abs((float) $value) >= 10 ** 13) {
                    throw new RuntimeException("Invalid budget in row {$rowNumber}.");
                }
                $data[$column] = number_format((float) $value, 2, '.', '');
            } else {
                $data[$column] = (string) $value;
                if ($column !== 'comment' && mb_strlen($data[$column]) > 255) {
                    throw new RuntimeException("The {$column} field is too long in row {$rowNumber}.");
                }
            }
        }
        if ($data['external_id'] === null || trim($data['external_id']) === '') {
            throw new RuntimeException("Missing external_id in row {$rowNumber}.");
        }

        return $data;
    }

    private function date(mixed $value, int $rowNumber): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        // Unstyled numeric dates use the conventional Excel 1900 calendar.
        if (is_numeric($value)) {
            return (new DateTimeImmutable('1899-12-30'))->modify('+'.(int) round((float) $value * 86400).' seconds')->format('Y-m-d H:i:s');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new RuntimeException("Invalid date in row {$rowNumber}.");
        }

        return $date->format('Y-m-d H:i:s');
    }
}
