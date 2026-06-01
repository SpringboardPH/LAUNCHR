<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if the key already exists to avoid duplicate entries if seeder was already run
        $exists = DB::table('system_settings')->where('key', 'system_name')->exists();
        
        if (!$exists) {
            DB::table('system_settings')->insert([
                'key' => 'system_name',
                'value' => 'Synctalents International',
                'description' => 'The name of the system displayed in the sidebar',
                'type' => 'string',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')->where('key', 'system_name')->delete();
    }
};
