<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class VoucherUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $user = User::create([
            'username' => 'upload-tester',
            'name' => 'Upload Tester',
            'password' => 'test-password',
        ]);
        $user->forceFill(['must_change_password' => false, 'is_admin' => true])->save();

        VoucherYear::create(['year' => 2026]);

        $this->actingAs($user);
    }

    public function test_pdf_at_500_mb_is_staged_and_confirmed(): void
    {
        $this->post(route('vouchers.store'), [
            'year' => 2026,
            'type' => 'ADA',
            'month' => 10,
            'files' => [UploadedFile::fake()->create('ada-voucher.pdf', 512000, 'application/pdf')],
        ])->assertRedirect(route('vouchers.confirm'));

        $this->get(route('vouchers.confirm'))
            ->assertOk()
            ->assertSee('ada-voucher.pdf');

        $this->assertCount(1, Storage::disk('local')->allFiles('staging'));
    }

    public function test_pdf_over_500_mb_is_rejected(): void
    {
        $this->from(route('vouchers.create'))
            ->post(route('vouchers.store'), [
                'year' => 2026,
                'type' => 'ADA',
                'month' => 10,
                'files' => [UploadedFile::fake()->create('oversized-voucher.pdf', 512001, 'application/pdf')],
            ])
            ->assertRedirect(route('vouchers.create'))
            ->assertSessionHas('upload_errors', fn (array $errors): bool => str_contains($errors[0], '500 MB'));

        $this->assertSame([], Storage::disk('local')->allFiles('staging'));
    }

    public function test_month_folder_upload_accumulates_chunks_and_opens_one_review(): void
    {
        $this->get(route('vouchers.create'))
            ->assertOk()
            ->assertSee('Choose a month folder')
            ->assertSee('webkitdirectory');

        $batchId = $this->postJson(route('vouchers.upload.start'), [
            'year' => 2026,
            'type' => 'ADA',
            'month' => 10,
        ])->assertOk()->json('batch_id');

        foreach (['first.pdf', 'second.pdf'] as $name) {
            $this->postJson(route('vouchers.upload.chunk'), [
                'batch_id' => $batchId,
                'chunk_id' => (string) Str::uuid(),
                'files' => [UploadedFile::fake()->create($name, 1, 'application/pdf')],
                'file_labels' => ['October/'.$name],
            ])->assertOk()->assertJsonPath('uploaded', 1);
        }

        $this->postJson(route('vouchers.upload.complete'), ['batch_id' => $batchId])
            ->assertOk()
            ->assertJsonPath('redirect', route('vouchers.confirm'));

        $this->get(route('vouchers.confirm'))
            ->assertOk()
            ->assertSee('October/first.pdf')
            ->assertSee('October/second.pdf')
            ->assertSee('review-pagination')
            ->assertSee('review-previous')
            ->assertSee('review-next');

        $this->assertCount(2, Storage::disk('local')->allFiles('staging'));
    }

    public function test_repeated_chunk_is_not_staged_twice(): void
    {
        $batchId = $this->postJson(route('vouchers.upload.start'), [
            'year' => 2026,
            'type' => 'ADA',
            'month' => 10,
        ])->assertOk()->json('batch_id');
        $chunkId = (string) Str::uuid();

        $payload = [
            'batch_id' => $batchId,
            'chunk_id' => $chunkId,
            'files' => [UploadedFile::fake()->create('retry.pdf', 1, 'application/pdf')],
        ];

        $this->postJson(route('vouchers.upload.chunk'), $payload)->assertOk();
        $this->postJson(route('vouchers.upload.chunk'), $payload)->assertOk();

        $this->assertCount(1, Storage::disk('local')->allFiles('staging'));
    }

    public function test_folder_upload_cannot_exceed_5_gb(): void
    {
        $batchId = $this->postJson(route('vouchers.upload.start'), [
            'year' => 2026,
            'type' => 'ADA',
            'month' => 10,
        ])->assertOk()->json('batch_id');

        for ($index = 0; $index < 9; $index++) {
            $this->postJson(route('vouchers.upload.chunk'), [
                'batch_id' => $batchId,
                'chunk_id' => (string) Str::uuid(),
                'files' => [UploadedFile::fake()->create("large-$index.pdf", 512000, 'application/pdf')],
            ])->assertOk();
        }

        $this->postJson(route('vouchers.upload.chunk'), [
            'batch_id' => $batchId,
            'chunk_id' => (string) Str::uuid(),
            'files' => [UploadedFile::fake()->create('over-limit.pdf', 512000, 'application/pdf')],
        ])->assertStatus(413);

        $this->assertCount(9, Storage::disk('local')->allFiles('staging'));
    }

    public function test_one_staged_pdf_can_be_removed_from_the_pending_upload(): void
    {
        $batchId = $this->postJson(route('vouchers.upload.start'), [
            'year' => 2026,
            'type' => 'ADA',
            'month' => 10,
        ])->assertOk()->json('batch_id');

        $this->postJson(route('vouchers.upload.chunk'), [
            'batch_id' => $batchId,
            'chunk_id' => (string) Str::uuid(),
            'files' => [
                UploadedFile::fake()->create('keep.pdf', 1, 'application/pdf'),
                UploadedFile::fake()->create('remove.pdf', 1, 'application/pdf'),
            ],
        ])->assertOk();

        $fileId = array_key_first(array_filter(session('voucher_upload.files'), fn (array $file): bool => $file['original_name'] === 'remove.pdf'));
        $this->assertNotNull($fileId);
        $this->assertCount(2, Storage::disk('local')->allFiles('staging'));

        $this->postJson(route('vouchers.upload.remove-file'), [
            'batch_id' => $batchId,
            'file_id' => $fileId,
        ])->assertOk()->assertJsonPath('remaining', 1)->assertJsonPath('redirect', null);

        $this->assertCount(1, Storage::disk('local')->allFiles('staging'));
        $this->assertSame(['keep.pdf'], array_values(array_column(session('voucher_upload.files'), 'original_name')));
    }

    public function test_saved_voucher_delete_removes_record_and_pdf(): void
    {
        $path = 'vouchers/2026/ADA/10/123456_654321_Test Payee.pdf';
        Storage::disk('local')->put($path, 'sample PDF');
        $voucher = Voucher::create([
            'checkada_no' => '123456',
            'dv_number' => '654321',
            'payee' => 'Test Payee',
            'amount' => 100.00,
            'year' => 2026,
            'type' => 'ADA',
            'month' => 10,
            'file_path' => $path,
            'uploaded_by' => auth()->id(),
        ]);

        $this->get(route('vouchers.show', $voucher))
            ->assertOk()
            ->assertSee('Delete saved voucher and PDF')
            ->assertSee('data-dv-number="654321"', false);

        $this->from(route('vouchers.show', $voucher))
            ->delete(route('vouchers.destroy', $voucher))
            ->assertRedirect(route('vouchers.index'))
            ->assertSessionHas('status', 'Voucher and saved PDF moved to the recycle bin.');

        $this->assertSoftDeleted('vouchers', ['id' => $voucher->id]);
        Storage::disk('local')->assertExists($path);
    }

    public function test_voucher_library_shows_current_page_even_when_there_is_only_one_page(): void
    {
        $this->get(route('vouchers.index'))
            ->assertOk()
            ->assertSeeText('Page 1 of 1')
            ->assertSeeText('0 vouchers');
    }

    public function test_voucher_library_shows_numbered_pages_when_it_has_multiple_pages(): void
    {
        foreach (range(1, 21) as $number) {
            Voucher::create([
                'checkada_no' => (string) $number,
                'dv_number' => str_pad((string) $number, 6, '0', STR_PAD_LEFT),
                'payee' => 'Test Payee '.$number,
                'amount' => 100.00,
                'year' => 2026,
                'type' => 'ADA',
                'month' => 10,
                'file_path' => 'vouchers/test-'.$number.'.pdf',
                'uploaded_by' => auth()->id(),
            ]);
        }

        $this->get(route('vouchers.index'))
            ->assertOk()
            ->assertSeeText('Page 1 of 2')
            ->assertSee('aria-current="page"', false)
            ->assertSee('aria-label="Page 1"', false)
            ->assertSee('aria-label="Page 2"', false)
            ->assertSee('>Next</a>', false);

        $this->get(route('vouchers.index', ['page' => 2]))
            ->assertOk()
            ->assertSeeText('Page 2 of 2')
            ->assertSee('>Previous</a>', false);
    }

    public function test_month_delete_removes_only_vouchers_and_pdfs_in_that_month(): void
    {
        $october = $this->makeVoucher('000001', 'ADA', 10);
        $november = $this->makeVoucher('000002', 'ADA', 11);
        $check = $this->makeVoucher('000003', 'CHECK', 10);

        foreach ([$october, $november, $check] as $voucher) {
            Storage::disk('local')->put($voucher->file_path, 'sample PDF');
        }

        $this->get(route('vouchers.index', ['year' => 2026, 'type' => 'ADA', 'month' => 10]))
            ->assertOk()
            ->assertSee('Delete October (1)')
            ->assertSee('Delete entire 2026 / ADA folder (2)');

        $this->delete(route('vouchers.destroy-month'), ['year' => 2026, 'type' => 'ADA', 'month' => 10])
            ->assertRedirect(route('vouchers.index', ['year' => 2026, 'type' => 'ADA', 'month' => 10]))
            ->assertSessionHas('status', '1 voucher(s) and PDF(s) moved to the recycle bin from 2026 / ADA / October.');

        $this->assertSoftDeleted('vouchers', ['id' => $october->id]);
        $this->assertDatabaseHas('vouchers', ['id' => $november->id]);
        $this->assertDatabaseHas('vouchers', ['id' => $check->id]);
        Storage::disk('local')->assertExists($october->file_path);
        Storage::disk('local')->assertExists($november->file_path);
        Storage::disk('local')->assertExists($check->file_path);
    }

    public function test_folder_delete_removes_all_its_months_but_keeps_other_types(): void
    {
        $october = $this->makeVoucher('000011', 'ADA', 10);
        $november = $this->makeVoucher('000012', 'ADA', 11);
        $check = $this->makeVoucher('000013', 'CHECK', 10);

        foreach ([$october, $november, $check] as $voucher) {
            Storage::disk('local')->put($voucher->file_path, 'sample PDF');
        }

        $this->delete(route('vouchers.destroy-folder'), ['year' => 2026, 'type' => 'ADA'])
            ->assertRedirect(route('vouchers.index', ['year' => 2026, 'type' => 'ADA']))
            ->assertSessionHas('status', '2 voucher(s) and PDF(s) moved to the recycle bin from 2026 / ADA (all months).');

        $this->assertSoftDeleted('vouchers', ['id' => $october->id]);
        $this->assertSoftDeleted('vouchers', ['id' => $november->id]);
        $this->assertDatabaseHas('vouchers', ['id' => $check->id]);
        Storage::disk('local')->assertExists($october->file_path);
        Storage::disk('local')->assertExists($november->file_path);
        Storage::disk('local')->assertExists($check->file_path);
    }

    public function test_selected_delete_removes_only_checked_pdfs_and_records(): void
    {
        $first = $this->makeVoucher('000021', 'ADA', 10);
        $unchecked = $this->makeVoucher('000022', 'ADA', 10);
        $second = $this->makeVoucher('000023', 'CHECK', 11);
        foreach ([$first, $unchecked, $second] as $voucher) {
            Storage::disk('local')->put($voucher->file_path, 'sample PDF');
        }

        $this->get(route('vouchers.index'))->assertOk()
            ->assertSee('form="delete-selected-form"', false)
            ->assertSee('name="voucher_ids[]"', false)
            ->assertSee('Delete selected');

        $this->delete(route('vouchers.destroy-selected'), [
            'voucher_ids' => [$first->id, $second->id],
        ])->assertRedirect(route('vouchers.index'))
            ->assertSessionHas('status', '2 voucher(s) and PDF(s) moved to the recycle bin from your selection.');

        $this->assertSoftDeleted('vouchers', ['id' => $first->id]);
        $this->assertSoftDeleted('vouchers', ['id' => $second->id]);
        $this->assertDatabaseHas('vouchers', ['id' => $unchecked->id]);
        Storage::disk('local')->assertExists($first->file_path);
        Storage::disk('local')->assertExists($second->file_path);
        $this->assertSame('sample PDF', Storage::disk('local')->get($unchecked->file_path));
        $this->assertCount(3, Storage::disk('local')->allFiles('vouchers'));
    }

    public function test_invalid_selection_deletes_nothing(): void
    {
        $voucher = $this->makeVoucher('000024', 'ADA', 10);
        Storage::disk('local')->put($voucher->file_path, 'sample PDF');

        $this->delete(route('vouchers.destroy-selected'), [
            'voucher_ids' => [$voucher->id, 999999],
        ])->assertSessionHasErrors('voucher_ids.1');

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id]);
        Storage::disk('local')->assertExists($voucher->file_path);
    }

    public function test_empty_selection_deletes_nothing(): void
    {
        $voucher = $this->makeVoucher('000025', 'ADA', 10);
        Storage::disk('local')->put($voucher->file_path, 'sample PDF');

        $this->delete(route('vouchers.destroy-selected'), ['voucher_ids' => []])
            ->assertSessionHasErrors('voucher_ids');

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id]);
        Storage::disk('local')->assertExists($voucher->file_path);
    }

    public function test_guests_cannot_delete_selected_pdfs(): void
    {
        $voucher = $this->makeVoucher('000026', 'ADA', 10);
        Storage::disk('local')->put($voucher->file_path, 'sample PDF');
        auth()->logout();

        $this->delete(route('vouchers.destroy-selected'), ['voucher_ids' => [$voucher->id]])
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id]);
        Storage::disk('local')->assertExists($voucher->file_path);
    }

    public function test_saved_pdf_can_be_renamed_and_still_opened_and_downloaded(): void
    {
        $voucher = $this->makeVoucher('000101', 'ADA', 10);
        $oldPath = $voucher->file_path;
        $details = $voucher->only(['dv_number', 'checkada_no', 'payee', 'amount', 'year', 'type', 'month']);
        Storage::disk('local')->put($oldPath, '%PDF-1.4 unchanged contents');

        $this->patch(route('vouchers.rename', $voucher), ['filename' => 'October payment revised.pdf'])
            ->assertRedirect(route('vouchers.show', $voucher))
            ->assertSessionHas('status', 'Saved PDF filename updated.');

        $newPath = 'vouchers/2026/ADA/10/October payment revised.pdf';
        $this->assertSame($newPath, $voucher->fresh()->file_path);
        $this->assertSame($details, $voucher->fresh()->only(array_keys($details)));
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame('%PDF-1.4 unchanged contents', Storage::disk('local')->get($newPath));
        $this->assertSame([$newPath], Storage::disk('local')->allFiles('vouchers'));
        $this->get(route('vouchers.show', $voucher))->assertOk()
            ->assertSee('Edit filename')->assertSee('October payment revised.pdf');
        $this->get(route('vouchers.pdf', $voucher))->assertOk();
        $this->get(route('vouchers.download', $voucher))->assertOk()
            ->assertDownload('October payment revised.pdf');
    }

    public function test_filename_changes_to_letter_case_are_supported(): void
    {
        $voucher = $this->makeVoucher('000102', 'ADA', 10);
        Storage::disk('local')->put($voucher->file_path, 'original PDF');

        $this->patch(route('vouchers.rename', $voucher), ['filename' => '000102.PDF'])
            ->assertRedirect(route('vouchers.show', $voucher))->assertSessionHasNoErrors();

        $this->assertSame('vouchers/2026/ADA/10/000102.PDF', $voucher->fresh()->file_path);
        $this->assertSame('original PDF', Storage::disk('local')->get($voucher->fresh()->file_path));
    }

    public function test_structured_filename_updates_voucher_numbers_payee_and_search(): void
    {
        $voucher = $this->makeVoucher('000112', 'CHECK', 7);
        Storage::disk('local')->put($voucher->file_path, '%PDF unchanged');
        $this->patchJson(route('vouchers.rename', $voucher), ['filename' => '11033_11033221_CANCELLED.pdf'])
            ->assertOk()->assertJsonPath('voucher.dv_number', '11033221')
            ->assertJsonPath('voucher.checkada_no', '11033')->assertJsonPath('voucher.payee', 'CANCELLED');
        $voucher->refresh();
        $this->assertSame('11033221', $voucher->dv_number);
        $this->assertSame('11033', $voucher->checkada_no);
        $this->assertSame('CANCELLED', $voucher->payee);
        $this->assertSame('100.00', $voucher->amount);
        $this->assertSame('%PDF unchanged', Storage::disk('local')->get($voucher->file_path));
        $this->get(route('vouchers.show', $voucher))->assertSee('11033221')->assertSee('11033');
        $this->get(route('vouchers.index', ['q' => '11033221']))->assertViewHas('vouchers', fn ($rows) => $rows->total() === 1);
        $this->get(route('vouchers.index', ['q' => '000112']))->assertViewHas('vouchers', fn ($rows) => $rows->total() === 0);
    }

    public function test_saving_an_already_renamed_filename_repairs_stale_voucher_details(): void
    {
        $voucher = $this->makeVoucher('000113', 'CHECK', 7);
        $voucher->update(['file_path' => 'vouchers/2026/CHECK/07/11033_11033221_CANCELLED.pdf']);
        Storage::disk('local')->put($voucher->file_path, '%PDF unchanged');
        $this->patchJson(route('vouchers.rename', $voucher), ['filename' => basename($voucher->file_path)])
            ->assertOk()->assertJsonPath('voucher.dv_number', '11033221');
        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'checkada_no' => '11033', 'dv_number' => '11033221', 'payee' => 'CANCELLED']);
        $this->assertSame('%PDF unchanged', Storage::disk('local')->get($voucher->file_path));
    }

    public function test_structured_rename_cannot_reuse_another_vouchers_number_pair(): void
    {
        $voucher = $this->makeVoucher('000114', 'CHECK', 7);
        $other = $this->makeVoucher('000115', 'ADA', 10);
        Storage::disk('local')->put($voucher->file_path, '%PDF first');
        Storage::disk('local')->put($other->file_path, '%PDF other');
        $this->patchJson(route('vouchers.rename', $voucher), ['filename' => '000115_000115_CANCELLED.pdf'])
            ->assertUnprocessable()->assertJsonValidationErrors('filename')
            ->assertJsonPath('errors.filename.0', fn ($message) => str_contains($message, 'October 2026 / ADA'));
        $this->assertSame('000114', $voucher->fresh()->dv_number);
        $this->assertSame('%PDF first', Storage::disk('local')->get($voucher->file_path));
        $this->assertSame('%PDF other', Storage::disk('local')->get($other->file_path));
    }

    public function test_cancelled_pdf_can_be_renamed_without_typing_the_extension(): void
    {
        $voucher = $this->makeVoucher('000108', 'CHECK', 7);
        $originalPath = $voucher->file_path;
        Storage::disk('local')->put($originalPath, '%PDF cancelled voucher');
        $this->postJson(route('vouchers.rename', $voucher), ['_method' => 'PATCH', 'filename' => 'Cancelled'])
            ->assertOk()->assertJsonPath('filename', 'Cancelled.pdf')
            ->assertJsonPath('message', 'Saved PDF filename updated.');
        $this->assertSame('vouchers/2026/CHECK/07/Cancelled.pdf', $voucher->fresh()->file_path);
        $this->assertSame('%PDF cancelled voucher', Storage::disk('local')->get($voucher->fresh()->file_path));
        Storage::disk('local')->assertMissing($originalPath);
        $this->get(route('vouchers.download', $voucher))->assertOk()->assertDownload('Cancelled.pdf');
    }

    public function test_json_rename_reports_a_collision_without_overwriting_either_pdf(): void
    {
        $voucher = $this->makeVoucher('000109', 'CHECK', 7);
        $other = $this->makeVoucher('000110', 'CHECK', 7);
        Storage::disk('local')->put($voucher->file_path, '%PDF first');
        Storage::disk('local')->put($other->file_path, '%PDF other');
        $this->patchJson(route('vouchers.rename', $voucher), ['filename' => '000110.pdf'])
            ->assertUnprocessable()->assertJsonValidationErrors('filename');
        $this->assertSame('%PDF first', Storage::disk('local')->get($voucher->fresh()->file_path));
        $this->assertSame('%PDF other', Storage::disk('local')->get($other->file_path));
    }

    public function test_background_status_checks_do_not_consume_filename_errors_before_the_page_opens(): void
    {
        $voucher = $this->makeVoucher('000111', 'CHECK', 7);
        Storage::disk('local')->put($voucher->file_path, '%PDF original');
        $this->from(route('vouchers.show', $voucher))->patch(route('vouchers.rename', $voucher), ['filename' => 'report.txt'])
            ->assertSessionHasErrors('filename');
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $this->getJson(route('vouchers.amount-status'))->assertOk();
        $this->getJson(route('vouchers.amount-status'))->assertOk();
        $this->get(route('vouchers.show', $voucher))->assertOk()
            ->assertSee('Enter a filename ending in .pdf')->assertSee('value="report.txt"', false);
        $this->get(route('vouchers.show', $voucher))->assertOk()
            ->assertDontSee('Enter a filename ending in .pdf')->assertDontSee('value="report.txt"', false);
    }

    public function test_renaming_does_not_overwrite_another_pdf(): void
    {
        $voucher = $this->makeVoucher('000103', 'ADA', 10);
        $other = $this->makeVoucher('000104', 'ADA', 10);
        Storage::disk('local')->put($voucher->file_path, 'first PDF');
        Storage::disk('local')->put($other->file_path, 'second PDF');

        $this->from(route('vouchers.show', $voucher))
            ->patch(route('vouchers.rename', $voucher), ['filename' => '000104.pdf'])
            ->assertSessionHasErrors('filename');

        $this->assertSame($voucher->file_path, $voucher->fresh()->file_path);
        $this->assertSame('first PDF', Storage::disk('local')->get($voucher->file_path));
        $this->assertSame('second PDF', Storage::disk('local')->get($other->file_path));
        $this->assertCount(2, Storage::disk('local')->allFiles('vouchers'));
    }

    #[TestWith(['../escape.pdf'])]
    #[TestWith(['folder\\escape.pdf'])]
    #[TestWith(['report.txt'])]
    #[TestWith(['CON.pdf'])]
    #[TestWith(['report:notes.pdf'])]
    #[TestWith([''])]
    public function test_invalid_filename_keeps_the_original_pdf(string $filename): void
    {
        $voucher = $this->makeVoucher('000105', 'ADA', 10);
        Storage::disk('local')->put($voucher->file_path, 'original PDF');

        $this->patch(route('vouchers.rename', $voucher), ['filename' => $filename])
            ->assertSessionHasErrors('filename');

        $this->assertSame($voucher->file_path, $voucher->fresh()->file_path);
        $this->assertSame('original PDF', Storage::disk('local')->get($voucher->file_path));
    }

    public function test_failed_filename_save_restores_the_original_pdf(): void
    {
        $voucher = $this->makeVoucher('000106', 'ADA', 10);
        Storage::disk('local')->put($voucher->file_path, 'original PDF');
        Voucher::updating(fn (): bool => false);

        try {
            $this->patch(route('vouchers.rename', $voucher), ['filename' => '11033_11033221_CANCELLED.pdf'])
                ->assertSessionHasErrors('filename');
        } finally {
            Voucher::flushEventListeners();
        }

        $this->assertSame($voucher->file_path, $voucher->fresh()->file_path);
        $this->assertSame('000106', $voucher->fresh()->dv_number);
        $this->assertSame('000106', $voucher->fresh()->checkada_no);
        $this->assertSame('Test Payee 000106', $voucher->fresh()->payee);
        $this->assertSame('original PDF', Storage::disk('local')->get($voucher->file_path));
        $this->assertSame([$voucher->file_path], Storage::disk('local')->allFiles('vouchers'));
    }

    public function test_guests_cannot_rename_saved_pdfs(): void
    {
        $voucher = $this->makeVoucher('000107', 'ADA', 10);
        Storage::disk('local')->put($voucher->file_path, 'original PDF');
        auth()->logout();

        $this->patch(route('vouchers.rename', $voucher), ['filename' => 'renamed.pdf'])
            ->assertRedirect(route('login'));

        $this->assertSame($voucher->file_path, $voucher->fresh()->file_path);
        Storage::disk('local')->assertExists($voucher->file_path);
    }

    public function test_scanned_sample_amount_is_detected_reviewed_and_saved(): void
    {
        $sample = getenv('VOUCHER_OCR_SAMPLE');
        if (! $sample) {
            $this->markTestSkipped('Set VOUCHER_OCR_SAMPLE to the supplied PLDT sample PDF to test the installed OCR tools.');
        }
        $sampleHash = hash_file('sha256', $sample);
        $copy = tempnam(sys_get_temp_dir(), 'ocr-sample-');
        copy($sample, $copy);
        $pdf = new UploadedFile($copy, '10779_2026012525_PLDT INC..pdf', 'application/pdf', null, true);

        try {
            $this->post(route('vouchers.store'), [
                'year' => 2026, 'type' => 'CHECK', 'month' => 4, 'files' => [$pdf],
            ])->assertRedirect(route('vouchers.confirm'));
        } finally {
            if (is_file($copy)) {
                unlink($copy);
            }
        }

        $files = session('voucher_upload.files');
        $id = array_key_first($files);
        $this->assertNull($files[$id]['detected_amount']);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'voucher-ocr', '--once' => true, '--tries' => 1])->assertSuccessful();
        $this->getJson(route('vouchers.upload.amounts', ['batch_id' => session('voucher_upload.batch_id')]))
            ->assertOk()->assertJsonPath("files.$id.amount", '5250.00');
        $this->get(route('vouchers.confirm'))->assertOk()
            ->assertSee('value="5250.00"', false)->assertSee('Automatically read from the PDF');

        $this->post(route('vouchers.finalize'), ['batch_id' => session('voucher_upload.batch_id'), 'vouchers' => [$id => [
            'checkada_no' => '10779', 'dv_number' => '2026012525', 'payee' => 'PLDT INC.', 'amount' => '5250.00',
        ]]])->assertRedirect(route('vouchers.index'));

        $this->assertDatabaseHas('vouchers', ['dv_number' => '2026012525', 'amount' => '5250.00']);
        $this->assertSame($sampleHash, hash_file('sha256', $sample));
    }

    private function makeVoucher(string $dvNumber, string $type, int $month): Voucher
    {
        return Voucher::create([
            'checkada_no' => $dvNumber,
            'dv_number' => $dvNumber,
            'payee' => 'Test Payee '.$dvNumber,
            'amount' => 100.00,
            'year' => 2026,
            'type' => $type,
            'month' => $month,
            'file_path' => "vouchers/2026/{$type}/".sprintf('%02d', $month)."/{$dvNumber}.pdf",
            'uploaded_by' => auth()->id(),
        ]);
    }
}
