<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('restrictedRoutes')]
    public function test_staff_cannot_use_management_routes(string $route, string $method): void
    {
        $voucher = $this->staffAndVoucher();
        $path = $voucher->file_path;
        $this->json($method, route($route, ['voucher' => $voucher->id]))->assertForbidden();
        $this->assertDatabaseHas('vouchers', ['id' => $voucher->id, 'file_path' => $path]);
        Storage::disk('local')->assertExists($path);
    }

    public static function restrictedRoutes(): array
    {
        return [
            ['vouchers.create', 'GET'], ['vouchers.store', 'POST'],
            ['vouchers.upload.start', 'POST'], ['vouchers.upload.chunk', 'POST'],
            ['vouchers.upload.complete', 'POST'], ['vouchers.upload.remove-file', 'POST'],
            ['vouchers.upload.amounts', 'GET'], ['vouchers.confirm', 'GET'],
            ['vouchers.finalize', 'POST'], ['vouchers.cancel-upload', 'DELETE'],
            ['vouchers.destroy-month', 'DELETE'], ['vouchers.destroy-selected', 'DELETE'],
            ['vouchers.destroy-folder', 'DELETE'], ['vouchers.destroy', 'DELETE'],
            ['vouchers.move', 'POST'], ['vouchers.rename', 'PATCH'],
            ['vouchers.update-amount', 'PATCH'],
            ['vouchers.download', 'GET'], ['years.create', 'GET'], ['years.store', 'POST'],
        ];
    }

    public function test_staff_can_search_and_view_pdfs_without_management_controls(): void
    {
        $voucher = $this->staffAndVoucher();
        $this->get(route('vouchers.index', ['q' => 'PLDT', 'year' => 2026, 'type' => 'CHECK', 'month' => 4]))
            ->assertOk()->assertSee('PLDT INC.')->assertSee('PHP 5,250.00')
            ->assertDontSee('name="amount_status"', false)->assertDontSee('More filters')
            ->assertDontSee('Upload vouchers')->assertDontSee('Delete selected')
            ->assertDontSee('Delete by folder')->assertDontSee('New year')->assertDontSee('data-select-pdf', false);
        $this->get(route('vouchers.show', $voucher))->assertOk()->assertSee('Open PDF')->assertDontSee('Edit filename')
            ->assertDontSee('Delete saved voucher')->assertDontSee('Move voucher')->assertDontSee('Download');
        $this->get(route('vouchers.pdf', $voucher))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_staff_ignore_amount_status_when_opening_an_admin_filter_link(): void
    {
        $voucher = $this->staffAndVoucher();

        $this->get(route('vouchers.index', ['amount_status' => 'missing']))
            ->assertOk()->assertSee($voucher->payee)->assertDontSee('name="amount_status"', false);
    }

    public function test_creating_staff_generates_a_temporary_password_and_never_promotes_them(): void
    {
        $this->assertArrayHasKey('users:create-staff', Artisan::all());
        $this->assertSame(0, Artisan::call('users:create-staff', ['username' => 'staff', '--name' => 'Office Staff']));
        preg_match('/Temporary password: (.+)/', Artisan::output(), $matches);
        $staff = User::where('username', 'staff')->firstOrFail();
        $this->assertFalse($staff->is_admin);
        $this->assertTrue($staff->must_change_password);
        $this->assertTrue(Hash::check(trim($matches[1]), $staff->password));
        $this->assertSame(1, Artisan::call('users:create-staff', ['username' => 'staff']));
        $this->assertSame($staff->password, $staff->fresh()->password);
    }

    public function test_staff_can_change_their_password_without_escalating_permissions(): void
    {
        $this->staffAndVoucher();
        $this->put(route('profile.update'), ['current_password' => 'CurrentStaff123!',
            'password' => 'ChangedStaff123!', 'password_confirmation' => 'ChangedStaff123!', 'is_admin' => true,
        ])->assertRedirect(route('vouchers.index'));
        $this->assertFalse(auth()->user()->fresh()->is_admin);
        $this->get(route('vouchers.create'))->assertForbidden();
    }

    private function staffAndVoucher(): Voucher
    {
        Storage::fake('local');
        $staff = User::create(['username' => 'staff-tester', 'name' => 'Staff Tester', 'password' => 'CurrentStaff123!']);
        $staff->forceFill(['must_change_password' => false])->save();
        $this->actingAs($staff);
        VoucherYear::create(['year' => 2026]);
        $path = 'vouchers/2026/CHECK/04/10779_2026012525_PLDT.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 test');

        return Voucher::create(['checkada_no' => '10779', 'dv_number' => '2026012525', 'payee' => 'PLDT INC.',
            'amount' => '5250.00', 'year' => 2026, 'type' => 'CHECK', 'month' => 4, 'file_path' => $path, 'uploaded_by' => $staff->id]);
    }
}
