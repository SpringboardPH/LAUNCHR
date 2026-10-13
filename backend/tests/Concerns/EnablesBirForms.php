<?php

namespace Tests\Concerns;

use App\Models\SystemSettings;

/**
 * Turns the bir_forms_enabled switch on for a BIR test. The test database starts empty, so the
 * switch is off (missing row = false) and every /bir route except GET /bir/config would refuse.
 * Laravel calls setUpEnablesBirForms() itself, after RefreshDatabase has migrated.
 */
trait EnablesBirForms
{
    protected function setUpEnablesBirForms(): void
    {
        SystemSettings::set('bir_forms_enabled', true, null, 'boolean');
    }
}
