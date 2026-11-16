<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoucherYear;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class StagedUploadCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_clears_its_staged_pdfs_and_ocr_state_under_the_upload_session_lock(): void
    {
        $disk = Storage::fake('local');
        $upload = $this->upload();
        $this->withSession(['voucher_upload' => $upload, '_token' => 'old-token']);
        $sessionId = session()->getId();
        $this->withCookie(config('session.cookie'), $sessionId);
        $disk->put('staging/unrelated.pdf', '%PDF unrelated');
        $disk->put('vouchers/saved.pdf', '%PDF saved');
        $lockedDuringCleanup = [];
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('delete')->andReturnUsing(function (string $path) use ($disk, $sessionId, &$lockedDuringCleanup): bool {
            $lock = Cache::store(config('session.block_store'))->lock('session:'.$sessionId, 120);
            $acquired = $lock->get();
            $lockedDuringCleanup[] = ! $acquired;
            if ($acquired) {
                $lock->release();
            }

            return $disk->delete($path);
        });
        Storage::set('local', $proxy);

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame('old-token', session()->token());
        $this->assertNull(session('voucher_upload'));
        $this->assertSame([true, true], $lockedDuringCleanup);
        foreach ($upload['files'] as $id => $file) {
            $disk->assertMissing($file['path']);
            $this->assertFalse(Cache::has('voucher-amount:'.$id));
        }
        $this->assertSame('%PDF unrelated', $disk->get('staging/unrelated.pdf'));
        $this->assertSame('%PDF saved', $disk->get('vouchers/saved.pdf'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_logout_still_invalidates_the_session_and_reports_each_failed_staged_pdf(bool $throws): void
    {
        $disk = Storage::fake('local');
        $upload = $this->upload();
        $failedId = array_key_first($upload['files']);
        $failedPath = $upload['files'][$failedId]['path'];
        $this->withSession(['voucher_upload' => $upload, '_token' => 'old-token']);
        $sessionId = session()->getId();
        $this->withCookie(config('session.cookie'), $sessionId);
        $this->failDeletion($disk, $failedPath, $throws);
        Exceptions::fake();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotSame('old-token', session()->token());
        $this->assertNull(session('voucher_upload'));
        $this->assertSame([$failedPath], $disk->allFiles('staging'));
        foreach ($upload['files'] as $id => $file) {
            $this->assertFalse(Cache::has('voucher-amount:'.$id));
        }
        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), $failedId)
            && str_contains($exception->getMessage(), $failedPath));
    }

    #[TestWith(['cancel', false])]
    #[TestWith(['cancel', true])]
    #[TestWith(['start', false])]
    #[TestWith(['start', true])]
    #[TestWith(['store', false])]
    #[TestWith(['store', true])]
    public function test_failed_cleanup_keeps_only_failed_references_and_allows_a_retry(string $action, bool $throws): void
    {
        $disk = Storage::fake('local');
        $upload = $this->upload();
        $failedId = array_key_first($upload['files']);
        $failedPath = $upload['files'][$failedId]['path'];
        $this->withSession(['voucher_upload' => $upload]);
        $this->failDeletion($disk, $failedPath, $throws);
        Exceptions::fake();
        $folder = ['year' => 2026, 'type' => 'CHECK', 'month' => 4];

        $response = match ($action) {
            'cancel' => $this->delete(route('vouchers.cancel-upload'), ['batch_id' => $upload['batch_id']]),
            'start' => $this->postJson(route('vouchers.upload.start'), $folder),
            'store' => $this->post(route('vouchers.store'), $folder + [
                'files' => [UploadedFile::fake()->create('new.pdf', 1, 'application/pdf')],
            ]),
        };

        if ($action === 'start') {
            $response->assertUnprocessable()->assertJsonValidationErrors('files');
        } else {
            $response->assertSessionHasErrors('files');
        }
        $this->assertAuthenticated();
        $this->assertSame($upload['batch_id'], session('voucher_upload.batch_id'));
        $this->assertSame([$failedId => $upload['files'][$failedId]], session('voucher_upload.files'));
        $this->assertSame([$failedPath], $disk->allFiles('staging'));
        foreach ($upload['files'] as $id => $file) {
            $this->assertFalse(Cache::has('voucher-amount:'.$id));
        }
        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), $failedPath));

        Storage::set('local', $disk);
        $this->delete(route('vouchers.cancel-upload'), ['batch_id' => $upload['batch_id']])->assertRedirect(route('vouchers.index'));
        $this->assertNull(session('voucher_upload'));
        $disk->assertMissing($failedPath);
    }

    /** @return array{batch_id: string, year: int, type: string, month: int, files: array<string, array{path: string, original_name: string}>} */
    private function upload(): array
    {
        $this->actingAs(User::factory()->administrator()->create());
        VoucherYear::create(['year' => 2026]);
        $files = [];
        foreach (['first.pdf', 'second.pdf'] as $name) {
            $id = (string) Str::uuid();
            $path = 'staging/'.$id.'.pdf';
            Storage::disk('local')->put($path, '%PDF staged');
            Cache::put('voucher-amount:'.$id, ['status' => 'pending', 'amount' => null], now()->addDay());
            $files[$id] = ['path' => $path, 'original_name' => $name];
        }

        return ['batch_id' => (string) Str::uuid(), 'year' => 2026, 'type' => 'CHECK', 'month' => 4, 'files' => $files];
    }

    private function failDeletion(FilesystemAdapter $disk, string $path, bool $throws): void
    {
        $proxy = Mockery::mock($disk);
        $proxy->shouldReceive('delete')->withAnyArgs()->andReturnUsing(fn (string $file): bool => $disk->delete($file))->byDefault();
        $failure = $proxy->shouldReceive('delete')->with($path)->once();
        $throws ? $failure->andThrow(new RuntimeException('Disk unavailable')) : $failure->andReturn(false);
        Storage::set('local', $proxy);
    }
}
