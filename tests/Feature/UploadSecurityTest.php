<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->administrator()->create());
        VoucherYear::create(['year' => 2026]);
    }

    public function test_legacy_upload_over_5_gb_keeps_the_previous_review_and_staged_pdf(): void
    {
        $previous = $this->previousUpload();

        $this->withSession(['voucher_upload' => $previous])->postJson(route('vouchers.store'), [
            'year' => 2026, 'type' => 'ADA', 'month' => 10,
            'files' => array_map(fn ($number) => UploadedFile::fake()->create("large-$number.pdf", 512000, 'application/pdf'), range(1, 10)),
        ])->assertUnprocessable()->assertJsonValidationErrors('files');

        $this->assertSame($previous, session('voucher_upload'));
        $this->assertSame(['staging/previous.pdf'], Storage::disk('local')->allFiles('staging'));
    }

    public function test_legacy_upload_within_5_gb_still_accepts_multiple_large_pdfs(): void
    {
        $this->post(route('vouchers.store'), [
            'year' => 2026, 'type' => 'ADA', 'month' => 10,
            'files' => array_map(fn ($number) => UploadedFile::fake()->create("large-$number.pdf", 512000, 'application/pdf'), range(1, 9)),
        ])->assertRedirect(route('vouchers.confirm'));

        $this->assertCount(9, session('voucher_upload.files'));
        $this->assertCount(9, Storage::disk('local')->allFiles('staging'));
    }

    #[TestWith(['vouchers.store'])]
    #[TestWith(['vouchers.upload.chunk'])]
    public function test_nested_file_arrays_are_rejected_without_losing_staged_files(string $routeName): void
    {
        $previous = $this->previousUpload();

        $this->withSession(['voucher_upload' => $previous])->postJson(route($routeName), [
            'year' => 2026, 'type' => 'ADA', 'month' => 10,
            'batch_id' => $previous['batch_id'], 'chunk_id' => (string) Str::uuid(),
            'files' => [[UploadedFile::fake()->create('nested.pdf', 1, 'application/pdf')]],
        ])->assertUnprocessable()->assertJsonValidationErrors('files.0');

        $this->assertSame($previous, session('voucher_upload'));
        Storage::disk('local')->assertExists('staging/previous.pdf');
    }

    private function previousUpload(): array
    {
        Storage::disk('local')->put('staging/previous.pdf', '%PDF-1.4 previous review');

        return ['batch_id' => (string) Str::uuid(), 'year' => 2026, 'type' => 'ADA', 'month' => 10,
            'received_size' => 23, 'chunks' => [], 'files' => [
                (string) Str::uuid() => ['path' => 'staging/previous.pdf', 'original_name' => 'previous.pdf'],
            ]];
    }
}
