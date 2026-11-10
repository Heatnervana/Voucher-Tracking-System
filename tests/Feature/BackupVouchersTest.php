<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackupVouchersTest extends TestCase
{
    private string $fixtureRoot;

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        if (isset($this->fixtureRoot)) {
            File::deleteDirectory($this->fixtureRoot);
        }
        parent::tearDown();
    }

    public function test_backup_can_restore_database_users_and_saved_pdfs_with_verified_hashes(): void
    {
        $voucher = $this->prepareVoucher();
        DB::statement('PRAGMA journal_mode = WAL');
        $voucher->update(['payee' => 'Committed in WAL']);
        $this->freezeTime();

        $this->assertArrayHasKey('vouchers:backup', Artisan::all());
        $backupResult = Artisan::call('vouchers:backup', ['destination' => $this->fixtureRoot.'/backups']);
        $this->assertSame(0, $backupResult, Artisan::output());
        $firstBackup = $this->completeBackups()[0];
        $manifest = json_decode(file_get_contents($firstBackup.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $manifest['voucher_count']);
        $this->assertSame('complete', $manifest['status']);
        foreach ($manifest['sha256'] as $path => $hash) {
            $this->assertSame($hash, hash_file('sha256', $firstBackup.'/'.$path));
        }
        $this->assertArrayHasKey('database.sqlite', $manifest['sha256']);
        $this->assertArrayHasKey('files/'.$voucher->file_path, $manifest['sha256']);
        $this->assertSame("APP_KEY=backup-test-secret\n", file_get_contents($firstBackup.'/.env'));
        $this->assertStringNotContainsString('backup-test-secret', Artisan::output());

        File::copyDirectory($firstBackup, $this->fixtureRoot.'/restored');
        $restored = new PDO('sqlite:'.$this->fixtureRoot.'/restored/database.sqlite');
        $this->assertSame('ok', $restored->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame('Committed in WAL', $restored->query('SELECT payee FROM vouchers')->fetchColumn());
        $this->assertSame(1, (int) $restored->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame('%PDF-1.4 saved voucher', file_get_contents($this->fixtureRoot.'/restored/files/'.$voucher->file_path));
        $restored = null;

        $this->assertSame(0, Artisan::call('vouchers:backup', ['destination' => $this->fixtureRoot.'/backups']));
        $this->assertCount(2, $this->completeBackups());
        $this->assertSame($manifest, json_decode(file_get_contents($firstBackup.'/manifest.json'), true));
    }

    public function test_missing_pdf_never_produces_a_complete_backup_and_releases_lock(): void
    {
        $voucher = $this->prepareVoucher();
        Storage::disk('local')->delete($voucher->file_path);

        $this->artisan('vouchers:backup', ['destination' => $this->fixtureRoot.'/backups'])->assertFailed();

        $this->assertSame([], $this->completeBackups());
        $lock = fopen(storage_path('framework/voucher-files.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        fclose($lock);
    }

    public function test_backup_includes_recycle_bin_records_and_their_retained_pdfs(): void
    {
        $voucher = $this->prepareVoucher();
        $voucher->delete();
        $this->assertSame(0, Artisan::call('vouchers:backup', ['destination' => $this->fixtureRoot.'/backups']));
        $backup = $this->completeBackups()[0];
        $snapshot = new PDO('sqlite:'.$backup.'/database.sqlite');
        $this->assertSame(1, (int) $snapshot->query('SELECT COUNT(*) FROM vouchers WHERE deleted_at IS NOT NULL')->fetchColumn());
        $this->assertSame('%PDF-1.4 saved voucher', file_get_contents($backup.'/files/'.$voucher->file_path));
        $snapshot = null;
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_pdf_paths_never_produce_a_complete_backup(string $path): void
    {
        $voucher = $this->prepareVoucher();
        $voucher->update(['file_path' => $path]);

        $this->artisan('vouchers:backup', ['destination' => $this->fixtureRoot.'/backups'])->assertFailed();

        $this->assertSame([], $this->completeBackups());
    }

    public static function unsafePaths(): array
    {
        return [
            'parent traversal' => ['vouchers/../../.env'],
            'absolute path' => ['C:/private/voucher.pdf'],
            'alternate stream' => ['vouchers/file.pdf:secret'],
            'staged file' => ['staging/file.pdf'],
            'backslash traversal' => ['vouchers\\..\\secret.pdf'],
        ];
    }

    public function test_public_and_pdf_storage_destinations_are_rejected(): void
    {
        $this->prepareVoucher();
        $this->app->usePublicPath($this->fixtureRoot.'/public');
        File::ensureDirectoryExists(public_path());
        config(['filesystems.disks.public.root' => $this->fixtureRoot.'/public-disk']);
        File::ensureDirectoryExists(config('filesystems.disks.public.root'));

        foreach ([public_path('backups'), Storage::disk('local')->path('backups'), config('filesystems.disks.public.root').'/backups'] as $destination) {
            $this->artisan('vouchers:backup', ['destination' => $destination])->assertFailed();
            $this->assertSame([], glob($destination.'/vouchers-*'));
        }
    }

    public function test_backup_fails_without_writing_when_voucher_lock_is_held(): void
    {
        $this->prepareVoucher();
        $lock = fopen(storage_path('framework/voucher-files.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->artisan('vouchers:backup', ['destination' => $this->fixtureRoot.'/backups'])->assertFailed();
            $this->assertSame([], $this->completeBackups());
        } finally {
            fclose($lock);
        }
    }

    public function test_corrupt_database_never_produces_a_complete_backup(): void
    {
        $this->prepareVoucher();
        DB::purge('sqlite');
        file_put_contents($this->fixtureRoot.'/database.sqlite', 'not a SQLite database');

        $this->artisan('vouchers:backup', ['destination' => $this->fixtureRoot.'/backups'])->assertFailed();

        $this->assertSame([], $this->completeBackups());
    }

    public function test_database_with_broken_references_fails_integrity_verification(): void
    {
        $voucher = $this->prepareVoucher();
        DB::statement('PRAGMA foreign_keys = OFF');
        $voucher->update(['uploaded_by' => 999999]);

        $this->artisan('vouchers:backup', ['destination' => $this->fixtureRoot.'/backups'])->assertFailed();

        $this->assertSame([], $this->completeBackups());
    }

    public function test_backup_without_environment_file_explicitly_omits_it_from_manifest(): void
    {
        $this->prepareVoucher();
        unlink($this->fixtureRoot.'/.env');

        $this->artisan('vouchers:backup', ['destination' => $this->fixtureRoot.'/backups'])->assertSuccessful();

        $backup = $this->completeBackups()[0];
        $manifest = json_decode(file_get_contents($backup.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('.env', $manifest['sha256']);
        $this->assertFileDoesNotExist($backup.'/.env');
    }

    private function prepareVoucher(): Voucher
    {
        $disk = Storage::fake('voucher-backups');
        $this->fixtureRoot = $disk->path('fixture');
        File::ensureDirectoryExists($this->fixtureRoot.'/runtime/framework');
        $this->app->useStoragePath($this->fixtureRoot.'/runtime');
        $this->app->useEnvironmentPath($this->fixtureRoot);
        file_put_contents($this->fixtureRoot.'/.env', "APP_KEY=backup-test-secret\n");
        config(['filesystems.disks.backup-pdfs' => ['driver' => 'local', 'root' => $this->fixtureRoot.'/pdfs']]);
        Storage::set('local', Storage::disk('backup-pdfs'));
        touch($this->fixtureRoot.'/database.sqlite');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->fixtureRoot.'/database.sqlite']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        $user = User::create(['username' => 'backup-tester', 'name' => 'Backup Tester', 'password' => 'test-password']);
        VoucherYear::create(['year' => 2026]);
        $voucher = Voucher::create([
            'checkada_no' => '123456', 'dv_number' => '000001', 'payee' => 'Test Payee',
            'amount' => '100.00', 'year' => 2026, 'type' => 'ADA', 'month' => 10,
            'file_path' => 'vouchers/2026/ADA/10/saved.pdf', 'uploaded_by' => $user->id,
        ]);
        Storage::disk('local')->put($voucher->file_path, '%PDF-1.4 saved voucher');

        return $voucher;
    }

    /** @return list<string> */
    private function completeBackups(): array
    {
        return glob($this->fixtureRoot.'/backups/vouchers-*', GLOB_ONLYDIR) ?: [];
    }
}
