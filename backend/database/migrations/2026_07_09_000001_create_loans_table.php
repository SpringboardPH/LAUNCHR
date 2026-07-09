<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->string('loan_type', 50);
            $table->decimal('principal', 12, 2);
            $table->decimal('interest_rate', 5, 4)->nullable();
            $table->decimal('total_payable', 12, 2);
            $table->decimal('installment_amount', 12, 2);
            $table->integer('term_count');
            $table->decimal('balance', 12, 2);
            $table->string('status', 50)->default('pending');
            $table->foreignId('request_id')->nullable()->constrained('employee_requests')->onDelete('set null');
            $table->date('start_cutoff');
            $table->foreignId('approver_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('employee_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
