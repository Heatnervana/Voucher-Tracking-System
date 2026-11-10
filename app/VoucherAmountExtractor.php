<?php

namespace App;

class VoucherAmountExtractor
{
    /**
     * @param  array<int, array{text: string, left: float|int, top: float|int, width: float|int, height: float|int, confidence: float|int}>  $words
     */
    public function extract(array $words): ?string
    {
        $numbers = [];
        foreach ($words as $word) {
            if (preg_match('/[0-9]/', $word['text'])) {
                $numbers[] = $word + ['amount' => $this->normalizeAmount($word['text'])];
            }
        }

        $amounts = [];
        $dueAmounts = [];
        foreach ($words as $heading) {
            if (! preg_match('/^Amount[:.]?$/i', $heading['text'])) {
                continue;
            }
            $height = max(1, $heading['height']);
            $due = null;
            $hasAdjacentWord = false;
            foreach ($words as $word) {
                if ($word === $heading || abs($word['top'] - $heading['top']) > $height * 0.75) {
                    continue;
                }
                $gap = $word['left'] - ($heading['left'] + $heading['width']);
                if (preg_match('/^Due[:.]?$/i', $word['text']) && $gap >= 0 && $gap <= $height * 2) {
                    if ($word['confidence'] < 80) {
                        return null;
                    }
                    $due = $word;
                    break;
                }
                $leftGap = $heading['left'] - ($word['left'] + $word['width']);
                if ((($gap >= 0 && $gap <= $height * 2) || ($leftGap >= 0 && $leftGap <= $height * 2))
                    && ! preg_match('/^(?:\(?PHP\)?|\(?₱\)?|P)$/iu', $word['text'])) {
                    $hasAdjacentWord = true;
                }
            }
            if ($due === null && $hasAdjacentWord) {
                continue;
            }
            if ($heading['confidence'] < 80) {
                return null;
            }
            if ($due !== null) {
                $matches = [];
                foreach ($numbers as $number) {
                    if ($number['left'] >= $due['left'] + $due['width']
                        && $number['top'] >= $heading['top'] - $height
                        && $number['top'] <= $heading['top'] + $height * 3) {
                        if ($number['amount'] === null || $number['confidence'] < 80) {
                            return null;
                        }
                        $matches[] = $number['amount'];
                    }
                }
                if ($matches === []) {
                    return null;
                }
                $dueAmounts = array_merge($dueAmounts, $matches);

                continue;
            }
            $candidates = array_filter($numbers, fn (array $number): bool => $number['top'] >= $heading['top'] + $height
                && $number['top'] <= $heading['top'] + $height * 12
                && $number['left'] >= $heading['left'] - $height * 2
                && $number['left'] <= $heading['left'] + $heading['width'] * 3);
            if ($candidates === []) {
                continue;
            }

            usort($candidates, fn (array $a, array $b): int => $a['top'] <=> $b['top']);
            $firstRow = $candidates[0];
            foreach ($candidates as $candidate) {
                if (abs($candidate['top'] - $firstRow['top']) <= max($height, $firstRow['height'])) {
                    if ($candidate['amount'] === null || $candidate['confidence'] < 80) {
                        return null;
                    }
                    $amounts[] = $candidate['amount'];
                }
            }
        }

        $amounts = array_values(array_unique($amounts));
        if (count($amounts) !== 1 || array_diff($dueAmounts, $amounts) !== []) {
            return null;
        }

        return $amounts[0];
    }

    private function normalizeAmount(string $text): ?string
    {
        if (! preg_match('/^(?:PHP|₱|P)?\s*((?:[0-9]{1,3}(?:,[0-9]{3})+|[0-9]+))\.([0-9]{2})$/u', trim($text), $matches)) {
            return null;
        }
        $integer = ltrim(str_replace(',', '', $matches[1]), '0') ?: '0';
        $amount = $integer.'.'.$matches[2];

        return strlen($integer) <= 13 && $amount !== '0.00' ? $amount : null;
    }
}
