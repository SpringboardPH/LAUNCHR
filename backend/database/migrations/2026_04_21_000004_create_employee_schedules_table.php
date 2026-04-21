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
        Schema::create('employee_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            $table->foreignId('schedule_template_id')->constrained('schedule_templates')->onDelete('restrict');
            $table->date('start_date')->comment('Week start date (Monday)');
            $table->date('end_date')->comment('Week end date (Sunday)');
            $table->string('status')->default('active')->comment('active, inactive, archived');
            $table->timestamps();
            
            // Ensure one active schedule per employee per date range
            $table->unique(['employee_id', 'start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_schedules');
    }
};
