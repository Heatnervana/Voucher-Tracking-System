<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class VoucherMoveRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $database = storage_path('move-recovery.sqlite');
        touch($database);
        config([
            'database.connections.sqlite.database' => $database,
            'database.connections.sqlite.journal_mode' => 'delete',
            'database.connections.sqlite.busy_timeout' => 20,
        ]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_a_real_commit_failure_restores_the_pdf_and_rolls_back_the_open_pdo_transaction(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        $oldPath = $voucher->file_path;
        $reader = new PDO('sqlite:'.DB::connection()->getDatabaseName());
        Event::listen(TransactionCommitting::class, static function () use ($reader): void {
            $reader->beginTransaction();
            $reader->query('SELECT * FROM vouchers')->fetchAll();
        });
        Exceptions::fake();

        try {
            $this->post(route('vouchers.move', $voucher), $this->destination())->assertSessionHasErrors('move');

            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($oldPath, $voucher->fresh()->file_path);
            $this->assertSame('%PDF original', Storage::disk('local')->get($oldPath));
            Storage::disk('local')->assertMissing($this->target());
            $this->assertDatabaseMissing('activity_logs', ['action' => 'voucher.moved']);
            Exceptions::assertReported(PDOException::class);
        } finally {
            if (DB::connection()->getPdo()->inTransaction()) {
                DB::connection()->getPdo()->rollBack();
            }
            if ($reader->inTransaction()) {
                $reader->rollBack();
            }
        }
    }

    public function test_a_post_commit_failure_keeps_the_pdf_at_the_committed_path_and_reports_the_exception(): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        $oldPath = $voucher->file_path;
        Event::listen(TransactionCommitted::class, static function (): void {
            throw new RuntimeException('Commit callback failed');
        });
        Exceptions::fake();

        $this->post(route('vouchers.move', $voucher), $this->destination())->assertRedirect();

        $this->assertSame($this->target(), $voucher->fresh()->file_path);
        $this->assertSame('%PDF original', Storage::disk('local')->get($this->target()));
        Storage::disk('local')->assertMissing($oldPath);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Commit callback failed');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_an_unreadable_commit_outcome_preserves_the_pdf_at_both_possible_paths(bool $committed): void
    {
        Storage::fake('local');
        $voucher = $this->voucher();
        $oldPath = $voucher->file_path;
        $unreadable = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$unreadable): void {
            if ($unreadable && str_contains($query, 'from "vouchers"')) {
                throw new RuntimeException('Recovery query unavailable');
            }
        });
        Event::listen($committed ? TransactionCommitted::class : TransactionCommitting::class, static function () use (&$unreadable): void {
            $unreadable = true;
            throw new RuntimeException('Commit outcome unavailable');
        });
        Exceptions::fake();

        $response = $this->post(route('vouchers.move', $voucher), $this->destination());
        $unreadable = false;

        $response->assertSessionHasErrors('move');
        $this->assertSame($committed ? $this->target() : $oldPath, $voucher->fresh()->file_path);
        $this->assertSame('%PDF original', Storage::disk('local')->get($oldPath));
        $this->assertSame('%PDF original', Storage::disk('local')->get($this->target()));
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Recovery query unavailable');
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_failed_restore_keeps_the_moved_bytes_and_reports_both_paths(bool $throws): void
    {
        $disk = Storage::fake('local');
        $voucher = $this->voucher();
        $oldPath = $voucher->file_path;
        $failingDisk = Mockery::mock($disk);
        $failingDisk->shouldReceive('move')->withAnyArgs()->andReturnUsing(fn (string $from, string $to): bool => $disk->move($from, $to))->byDefault();
        $failure = $failingDisk->shouldReceive('move')->with($this->target(), $oldPath)->once();
        $throws ? $failure->andThrow(new RuntimeException('Disk unavailable')) : $failure->andReturn(false);
        Storage::set('local', $failingDisk);
        DB::unprepared("CREATE TRIGGER reject_move BEFORE UPDATE ON vouchers BEGIN SELECT RAISE(FAIL, 'Move rejected'); END");
        Exceptions::fake();

        $this->post(route('vouchers.move', $voucher), $this->destination())->assertSessionHasErrors('move');

        $this->assertSame($oldPath, $voucher->fresh()->file_path);
        $this->assertSame('%PDF original', $disk->get($this->target()));
        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), $oldPath)
            && str_contains($exception->getMessage(), $this->target()));
        Exceptions::assertReported(fn (QueryException $exception): bool => str_contains($exception->getMessage(), 'Move rejected'));
    }

    private function voucher(): Voucher
    {
        $this->actingAs(User::factory()->administrator()->create());
        VoucherYear::create(['year' => 2026]);
        $path = 'vouchers/2026/CHECK/04/original.pdf';
        Storage::disk('local')->put($path, '%PDF original');

        return Voucher::create([
            'checkada_no' => '1000', 'dv_number' => '123456', 'payee' => 'Test Payee', 'amount' => '25.00',
            'year' => 2026, 'type' => 'CHECK', 'month' => 4, 'file_path' => $path, 'uploaded_by' => auth()->id(),
        ]);
    }

    /** @return array{year: int, type: string, month: int} */
    private function destination(): array
    {
        return ['year' => 2026, 'type' => 'ADA', 'month' => 5];
    }

    private function target(): string
    {
        return 'vouchers/2026/ADA/05/original.pdf';
    }
}
