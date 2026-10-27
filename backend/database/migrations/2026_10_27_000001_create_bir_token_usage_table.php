<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The AI tokens the BIR chat used, one row per day and user (BirTokenUsage), so the chat can
// show how many are left of the budget set in System Settings until it resets.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bir_token_usage', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->timestamps();
            $table->unique(['day', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bir_token_usage');
    }
};
