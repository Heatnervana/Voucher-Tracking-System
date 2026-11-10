<?php

namespace App;

use App\Models\Voucher;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VoucherRecycleBin
{
    /** @param Collection<int, Voucher> $vouchers */
    public function trash(Collection $vouchers, ?int $deletedBy): int
    {
        return DB::transaction(function () use ($vouchers, $deletedBy): int {
            foreach ($vouchers as $voucher) {
                $voucher->refresh();
                if ($voucher->trashed()) {
                    throw new RuntimeException('A selected voucher is already in the recycle bin.');
                }
                $voucher->forceFill([
                    'deleted_by' => $deletedBy,
                    'amount_read_token' => (string) Str::uuid(),
                    'amount_status' => $voucher->amount === null ? 'manual' : $voucher->amount_status,
                ])->saveOrFail();
                if (! $voucher->delete()) {
                    throw new RuntimeException('The voucher could not be moved to the recycle bin.');
                }
            }

            return $vouchers->count();
        }, 3);
    }

    /** @param Collection<int, Voucher> $vouchers */
    public function restore(Collection $vouchers): int
    {
        $disk = Storage::disk('local');

        return DB::transaction(function () use ($vouchers, $disk): int {
            foreach ($vouchers as $voucher) {
                $voucher->refresh();
                if (! $voucher->trashed()) {
                    throw new DomainException('A selected voucher is no longer in the recycle bin.');
                }
                if (! $disk->exists($voucher->file_path)) {
                    throw new DomainException('The PDF '.basename($voucher->file_path).' is missing. Recover its file from a backup before restoring.');
                }
                if (Voucher::withVoucherNumbers($voucher->checkada_no, $voucher->dv_number)->whereKeyNot($voucher->id)->exists()) {
                    throw new DomainException('A saved voucher already uses the same Check / ADA and DV numbers. Nothing was restored.');
                }
                $voucher->forceFill(['deleted_by' => null]);
                if (! $voucher->restore()) {
                    throw new RuntimeException('The voucher could not be restored.');
                }
            }

            return $vouchers->count();
        }, 3);
    }

    /**
     * @param  Collection<int, Voucher>  $vouchers
     * @return array{purged: int, cleanup_failed: bool}
     */
    public function purge(Collection $vouchers): array
    {
        $disk = Storage::disk('local');
        $moved = [];
        try {
            foreach ($vouchers as $voucher) {
                $voucher->refresh();
                if (! $voucher->trashed()) {
                    throw new RuntimeException('Only PDFs in the recycle bin can be permanently deleted.');
                }
                if ($disk->exists($voucher->file_path)) {
                    $temporary = $voucher->file_path.'.deleting-'.Str::uuid();
                    if (! $disk->move($voucher->file_path, $temporary)) {
                        throw new RuntimeException('The PDF could not be prepared for permanent deletion.');
                    }
                    $moved[$voucher->file_path] = $temporary;
                }
            }
            DB::transaction(function () use ($vouchers): void {
                foreach ($vouchers as $voucher) {
                    if (! $voucher->forceDelete()) {
                        throw new RuntimeException('The voucher could not be permanently deleted.');
                    }
                }
            }, 3);
        } catch (Throwable $exception) {
            foreach ($moved as $original => $temporary) {
                if ($disk->exists($temporary) && ! $disk->move($temporary, $original)) {
                    Log::critical('Recycle-bin rollback could not restore PDF', ['path' => $original, 'temporary_path' => $temporary]);
                }
            }
            throw $exception;
        }

        $cleanupFailed = false;
        foreach ($moved as $temporary) {
            try {
                if (! $disk->delete($temporary)) {
                    throw new RuntimeException('PDF cleanup failed.');
                }
            } catch (Throwable $exception) {
                $cleanupFailed = true;
                Log::error('Recycle-bin PDF cleanup failed', ['path' => $temporary, 'reason' => $exception->getMessage()]);
            }
        }

        return ['purged' => $vouchers->count(), 'cleanup_failed' => $cleanupFailed];
    }
}
