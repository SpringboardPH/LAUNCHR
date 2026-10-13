<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The company password that guards the bir_forms_enabled switch (contract §1, §6). One shared
 * password, set and changed by an admin in the app; only its bcrypt hash is stored, and no
 * endpoint ever returns it. These routes sit outside bir.enabled, or BIR could never be turned on.
 */
class BirSwitchController extends Controller
{
    /** Never returned by any endpoint, and refused by the general settings save. */
    public const PASSWORD_KEY = 'bir_switch_password_hash';

    public function setPassword(Request $request)
    {
        $request->validate([
            'password' => 'required|string|min:8|confirmed',
            'current_password' => 'nullable|string',
        ]);

        $hash = SystemSettings::get(self::PASSWORD_KEY);
        $changing = !empty($hash);

        if ($changing && !Hash::check((string) $request->input('current_password'), $hash)) {
            return response()->json([
                'success' => false,
                'message' => 'The current company password is incorrect',
            ], 422);
        }

        $existing = SystemSettings::where('key', self::PASSWORD_KEY)->first();
        SystemSettings::set(
            self::PASSWORD_KEY,
            Hash::make($request->input('password')),
            $existing?->description ?? 'Hash of the company password for the BIR switch (never returned by the API)',
            'string'
        );

        // No old/new values: a password or its hash never goes into the audit log.
        $verb = $changing ? 'changed' : 'set';
        AuditLog::log('BIR_SWITCH_PASSWORD', "{$request->user()->name} {$verb} the company password for the BIR switch");

        return response()->json([
            'success' => true,
            'data' => ['switch_password_set' => true],
            'message' => 'Company password saved',
        ]);
    }
}
