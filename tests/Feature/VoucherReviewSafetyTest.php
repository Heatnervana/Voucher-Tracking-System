<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoucherReviewSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $user = User::create(['username' => 'review-tester', 'name' => 'Reviewer', 'password' => 'test-password']);
        $user->forceFill(['must_change_password' => false, 'is_admin' => true])->save();
        $this->actingAs($user);
        VoucherYear::create(['year' => 2026]);
    }

    public function test_a_stale_review_cannot_cancel_the_current_batch(): void
    {
        $old = $this->batch(1);
        $current = $this->batch(1);
        $this->withSession(['voucher_upload' => $current])->deleteJson(route('vouchers.cancel-upload'), ['batch_id' => $old['batch_id']])->assertConflict();
        $this->assertSame($current, session('voucher_upload'));
        Storage::disk('local')->assertExists(current($current['files'])['path']);
    }

    public function test_cancel_requires_a_batch_id_and_only_removes_the_matching_upload(): void
    {
        $batch = $this->batch(1);
        $this->withSession(['voucher_upload' => $batch])->deleteJson(route('vouchers.cancel-upload'))->assertUnprocessable()->assertJsonValidationErrors('batch_id');
        Storage::disk('local')->assertExists(current($batch['files'])['path']);
        $this->delete(route('vouchers.cancel-upload'), ['batch_id' => $batch['batch_id']])->assertRedirect(route('vouchers.index'));
        Storage::disk('local')->assertMissing(current($batch['files'])['path']);
        $this->assertNull(session('voucher_upload'));
    }

    public function test_a_stale_review_cannot_save_the_current_batch(): void
    {
        $batch = $this->batch(1);
        $this->withSession(['voucher_upload' => $batch])->postJson(route('vouchers.finalize'), [
            'batch_id' => (string) Str::uuid(), 'vouchers' => $this->details($batch),
        ])->assertConflict();
        $this->assertDatabaseCount('vouchers', 0);
        Storage::disk('local')->assertExists(current($batch['files'])['path']);
    }

    public function test_three_hundred_reviewed_pdfs_can_be_saved_with_one_form_field(): void
    {
        $batch = $this->batch(300);
        $payload = ['batch_id' => $batch['batch_id'], 'voucher_details' => json_encode($this->details($batch), JSON_THROW_ON_ERROR)];
        parse_str(http_build_query($payload), $parsed);
        $this->withSession(['voucher_upload' => $batch])->post(route('vouchers.finalize'), $parsed)->assertRedirect(route('vouchers.index'));
        $this->assertDatabaseCount('vouchers', 300);
        $this->assertCount(300, Storage::disk('local')->allFiles('vouchers'));
        $this->assertSame([], Storage::disk('local')->allFiles('staging'));
    }

    public function test_invalid_json_leaves_the_staged_files_available(): void
    {
        $batch = $this->batch(1);
        $this->withSession(['voucher_upload' => $batch])->postJson(route('vouchers.finalize'), [
            'batch_id' => $batch['batch_id'], 'voucher_details' => '{invalid',
        ])->assertUnprocessable()->assertJsonValidationErrors('voucher_details');
        $this->assertDatabaseCount('vouchers', 0);
        Storage::disk('local')->assertExists(current($batch['files'])['path']);
    }

    public function test_json_scalar_details_are_rejected_before_saving(): void
    {
        $batch = $this->batch(1);
        $this->withSession(['voucher_upload' => $batch])->postJson(route('vouchers.finalize'), [
            'batch_id' => $batch['batch_id'], 'voucher_details' => 'true',
        ])->assertUnprocessable()->assertJsonValidationErrors('vouchers');
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_scalar_voucher_entries_are_rejected_without_a_server_error(): void
    {
        $batch = $this->batch(1);
        $id = array_key_first($batch['files']);
        $this->withSession(['voucher_upload' => $batch])->postJson(route('vouchers.finalize'), [
            'batch_id' => $batch['batch_id'], 'voucher_details' => json_encode([$id => true], JSON_THROW_ON_ERROR),
        ])->assertUnprocessable()->assertJsonValidationErrors('vouchers.'.$id);
        $this->assertDatabaseCount('vouchers', 0);
        Storage::disk('local')->assertExists($batch['files'][$id]['path']);
    }

    private function batch(int $count): array
    {
        $files = [];
        foreach (range(1, $count) as $number) {
            $id = (string) Str::uuid();
            $path = "staging/$id.pdf";
            Storage::disk('local')->put($path, '%PDF-1.4 test');
            $files[$id] = ['path' => $path, 'original_name' => "voucher-$number.pdf", 'detected_amount' => null,
                'checkada_no' => (string) (10779 + $number), 'dv_number' => sprintf('%010d', $number), 'payee' => "Payee $number", 'size' => 13];
        }

        return ['batch_id' => (string) Str::uuid(), 'year' => 2026, 'type' => 'CHECK', 'month' => 4, 'files' => $files];
    }

    private function details(array $batch): array
    {
        return array_map(fn (array $file): array => array_intersect_key($file, array_flip(['checkada_no', 'dv_number', 'payee'])) + ['amount' => '5250.00'], $batch['files']);
    }
}
