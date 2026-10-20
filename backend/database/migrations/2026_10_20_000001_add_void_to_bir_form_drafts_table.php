<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Voiding replaces deleting: the form stays, with status 'void' and why, by whom and when.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bir_form_drafts', function (Blueprint $table) {
            $table->text('void_reason')->nullable()->after('rejection_reason');
            $table->foreignId('voided_by')->nullable()->after('void_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('bir_form_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['void_reason', 'voided_at']);
        });
    }
};
