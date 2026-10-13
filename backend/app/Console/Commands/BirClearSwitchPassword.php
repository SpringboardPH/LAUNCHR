<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\BirSwitchController;
use App\Models\SystemSettings;
use Illuminate\Console\Command;

/**
 * Server-only fallback for a forgotten company password (contract §6): deletes the saved hash and
 * turns the BIR Form Assistant off. An admin then sets a new password in the app.
 *
 *   php artisan bir:clear-switch-password
 */
class BirClearSwitchPassword extends Command
{
    protected $signature = 'bir:clear-switch-password';

    protected $description = 'Delete the BIR switch company password and turn the BIR Form Assistant off';

    public function handle(): int
    {
        if (!$this->confirm('Are you sure? This deletes the company password and turns the BIR Form Assistant off.')) {
            $this->info('Nothing changed.');

            return self::SUCCESS;
        }

        SystemSettings::where('key', BirSwitchController::PASSWORD_KEY)->delete();

        $existing = SystemSettings::where('key', 'bir_forms_enabled')->first();
        SystemSettings::set('bir_forms_enabled', false, $existing?->description, 'boolean');

        $this->info('Company password deleted and the BIR Form Assistant is off. An admin can set a new password in the app.');

        return self::SUCCESS;
    }
}
