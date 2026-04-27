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
        Schema::create('calendar_event_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., "Holiday", "Company Event", "Maintenance Day"
            $table->text('description')->nullable();
            $table->string('color')->default('#3B82F6'); // Tailwind blue by default
            $table->boolean('counts_as_absence')->default(false); // Whether this event type exempts employees from absence
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_event_types');
    }
};
