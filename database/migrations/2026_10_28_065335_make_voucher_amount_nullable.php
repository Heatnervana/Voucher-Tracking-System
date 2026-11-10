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
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->decimal('amount', 15, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('vouchers')->whereNull('amount')->exists()) {
            throw new RuntimeException('Enter all missing voucher amounts before rolling back this migration.');
        }
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->decimal('amount', 15, 2)->nullable(false)->change();
        });
    }
};
