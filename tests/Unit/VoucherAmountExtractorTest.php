<?php

namespace Tests\Unit;

use App\VoucherAmountExtractor;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class VoucherAmountExtractorTest extends TestCase
{
    public function test_reads_the_amount_beneath_the_heading_in_the_sample_layout(): void
    {
        $words = [
            $this->word('999.99', 200, 200),
            $this->word('Amount', 1407, 733, 97),
            $this->word('5,250.00', 1499, 812, 95),
            $this->word('Amount', 407, 1166, 97),
            $this->word('Due', 516, 1166, 45),
            $this->word('5,250.00', 1499, 1164, 95),
        ];

        $this->assertSame('5250.00', (new VoucherAmountExtractor)->extract($words));
    }

    public function test_follows_a_shifted_heading_instead_of_using_fixed_page_coordinates(): void
    {
        $words = [
            $this->word('Amount', 500, 100, 97),
            $this->word('123.45', 100, 170),
            $this->word('8,910.25', 580, 180),
        ];

        $this->assertSame('8910.25', (new VoucherAmountExtractor)->extract($words));
    }

    public function test_leaves_the_amount_blank_when_there_is_no_heading(): void
    {
        $this->assertNull((new VoucherAmountExtractor)->extract([$this->word('5250.00', 1499, 812)]));
    }

    public function test_leaves_low_confidence_numbers_for_manual_entry(): void
    {
        $words = [$this->word('Amount', 500, 100), $this->word('5250.00', 550, 180, 95, 42)];

        $this->assertNull((new VoucherAmountExtractor)->extract($words));
    }

    #[TestWith(['5250.00', 42])]
    #[TestWith(['5,25.00', 96])]
    #[TestWith(['5250', 96])]
    public function test_does_not_skip_an_uncertain_amount_to_read_a_later_row(string $text, float $confidence): void
    {
        $words = [
            $this->word('Amount', 500, 100),
            $this->word($text, 550, 180, 95, $confidence),
            $this->word('100.00', 550, 220),
        ];

        $this->assertNull((new VoucherAmountExtractor)->extract($words));
    }

    public function test_leaves_an_uncertain_number_in_the_same_amount_row_for_manual_entry(): void
    {
        $words = [
            $this->word('Amount', 500, 100),
            $this->word('5250.00', 500, 180),
            $this->word('6250.00', 620, 180, 95, 42),
        ];

        $this->assertNull((new VoucherAmountExtractor)->extract($words));
    }

    public function test_leaves_conflicting_numbers_in_the_same_row_for_manual_entry(): void
    {
        $words = [
            $this->word('Amount', 500, 100),
            $this->word('5250.00', 500, 180),
            $this->word('6250.00', 620, 180),
        ];

        $this->assertNull((new VoucherAmountExtractor)->extract($words));
    }

    public function test_does_not_guess_when_amount_due_disagrees(): void
    {
        $words = [
            $this->word('Amount', 1407, 733, 97),
            $this->word('5,250.00', 1499, 812, 95),
            $this->word('Amount', 407, 1166, 97),
            $this->word('Due', 516, 1166, 45),
            $this->word('4,950.00', 1499, 1164, 95),
        ];

        $this->assertNull((new VoucherAmountExtractor)->extract($words));
    }

    public function test_leaves_a_low_confidence_amount_due_heading_for_manual_review(): void
    {
        $words = [
            $this->word('Amount', 1407, 100, 97),
            $this->word('100.00', 1499, 180),
            $this->word('Amount', 407, 300, 97, 42),
            $this->word('Due', 516, 300, 45),
            $this->word('200.00', 1499, 300),
        ];

        $this->assertNull((new VoucherAmountExtractor)->extract($words));
    }

    public function test_does_not_accept_malformed_currency_or_an_unrelated_number_far_below(): void
    {
        $words = [
            $this->word('Amount', 500, 100),
            $this->word('5,25.00', 550, 180),
            $this->word('5250.00', 550, 1900),
        ];

        $this->assertNull((new VoucherAmountExtractor)->extract($words));
    }

    #[TestWith(['Paid'])]
    #[TestWith(['in'])]
    public function test_does_not_treat_a_phrase_as_a_standalone_amount_heading(string $followingWord): void
    {
        $words = [
            $this->word('Amount', 500, 100),
            $this->word($followingWord, 610, 100, 45),
            $this->word('100.00', 550, 180),
        ];

        $this->assertNull((new VoucherAmountExtractor)->extract($words));
    }

    public function test_does_not_treat_net_amount_as_the_requested_amount_heading(): void
    {
        $words = [
            $this->word('Net', 450, 100, 40),
            $this->word('Amount', 500, 100),
            $this->word('100.00', 550, 180),
        ];

        $this->assertNull((new VoucherAmountExtractor)->extract($words));
    }

    #[TestWith([79, 300])]
    #[TestWith([96, 330])]
    public function test_leaves_unclear_or_conflicting_amount_due_for_review(float $confidence, int $top): void
    {
        $words = [
            $this->word('Amount', 1407, 100, 97),
            $this->word('100.00', 1499, 180),
            $this->word('Amount', 407, 300, 97),
            $this->word('Due', 516, 300, 45),
            $this->word('200.00', 1499, $top, 95, $confidence),
        ];

        $this->assertNull((new VoucherAmountExtractor)->extract($words));
    }

    private function word(string $text, int $left, int $top, int $width = 100, float $confidence = 96): array
    {
        return ['text' => $text, 'left' => $left, 'top' => $top, 'width' => $width, 'height' => 20, 'confidence' => $confidence];
    }
}
