<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        if (!in_array($request->user()->role, $roles)) {
            // Routes that admin may use keep the old message; one closed to admin
            // (e.g. BIR approve, accounting-only) says who may use it instead.
            $message = in_array('admin', $roles)
                ? 'Unauthorized. Admin access required.'
                : 'Unauthorized. Only ' . implode(' or ', $roles) . ' users can do this.';

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 403);
        }

        return $next($request);
    }
}
