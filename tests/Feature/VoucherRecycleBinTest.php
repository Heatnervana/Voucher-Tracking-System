<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class VoucherRecycleBinTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_pdf_keeps_its_bytes_and_removes_it_from_the_active_library(): void
    {
        $voucher = $this->prepareVoucher();
        $this->delete(route('vouchers.destroy', $voucher))->assertRedirect(route('vouchers.index'));
        $this->assertSoftDeleted('vouchers', ['id' => $voucher->id]);
        $this->assertSame('%PDF original', Storage::disk('local')->get($voucher->file_path));
        $this->get(route('vouchers.show', $voucher))->assertNotFound();
        $this->get(route('vouchers.pdf', $voucher))->assertNotFound();
        $this->get(route('vouchers.index', ['q' => $voucher->dv_number]))->assertViewHas('vouchers', fn ($rows) => $rows->total() === 0);
        $this->get('/recycle-bin')->assertOk()->assertSee($voucher->dv_number);
    }

    public function test_a_trashed_pdf_can_be_previewed_restored_and_found_again(): void
    {
        $voucher = $this->prepareVoucher();
        $this->delete(route('vouchers.destroy', $voucher))->assertRedirect();
        $this->get('/recycle-bin/'.$voucher->id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post('/recycle-bin/'.$voucher->id.'/restore')->assertRedirect('/recycle-bin');
        $this->assertNotSoftDeleted('vouchers', ['id' => $voucher->id]);
        $this->assertSame('25.00', $voucher->fresh()->amount);
        $this->get(route('vouchers.show', $voucher))->assertOk();
        $this->assertSame('%PDF original', Storage::disk('local')->get($voucher->file_path));
    }

    public function test_only_a_trashed_pdf_can_be_permanently_removed(): void
    {
        $voucher = $this->prepareVoucher();
        $this->delete('/recycle-bin/'.$voucher->id)->assertNotFound();
        $this->delete(route('vouchers.destroy', $voucher))->assertRedirect();
        $this->delete('/recycle-bin/'.$voucher->id)->assertRedirect('/recycle-bin');
        $this->assertDatabaseMissing('vouchers', ['id' => $voucher->id]);
        Storage::disk('local')->assertMissing($voucher->file_path);
    }

    public function test_selected_pdfs_can_be_trashed_and_restored_together(): void
    {
        $first = $this->prepareVoucher();
        $second = $first->replicate()->fill(['dv_number' => '123457', 'file_path' => 'vouchers/2026/CHECK/07/second.pdf']);
        $second->save();
        Storage::disk('local')->put($second->file_path, '%PDF second');
        $ids = [$first->id, $second->id];
        $this->delete(route('vouchers.destroy-selected'), ['voucher_ids' => $ids])->assertRedirect();
        $this->assertSame(2, Voucher::onlyTrashed()->count());
        $this->post('/recycle-bin/restore-selected', ['voucher_ids' => $ids])->assertRedirect('/recycle-bin');
        $this->assertSame(2, Voucher::count());
        $this->assertSame('%PDF second', Storage::disk('local')->get($second->file_path));
    }

    public function test_missing_pdf_prevents_restore_without_changing_its_recycle_record(): void
    {
        $voucher = $this->prepareVoucher();
        $this->delete(route('vouchers.destroy', $voucher))->assertRedirect();
        Storage::disk('local')->delete($voucher->file_path);
        $this->from('/recycle-bin')->post('/recycle-bin/'.$voucher->id.'/restore')->assertSessionHasErrors('recycle');
        $this->assertSoftDeleted('vouchers', ['id' => $voucher->id]);
    }

    public function test_failed_purge_restores_the_quarantined_pdf_and_record(): void
    {
        $voucher = $this->prepareVoucher();
        $this->delete(route('vouchers.destroy', $voucher))->assertRedirect();
        Voucher::deleting(fn (): bool => false);
        try {
            $this->from('/recycle-bin')->delete('/recycle-bin/'.$voucher->id)->assertSessionHasErrors('recycle');
        } finally {
            Voucher::flushEventListeners();
        }
        $this->assertSoftDeleted('vouchers', ['id' => $voucher->id]);
        $this->assertSame('%PDF original', Storage::disk('local')->get($voucher->file_path));
    }

    public function test_trash_and_restore_stop_stale_ocr_without_restarting_a_second_read(): void
    {
        $voucher = $this->prepareVoucher();
        $voucher->update(['amount' => null, 'amount_status' => 'pending', 'amount_read_token' => 'old-reading']);
        $this->delete(route('vouchers.destroy', $voucher))->assertRedirect();
        $this->post('/recycle-bin/'.$voucher->id.'/restore')->assertRedirect();
        $this->assertSame('manual', $voucher->fresh()->amount_status);
        $this->assertNotSame('old-reading', $voucher->fresh()->amount_read_token);
        $this->assertNull($voucher->fresh()->amount);
    }

    public function test_bulk_purge_rolls_back_every_pdf_and_audit_if_the_second_audit_write_fails(): void
    {
        $first = $this->prepareVoucher();
        $second = $first->replicate()->fill(['dv_number' => '11033222', 'file_path' => 'vouchers/2026/CHECK/07/second.pdf']);
        $second->save();
        Storage::disk('local')->put($second->file_path, '%PDF second');
        $ids = [$first->id, $second->id];
        $this->delete(route('vouchers.destroy-selected'), ['voucher_ids' => $ids])->assertRedirect();
        $writes = 0;
        ActivityLog::creating(function (ActivityLog $activity) use (&$writes): void {
            if ($activity->action === 'voucher.purged' && ++$writes === 2) {
                throw new \RuntimeException('Audit storage unavailable');
            }
        });
        try {
            $this->from('/recycle-bin')->delete('/recycle-bin/selected', ['voucher_ids' => $ids])->assertSessionHasErrors('recycle');
        } finally {
            ActivityLog::flushEventListeners();
        }
        $this->assertSame(2, Voucher::onlyTrashed()->count());
        $this->assertSame(0, ActivityLog::where('action', 'voucher.purged')->count());
        $this->assertSame('%PDF original', Storage::disk('local')->get($first->file_path));
        $this->assertSame('%PDF second', Storage::disk('local')->get($second->file_path));
        $this->assertCount(2, Storage::disk('local')->allFiles('vouchers'));
    }

    public function test_reuploading_a_recycled_number_pair_shows_the_recycle_bin_location(): void
    {
        $voucher = $this->prepareVoucher();
        $this->delete(route('vouchers.destroy', $voucher))->assertRedirect();
        $id = (string) Str::uuid();
        $batch = (string) Str::uuid();
        $path = 'staging/'.$id.'.pdf';
        Storage::disk('local')->put($path, '%PDF new');
        $this->withSession(['voucher_upload' => ['batch_id' => $batch, 'year' => 2026, 'type' => 'CHECK', 'month' => 7,
            'folder_action' => 'replace', 'files' => [$id => ['path' => $path, 'original_name' => 'new.pdf']]]])
            ->post(route('vouchers.finalize'), ['batch_id' => $batch, 'vouchers' => [$id => [
                'checkada_no' => $voucher->checkada_no, 'dv_number' => $voucher->dv_number, 'payee' => 'New Payee', 'amount' => '25.00',
            ]]])->assertSessionHas('upload_errors', fn ($errors) => str_contains($errors[0], 'recycle bin') && str_contains($errors[0], 'July 2026'));
        $this->assertSoftDeleted('vouchers', ['id' => $voucher->id]);
        $this->assertSame('%PDF original', Storage::disk('local')->get($voucher->file_path));
    }

    #[TestWith(['GET', '/recycle-bin'])]
    #[TestWith(['POST', '/recycle-bin/1/restore'])]
    #[TestWith(['POST', '/recycle-bin/restore-selected'])]
    #[TestWith(['DELETE', '/recycle-bin/1'])]
    #[TestWith(['DELETE', '/recycle-bin/selected'])]
    #[TestWith(['GET', '/recycle-bin/1/pdf'])]
    public function test_staff_and_guests_cannot_use_the_recycle_bin(string $method, string $path): void
    {
        $voucher = $this->prepareVoucher();
        $this->delete(route('vouchers.destroy', $voucher))->assertRedirect();
        $this->actingAs(User::factory()->create())->json($method, $path, ['voucher_ids' => [$voucher->id]])->assertForbidden();
        $this->post('/logout');
        $this->json($method, $path, ['voucher_ids' => [$voucher->id]])->assertUnauthorized();
    }

    private function prepareVoucher(): Voucher
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->administrator()->create());
        VoucherYear::create(['year' => 2026]);
        $path = 'vouchers/2026/CHECK/07/example.pdf';
        Storage::disk('local')->put($path, '%PDF original');

        return Voucher::create(['checkada_no' => '11033', 'dv_number' => '11033221', 'payee' => 'Test Payee', 'amount' => '25.00',
            'year' => 2026, 'type' => 'CHECK', 'month' => 7, 'file_path' => $path, 'uploaded_by' => auth()->id()]);
    }
}
