<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use App\PdfAmountReader;
use App\VoucherUtilityCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class UtilityVoucherCategoryTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['electrical', 'electricity'])]
    #[TestWith(['water', 'water'])]
    public function test_first_page_text_produces_an_amount_and_category(string $utility, string $category): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('sample.pdf', '%PDF-1.4 synthetic sample');
        $this->mock(ExecutableFinder::class)->shouldReceive('find')->andReturnUsing(
            fn (string $name): ?string => $name === 'pdftotext' ? 'pdftotext' : null
        );
        $xml = <<<XML
        <doc><page>
        <word xMin="326" yMin="90" xMax="357" yMax="98">Amount</word>
        <word xMin="326" yMin="102" xMax="357" yMax="110">5250.00</word>
        <word xMin="38" yMin="90" xMax="78" yMax="98">Description:</word>
        <word xMin="82" yMin="90" xMax="115" yMax="98">Payment</word>
        <word xMin="117" yMin="90" xMax="130" yMax="98">of</word>
        <word xMin="132" yMin="90" xMax="167" yMax="98">{$utility}</word>
        <word xMin="170" yMin="90" xMax="199" yMax="98">services</word>
        <word xMin="82" yMin="100" xMax="139" yMax="108">consumption</word>
        </page></doc>
        XML;
        Process::fake(['*' => Process::result(output: $xml)]);
        $this->assertSame(['amount' => '5250.00', 'utility' => $category],
            app(PdfAmountReader::class)->readDetails(Storage::disk('local')->path('sample.pdf')));
        Process::assertRanTimes(fn (): bool => true, 1);
    }

    public function test_mixed_folder_categorizes_each_file_and_flags_unreadable_descriptions_without_setup(): void
    {
        $this->prepareUpload(true);
        $this->get(route('vouchers.create'))->assertOk()->assertDontSee('name="utility_batch"', false);
        $files = $this->upload([
            '101_2026000101_TEST ELECTRIC.pdf',
            '102_2026000102_TEST WATER.pdf',
            '103_2026000103_UNKNOWN UTILITY.pdf',
        ]);
        $this->get(route('vouchers.confirm'))->assertOk()->assertDontSee('[utility_category]', false);
        $this->mock(PdfAmountReader::class)->shouldReceive('readDetails')
            ->times(3)->andReturn(
                ['amount' => '521.00', 'utility' => 'electricity'],
                ['amount' => '300.00', 'utility' => 'water'],
                ['amount' => null, 'utility' => 'review'],
            );
        foreach ($files as $id => $file) {
            $this->work();
        }

        $this->getJson(route('vouchers.upload.amounts', ['batch_id' => session('voucher_upload.batch_id')]))
            ->assertJsonPath('files.'.array_key_first($files).'.category', 'electricity');
        $this->finalize($files);
        $this->assertSame(['electricity', 'water', null], Voucher::orderBy('id')->pluck('utility_category')->all());
        $this->assertSame(1, Voucher::needsUtilityCategory()->count());

        $unresolved = Voucher::where('dv_number', '2026000103')->sole();
        $this->get(route('vouchers.index', ['utility_status' => 'missing']))
            ->assertViewHas('vouchers', fn ($rows) => $rows->pluck('id')->all() === [$unresolved->id]);
        $this->get(route('profile.edit'))->assertSee('1 voucher needs a category');
        $this->patch(route('vouchers.update-category', $unresolved), ['utility_category' => 'water'])->assertSessionHasNoErrors();
        $this->assertSame('water', $unresolved->fresh()->utility_category);
        $this->get(route('profile.edit'))->assertDontSee('1 voucher needs a category');

        $this->actingAs(User::factory()->create(['must_change_password' => false]));
        $this->get(route('vouchers.index', ['category' => 'water', 'month' => 10]))
            ->assertOk()->assertDontSee('id="amount-notification"', false)
            ->assertViewHas('vouchers', fn ($rows) => $rows->pluck('dv_number')->all() === ['2026000102', '2026000103']);
        $this->patch(route('vouchers.update-category', $unresolved), ['utility_category' => 'electricity'])->assertForbidden();
        $this->getJson(route('vouchers.amount-status'))->assertJsonPath('category_manual_count', null);
    }

    public function test_background_category_read_continues_with_a_manual_amount_and_respects_manual_decisions(): void
    {
        $this->prepareUpload(true);
        $files = $this->upload(['111_2026000111_TEST UTILITY.pdf']);
        $this->finalize($files, '75.00');
        $voucher = Voucher::sole();
        $this->assertSame('pending', $voucher->utility_status);
        $this->assertSame('75.00', $voucher->amount);
        $this->assertSame(0, Voucher::needsUtilityCategory()->count());

        $this->mock(PdfAmountReader::class)->shouldReceive('readDetails')->once()->andReturnUsing(function () use ($voucher): array {
            $voucher->update(['utility_category' => 'water', 'utility_status' => 'none']);

            return ['amount' => '89.00', 'utility' => 'electricity'];
        });
        $this->work(); // The staged job sees that the file has already moved.
        $this->work();
        $this->assertSame('water', $voucher->fresh()->utility_category);
        $this->assertSame('75.00', $voucher->fresh()->amount);
    }

    public function test_clear_filename_evidence_is_available_immediately_and_wins_over_ocr(): void
    {
        $this->prepareUpload(true);
        $files = $this->upload([
            '121_2026000121_PRIMEWATER INFRASTRUCTURE CORP.pdf',
            '122_2026000122_MIS. OCC. I ELECTRIC COOP INC. (MOELCI-I).pdf',
        ]);
        [$water, $electricity] = array_keys($files);
        $status = route('vouchers.upload.amounts', ['batch_id' => session('voucher_upload.batch_id')]);
        $this->getJson($status)->assertJsonPath("files.$water.category", 'water')
            ->assertJsonPath("files.$electricity.category", 'electricity');

        $reader = $this->mock(PdfAmountReader::class);
        $reader->shouldReceive('read')->twice()->andReturn('25.00');
        $reader->shouldNotReceive('readDetails');
        $this->work();
        $this->work();
        $this->finalize($files);

        $this->assertSame(['water', 'electricity'], Voucher::orderBy('id')->pluck('utility_category')->all());
        $this->assertSame(0, Voucher::needsUtilityCategory()->count());
    }

    public function test_only_unresolved_utility_evidence_is_flagged_and_a_normal_voucher_stores_no_category(): void
    {
        $this->prepareUpload(true);
        $files = $this->upload([
            '123_2026000123_PLDT INC.pdf',
            '124_2026000124_FUEL SUPPLIER.pdf',
            '125_2026000125_UTILITY BILLS.pdf',
        ]);
        [$telephone, $fuel, $unclear] = array_keys($files);
        foreach ([$telephone => null, $fuel => 'unmatched', $unclear => 'review'] as $id => $utility) {
            Cache::put('voucher-amount:'.$id, ['status' => 'ready', 'amount' => '25.00', 'utility' => $utility], 3600);
        }

        $this->finalize($files);
        $this->assertSame([null, null, null], Voucher::orderBy('id')->pluck('utility_category')->all());
        $this->assertSame(['2026000125'], Voucher::needsUtilityCategory()->pluck('dv_number')->all());
        $this->getJson(route('vouchers.amount-status'))->assertJsonPath('category_manual_count', 1)->assertJsonPath('manual_count', 0);

        $regularVoucher = Voucher::where('dv_number', '2026000123')->sole();
        $this->get(route('vouchers.index'))->assertOk()->assertDontSee('Uncategorized');
        $this->get(route('vouchers.show', $regularVoucher))->assertOk()
            ->assertSee('Leave without a category')->assertDontSee('Uncategorized')->assertSee('data-saved-category-row hidden', false);

        $unresolvedVoucher = Voucher::where('dv_number', '2026000125')->sole();
        $this->patch(route('vouchers.update-category', $unresolvedVoucher), ['utility_category' => 'other'])->assertSessionHasNoErrors();
        $this->assertNull($unresolvedVoucher->fresh()->utility_category);
        $this->get(route('vouchers.show', $unresolvedVoucher))->assertOk()->assertSee('data-saved-category-row hidden', false);
        $this->assertSame(0, Voucher::needsUtilityCategory()->count());
    }

    public function test_failed_background_read_alerts_after_saving_without_overwriting_a_manual_amount(): void
    {
        $this->prepareUpload(true);
        $this->finalize($this->upload(['131_2026000131_UTILITY BILLS.pdf']), '55.00');
        $voucher = Voucher::sole();
        $this->getJson(route('vouchers.amount-status'))->assertJsonPath('category_manual_count', 0);
        $this->mock(PdfAmountReader::class)->shouldReceive('readDetails')->once()->andThrow(new \RuntimeException('OCR unavailable'));

        $this->work();
        $this->work();

        $this->assertSame('55.00', $voucher->fresh()->amount);
        $this->getJson(route('vouchers.amount-status'))->assertJsonPath('category_manual_count', 1)->assertJsonPath('manual_count', 0);
    }

    #[TestWith(['WATER BILLS.pdf', 'water'])]
    #[TestWith(['ELECTRICITY BILLS.pdf', 'electricity'])]
    public function test_plural_bill_names_identify_the_utility(string $filename, string $category): void
    {
        $this->assertSame($category, VoucherUtilityCategory::fromName($filename));
    }

    public function test_manual_clear_wins_if_ocr_finishes_after_the_record_was_loaded(): void
    {
        $this->prepareUpload(true);
        $this->finalize($this->upload(['141_2026000141_UTILITY BILLS.pdf']), '55.00');
        $voucher = Voucher::sole();
        $reads = 0;
        Voucher::retrieved(function (Voucher $loaded) use ($voucher, &$reads): void {
            if ($loaded->is($voucher) && ++$reads === 2) {
                Voucher::whereKey($loaded->id)->update(['utility_category' => 'water', 'utility_status' => 'none']);
            }
        });

        $this->patch(route('vouchers.update-category', $voucher), ['utility_category' => 'other'])->assertSessionHasNoErrors();
        $voucher->refresh();

        $this->assertNull($voucher->utility_category);
        $this->assertSame('confirmed', $voucher->utility_status);
        $activity = ActivityLog::where('action', 'voucher.category_changed')->sole();
        $this->assertSame('water', $activity->before['utility_category']);
        $this->assertNull($activity->after['utility_category']);
    }

    private function prepareUpload(bool $admin): void
    {
        Storage::fake('local');
        $account = $admin ? User::factory()->administrator() : User::factory();
        $this->actingAs($account->create(['must_change_password' => false]));
        VoucherYear::create(['year' => 2026]);
    }

    private function upload(array $names): array
    {
        $this->post(route('vouchers.store'), [
            'year' => 2026, 'type' => 'CHECK', 'month' => 10,
            'files' => array_map(fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n"), $names),
        ])->assertRedirect(route('vouchers.confirm'));

        return session('voucher_upload.files');
    }

    private function finalize(array $files, ?string $amount = null): void
    {
        $details = array_map(fn (array $file): array => [
            'checkada_no' => $file['checkada_no'], 'dv_number' => $file['dv_number'],
            'payee' => $file['payee'], 'amount' => $amount,
        ], $files);
        $this->post(route('vouchers.finalize'), [
            'batch_id' => session('voucher_upload.batch_id'), 'voucher_details' => json_encode($details, JSON_THROW_ON_ERROR),
        ])->assertRedirect(route('vouchers.index'));
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'voucher-ocr', '--once' => true, '--tries' => 1])->assertSuccessful();
    }
}
