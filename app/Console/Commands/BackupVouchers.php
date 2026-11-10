<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use Throwable;

class BackupVouchers extends Command
{
    protected $signature = 'vouchers:backup {destination : Private directory for complete backup folders}';

    protected $description = 'Back up the SQLite database, saved voucher PDFs, and recovery environment';

    public function handle(): int
    {
        $lock = @fopen(storage_path('framework/voucher-files.lock'), 'c');

        if ($lock === false) {
            $this->error('Backup failed: could not open the voucher storage lock.');

            return self::FAILURE;
        }

        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Voucher files are busy. Retry the backup after the current change finishes.');
            }

            $database = DB::connection()->getPdo();
            if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                throw new RuntimeException('This backup command requires SQLite.');
            }

            $destination = (string) $this->argument('destination');
            if (trim($destination) === '' || str_contains($destination, '://')) {
                throw new RuntimeException('Choose a private local backup directory.');
            }

            $this->makeDirectory($destination);
            $destination = realpath($destination);
            $diskRoot = realpath(Storage::disk('local')->path(''));

            if ($destination === false || $diskRoot === false) {
                throw new RuntimeException('The backup or PDF storage directory is unavailable.');
            }

            $protectedRoots = [
                public_path(),
                (string) config('filesystems.disks.public.root'),
                $diskRoot,
                ...array_values(config('filesystems.links', [])),
            ];
            foreach ($protectedRoots as $root) {
                $resolvedRoot = realpath($root);
                if ($resolvedRoot !== false && $this->isWithin($destination, $resolvedRoot)) {
                    throw new RuntimeException('Backups must be outside public directories and PDF storage.');
                }
            }

            $name = 'vouchers-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(8));
            $workingDirectory = $destination.'/.incomplete-'.$name;
            if (! @mkdir($workingDirectory, 0700)) {
                throw new RuntimeException('Could not create a new backup directory.');
            }

            $snapshotPath = $workingDirectory.'/database.sqlite';
            $database->prepare('VACUUM INTO ?')->execute([$snapshotPath]);
            $snapshot = new PDO('sqlite:'.$snapshotPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($snapshot->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']
                || $snapshot->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
                throw new RuntimeException('The database snapshot failed its integrity checks.');
            }

            $hashes = ['database.sqlite' => $this->checksum($snapshotPath)];
            $voucherCount = 0;
            $paths = $snapshot->query('SELECT file_path FROM vouchers ORDER BY id');
            while (($path = $paths->fetchColumn()) !== false) {
                $path = (string) $path;
                if (! str_starts_with($path, 'vouchers/')
                    || preg_match('/[\\\\:\x00-\x1F]/', $path)
                    || preg_match('~(?:^|/)(?:\\.\\.?|)(?:/|$)~', $path)
                    || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
                    throw new RuntimeException('A saved voucher has an unsafe PDF path.');
                }

                $source = realpath($diskRoot.'/'.$path);
                if ($source === false || ! is_file($source) || ! $this->isWithin($source, $diskRoot.'/vouchers')) {
                    throw new RuntimeException('A saved voucher PDF is missing or outside its storage directory.');
                }

                $hashes['files/'.$path] = $this->verifiedCopy($source, $workingDirectory.'/files/'.$path);
                $voucherCount++;
            }
            $paths = null;
            $snapshot = null;

            $environment = app()->environmentFilePath();
            if (is_file($environment)) {
                $hashes['.env'] = $this->verifiedCopy($environment, $workingDirectory.'/.env');
            }

            $manifest = json_encode([
                'format_version' => 1,
                'status' => 'complete',
                'created_at' => now()->toIso8601String(),
                'voucher_count' => $voucherCount,
                'sha256' => $hashes,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($workingDirectory.'/manifest.json', $manifest, LOCK_EX) !== strlen($manifest)) {
                throw new RuntimeException('Could not write the backup completion manifest.');
            }

            $completedDirectory = $destination.'/'.$name;
            if (file_exists($completedDirectory) || ! @rename($workingDirectory, $completedDirectory)) {
                throw new RuntimeException('Could not publish the complete backup.');
            }

            $this->info('Backup complete: '.$completedDirectory);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Backup failed: '.$exception->getMessage());
            $this->line('No complete backup was published. Any .incomplete- directory must not be used for recovery.');

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Could not create a backup directory.');
        }
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root.'/');
    }

    private function checksum(string $path): string
    {
        $hash = @hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Could not verify a backup file.');
        }

        return $hash;
    }

    private function verifiedCopy(string $source, string $destination): string
    {
        $expectedHash = $this->checksum($source);
        $this->makeDirectory(dirname($destination));
        $input = @fopen($source, 'rb');
        $output = @fopen($destination, 'xb');

        try {
            if ($input === false || $output === false || stream_copy_to_stream($input, $output) === false || ! fflush($output)) {
                throw new RuntimeException('Could not copy a backup file.');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
        }

        if (! hash_equals($expectedHash, $this->checksum($destination))
            || ! hash_equals($expectedHash, $this->checksum($source))) {
            throw new RuntimeException('A backup file failed checksum verification.');
        }

        return $expectedHash;
    }
}
