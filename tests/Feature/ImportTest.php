<?php

namespace Tests\Feature;

use App\Jobs\ImportXlsx;
use App\Models\Import;
use App\Services\XlsxImporter;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['queue.default' => 'database']);
    }

    public function test_upload_persists_file_and_a_real_queue_job_then_imports_duplicates_and_nulls(): void
    {
        $path = $this->xlsx([
            ['duplicate', new DateTimeImmutable('2026-01-02 03:04:05'), 'Alice', null, '+380123', null, null, null, null, null, 0, null, null, null, null],
            ['duplicate', null, 'Bob', null, null, null, null, null, null, null, null, null, null, 'Example', null],
        ]);
        try {
            $response = $this->post('/imports', ['file' => new UploadedFile($path, 'example.xlsx', null, null, true)], ['Accept' => 'application/json']);
            $import = Import::firstOrFail();
            $response->assertStatus(202)->assertJson([
                'id' => $import->id,
                'status' => 'queued',
                'processed_rows' => 0,
                'total_rows' => null,
                'status_url' => route('imports.show', $import),
            ]);
            $this->assertFalse($response->headers->has('Location'));
            $this->getJson(route('imports.show', $import))->assertOk()
                ->assertJsonPath('status', 'queued')->assertJsonPath('processed_rows', 0)
                ->assertJsonMissingPath('path');
            Storage::disk('local')->assertExists($import->path);
            $this->assertSame('queued', $import->status);
            $this->assertDatabaseCount('jobs', 1);
            $this->assertDatabaseCount('leads', 0);

            $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'imports', '--once' => true])->assertExitCode(0);
            $this->assertDatabaseHas('imports', ['id' => $import->id, 'status' => 'completed', 'processed_rows' => 2]);
            $this->assertDatabaseCount('leads', 2);
            $this->assertSame(2, DB::table('leads')->where('external_id', 'duplicate')->count());
            $this->assertDatabaseHas('leads', ['source_row' => 2, 'created_at' => '2026-01-02 03:04:05', 'email' => null, 'budget_uah' => 0]);
            $this->assertDatabaseHas('leads', ['source_row' => 3, 'budget_uah' => null, 'next_contact_at' => null]);
            $this->assertDatabaseCount('jobs', 0);
            $this->assertDatabaseCount('failed_jobs', 0);
            $this->getJson(route('imports.show', $import))->assertOk()->assertJson([
                'id' => $import->id, 'status' => 'completed', 'processed_rows' => 2, 'total_rows' => 2, 'error' => null,
            ]);
            $this->get('/')->assertOk()->assertSee('Upload and Import');
            $this->get(route('imports.show', $import))->assertOk()->assertSee('completed');
        } finally {
            @unlink($path);
        }
    }

    public function test_retry_after_a_committed_batch_does_not_duplicate_or_drop_rows(): void
    {
        $rows = array_fill(0, 501, array_pad(['same-id'], 15, null));
        $rows[500][0] = null;
        $rows[500][2] = 'Invalid row';
        $path = $this->xlsx($rows);
        $stored = 'imports/retry.xlsx';
        Storage::disk('local')->put($stored, file_get_contents($path));
        @unlink($path);
        $import = Import::create(['original_name' => 'retry.xlsx', 'path' => $stored]);
        try {
            app(XlsxImporter::class)->run($import);
            $this->fail('Invalid row must fail the import.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('external_id', $exception->getMessage());
        }
        $this->assertDatabaseCount('leads', 500);
        $this->assertSame(500, $import->fresh()->processed_rows);
        $this->getJson(route('imports.show', $import))->assertOk()->assertJson([
            'status' => 'processing', 'processed_rows' => 500, 'total_rows' => null,
        ]);
        $rows[500][0] = 'same-id';
        $path = $this->xlsx($rows);
        Storage::disk('local')->put($stored, file_get_contents($path));
        @unlink($path);
        app(XlsxImporter::class)->run($import->fresh());
        $this->assertDatabaseCount('leads', 501);
        $this->assertSame(501, $import->fresh()->processed_rows);
        $this->assertSame('completed', $import->fresh()->status);
        app(XlsxImporter::class)->run($import->fresh());
        $this->assertDatabaseCount('leads', 501);
    }

    public function test_invalid_headers_are_reported_as_a_failed_queue_job(): void
    {
        $path = $this->xlsx([], ['wrong_header']);
        Storage::disk('local')->put('imports/bad.xlsx', file_get_contents($path));
        @unlink($path);
        $import = Import::create(['original_name' => 'bad.xlsx', 'path' => 'imports/bad.xlsx']);
        $job = new ImportXlsx($import->id);
        $job->tries = 1;
        dispatch($job->onConnection('database')->onQueue('imports'));
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'imports', '--once' => true])->assertExitCode(0);
        $this->assertSame('failed', $import->fresh()->status);
        $this->assertSame('Invalid column headers in the first row of the XLSX file.', $import->fresh()->error);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('leads', 0);
        $this->getJson(route('imports.show', $import))->assertOk()->assertJson([
            'status' => 'failed', 'error' => 'Invalid column headers in the first row of the XLSX file.',
        ]);
    }

    public function test_missing_or_non_xlsx_upload_is_rejected_without_creating_an_import(): void
    {
        $this->postJson('/imports')->assertStatus(422)->assertJsonValidationErrors('file');
        $this->post('/imports', ['file' => UploadedFile::fake()->create('input.txt', 1, 'text/plain')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('imports', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_unknown_import_status_returns_json_404(): void
    {
        $this->getJson('/imports/999')->assertNotFound();
    }

    public function test_shared_strings_across_disk_pages_keep_unicode_and_line_breaks(): void
    {
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $row = array_pad(['repeated-id'], 15, null);
            $row[2] = 'Олександр';
            $row[5] = "user{$i}@example.test";
            $row[13] = "Коментар {$i}\nДруга строка";
            $rows[] = $row;
        }
        $path = $this->xlsx($rows);
        Storage::disk('local')->put('imports/shared.xlsx', file_get_contents($path));
        @unlink($path);
        $import = Import::create(['original_name' => 'shared.xlsx', 'path' => 'imports/shared.xlsx']);
        app(XlsxImporter::class)->run($import);
        $this->assertDatabaseCount('leads', 600);
        foreach ([0, 255, 256, 599] as $i) {
            $this->assertDatabaseHas('leads', ['source_row' => $i + 2, 'first_name' => 'Олександр', 'email' => "user{$i}@example.test", 'comment' => "Коментар {$i}\nДруга строка"]);
        }
    }

    public function test_formula_phone_uses_cached_result_instead_of_formula_text(): void
    {
        $row = array_pad(['formula-id'], 15, null);
        $row[4] = 380671234567;
        $path = $this->xlsx([$row]);
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $xml = str_replace('<v>380671234567</v>', '<f>+380671234567</f><v>380671234567</v>', $xml);
        $this->assertStringContainsString('<f>+380671234567</f>', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();
        Storage::disk('local')->put('imports/formula.xlsx', file_get_contents($path));
        @unlink($path);
        $import = Import::create(['original_name' => 'formula.xlsx', 'path' => 'imports/formula.xlsx']);
        app(XlsxImporter::class)->run($import);
        $this->assertDatabaseHas('leads', ['external_id' => 'formula-id', 'phone' => '380671234567']);
    }

    private function xlsx(array $rows, array $header = XlsxImporter::COLUMNS): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test-xlsx-');
        rename($path, $path.'.xlsx');
        $path .= '.xlsx';
        $options = new Options;
        $options->SHOULD_USE_INLINE_STRINGS = false;
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($header));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $path;
    }
}
