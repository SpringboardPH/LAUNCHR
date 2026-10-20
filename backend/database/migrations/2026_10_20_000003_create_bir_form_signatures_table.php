<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per signature on a finalized form: the employer's, and on a 2316 also the employee's.
// image_path is a frozen copy of the signer's saved image, so replacing it later changes nothing here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bir_form_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bir_form_draft_id')->constrained('bir_form_drafts')->cascadeOnDelete();
            $table->string('role', 20);
            $table->foreignId('user_id')->constrained('users');
            $table->string('image_path');
            $table->timestamp('signed_at');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->char('fields_hash', 64);
            $table->timestamps();

            // Nobody signs the same form twice, even with two clicks at once.
            $table->unique(['bir_form_draft_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bir_form_signatures');
    }
};
