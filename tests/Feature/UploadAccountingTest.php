<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UploadAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $user = User::create(['username' => 'accounting-admin', 'name' => 'Administrator', 'password' => 'TestPassword123!']);
        $user->forceFill(['is_admin' => true, 'must_change_password' => false])->save();
        $this->actingAs($user);
        VoucherYear::create(['year' => 2026]);
    }

    public function test_completion_detects_missing_files_and_keeps_the_staged_pdfs(): void
    {
        $batch = $this->start(2);
        $this->chunk($batch, ['one.pdf'])->assertOk();
        $this->postJson(route('vouchers.upload.complete'), ['batch_id' => $batch])->assertStatus(409)
            ->assertJsonPath('code', 'incomplete_upload')->assertJsonPath('received_files', 1)->assertJsonPath('expected_files', 2);
        $this->assertCount(1, session('voucher_upload.files'));
        $this->chunk($batch, ['two.pdf'])->assertOk();
        $this->postJson(route('vouchers.upload.complete'), ['batch_id' => $batch])->assertOk();
        $this->assertCount(2, session('voucher_upload.files'));
    }

    #[TestWith(['vouchers.amount-status'])]
    #[TestWith(['vouchers.index'])]
    public function test_background_and_library_requests_cannot_overwrite_an_upload_session(string $routeName): void
    {
        Route::getRoutes()->getByName($routeName)->uses(function (Request $request) {
            $lock = Cache::store(config('session.block_store'))->lock('session:'.$request->session()->getId(), 120);
            $acquired = $lock->get();
            if ($acquired) {
                $lock->release();
            }

            return response()->json(['upload_session_protected' => ! $acquired]);
        });
        $this->getJson(route($routeName))->assertOk()->assertJsonPath('upload_session_protected', true);
    }

    public function test_incomplete_upload_identifies_received_chunks_and_replaying_them_does_not_duplicate_files(): void
    {
        $batch = $this->start(103);
        $received = [];
        foreach (array_chunk(range(6, 103), 5) as $numbers) {
            $chunkId = (string) Str::uuid();
            $received[$chunkId] = array_map(fn ($number) => 'voucher-'.$number.'.pdf', $numbers);
            $this->chunk($batch, $received[$chunkId], $chunkId)->assertOk();
        }
        $this->postJson(route('vouchers.upload.complete'), ['batch_id' => $batch])->assertConflict()
            ->assertJsonPath('received_files', 98)->assertJsonPath('received_chunk_ids', array_keys($received));
        $this->chunk($batch, $received[array_key_first($received)], array_key_first($received))->assertOk();
        $this->assertCount(98, session('voucher_upload.files'));
        $this->chunk($batch, array_map(fn ($number) => 'voucher-'.$number.'.pdf', range(1, 5)))->assertOk();
        $this->postJson(route('vouchers.upload.complete'), ['batch_id' => $batch])->assertOk();
        $this->assertCount(103, session('voucher_upload.files'));
    }

    public function test_a_partial_chunk_is_rejected_before_staging_and_can_be_retried(): void
    {
        $batch = $this->start(2);
        $chunkId = (string) Str::uuid();
        $this->postJson(route('vouchers.upload.chunk'), ['batch_id' => $batch, 'chunk_id' => $chunkId,
            'expected_files' => 2, 'files' => [UploadedFile::fake()->create('one.pdf', 1, 'application/pdf')],
            'file_labels' => ['June/one.pdf', 'June/two.pdf'],
        ])->assertStatus(422)->assertJsonPath('code', 'incomplete_chunk');
        $this->assertSame([], Storage::disk('local')->allFiles('staging'));
        $this->chunk($batch, ['one.pdf', 'two.pdf'], $chunkId)->assertOk()->assertJsonPath('uploaded', 2);
    }

    public function test_rejected_pdf_has_a_named_reason_in_review_and_the_log(): void
    {
        Log::spy();
        $batch = $this->start(2);
        $this->postJson(route('vouchers.upload.chunk'), ['batch_id' => $batch, 'chunk_id' => (string) Str::uuid(),
            'expected_files' => 2, 'files' => [UploadedFile::fake()->create('good.pdf', 1, 'application/pdf'),
                UploadedFile::fake()->createWithContent('bad.pdf', 'This is not a PDF')->mimeType('text/plain')],
        ])->assertOk()->assertJsonPath('uploaded', 1);
        $this->postJson(route('vouchers.upload.complete'), ['batch_id' => $batch])->assertOk();
        $this->get(route('vouchers.confirm'))->assertSee('bad.pdf')->assertSee('2 selected')->assertSee('1 rejected');
        $this->get(route('vouchers.confirm'))->assertSee('bad.pdf');
        Log::shouldHaveReceived('warning')->with('Voucher upload rejected', \Mockery::on(fn ($context) => $context['filename'] === 'bad.pdf' && str_contains($context['reason'], 'PDF')))->once();
    }

    public function test_seventy_five_folder_pdfs_are_accounted_for(): void
    {
        $batch = $this->start(75);
        foreach (array_chunk(range(1, 75), 5) as $numbers) {
            $this->chunk($batch, array_map(fn ($number) => 'voucher-'.$number.'.pdf', $numbers))->assertOk();
        }
        $this->postJson(route('vouchers.upload.complete'), ['batch_id' => $batch])->assertOk();
        $this->assertCount(75, session('voucher_upload.files'));
        $this->get(route('vouchers.confirm'))->assertSee('75 selected')->assertSee('0 rejected');
    }

    private function start(int $count): string
    {
        return $this->postJson(route('vouchers.upload.start'), ['year' => 2026, 'type' => 'CHECK', 'month' => 6,
            'expected_files' => $count])->assertOk()->json('batch_id');
    }

    private function chunk(string $batch, array $names, ?string $chunkId = null): TestResponse
    {
        return $this->postJson(route('vouchers.upload.chunk'), ['batch_id' => $batch, 'chunk_id' => $chunkId ?? (string) Str::uuid(),
            'expected_files' => count($names), 'file_labels' => array_map(fn ($name) => 'June/'.$name, $names),
            'files' => array_map(fn ($name) => UploadedFile::fake()->create($name, 1, 'application/pdf'), $names),
        ]);
    }
}
