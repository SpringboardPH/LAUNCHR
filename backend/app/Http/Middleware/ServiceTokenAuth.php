<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service-to-service auth for the HR-assistant bridge (consumed by Ledgr's assistant).
 *
 * Guards a read-only, minimal-field HR API with a shared bearer token. This is NOT a
 * user session — it authenticates a trusted internal caller (the Ledgr server), not an
 * employee. The whole bridge is also gated by HR_ASSISTANT_BRIDGE_ENABLED so it can be
 * killed server-side independent of the token.
 */
class ServiceTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('services.hr_assistant.enabled')) {
            abort(404); // bridge disabled: behave as if the route does not exist
        }

        $expected = config('services.hr_assistant.token');
        $provided = $request->bearerToken();

        if (empty($expected) || !is_string($provided) || !hash_equals($expected, $provided)) {
            abort(401, 'Invalid service token.');
        }

        return $next($request);
    }
}
