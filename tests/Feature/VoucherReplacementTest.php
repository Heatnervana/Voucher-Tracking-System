<?php

namespace Tests\Feature;

use App\Jobs\ReadSavedVoucherAmount;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class VoucherReplacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $user = User::create(['username' => 'replace-admin', 'name' => 'Administrator', 'password' => 'TestPassword123!']);
        $user->forceFill(['is_admin' => true, 'must_change_password' => false])->save();
        $this->actingAs($user);
        VoucherYear::create(['year' => 2026]);
    }

    public function test_existing_month_requires_a_choice_before_starting(): void
    {
        $existing = $this->voucher('100001', '11');
        $this->postJson(route('vouchers.upload.start'), $this->folder())->assertConflict()
            ->assertJsonPath('code', 'existing_folder')->assertJsonPath('existing_files', 1);
        $this->assertNull(session('voucher_upload'));
        Storage::disk('local')->assertExists($existing->file_path);
    }

    public function test_confirmed_replacement_updates_matching_check_and_keeps_other_pdfs(): void
    {
        $existing = $this->voucher('100001', '11');
        $untouched = $this->voucher('100002', '22');
        $this->start('replace');
        $this->save('11', '100001')->assertRedirect(route('vouchers.index'));
        $this->assertDatabaseCount('vouchers', 2);
        $this->assertSame('Replacement', $existing->fresh()->payee);
        $this->assertSame('%PDF replacement', Storage::disk('local')->get($existing->fresh()->file_path));
        $this->assertSame('%PDF original', Storage::disk('local')->get($untouched->file_path));
    }

    public function test_keep_choice_preserves_existing_matching_pdf(): void
    {
        $existing = $this->voucher('100001', '11');
        $this->start('keep');
        $this->save('11', '100001')->assertRedirect(route('vouchers.confirm'))->assertSessionHas('upload_errors', fn ($errors) => str_contains($errors[0], 'June 2026 / CHECK') && str_contains($errors[0], $existing->file_path));
        $this->assertSame('%PDF original', Storage::disk('local')->get($existing->file_path));
        $this->assertDatabaseCount('vouchers', 1);
    }

    public function test_record_changed_after_consent_is_not_replaced(): void
    {
        $existing = $this->voucher('100001', '11');
        $this->start('replace');
        $existing->update(['amount' => '99.00']);
        $this->save('11', '100001')->assertRedirect(route('vouchers.confirm'))->assertSessionHas('upload_errors');
        $this->assertSame('99.00', $existing->fresh()->amount);
        $this->assertSame('%PDF original', Storage::disk('local')->get($existing->file_path));
    }

    public function test_replace_mode_adds_a_different_pair_without_replacing_shared_check_number(): void
    {
        $existing = $this->voucher('100001', '11');
        $this->start('replace');
        $this->save('11', '100099')->assertRedirect(route('vouchers.index'));
        $this->assertDatabaseCount('vouchers', 2);
        $this->assertSame('%PDF original', Storage::disk('local')->get($existing->file_path));
        $this->assertSame('Original', $existing->fresh()->payee);
    }

    public function test_failed_database_update_restores_original_and_keeps_new_file_in_review(): void
    {
        $existing = $this->voucher('100001', '11');
        $this->start('replace');
        Log::spy();
        DB::statement("CREATE TRIGGER fail_replacement BEFORE UPDATE ON vouchers BEGIN SELECT RAISE(ABORT, 'Database failure'); END");
        try {
            $this->save('11', '100001')->assertRedirect(route('vouchers.confirm'));
        } finally {
            DB::statement('DROP TRIGGER fail_replacement');
        }
        Log::shouldHaveReceived('warning')->with('Voucher save rejected', \Mockery::on(fn ($context) => str_contains($context['reason'], 'Database failure')))->once();
        $this->assertSame('%PDF original', Storage::disk('local')->get($existing->file_path));
        $this->assertCount(1, session('voucher_upload.files'));
        Storage::disk('local')->assertExists(current(session('voucher_upload.files'))['path']);
    }

    public function test_late_old_ocr_amount_does_not_carry_into_the_replacement_pdf(): void
    {
        Queue::fake([ReadSavedVoucherAmount::class]);
        $existing = $this->voucher('100001', '11');
        $existing->update(['amount' => null, 'amount_status' => 'pending', 'amount_read_token' => 'old-read']);
        $this->start('replace');
        $disk = Storage::disk('local');
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('move')->andReturnUsing(function ($from, $to) use ($disk, $existing) {
            if (str_starts_with($from, 'staging/')) {
                Voucher::whereKey($existing->id)->update(['amount' => '5250.00', 'amount_status' => 'ready']);
            }

            return $disk->move($from, $to);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        $this->save('11', '100001', null)->assertRedirect(route('vouchers.index'));
        $this->assertNull($existing->fresh()->amount);
        $this->assertSame('pending', $existing->fresh()->amount_status);
        $this->assertNotSame('old-read', $existing->fresh()->amount_read_token);
        Queue::assertPushed(ReadSavedVoucherAmount::class, fn ($job) => $job->readToken === $existing->fresh()->amount_read_token);
    }

    private function folder(): array
    {
        return ['year' => 2026, 'type' => 'CHECK', 'month' => 6];
    }

    private function start(string $action): void
    {
        $this->postJson(route('vouchers.upload.start'), $this->folder() + ['folder_action' => $action])->assertOk();
    }

    private function save(string $check, string $dv, ?string $amount = '25.00'): TestResponse
    {
        $upload = session('voucher_upload');
        $id = (string) Str::uuid();
        $path = 'staging/'.$id.'.pdf';
        Storage::disk('local')->put($path, '%PDF replacement');
        if ($amount === null) {
            Cache::put('voucher-amount:'.$id, ['status' => 'pending', 'amount' => null], 3600);
        }
        $upload['files'][$id] = ['path' => $path, 'original_name' => 'replacement.pdf'];

        return $this->withSession(['voucher_upload' => $upload])->post(route('vouchers.finalize'), [
            'batch_id' => $upload['batch_id'], 'vouchers' => [$id => ['checkada_no' => $check, 'dv_number' => $dv, 'payee' => 'Replacement', 'amount' => $amount]],
        ]);
    }

    private function voucher(string $dv, string $check): Voucher
    {
        $path = 'vouchers/2026/CHECK/06/'.$dv.'.pdf';
        Storage::disk('local')->put($path, '%PDF original');

        return Voucher::create(['checkada_no' => $check, 'dv_number' => $dv, 'payee' => 'Original', 'amount' => '10.00', 'amount_status' => 'ready',
            'year' => 2026, 'type' => 'CHECK', 'month' => 6, 'file_path' => $path, 'uploaded_by' => auth()->id()]);
    }
}
