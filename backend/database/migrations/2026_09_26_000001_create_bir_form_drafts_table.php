<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bir_form_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('form_type', 10); // 1601-C | 2316
            $table->string('period', 10); // YYYY-MM for 1601-C, YYYY for 2316
            $table->foreignId('employee_id')->nullable()->constrained('employees')->onDelete('set null'); // 2316 only
            $table->string('status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('parent_id')->nullable()->constrained('bir_form_drafts')->onDelete('set null');
            $table->json('fields')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->json('validation_errors')->nullable();
            $table->foreignId('prepared_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['form_type', 'period']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bir_form_drafts');
    }
};
