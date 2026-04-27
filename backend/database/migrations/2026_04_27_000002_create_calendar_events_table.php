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
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_event_type_id')->constrained('calendar_event_types')->cascadeOnDelete();
            $table->date('event_date');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('color')->nullable(); // Can override type color per event
            $table->boolean('counts_as_absence')->nullable(); // Can override type setting per event
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            
            $table->index('event_date');
            $table->index(['event_date', 'calendar_event_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
