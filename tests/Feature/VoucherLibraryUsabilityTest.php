<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class VoucherLibraryUsabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unsuccessful_search_offers_reset_instead_of_uploading(): void
    {
        $this->signIn(true);
        $this->get(route('vouchers.index', ['q' => 'missing']))->assertOk()
            ->assertSee('No vouchers match your search')->assertSee('Clear search and filters')
            ->assertDontSee('Upload PDFs to start your library.');
    }

    public function test_empty_library_and_management_controls_are_role_appropriate(): void
    {
        $this->signIn(true);
        $response = $this->get(route('vouchers.index'))->assertOk()->assertSee('No vouchers here yet');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//details[@id="record-management" and not(@open)]//form[@id="delete-selected-form"]')->length);
        auth()->user()->forceFill(['is_admin' => false])->save();
        $this->get(route('vouchers.index'))->assertOk()->assertDontSee('Manage records')->assertDontSee('Delete selected');
    }

    public function test_folder_deletion_is_inside_explicit_management_controls(): void
    {
        $this->signIn(true);
        $response = $this->get(route('vouchers.index', ['year' => 2026, 'type' => 'ADA', 'month' => 10]))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//details[@id="record-management"]//div[@class="bulk-delete-actions"]/form')->length);
        $response->assertSee('Clear search and filters');
    }

    public function test_library_shows_quick_filters_and_summarizes_the_first_voucher(): void
    {
        $this->signIn(true);
        $voucher = Voucher::create(['checkada_no' => '123', 'dv_number' => '123456', 'payee' => 'Sample supplier',
            'amount' => '25.00', 'year' => 2026, 'type' => 'CHECK', 'month' => 7,
            'file_path' => 'vouchers/2026/CHECK/07/example.pdf', 'uploaded_by' => auth()->id()]);

        $response = $this->get(route('vouchers.index'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);

        $this->assertSame(1, $xpath->query('//nav[@aria-label="Main navigation" and not(ancestor::header)]')->length);
        $filterNames = [];
        foreach ($xpath->query('//form[@role="search"]//select[not(ancestor::details)]') as $filter) {
            $filterNames[] = $filter->getAttribute('name');
        }
        $this->assertSame(['type', 'year', 'month', 'amount_status'], $filterNames);
        $response->assertDontSee('More filters');
        $this->assertSame('Sample supplier', trim($xpath->query('//*[@data-preview-title]')->item(0)->textContent));
        $this->assertSame('PHP 25.00', trim($xpath->query('//*[@data-preview-amount]')->item(0)->textContent));
        $this->assertSame(route('vouchers.show', $voucher), $xpath->query('//*[@data-preview-open]')->item(0)->getAttribute('href'));

        $this->get(route('vouchers.index', ['q' => 'no matching voucher']))->assertOk()->assertDontSee('data-preview-title', false);
    }

    public function test_library_has_a_separate_month_column_for_administrators_and_staff(): void
    {
        $this->signIn(true);
        Voucher::create(['checkada_no' => '123', 'dv_number' => '123456', 'payee' => 'Test Payee', 'amount' => null,
            'amount_status' => 'manual', 'year' => 2026, 'type' => 'CHECK', 'month' => 7,
            'file_path' => 'vouchers/2026/CHECK/07/example.pdf', 'uploaded_by' => auth()->id()]);
        foreach ([true, false] as $isAdmin) {
            auth()->user()->forceFill(['is_admin' => $isAdmin])->save();
            $response = $this->get(route('vouchers.index'))->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $headers = [];
            foreach ($xpath->query('//table/thead/tr/th[not(@class="management-cell")]') as $header) {
                $headers[] = trim($header->textContent);
            }
            $this->assertSame(['DV number', 'Check / ADA', 'Payee', 'Amount', 'Folder', 'Month', 'Actions'], $headers);
            $this->assertSame('July', trim($xpath->query('//td[@data-label="Month"]')->item(0)->textContent));
            $this->assertSame('CHECK2026', preg_replace('/\s+/u', '', $xpath->query('//td[@data-label="Folder"]')->item(0)->textContent));
        }
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_check_numbers_sort_ascending_across_months_and_years(bool $admin): void
    {
        $this->signIn($admin);
        VoucherYear::create(['year' => 2025]);
        foreach ([['2026221', 2026, 7], ['2026219', 2025, 12], ['2026220', 2026, 8]] as $index => [$checkNumber, $year, $month]) {
            Voucher::create(['checkada_no' => $checkNumber, 'dv_number' => (string) (100003 - $index),
                'payee' => 'Sequence Payee', 'amount' => null, 'amount_status' => 'manual',
                'year' => $year, 'type' => 'CHECK', 'month' => $month,
                'file_path' => 'vouchers/sequence-'.$index.'.pdf', 'uploaded_by' => auth()->id()]);
        }

        $response = $this->get(route('vouchers.index', ['q' => 'Sequence Payee']))->assertOk();

        $this->assertSame(['2026219', '2026220', '2026221'], $response->viewData('vouchers')->pluck('checkada_no')->all());
    }

    public function test_filtered_check_numbers_sort_numerically_without_changing_leading_zeroes(): void
    {
        $this->signIn(true);
        $checkNumbers = ['2026221', '10000000000000000000', '999999', '0002026218', '2026220', '9223372036854775808', '2026219'];
        foreach ($checkNumbers as $index => $checkNumber) {
            Voucher::create(['checkada_no' => $checkNumber, 'dv_number' => (string) (100000 + $index),
                'payee' => 'Filtered Sequence', 'amount' => null, 'amount_status' => 'manual',
                'year' => 2026, 'type' => 'ADA', 'month' => 7,
                'file_path' => 'vouchers/numeric-'.$index.'.pdf', 'uploaded_by' => auth()->id()]);
        }
        Voucher::create(['checkada_no' => '000001', 'dv_number' => '999999', 'payee' => 'Filtered Sequence',
            'amount' => null, 'amount_status' => 'manual', 'year' => 2026, 'type' => 'ADA', 'month' => 8,
            'file_path' => 'vouchers/other-month.pdf', 'uploaded_by' => auth()->id()]);

        $response = $this->get(route('vouchers.index', ['q' => 'Filtered Sequence', 'year' => 2026, 'type' => 'ADA', 'month' => 7, 'amount_status' => 'missing']))->assertOk();

        $this->assertSame(['999999', '0002026218', '2026219', '2026220', '2026221', '9223372036854775808', '10000000000000000000'], $response->viewData('vouchers')->pluck('checkada_no')->all());
    }

    public function test_filtered_check_number_sequence_continues_on_the_next_page(): void
    {
        $this->signIn(true);
        foreach (range(2026239, 2026219) as $index => $checkNumber) {
            Voucher::create(['checkada_no' => (string) $checkNumber, 'dv_number' => (string) (100000 + $index),
                'payee' => 'Paged Sequence', 'amount' => '1.00', 'year' => 2026, 'type' => 'CHECK', 'month' => 7,
                'file_path' => 'vouchers/paged-'.$index.'.pdf', 'uploaded_by' => auth()->id()]);
        }
        $filters = ['year' => 2026, 'type' => 'CHECK', 'month' => 7, 'q' => 'Paged Sequence'];

        $firstPage = $this->get(route('vouchers.index', $filters))->assertOk();
        $secondPage = $this->get(route('vouchers.index', [...$filters, 'page' => 2]))->assertOk();

        $this->assertSame(array_map('strval', range(2026219, 2026238)), $firstPage->viewData('vouchers')->pluck('checkada_no')->all());
        $this->assertSame(['2026239'], $secondPage->viewData('vouchers')->pluck('checkada_no')->all());
        $this->assertStringContainsString('month=7', $firstPage->viewData('vouchers')->nextPageUrl());
    }

    private function signIn(bool $admin): void
    {
        $user = User::create(['username' => 'library-test', 'name' => 'Library Tester', 'password' => 'PasswordTest123!']);
        $user->forceFill(['is_admin' => $admin, 'must_change_password' => false])->save();
        $this->actingAs($user);
        VoucherYear::create(['year' => 2026]);
    }
}
