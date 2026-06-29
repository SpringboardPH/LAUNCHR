<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtr_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('cutoff_type', 20); // '10th', '25th', 'monthly'
            $table->string('period_label', 20); // e.g. 'June 2026'
            $table->string('original_filename');
            $table->string('file_path');
            $table->timestamp('auto_delete_at');
            $table->timestamps();
            $table->unique(['employee_id', 'cutoff_type', 'period_label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_uploads');
    }
};
