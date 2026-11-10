<?php

namespace Tests\Feature;

use App\Http\Controllers\VoucherController;
use App\Http\Middleware\LockVoucherFiles;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class VoucherFileConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('mutationRoutes')]
    public function test_saved_file_mutations_hold_the_same_exclusive_lock(string $name, string $method): void
    {
        $this->prepareStorageAndUser();
        $probe = fopen(storage_path('framework/voucher-files.lock'), 'c');
        $route = Route::getRoutes()->getByName($name);
        $route->uses(function () use ($probe): JsonResponse {
            $acquired = flock($probe, LOCK_EX | LOCK_NB);
            if ($acquired) {
                flock($probe, LOCK_UN);
            }

            return response()->json(['exclusive' => ! $acquired]);
        });

        try {
            $this->json($method, route($name, ['voucher' => 1]))
                ->assertOk()->assertJsonPath('exclusive', true);
        } finally {
            fclose($probe);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function mutationRoutes(): array
    {
        return [
            'finalize' => ['vouchers.finalize', 'POST'],
            'rename' => ['vouchers.rename', 'PATCH'],
            'move' => ['vouchers.move', 'POST'],
            'delete' => ['vouchers.destroy', 'DELETE'],
            'selected delete' => ['vouchers.destroy-selected', 'DELETE'],
            'month delete' => ['vouchers.destroy-month', 'DELETE'],
            'folder delete' => ['vouchers.destroy-folder', 'DELETE'],
        ];
    }

    public function test_a_stale_bound_voucher_deletes_the_latest_saved_pdf(): void
    {
        $disk = $this->prepareStorageAndUser();
        $voucher = $this->createVoucher('000001', 'original.pdf');
        $disk->put($voucher->file_path, 'original PDF');
        $boundBeforeRename = Voucher::findOrFail($voucher->id);

        $this->patch(route('vouchers.rename', $voucher), ['filename' => 'renamed.pdf'])
            ->assertRedirect(route('vouchers.show', $voucher));

        app(LockVoucherFiles::class)->handle(Request::create('/', 'DELETE'), function () use ($boundBeforeRename): Response {
            return app(VoucherController::class)->destroy($boundBeforeRename);
        });

        $this->assertSoftDeleted('vouchers', ['id' => $voucher->id]);
        $this->assertSame('original PDF', $disk->get($voucher->fresh()->file_path));
    }

    public function test_interleaved_renames_cannot_destroy_either_pdf(): void
    {
        $disk = $this->prepareStorageAndUser();
        $first = $this->createVoucher('000001', 'first.pdf');
        $second = $this->createVoucher('000002', 'second.pdf');
        $disk->put($first->file_path, 'first PDF');
        $disk->put($second->file_path, 'second PDF');
        $interleaved = false;
        $wrapper = Mockery::mock(LocalFilesystemAdapter::class, [$disk->getDriver(), $disk->getAdapter(), $disk->getConfig()])->makePartial();
        Storage::set('local', $wrapper);
        $wrapper->shouldReceive('move')->andReturnUsing(function (string $source, string $target) use ($disk, $second, &$interleaved): bool {
            if (! $interleaved && $target === 'vouchers/2026/ADA/10/shared.pdf') {
                $interleaved = true;
                $this->patch(route('vouchers.rename', $second), ['filename' => 'shared.pdf'])
                    ->assertRedirect()->assertSessionHasErrors('voucher_files');
            }

            return $disk->move($source, $target);
        });

        $this->patch(route('vouchers.rename', $first), ['filename' => 'shared.pdf']);

        $this->assertTrue($interleaved);
        $this->assertSame('first PDF', $disk->get($first->fresh()->file_path));
        $this->assertSame('second PDF', $disk->get($second->fresh()->file_path));
        $this->assertCount(2, $disk->allFiles('vouchers'));
    }

    public function test_another_process_waits_until_the_lock_is_released(): void
    {
        $this->prepareStorageAndUser();
        $holder = fopen(storage_path('framework/voucher-files.lock'), 'c');
        $this->assertTrue(flock($holder, LOCK_EX | LOCK_NB));
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/voucher-file-lock-worker.php'), storage_path()]);
        $process->setTimeout(20);

        try {
            $process->start();
            $process->waitUntil(fn (): bool => str_contains($process->getOutput(), 'READY'));
            usleep(100_000);
            $this->assertStringNotContainsString('ENTERED', $process->getOutput());
            flock($holder, LOCK_UN);
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->assertStringContainsString('ENTERED', $process->getOutput());
        } finally {
            fclose($holder);
            $process->stop();
        }
    }

    public function test_an_exception_releases_the_file_lock(): void
    {
        $this->prepareStorageAndUser();

        try {
            app(LockVoucherFiles::class)->handle(Request::create('/'), function (): Response {
                throw new RuntimeException('Operation failed.');
            });
            $this->fail('The operation must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Operation failed.', $exception->getMessage());
        }

        $probe = fopen(storage_path('framework/voucher-files.lock'), 'c');
        try {
            $this->assertTrue(flock($probe, LOCK_EX | LOCK_NB));
        } finally {
            fclose($probe);
        }
    }

    public function test_a_contended_json_mutation_returns_a_retryable_error(): void
    {
        $this->prepareStorageAndUser();
        $holder = fopen(storage_path('framework/voucher-files.lock'), 'c');
        $this->assertTrue(flock($holder, LOCK_EX | LOCK_NB));

        try {
            $response = app(LockVoucherFiles::class)->handle(
                Request::create('/', 'POST', server: ['HTTP_ACCEPT' => 'application/json']),
                function (): Response {
                    $this->fail('A contended operation must not reach the controller.');
                },
            );

            $this->assertSame(423, $response->getStatusCode());
            $this->assertStringContainsString('Please try again', $response->getContent());
        } finally {
            fclose($holder);
        }
    }

    public function test_unavailable_lock_storage_returns_503_without_running_the_mutation(): void
    {
        $this->prepareStorageAndUser();
        Exceptions::fake();
        $this->app->useStoragePath(storage_path('missing'));

        $this->deleteJson(route('vouchers.destroy-selected'), ['voucher_ids' => [1]])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Voucher storage is temporarily unavailable. Please try again.');

        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_contended_finalize_preserves_manually_entered_json_details(): void
    {
        $disk = $this->prepareStorageAndUser();
        $fileId = (string) Str::uuid();
        $batchId = (string) Str::uuid();
        $path = 'staging/'.$fileId.'.pdf';
        $disk->put($path, 'staged PDF');
        $holder = fopen(storage_path('framework/voucher-files.lock'), 'c');
        $this->assertTrue(flock($holder, LOCK_EX | LOCK_NB));

        try {
            $this->withSession(['voucher_upload' => [
                'batch_id' => $batchId, 'year' => 2026, 'type' => 'ADA', 'month' => 10,
                'files' => [$fileId => ['path' => $path, 'original_name' => 'original.pdf']],
            ]])->from(route('vouchers.confirm'))->post(route('vouchers.finalize'), [
                'batch_id' => $batchId,
                'voucher_details' => json_encode([$fileId => [
                    'checkada_no' => '123456', 'dv_number' => '000001', 'payee' => 'Edited Payee', 'amount' => '42.35',
                ]]),
            ])->assertRedirect(route('vouchers.confirm'))
                ->assertSessionHasErrors('voucher_files')
                ->assertSessionHasInput('vouchers.'.$fileId.'.amount', '42.35')
                ->assertSessionHasInput('vouchers.'.$fileId.'.payee', 'Edited Payee');

            $this->assertDatabaseCount('vouchers', 0);
            $this->assertSame('staged PDF', $disk->get($path));
        } finally {
            fclose($holder);
        }
    }

    private function prepareStorageAndUser(): LocalFilesystemAdapter
    {
        $disk = Storage::fake('voucher-concurrency');
        Storage::set('local', $disk);
        $this->app->useStoragePath($disk->path('runtime'));
        File::ensureDirectoryExists(storage_path('framework'));
        $user = User::create(['username' => 'file-tester', 'name' => 'File Tester', 'password' => 'test-password']);
        $user->forceFill(['must_change_password' => false, 'is_admin' => true])->save();
        $this->actingAs($user);
        VoucherYear::create(['year' => 2026]);

        return $disk;
    }

    private function createVoucher(string $dvNumber, string $name): Voucher
    {
        return Voucher::create([
            'checkada_no' => $dvNumber, 'dv_number' => $dvNumber, 'payee' => 'Test Payee',
            'amount' => '100.00', 'year' => 2026, 'type' => 'ADA', 'month' => 10,
            'file_path' => 'vouchers/2026/ADA/10/'.$name, 'uploaded_by' => auth()->id(),
        ]);
    }
}
