<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How a finalized form is signed: 'digital' (e-signatures) or 'paper' (a wet signature on the
// printout). Null until accounting chooses; signing is optional.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bir_form_drafts', function (Blueprint $table) {
            $table->string('signing_method', 20)->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('bir_form_drafts', function (Blueprint $table) {
            $table->dropColumn('signing_method');
        });
    }
};
