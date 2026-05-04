<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->date('cutoff_start');
            $table->date('cutoff_end');
            $table->decimal('base_salary', 12, 2);
            $table->decimal('total_hours', 10, 2)->default(0);
            $table->decimal('overtime_hours', 10, 2)->default(0);
            $table->integer('late_minutes')->default(0);
            $table->integer('undertime_minutes')->default(0);
            $table->decimal('gross_pay', 12, 2)->default(0);
            $table->json('deductions')->nullable();
            $table->json('allowances')->nullable();
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->string('status')->default('draft'); // draft, finalized, paid
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
