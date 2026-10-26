<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A due date set by hand (in the chat) for one form. Null keeps the usual one: the 10th of the
// next month for a 1601-C, January 31 of the next year for a 2316 (BirFormDraft::dueDate()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bir_form_drafts', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('signing_method');
        });
    }

    public function down(): void
    {
        Schema::table('bir_form_drafts', function (Blueprint $table) {
            $table->dropColumn('due_date');
        });
    }
};
