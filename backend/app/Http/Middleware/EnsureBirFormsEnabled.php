<?php

namespace App\Http\Middleware;

use App\Models\SystemSettings;
use Closure;
use Illuminate\Http\Request;

/**
 * The bir_forms_enabled switch (contract §1). Off, or no row at all, refuses every role,
 * admin included, like dtr_page_enabled does for DTR uploads. GET /bir/config is not behind it.
 */
class EnsureBirFormsEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (!SystemSettings::get('bir_forms_enabled', false)) {
            return response()->json([
                'success' => false,
                'message' => 'The BIR Form Assistant is not enabled',
            ], 403);
        }

        return $next($request);
    }
}
