<?php

namespace App\Observers;

use App\ActivityRecorder;
use App\Models\Voucher;

class VoucherActivityObserver
{
    public function __construct(private ActivityRecorder $recorder) {}

    public function created(Voucher $voucher): void
    {
        $this->recorder->record($voucher, 'voucher.uploaded', after: $this->recorder->snapshot($voucher));
    }

    /**
     * Handle the Voucher "updated" event.
     */
    public function updated(Voucher $voucher): void
    {
        $fields = array_intersect(['checkada_no', 'dv_number', 'payee'], array_keys($voucher->getChanges()));
        $before = array_intersect_key($voucher->getOriginal(), array_flip($fields));
        $after = array_intersect_key($this->recorder->snapshot($voucher), array_flip($fields));
        $filenameChanged = $voucher->wasChanged('file_path') && basename($voucher->getOriginal('file_path')) !== basename($voucher->file_path);
        if ($filenameChanged) {
            $before['file_name'] = basename($voucher->getOriginal('file_path'));
            $after['file_name'] = basename($voucher->file_path);
        }
        if ($fields !== [] || $filenameChanged) {
            $this->recorder->record($voucher, $filenameChanged ? 'voucher.renamed' : 'voucher.details_changed', $before, $after);
        }
        if ($voucher->wasChanged('amount')) {
            $this->recorder->record($voucher, 'voucher.amount_changed', ['amount' => $voucher->getOriginal('amount')], ['amount' => $voucher->amount]);
        }
        if ($voucher->wasChanged(['year', 'type', 'month'])) {
            $fields = ['year', 'type', 'month'];
            $this->recorder->record($voucher, 'voucher.moved', array_intersect_key($voucher->getOriginal(), array_flip($fields)), array_intersect_key($this->recorder->snapshot($voucher), array_flip($fields)));
        }
    }

    /**
     * Handle the Voucher "deleted" event.
     */
    public function deleted(Voucher $voucher): void
    {
        if (! $voucher->isForceDeleting()) {
            $this->recorder->record($voucher, 'voucher.deleted', before: $this->recorder->snapshot($voucher));
        }
    }

    /**
     * Handle the Voucher "restored" event.
     */
    public function restored(Voucher $voucher): void
    {
        $this->recorder->record($voucher, 'voucher.restored', after: $this->recorder->snapshot($voucher));
    }

    /**
     * Handle the Voucher "force deleted" event.
     */
    public function forceDeleted(Voucher $voucher): void
    {
        $this->recorder->record($voucher, 'voucher.purged', before: $this->recorder->snapshot($voucher));
    }
}
