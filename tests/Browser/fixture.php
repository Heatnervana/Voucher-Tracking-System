<?php

use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherYear;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$project = dirname(__DIR__, 2);
$directory = realpath($argv[2] ?? '');
$parent = realpath($project.'/storage/framework/testing');
if ($directory === false || $parent === false
    || ! str_starts_with($directory, $parent.DIRECTORY_SEPARATOR.'browser-')) {
    throw new RuntimeException('Browser fixture requires its own testing directory.');
}

require $project.'/vendor/autoload.php';
$app = require $project.'/bootstrap/app.php';
$app->useStoragePath($directory);
$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? '') !== 'prepare'
    && (config('database.connections.sqlite.database') !== $directory.'/database.sqlite'
        || config('filesystems.disks.local.root') !== $directory.'/private')) {
    throw new RuntimeException('Refusing to use a database outside the browser fixture.');
}

if (($argv[1] ?? '') === 'prepare') {
    config([
        'app.env' => 'testing',
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.url' => $argv[3],
        'database.default' => 'sqlite',
        'database.connections.sqlite.url' => null,
        'database.connections.sqlite.database' => $directory.'/database.sqlite',
        'database.connections.sqlite.foreign_key_constraints' => true,
        'cache.default' => 'file',
        'cache.stores.file.path' => $directory.'/cache',
        'cache.stores.file.lock_path' => $directory.'/locks',
        'session.driver' => getenv('BROWSER_TEST_ACCOUNTS_ONLY') === '1' ? 'database' : 'file',
        'session.files' => $directory.'/sessions',
        'session.cookie' => 'browser_regression_session',
        'session.domain' => null,
        'session.secure' => false,
        'filesystems.disks.local.root' => $directory.'/private',
        'view.compiled' => $directory.'/views',
        'logging.default' => 'single',
        'logging.channels.single.path' => $directory.'/laravel.log',
        'hashing.bcrypt.rounds' => 4,
        'queue.connections.database.connection' => 'sqlite',
    ]);
    DB::purge('sqlite');
    Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    User::create(['username' => 'browser-tester', 'name' => 'Browser Tester', 'password' => 'TemporaryBrowser123!'])->forceFill(['is_admin' => true])->save();
    $employee = User::create(['username' => 'browser-employee', 'name' => 'Browser Employee', 'password' => 'BrowserEmployee123!']);
    $employee->forceFill(['must_change_password' => false, 'is_admin' => true])->save();
    VoucherYear::create(['year' => 2026]);
    file_put_contents($directory.'/config.php', '<?php return '.var_export(config()->all(), true).';');
    echo 'Isolated browser fixture prepared.'.PHP_EOL;
} elseif (($argv[1] ?? '') === 'inspect') {
    echo json_encode([
        'vouchers' => Voucher::count(),
        'saved_pdfs' => Voucher::get()->filter(fn (Voucher $voucher): bool => Storage::disk('local')->exists($voucher->file_path))->count(),
        'trashed_pdfs' => Voucher::onlyTrashed()->count(),
        'stored_pdfs' => count(Storage::disk('local')->allFiles('vouchers')),
        'staged_pdfs' => count(Storage::disk('local')->allFiles('staging')),
        'temporary_password' => User::where('username', 'browser-tester')->value('must_change_password'),
        'scanned_amount' => Voucher::where('dv_number', '2026012525')->value('amount'),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} elseif (($argv[1] ?? '') === 'inspect-upload-reliability') {
    $disk = Storage::disk('local');
    echo json_encode(Voucher::orderBy('id')->get()->map(fn (Voucher $voucher): array => [
        'id' => $voucher->id, 'checkada_no' => $voucher->checkada_no,
        'path' => $voucher->file_path, 'amount_status' => $voucher->amount_status,
        'amount_retry_count' => $voucher->amount_retry_count,
        'hash' => $disk->exists($voucher->file_path) ? hash('sha256', $disk->get($voucher->file_path)) : null,
    ])->all(), JSON_THROW_ON_ERROR).PHP_EOL;
} elseif (($argv[1] ?? '') === 'seed-parallel') {
    $iteration = filter_var($argv[3] ?? '', FILTER_VALIDATE_INT);
    if ($iteration === false || $iteration < 0 || $iteration > 9) {
        throw new RuntimeException('Invalid parallel fixture iteration.');
    }
    User::where('username', 'browser-tester')->update(['must_change_password' => false]);
    $vouchers = [];
    foreach (['left', 'right', 'victim'] as $offset => $role) {
        $path = 'vouchers/2026/ADA/10/'.$role.'-'.$iteration.'.pdf';
        $bytes = '%PDF-1.4 parallel '.$role.' '.$iteration.PHP_EOL.'%%EOF';
        Storage::disk('local')->put($path, $bytes);
        $voucher = Voucher::create([
            'checkada_no' => 'PARALLEL', 'dv_number' => (string) (80000000 + $iteration * 3 + $offset),
            'payee' => 'Parallel '.$role, 'amount' => '1.00', 'year' => 2026, 'type' => 'ADA', 'month' => 10,
            'file_path' => $path, 'uploaded_by' => User::where('username', 'browser-tester')->value('id'),
        ]);
        $vouchers[$role] = ['id' => $voucher->id, 'path' => $path, 'hash' => hash('sha256', $bytes)];
    }
    echo json_encode($vouchers, JSON_THROW_ON_ERROR).PHP_EOL;
} elseif (($argv[1] ?? '') === 'inspect-parallel') {
    $disk = Storage::disk('local');
    echo json_encode([
        'records' => Voucher::all()->map(fn (Voucher $voucher): array => [
            'id' => $voucher->id, 'path' => $voucher->file_path,
            'hash' => $disk->exists($voucher->file_path) ? hash('sha256', $disk->get($voucher->file_path)) : null,
        ])->all(),
        'trash_records' => Voucher::onlyTrashed()->get()->map(fn (Voucher $voucher): array => [
            'id' => $voucher->id, 'path' => $voucher->file_path,
            'hash' => $disk->exists($voucher->file_path) ? hash('sha256', $disk->get($voucher->file_path)) : null,
        ])->all(),
        'files' => $disk->allFiles('vouchers'),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} else {
    throw new RuntimeException('Unknown browser fixture operation.');
}
