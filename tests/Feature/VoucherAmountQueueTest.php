<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoucherYear;
use App\PdfAmountReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoucherAmountQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $user = User::create(['username' => 'ocr-tester', 'name' => 'OCR Tester', 'password' => 'test-password']);
        $user->forceFill(['must_change_password' => false, 'is_admin' => true])->save();
        VoucherYear::create(['year' => 2026]);
        $this->actingAs($user);
    }

    public function test_upload_queues_amount_reading_without_launching_any_process(): void
    {
        Process::fake();
        $id = $this->uploadPdf();

        $this->assertDatabaseHas('jobs', ['queue' => 'voucher-ocr']);
        $this->assertNull(session("voucher_upload.files.$id.detected_amount"));
        $this->get(route('vouchers.confirm'))->assertOk()->assertSee('Reading amount in the background');
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath("files.$id.status", 'pending');
        Process::assertNothingRan();
    }

    public function test_worker_result_appears_in_review_and_manual_corrections_are_saved(): void
    {
        $id = $this->uploadPdf();
        $this->mock(PdfAmountReader::class)->shouldReceive('read')->once()
            ->with(Storage::disk('local')->path("staging/$id.pdf"))->andReturn('5250.00');

        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'voucher-ocr', '--once' => true, '--tries' => 1])->assertSuccessful();
        $this->getJson($this->statusUrl())->assertOk()
            ->assertJsonPath("files.$id.status", 'ready')->assertJsonPath("files.$id.amount", '5250.00');
        $this->get(route('vouchers.confirm'))->assertOk()->assertSee('value="5250.00"', false);

        $this->post(route('vouchers.finalize'), ['batch_id' => session('voucher_upload.batch_id'), 'vouchers' => [$id => [
            'checkada_no' => '10779', 'dv_number' => '2026012525', 'payee' => 'PLDT INC.', 'amount' => '5300.00',
        ]]])->assertRedirect(route('vouchers.index'));

        $this->assertDatabaseHas('vouchers', ['dv_number' => '2026012525', 'amount' => '5300.00']);
        $this->assertFalse(Cache::has("voucher-amount:$id"));
    }

    public function test_a_queue_failure_keeps_the_pdf_available_for_manual_saving(): void
    {
        Schema::drop('jobs');
        $id = $this->uploadPdf();
        Storage::disk('local')->assertExists("staging/$id.pdf");
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath("files.$id.status", 'manual');
        $this->post(route('vouchers.finalize'), ['batch_id' => session('voucher_upload.batch_id'), 'vouchers' => [$id => [
            'checkada_no' => '10779', 'dv_number' => '2026012525', 'payee' => 'PLDT INC.', 'amount' => '5250.00',
        ]]])->assertRedirect(route('vouchers.index'));
        $this->assertDatabaseHas('vouchers', ['dv_number' => '2026012525', 'amount' => '5250.00']);
    }

    public function test_unreadable_amount_and_expired_results_allow_manual_entry(): void
    {
        $id = $this->uploadPdf();
        $this->mock(PdfAmountReader::class)->shouldReceive('read')->once()->andReturnNull();
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'voucher-ocr', '--once' => true])->assertSuccessful();
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath("files.$id.status", 'manual')->assertJsonPath("files.$id.amount", null);
        Cache::forget("voucher-amount:$id");
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath("files.$id.status", 'manual');
    }

    public function test_failed_worker_marks_the_amount_for_manual_entry(): void
    {
        $id = $this->uploadPdf();
        $this->mock(PdfAmountReader::class)->shouldReceive('read')->once()->andThrow(new \RuntimeException('OCR unavailable'));
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'voucher-ocr', '--once' => true, '--tries' => 1])->assertSuccessful();
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath("files.$id.status", 'manual');
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_status_only_returns_the_current_sessions_batch(): void
    {
        $this->uploadPdf();
        $url = $this->statusUrl();
        $this->getJson(route('vouchers.upload.amounts', ['batch_id' => (string) Str::uuid()]))->assertConflict();
        session()->forget('voucher_upload');
        $this->getJson($url)->assertConflict();
        auth()->logout();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_removing_a_pdf_cancels_its_pending_amount_reading(): void
    {
        $id = $this->uploadPdf();
        $this->mock(PdfAmountReader::class)->shouldNotReceive('read');
        $this->postJson(route('vouchers.upload.remove-file'), ['batch_id' => session('voucher_upload.batch_id'), 'file_id' => $id])->assertOk();
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'voucher-ocr', '--once' => true])->assertSuccessful();
        $this->assertFalse(Cache::has("voucher-amount:$id"));
        Storage::disk('local')->assertMissing("staging/$id.pdf");
    }

    public function test_status_polling_does_not_restore_removed_session_files(): void
    {
        $id = $this->uploadPdf();
        $before = session('voucher_upload');
        Cache::put("voucher-amount:$id", ['status' => 'ready', 'amount' => '5250.00'], 3600);
        $this->getJson($this->statusUrl())->assertOk()->assertJsonPath("files.$id.amount", '5250.00');
        $this->assertSame($before, session('voucher_upload'));
        $this->delete(route('vouchers.cancel-upload'), ['batch_id' => session('voucher_upload.batch_id')])->assertRedirect();
        $this->assertFalse(Cache::has("voucher-amount:$id"));
    }

    public function test_a_separate_worker_process_reads_the_scanned_sample(): void
    {
        $sample = getenv('VOUCHER_OCR_SAMPLE');
        if (! $sample) {
            $this->markTestSkipped('Set VOUCHER_OCR_SAMPLE to test the independent worker with installed OCR tools.');
        }

        $database = tempnam(sys_get_temp_dir(), 'ocr-db-');
        $workerConfig = tempnam(Storage::disk('local')->path(''), 'ocr-config-');
        $copy = tempnam(sys_get_temp_dir(), 'ocr-copy-');
        $sampleHash = hash_file('sha256', $sample);
        try {
            config([
                'database.connections.ocr_check' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true],
                'queue.connections.database.connection' => 'ocr_check',
                'cache.default' => 'file',
                'cache.stores.file.path' => Storage::disk('local')->path('ocr-cache'),
            ]);
            $this->artisan('migrate', ['--database' => 'ocr_check', '--path' => 'database/migrations/0001_01_01_000002_create_jobs_table.php', '--force' => true])->assertSuccessful();
            copy($sample, $copy);
            $this->post(route('vouchers.store'), ['year' => 2026, 'type' => 'CHECK', 'month' => 4,
                'files' => [new UploadedFile($copy, '10779_2026012525_PLDT INC..pdf', 'application/pdf', null, true)],
            ])->assertRedirect(route('vouchers.confirm'));
            $id = array_key_first(session('voucher_upload.files'));
            $this->assertSame(1, DB::connection('ocr_check')->table('jobs')->count(), 'The isolated worker must receive the queued PDF.');
            $settings = config()->all();
            $settings['database']['default'] = 'ocr_check';
            $settings['filesystems']['disks']['local']['root'] = Storage::disk('local')->path('');
            file_put_contents($workerConfig, '<?php return '.var_export($settings, true).';');
            $worker = Process::timeout(75)->env(['APP_CONFIG_CACHE' => substr($workerConfig, strlen(base_path()) + 1)])->run([
                PHP_BINARY, base_path('artisan'), 'queue:work', 'database', '--queue=voucher-ocr', '--once', '--tries=1', '--no-interaction',
            ]);

            $this->assertTrue($worker->successful(), $worker->output().$worker->errorOutput());
            $this->assertSame(0, DB::connection('ocr_check')->table('jobs')->count(), 'Independent worker did not consume its queued PDF. '.$worker->output().$worker->errorOutput());
            $this->getJson($this->statusUrl())->assertOk()->assertJsonPath("files.$id.status", 'ready')->assertJsonPath("files.$id.amount", '5250.00');
            $this->assertSame($sampleHash, hash_file('sha256', $sample));
        } finally {
            DB::purge('ocr_check');
            foreach ([$database, $database.'-wal', $database.'-shm', $workerConfig, $copy] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }

    private function uploadPdf(): string
    {
        $this->post(route('vouchers.store'), ['year' => 2026, 'type' => 'CHECK', 'month' => 4,
            'files' => [UploadedFile::fake()->create('10779_2026012525_PLDT INC..pdf', 1, 'application/pdf')],
        ])->assertRedirect(route('vouchers.confirm'));

        return array_key_first(session('voucher_upload.files'));
    }

    private function statusUrl(): string
    {
        return route('vouchers.upload.amounts', ['batch_id' => session('voucher_upload.batch_id')]);
    }
}
