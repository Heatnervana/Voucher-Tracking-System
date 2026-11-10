<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StagedPdfPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_preview_only_the_session_file_inline_without_caching(): void
    {
        $this->signIn();
        $batch = $this->stagePdf();
        Storage::disk('local')->put('private.pdf', 'unrelated private content');

        $response = $this->withSession(['voucher_upload' => $batch])->get($this->previewUrl($batch).'&path=private.pdf');

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeaderContains('Content-Disposition', 'inline')
            ->assertHeaderContains('Cache-Control', 'private')
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertStreamedContent('%PDF-1.4 staged voucher');
        $this->assertSame($batch, session('voucher_upload'));
        Storage::disk('local')->assertExists(current($batch['files'])['path']);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $batch = $this->stagePdf();

        $this->withSession(['voucher_upload' => $batch])->get($this->previewUrl($batch))
            ->assertRedirect(route('login'));
    }

    public function test_staff_cannot_preview_staged_pdfs(): void
    {
        $this->signIn(false);
        $batch = $this->stagePdf();

        $this->withSession(['voucher_upload' => $batch])->get($this->previewUrl($batch))->assertForbidden();
    }

    public function test_another_admin_session_cannot_preview_the_original_upload(): void
    {
        $this->signIn();
        $originalBatch = $this->stagePdf();
        $this->withSession(['voucher_upload' => $originalBatch]);
        session()->invalidate();
        $this->signIn();

        $this->get($this->previewUrl($originalBatch))->assertConflict();
    }

    public function test_stale_batch_cannot_preview_a_current_file(): void
    {
        $this->signIn();
        $batch = $this->stagePdf();
        $staleBatch = array_replace($batch, ['batch_id' => (string) Str::uuid()]);

        $this->withSession(['voucher_upload' => $batch])->get($this->previewUrl($staleBatch))->assertConflict();
    }

    public function test_file_outside_the_current_upload_is_not_found(): void
    {
        $this->signIn();
        $batch = $this->stagePdf();
        $otherFileId = (string) Str::uuid();
        Storage::disk('local')->put('staging/'.$otherFileId.'.pdf', '%PDF-1.4 another upload');

        $this->withSession(['voucher_upload' => $batch])
            ->get('/vouchers/upload/preview?'.http_build_query(['batch_id' => $batch['batch_id'], 'file_id' => $otherFileId]))
            ->assertNotFound();
    }

    public function test_missing_staged_file_is_not_found(): void
    {
        $this->signIn();
        $batch = $this->stagePdf();
        Storage::disk('local')->delete(current($batch['files'])['path']);

        $this->withSession(['voucher_upload' => $batch])->get($this->previewUrl($batch))->assertNotFound();
    }

    public function test_preview_requires_batch_and_file_identifiers(): void
    {
        $this->signIn();

        $this->getJson('/vouchers/upload/preview')->assertUnprocessable()
            ->assertJsonValidationErrors(['batch_id', 'file_id']);
    }

    public function test_preview_rejects_non_uuid_identifiers_including_paths(): void
    {
        $this->signIn();

        $this->getJson('/vouchers/upload/preview?batch_id=invalid&file_id=../../private.pdf')
            ->assertUnprocessable()->assertJsonValidationErrors(['batch_id', 'file_id']);
    }

    public function test_review_links_to_the_staged_pdf_in_a_new_tab(): void
    {
        $this->signIn();
        $batch = $this->stagePdf();

        $this->withSession(['voucher_upload' => $batch])->get(route('vouchers.confirm'))->assertOk()
            ->assertSee('Preview PDF (opens in new tab)')
            ->assertSee('href="'.e(url($this->previewUrl($batch))).'" target="_blank" rel="noopener"', false);
    }

    private function signIn(bool $isAdmin = true): void
    {
        $user = User::create(['username' => 'preview-'.Str::uuid(), 'name' => 'Preview Tester', 'password' => 'test-password']);
        $user->forceFill(['must_change_password' => false, 'is_admin' => $isAdmin])->save();
        $this->actingAs($user);
    }

    /**
     * @return array{batch_id: string, year: int, type: string, month: int, files: array<string, array<string, mixed>>}
     */
    private function stagePdf(): array
    {
        Storage::fake('local');
        $fileId = (string) Str::uuid();
        $path = 'staging/'.$fileId.'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 staged voucher');

        return ['batch_id' => (string) Str::uuid(), 'year' => 2026, 'type' => 'CHECK', 'month' => 4, 'files' => [
            $fileId => ['path' => $path, 'original_name' => 'voucher.pdf', 'detected_amount' => null,
                'checkada_no' => '10779', 'dv_number' => '2026012525', 'payee' => 'Preview Payee', 'size' => 23],
        ]];
    }

    /**
     * @param  array{batch_id: string, files: array<string, array<string, mixed>>}  $batch
     */
    private function previewUrl(array $batch): string
    {
        return '/vouchers/upload/preview?'.http_build_query(['batch_id' => $batch['batch_id'], 'file_id' => array_key_first($batch['files'])]);
    }
}
