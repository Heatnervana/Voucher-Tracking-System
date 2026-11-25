<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->string('utility_category', 12)->nullable()->index();
            $table->string('utility_status', 16)->default('none')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropIndex(['utility_category']);
            $table->dropIndex(['utility_status']);
            $table->dropColumn(['utility_category', 'utility_status']);
        });
    }
};
