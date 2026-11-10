<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class LockVoucherFiles
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $lock = @fopen(storage_path('framework/voucher-files.lock'), 'c');

        if ($lock === false) {
            report(new RuntimeException('Could not open the voucher storage lock.'));
            abort(503, 'Voucher storage is temporarily unavailable. Please try again.');
        }

        try {
            $deadline = hrtime(true) + 15_000_000_000;

            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    $message = 'A backup or another voucher change is in progress. Please try again after it finishes.';

                    if ($request->expectsJson()) {
                        return response()->json(['message' => $message], 423);
                    }

                    $input = $request->input();
                    $encoded = $request->input('voucher_details');
                    if (is_string($encoded) && is_array($details = json_decode($encoded, true))) {
                        $input['vouchers'] = $details;
                    }

                    return back()->withInput($input)->withErrors(['voucher_files' => $message]);
                }

                usleep(25_000);
            }

            return $next($request);
        } finally {
            fclose($lock);
        }
    }
}
