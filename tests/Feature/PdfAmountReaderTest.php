<?php

namespace Tests\Feature;

use App\PdfAmountReader;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class PdfAmountReaderTest extends TestCase
{
    public function test_reads_the_amount_from_ocr_word_positions(): void
    {
        $this->fakeTools(['pdftoppm' => 'pdftoppm', 'tesseract' => 'tesseract']);
        Process::fake(function (PendingProcess $process) {
            if ($process->command[0] === 'pdftoppm') {
                file_put_contents(end($process->command).'.png', 'rendered image');

                return Process::result();
            }

            return Process::result(output: $this->ocrOutput());
        });

        $this->assertSame('5250.00', $this->readTemporaryPdf());
        Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'tesseract' && in_array('tsv', $process->command));
    }

    public function test_reads_selectable_pdf_text_before_using_ocr(): void
    {
        $this->fakeTools(['pdftotext' => 'pdftotext']);
        Process::fake([
            '*' => Process::result(output: '<html><body><doc><page width="1700" height="2600"><word xMin="1407" yMin="733" xMax="1504" yMax="753">Amount</word><word xMin="1499" yMin="812" xMax="1594" yMax="836">5,250.00</word></page></doc></body></html>'),
        ]);

        $this->assertSame('5250.00', $this->readTemporaryPdf());
        Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'pdftotext' && in_array('-bbox', $process->command));
    }

    public function test_ocr_failure_keeps_manual_amount_entry_available(): void
    {
        $this->fakeTools(['pdftoppm' => 'pdftoppm', 'tesseract' => 'tesseract']);
        Process::fake(['*' => Process::result(errorOutput: 'Unreadable PDF', exitCode: 1)]);

        $this->assertNull($this->readTemporaryPdf());
        Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'pdftoppm');
    }

    public function test_missing_tools_do_not_prevent_an_upload(): void
    {
        $this->fakeTools([]);
        Process::fake();

        $this->assertNull($this->readTemporaryPdf());
        Process::assertNothingRan();
    }

    public function test_timed_out_ocr_keeps_manual_entry_and_cleans_up_the_image(): void
    {
        $this->fakeTools(['pdftoppm' => 'pdftoppm', 'tesseract' => 'tesseract']);
        $imageBase = null;
        Process::fake(function (PendingProcess $process) use (&$imageBase) {
            if ($process->command[0] === 'pdftoppm') {
                $imageBase = end($process->command);
                file_put_contents($imageBase.'.png', 'rendered image');

                return Process::result();
            }

            throw new ProcessTimedOutException(new SymfonyProcess(['tesseract']), ProcessTimedOutException::TYPE_GENERAL);
        });

        $this->assertNull($this->readTemporaryPdf());
        $this->assertNotNull($imageBase);
        $this->assertFileDoesNotExist($imageBase.'.png');
        $this->assertFileDoesNotExist($imageBase);
    }

    private function readTemporaryPdf(): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'test-amount-');
        file_put_contents($path, '%PDF-1.4 sample');

        try {
            return app(PdfAmountReader::class)->read($path);
        } finally {
            unlink($path);
        }
    }

    private function fakeTools(array $tools): void
    {
        $finder = $this->mock(ExecutableFinder::class);
        $finder->shouldReceive('find')->andReturnUsing(fn (string $name): ?string => $tools[$name] ?? null);
    }

    private function ocrOutput(): string
    {
        return implode("\n", [
            "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext",
            "5\t1\t1\t1\t1\t1\t1407\t733\t97\t20\t96.6\tAmount",
            "5\t1\t1\t1\t2\t1\t1499\t812\t95\t24\t96.3\t5,250.00",
        ]);
    }
}
