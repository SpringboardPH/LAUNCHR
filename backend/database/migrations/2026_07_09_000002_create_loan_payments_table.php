<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('cascade');
            $table->foreignId('payroll_id')->constrained('payrolls')->onDelete('cascade');
            $table->decimal('amount', 12, 2);
            $table->date('cutoff_start');
            $table->date('cutoff_end');
            $table->timestamps();

            $table->unique(['loan_id', 'payroll_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
    }
};
