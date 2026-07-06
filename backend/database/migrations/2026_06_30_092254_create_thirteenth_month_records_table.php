<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thirteenth_month_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month'); // 1–12
            $table->decimal('basic_pay', 12, 2);
            $table->boolean('is_override')->default(false);
            $table->timestamps();
            $table->unique(['employee_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thirteenth_month_records');
    }
};
