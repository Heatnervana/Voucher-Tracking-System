<?php

namespace App;

class VoucherFilename
{
    /** @return array{checkada_no: string, dv_number: string, payee: string}|null */
    public function parse(string $filename): ?array
    {
        if (! preg_match('/^([A-Za-z0-9-]{1,40})_([0-9]{6,12})_(.+)\.pdf$/i', $filename, $matches)) {
            return null;
        }

        $payee = trim(str_replace(['/', '\\', "\0"], '', $matches[3]));

        if ($payee === '') {
            return null;
        }

        return [
            'checkada_no' => $matches[1],
            'dv_number' => $matches[2],
            'payee' => mb_substr($payee, 0, 255),
        ];
    }
}
