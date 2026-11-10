<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $conflict = DB::table('vouchers')->selectRaw('UPPER(checkada_no) AS number, dv_number, COUNT(*) AS total')
                ->groupByRaw('UPPER(checkada_no), dv_number')->havingRaw('COUNT(*) > 1')->first();
            if ($conflict !== null) {
                throw new RuntimeException('Duplicate voucher pairs must be resolved before upgrading. No records were removed.');
            }
            DB::statement('DROP INDEX vouchers_checkada_no_dv_number_unique');
            DB::statement('CREATE UNIQUE INDEX vouchers_checkada_no_dv_number_unique ON vouchers (checkada_no COLLATE NOCASE, dv_number)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep the required case-insensitive identity constraint when rolling back this repair.
    }
};
