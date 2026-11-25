<?php

namespace App;

use DOMDocument;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\ExecutableFinder;

class PdfAmountReader
{
    public function __construct(
        private VoucherAmountExtractor $extractor,
        private ExecutableFinder $executables,
    ) {}

    /**
     * TRUST-BOUNDARY: Callers supply private server-owned paths; first-page parser failures leave manual entry available.
     */
    public function read(string $pdfPath): ?string
    {
        return $this->readDetails($pdfPath, readUtility: false)['amount'];
    }

    /**
     * @return array{amount: ?string, utility: ?string}
     */
    public function readDetails(string $pdfPath, bool $readUtility = true, bool $readAmount = true): array
    {
        $result = ['amount' => null, 'utility' => null];
        if (! is_file($pdfPath) || filesize($pdfPath) === 0) {
            return $result;
        }

        $pdftotext = $this->executables->find('pdftotext');
        if ($pdftotext !== null) {
            $xml = $this->run([$pdftotext, '-f', '1', '-l', '1', '-bbox', $pdfPath, '-'], 5);
            if ($xml !== null) {
                $words = $this->textWords($xml);
                $result['amount'] = $readAmount ? $this->extractor->extract($words) : null;
                $result['utility'] = $readUtility ? VoucherUtilityCategory::fromWords($words) : null;
            }
        }

        if ((! $readAmount || $result['amount'] !== null) && (! $readUtility || $result['utility'] !== null)) {
            return $result;
        }

        $words = $this->scanWords($pdfPath);
        if ($readAmount && $result['amount'] === null) {
            $result['amount'] = $this->extractor->extract($words);
        }
        if ($readUtility && $result['utility'] === null) {
            $result['utility'] = VoucherUtilityCategory::fromWords($words);
        }

        return $result;
    }

    private function scanWords(string $pdfPath): array
    {

        $tesseract = $this->executables->find('tesseract', null, [
            (getenv('ProgramFiles') ?: 'C:\Program Files').'\Tesseract-OCR',
        ]);
        if ($tesseract === null) {
            return [];
        }

        $imageBase = tempnam(sys_get_temp_dir(), 'voucher-amount-');
        if ($imageBase === false) {
            return [];
        }
        $image = $imageBase.'.png';

        try {
            $pdftoppm = $this->executables->find('pdftoppm');
            if ($pdftoppm !== null) {
                $rendered = $this->run([$pdftoppm, '-f', '1', '-l', '1', '-singlefile', '-scale-to', '1700', '-png', $pdfPath, $imageBase], 20);
            } elseif (PHP_OS_FAMILY === 'Windows') {
                $powershell = $this->executables->find('powershell', null, [
                    (getenv('SystemRoot') ?: 'C:\Windows').'\System32\WindowsPowerShell\v1.0',
                ]);
                $rendered = $powershell === null ? null : $this->run([
                    $powershell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
                    '-File', __DIR__.'/render-voucher-page.ps1', '-PdfPath', $pdfPath, '-ImagePath', $image,
                ], 20);
            } else {
                return [];
            }

            if ($rendered === null || ! is_file($image)) {
                return [];
            }
            $tsv = $this->run([$tesseract, $image, 'stdout', '-l', 'eng', '--psm', '3', 'tsv'], 20);

            return $tsv !== null ? $this->ocrWords($tsv) : [];
        } finally {
            @unlink($image);
            @unlink($imageBase);
        }
    }

    /**
     * SECURITY: Argument arrays avoid shell interpolation and timeouts bound each native parser invocation.
     */
    private function run(array $command, int $timeout): ?string
    {
        try {
            $result = Process::timeout($timeout)->env(['OMP_THREAD_LIMIT' => '2'])->run($command);

            return $result->successful() ? $result->output() : null;
        } catch (ExceptionInterface|ProcessTimedOutException) {
            return null;
        }
    }

    /**
     * SECURITY: Parser-generated XML is still untrusted; network access is disabled and entity substitution is not enabled.
     */
    private function textWords(string $xml): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                return [];
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $words = [];
        foreach ($document->getElementsByTagName('word') as $word) {
            $left = (float) $word->getAttribute('xMin');
            $top = (float) $word->getAttribute('yMin');
            $words[] = [
                'text' => trim($word->textContent),
                'left' => $left,
                'top' => $top,
                'width' => (float) $word->getAttribute('xMax') - $left,
                'height' => (float) $word->getAttribute('yMax') - $top,
                'confidence' => 100,
            ];
        }

        return $words;
    }

    /**
     * TRUST-BOUNDARY: Only first-page word rows enter detection; OCR text remains an untrusted amount suggestion.
     */
    private function ocrWords(string $tsv): array
    {
        $words = [];
        foreach (preg_split('/\r?\n/', $tsv) as $line) {
            $columns = explode("\t", $line, 12);
            if (count($columns) !== 12 || $columns[0] !== '5' || $columns[1] !== '1') {
                continue;
            }
            $words[] = [
                'text' => trim($columns[11]),
                'left' => (int) $columns[6],
                'top' => (int) $columns[7],
                'width' => (int) $columns[8],
                'height' => (int) $columns[9],
                'confidence' => (float) $columns[10],
            ];
        }

        return $words;
    }
}
