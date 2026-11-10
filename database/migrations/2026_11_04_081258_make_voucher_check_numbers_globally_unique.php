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
            $conflict = DB::table('vouchers')->selectRaw('UPPER(checkada_no) as number, COUNT(*) as total')
                ->groupByRaw('UPPER(checkada_no)')->havingRaw('COUNT(*) > 1')->first();
            if ($conflict !== null) {
                throw new RuntimeException('Resolve repeated Check / ADA number '.$conflict->number.' across the archive before applying this rule. No records were removed.');
            }
            DB::statement('CREATE UNIQUE INDEX vouchers_checkada_no_unique ON vouchers (checkada_no COLLATE NOCASE)');
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->dropUnique('vouchers_year_checkada_no_unique');
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('CREATE UNIQUE INDEX vouchers_year_checkada_no_unique ON vouchers (year, checkada_no COLLATE NOCASE)');
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->dropUnique('vouchers_checkada_no_unique');
            });
        });
    }
};
