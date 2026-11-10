<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('checkada_no', 40);
            $table->string('dv_number', 12);
            $table->string('payee');
            $table->decimal('amount', 15, 2);
            $table->unsignedSmallInteger('year');
            $table->string('type', 10);
            $table->unsignedTinyInteger('month');
            $table->string('file_path')->unique();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->foreign('year')->references('year')->on('years')->restrictOnDelete();
            $table->unique(['year', 'dv_number']);
            $table->index(['year', 'type', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
