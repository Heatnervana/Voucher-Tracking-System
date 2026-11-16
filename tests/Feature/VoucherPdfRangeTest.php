<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VoucherPdfRangeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('viewerRoles')]
    public function test_signed_in_users_receive_only_the_requested_pdf_bytes(bool $administrator): void
    {
        $voucher = $this->voucher();
        $user = $administrator ? User::factory()->administrator()->create() : User::factory()->create();

        $response = $this->actingAs($user)->withHeader('Range', 'bytes=0-7')->get(route('vouchers.pdf', $voucher));

        $response->assertStatus(206)->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Range', 'bytes 0-7/25')
            ->assertHeader('Content-Length', '8')->assertHeaderContains('Content-Disposition', 'inline')
            ->assertHeaderContains('Cache-Control', 'private')->assertHeaderContains('Cache-Control', 'no-store');
        $this->assertSame('%PDF-1.4', $this->responseBytes($response));
        $this->assertDatabaseHas('activity_logs', ['action' => 'voucher.viewed', 'actor_id' => $user->id, 'subject_id' => $voucher->id]);
    }

    public static function viewerRoles(): array
    {
        return ['administrator' => [true], 'staff' => [false]];
    }

    #[DataProvider('seekRanges')]
    public function test_viewer_can_seek_to_pdf_content_and_the_trailer(string $range, string $contentRange, string $expected): void
    {
        $voucher = $this->voucher();

        $response = $this->actingAs(User::factory()->create())->withHeader('Range', $range)->get(route('vouchers.pdf', $voucher));

        $response->assertStatus(206)->assertHeader('Content-Range', $contentRange)->assertHeader('Content-Length', '5');
        $this->assertSame($expected, $this->responseBytes($response));
    }

    public static function seekRanges(): array
    {
        return [
            'middle' => ['bytes=9-13', 'bytes 9-13/25', '01234'],
            'open end' => ['bytes=20-', 'bytes 20-24/25', '%%EOF'],
            'suffix' => ['bytes=-5', 'bytes 20-24/25', '%%EOF'],
        ];
    }

    public function test_regular_requests_still_receive_the_complete_pdf(): void
    {
        $voucher = $this->voucher();

        $response = $this->actingAs(User::factory()->create())->get(route('vouchers.pdf', $voucher));

        $response->assertOk()->assertHeader('Content-Length', '25')->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame("%PDF-1.4\n0123456789\n%%EOF", $this->responseBytes($response));
    }

    public function test_range_beyond_the_pdf_returns_416_without_file_bytes(): void
    {
        $voucher = $this->voucher();

        $response = $this->actingAs(User::factory()->create())->withHeader('Range', 'bytes=100-109')->get(route('vouchers.pdf', $voucher));

        $response->assertStatus(416)->assertHeader('Content-Range', 'bytes */25')->assertHeader('Content-Length', '0');
        $this->assertSame('', $this->responseBytes($response));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'voucher.viewed']);
    }

    public function test_head_request_returns_file_metadata_without_pdf_bytes(): void
    {
        $voucher = $this->voucher();

        $response = $this->actingAs(User::factory()->create())->head(route('vouchers.pdf', $voucher));

        $response->assertOk()->assertHeader('Content-Length', '25')->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame('', $this->responseBytes($response));
    }

    public function test_range_requests_still_require_sign_in(): void
    {
        $voucher = $this->voucher();

        $this->withHeader('Range', 'bytes=0-7')->get(route('vouchers.pdf', $voucher))->assertRedirect(route('login'));
    }

    public function test_range_requests_do_not_expose_recycled_vouchers(): void
    {
        $voucher = $this->voucher();
        $voucher->delete();

        $this->actingAs(User::factory()->create())->withHeader('Range', 'bytes=0-7')
            ->get(route('vouchers.pdf', $voucher))->assertNotFound();
    }

    public function test_missing_saved_pdf_still_returns_404(): void
    {
        $voucher = $this->voucher();
        Storage::disk('local')->delete($voucher->file_path);

        $this->actingAs(User::factory()->create())->withHeader('Range', 'bytes=0-7')
            ->get(route('vouchers.pdf', $voucher))->assertNotFound();
    }

    private function voucher(): Voucher
    {
        Storage::fake('local');
        Storage::disk('local')->put('vouchers/range.pdf', "%PDF-1.4\n0123456789\n%%EOF");
        VoucherYear::create(['year' => 2026]);

        return Voucher::create([
            'checkada_no' => '123', 'dv_number' => '456', 'payee' => 'Range Test',
            'year' => 2026, 'type' => 'CHECK', 'month' => 1, 'amount' => '100.00',
            'file_path' => 'vouchers/range.pdf', 'uploaded_by' => User::factory()->administrator()->create()->id,
        ]);
    }

    private function responseBytes(TestResponse $response): string
    {
        ob_start();
        try {
            $response->baseResponse->sendContent();

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
