<?php

namespace App;

class VoucherUtilityCategory
{
    public const LABELS = ['electricity' => 'Electricity', 'water' => 'Water'];

    public const MANUAL_OPTIONS = self::LABELS + ['other' => 'Leave without a category'];

    public static function fromName(string $filename): ?string
    {
        $basename = basename(str_replace('\\', '/', $filename));
        $label = preg_replace('/^(?:[A-Z0-9-]{1,40})_\d{6,12}_|\.pdf$/i', '', $basename) ?? '';
        $text = self::normalize($label);
        $billing = self::classifyText($text);

        $electricity = $billing === 'electricity' || $text === 'electricity'
            || preg_match('/\b(?:moelci|mis(?:amis)? occ(?:idental)? (?:i |1 )?electric co(?:op|operative))\b/', $text);
        $water = $billing === 'water' || $text === 'water'
            || preg_match('/\bprime ?water\b/', $text);

        if (($electricity && $water) || $billing === 'review') {
            return 'review';
        }
        if ($electricity || $water) {
            return $electricity ? 'electricity' : 'water';
        }

        return null;
    }

    /**
     * @param  array<int, array{text: string, left: float|int, top: float|int, width: float|int, height: float|int, confidence: float|int}>  $words
     */
    public static function fromWords(array $words): ?string
    {
        $description = null;
        foreach ($words as $word) {
            if ($word['confidence'] >= 55 && preg_match('/^(?:Description|Particulars)[:.]?$/i', $word['text'])) {
                $description = $word;
                if (str_starts_with(strtolower($word['text']), 'description')) {
                    break;
                }
            }
        }
        if ($description === null) {
            return null;
        }

        $height = max(1, $description['height']);
        $left = $description['left'];
        $top = $description['top'];
        $right = $left + 35 * $height;
        foreach ($words as $word) {
            if (strcasecmp($word['text'], 'Responsibility') === 0 && $word['left'] > $left
                && $word['top'] >= $top - 8 * $height && $word['top'] <= $top + $height) {
                $right = min($right, $word['left']);
            }
        }

        $body = array_filter($words, fn (array $word): bool => $word['left'] >= $left && $word['left'] < $right
            && $word['top'] >= $top - $height / 2 && $word['top'] <= $top + 9 * $height && $word !== $description);
        usort($body, fn (array $first, array $second): int => $first['top'] <=> $second['top']);
        $lines = [];
        foreach ($body as $word) {
            $line = array_key_last($lines);
            if ($line === null || abs($word['top'] - $lines[$line][0]['top']) > $height * 0.6) {
                $lines[] = [$word];
            } else {
                $lines[$line][] = $word;
            }
        }

        $text = '';
        foreach ($lines as $line) {
            usort($line, fn (array $first, array $second): int => $first['left'] <=> $second['left']);
            $text .= ' '.implode(' ', array_map(fn (array $word): string => $word['confidence'] >= 65 ? $word['text'] : ' ?', $line));
        }

        return self::classifyText(self::normalize($text));
    }

    private static function normalize(string $text): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9?]+/i', ' ', $text) ?? ''));
    }

    private static function classifyText(string $text): ?string
    {
        if (! preg_match('/[a-z]{3,}/', $text)) {
            return null;
        }

        $electricity = preg_match('/\b(?:electrical services? consumption|electric(?:ity|al)? (?:services? )?(?:bills?|consumption|charges)|power consumption charges)\b/', $text);
        $water = preg_match('/\bwater (?:services? )?(?:consumption|bills?|charges)\b/', $text);
        $both = preg_match('/\b(?:water (?:and|or) (?:electricity|electrical|electric|power)|(?:electricity|electrical|electric|power) (?:and|or) water)\b/', $text);
        if ($both || ($electricity && $water) || (! $electricity && ! $water && preg_match('/\butility (?:bills?|consumption|charges)\b/', $text))) {
            return 'review';
        }
        if ($electricity || $water) {
            return $electricity ? 'electricity' : 'water';
        }

        if (str_contains($text, '?')) {
            return preg_match('/\b(?:water|electrical|electricity|utility)\b/', $text)
                && preg_match('/\b(?:bills?|consumption|charges|services)\b/', $text)
                ? 'review' : null;
        }

        return 'unmatched';
    }

    /**
     * @return array{category: ?string, status: string}
     */
    public static function resolve(?string $description, ?string $filename = null): array
    {
        if (isset(self::LABELS[$filename])) {
            return ['category' => $filename, 'status' => 'none'];
        }
        if (isset(self::LABELS[$description])) {
            return ['category' => $description, 'status' => 'none'];
        }

        return ['category' => null, 'status' => $filename === 'review' || $description === 'review' ? 'manual' : 'none'];
    }
}
