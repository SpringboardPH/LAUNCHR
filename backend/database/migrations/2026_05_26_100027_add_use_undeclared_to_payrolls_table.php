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
        Schema::table('payrolls', function (Blueprint $table) {
            $table->decimal('undeclared_salary', 12, 2)->nullable()->comment('Employee undeclared salary for calculation toggle');
            $table->boolean('use_undeclared')->default(false)->comment('Toggle between base salary (false) and undeclared salary (true) for undertime deduction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['undeclared_salary', 'use_undeclared']);
        });
    }
};
