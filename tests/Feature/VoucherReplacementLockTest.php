<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PDO;
use Tests\TestCase;

class VoucherReplacementLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $database = storage_path('replacement-lock-test.sqlite');
        touch($database);
        config([
            'database.connections.sqlite.database' => $database,
            'database.connections.sqlite.journal_mode' => 'delete',
            'database.connections.sqlite.busy_timeout' => 50,
        ]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        Storage::fake('local');
        Log::spy();
        $this->actingAs(User::factory()->administrator()->create());
        VoucherYear::create(['year' => 2026]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_a_temporary_write_lock_retries_the_replacement_and_records_it_once(): void
    {
        [$voucher, $stagedPath, $response, $attempts] = $this->replaceWithCompetingWriter(releaseOnRollback: true);

        $response->assertRedirect(route('vouchers.index'))->assertSessionHas('status', '1 voucher(s) saved.');
        $this->assertSame(2, $attempts);
        $this->assertSame('25.00', $voucher->fresh()->amount);
        $this->assertSame('%PDF replacement', Storage::disk('local')->get($voucher->file_path));
        Storage::disk('local')->assertMissing($stagedPath);
        $this->assertSame([], Storage::disk('local')->allFiles('replacements'));
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'voucher.replaced')->count());
    }

    public function test_a_persistent_write_lock_leaves_both_files_safe_and_reports_the_busy_database(): void
    {
        [$voucher, $stagedPath, $response, $attempts] = $this->replaceWithCompetingWriter(releaseOnRollback: false);

        $response->assertRedirect(route('vouchers.confirm'))
            ->assertSessionHas('upload_errors', ['replacement.pdf: The database is busy. This PDF is still in review. Click Save vouchers again to retry.']);
        $this->assertSame(3, $attempts);
        $this->assertSame('10.00', $voucher->fresh()->amount);
        $this->assertSame('%PDF original', Storage::disk('local')->get($voucher->file_path));
        $this->assertSame('%PDF replacement', Storage::disk('local')->get($stagedPath));
        $this->assertSame([], Storage::disk('local')->allFiles('replacements'));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'voucher.replaced']);
    }

    /** @return array{Voucher, string, TestResponse, int} */
    private function replaceWithCompetingWriter(bool $releaseOnRollback): array
    {
        $path = 'vouchers/2026/CHECK/10/11367_2026037954_ORIGINAL.pdf';
        Storage::disk('local')->put($path, '%PDF original');
        $voucher = Voucher::create(['checkada_no' => '11367', 'dv_number' => '2026037954', 'payee' => 'Original',
            'amount' => '10.00', 'amount_status' => 'ready', 'year' => 2026, 'type' => 'CHECK', 'month' => 10,
            'file_path' => $path, 'uploaded_by' => auth()->id()]);
        $this->postJson(route('vouchers.upload.start'), [
            'year' => 2026, 'type' => 'CHECK', 'month' => 10, 'folder_action' => 'replace',
        ])->assertOk();
        $upload = session('voucher_upload');
        $fileId = (string) Str::uuid();
        $stagedPath = 'staging/'.$fileId.'.pdf';
        Storage::disk('local')->put($stagedPath, '%PDF replacement');
        $upload['files'][$fileId] = ['path' => $stagedPath, 'original_name' => 'replacement.pdf'];

        $writer = new PDO('sqlite:'.DB::connection()->getDatabaseName());
        $attempts = 0;
        DB::connection()->beforeExecuting(static function (string $query) use (&$writer, &$attempts): void {
            if (! str_starts_with($query, 'update "vouchers"')) {
                return;
            }
            if (++$attempts === 1) {
                $writer->beginTransaction();
                $writer->exec("INSERT INTO cache (\"key\", value, expiration) VALUES ('competing-write', '1', 2147483647)");
            }
        });
        if ($releaseOnRollback) {
            Event::listen(TransactionRolledBack::class, static function () use (&$writer): void {
                if ($writer?->inTransaction()) {
                    $writer->rollBack();
                }
            });
        }

        try {
            $response = $this->withSession(['voucher_upload' => $upload])->post(route('vouchers.finalize'), [
                'batch_id' => $upload['batch_id'],
                'vouchers' => [$fileId => ['checkada_no' => '11367', 'dv_number' => '2026037954', 'payee' => 'Original', 'amount' => '25.00']],
            ]);
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
            $writer = null;
        }

        return [$voucher, $stagedPath, $response, $attempts];
    }
}
