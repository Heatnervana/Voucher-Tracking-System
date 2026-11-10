<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecycleBinController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\YearController;
use App\Http\Middleware\LockVoucherFiles;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::middleware('password.changed')->group(function (): void {
        Route::redirect('/', '/vouchers');
        Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
        Route::get('/vouchers/amount-status', [VoucherController::class, 'savedAmountStatus'])->name('vouchers.amount-status');
        Route::middleware('can:manage-accounts')->group(function (): void {
            Route::get('/activity', [ActivityLogController::class, 'index'])->name('activity.index');
            Route::resource('accounts', AccountController::class)->except('show');
            Route::put('/accounts/{account}/password', [AccountController::class, 'resetPassword'])->name('accounts.password');
        });
        Route::middleware('can:manage-vouchers')->group(function (): void {
            Route::get('/recycle-bin', [RecycleBinController::class, 'index'])->name('recycle-bin.index');
            Route::post('/recycle-bin/restore-selected', [RecycleBinController::class, 'restoreSelected'])->middleware(LockVoucherFiles::class)->name('recycle-bin.restore-selected');
            Route::delete('/recycle-bin/selected', [RecycleBinController::class, 'destroySelected'])->middleware(LockVoucherFiles::class)->name('recycle-bin.destroy-selected');
            Route::get('/recycle-bin/{voucher}/pdf', [RecycleBinController::class, 'preview'])->withTrashed()->name('recycle-bin.preview');
            Route::post('/recycle-bin/{voucher}/restore', [RecycleBinController::class, 'restore'])->withTrashed()->middleware(LockVoucherFiles::class)->name('recycle-bin.restore');
            Route::delete('/recycle-bin/{voucher}', [RecycleBinController::class, 'destroy'])->withTrashed()->middleware(LockVoucherFiles::class)->name('recycle-bin.destroy');
            Route::get('/vouchers/upload', [VoucherController::class, 'create'])->name('vouchers.create');
            Route::post('/vouchers/upload', [VoucherController::class, 'store'])->block(120, 15)->name('vouchers.store');
            Route::post('/vouchers/upload/start', [VoucherController::class, 'startUpload'])->block(120, 15)->name('vouchers.upload.start');
            Route::post('/vouchers/upload/chunk', [VoucherController::class, 'uploadChunk'])->block(120, 15)->name('vouchers.upload.chunk');
            Route::post('/vouchers/upload/complete', [VoucherController::class, 'completeUpload'])->block(120, 15)->name('vouchers.upload.complete');
            Route::post('/vouchers/upload/remove-file', [VoucherController::class, 'removeStagedFile'])->block(120, 15)->name('vouchers.upload.remove-file');
            Route::get('/vouchers/confirm', [VoucherController::class, 'confirm'])->block(120, 15)->name('vouchers.confirm');
            Route::get('/vouchers/upload/amounts', [VoucherController::class, 'amounts'])->block(120, 15)->name('vouchers.upload.amounts');
            Route::get('/vouchers/upload/preview', [VoucherController::class, 'preview'])->block(120, 15)->name('vouchers.upload.preview');
            Route::post('/vouchers/confirm', [VoucherController::class, 'finalize'])->middleware(LockVoucherFiles::class)->block(120, 15)->name('vouchers.finalize');
            Route::delete('/vouchers/confirm', [VoucherController::class, 'cancelUpload'])->block(120, 15)->name('vouchers.cancel-upload');
            Route::delete('/vouchers/bulk/month', [VoucherController::class, 'destroyMonth'])->middleware(LockVoucherFiles::class)->name('vouchers.destroy-month');
            Route::delete('/vouchers/bulk/selected', [VoucherController::class, 'destroySelected'])->middleware(LockVoucherFiles::class)->name('vouchers.destroy-selected');
            Route::delete('/vouchers/bulk/folder', [VoucherController::class, 'destroyFolder'])->middleware(LockVoucherFiles::class)->name('vouchers.destroy-folder');
            Route::get('/vouchers/{voucher}/download', [VoucherController::class, 'download'])->name('vouchers.download');
            Route::post('/vouchers/{voucher}/move', [VoucherController::class, 'move'])->middleware(LockVoucherFiles::class)->name('vouchers.move');
            Route::patch('/vouchers/{voucher}/filename', [VoucherController::class, 'rename'])->middleware(LockVoucherFiles::class)->name('vouchers.rename');
            Route::patch('/vouchers/{voucher}/amount', [VoucherController::class, 'updateAmount'])->middleware(LockVoucherFiles::class)->name('vouchers.update-amount');
            Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy'])->middleware(LockVoucherFiles::class)->name('vouchers.destroy');
            Route::get('/years/create', [YearController::class, 'create'])->name('years.create');
            Route::post('/years', [YearController::class, 'store'])->name('years.store');
        });
        Route::get('/vouchers/{voucher}/pdf', [VoucherController::class, 'pdf'])->name('vouchers.pdf');
        Route::get('/vouchers/{voucher}', [VoucherController::class, 'show'])->name('vouchers.show');
    });
});
