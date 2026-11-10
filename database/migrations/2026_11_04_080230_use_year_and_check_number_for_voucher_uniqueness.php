<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $conflict = DB::table('vouchers')->selectRaw('year, UPPER(checkada_no) as number, COUNT(*) as total')
                ->groupBy('year')->groupByRaw('UPPER(checkada_no)')->havingRaw('COUNT(*) > 1')->first();
            if ($conflict !== null) {
                throw new RuntimeException('Resolve Check / ADA number '.$conflict->number.' repeated in '.$conflict->year.' before applying this rule. No records were removed.');
            }
            DB::statement('CREATE UNIQUE INDEX vouchers_year_checkada_no_unique ON vouchers (year, checkada_no COLLATE NOCASE)');
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->dropUnique('vouchers_year_dv_number_unique');
                $table->index('dv_number');
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            if (DB::table('vouchers')->select('year', 'dv_number')->groupBy('year', 'dv_number')->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Cannot restore DV uniqueness while a year contains repeated DV numbers. No records were removed.');
            }
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->unique(['year', 'dv_number']);
                $table->dropIndex('vouchers_dv_number_index');
                $table->dropUnique('vouchers_year_checkada_no_unique');
            });
        });
    }
};
