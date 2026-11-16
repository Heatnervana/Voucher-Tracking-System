<?php

namespace App;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StagedVoucherUpload
{
    /**
     * TRUST-BOUNDARY: Paths come from the locked upload session; failed entries remain available for retry.
     */
    public function clear(Session $session): bool
    {
        $upload = $session->get('voucher_upload');
        if (! is_array($upload)) {
            return true;
        }

        $remaining = $upload['files'] ?? [];
        foreach ($remaining as $id => $file) {
            $path = $file['path'] ?? null;
            try {
                Cache::forget('voucher-amount:'.$id);
                if ($path !== null && ! Storage::disk('local')->delete($path)) {
                    throw new RuntimeException('The staged PDF could not be deleted.');
                }
                unset($remaining[$id]);
            } catch (Throwable $exception) {
                report(new RuntimeException("Could not clear staged PDF {$id} at {$path}.", 0, $exception));
            }
        }

        if ($remaining !== []) {
            $upload['files'] = $remaining;
            $session->put('voucher_upload', $upload);

            return false;
        }

        $session->forget('voucher_upload');

        return true;
    }
}
