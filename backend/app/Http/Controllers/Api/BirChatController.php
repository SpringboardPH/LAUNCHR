<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BIR\BirIntentService;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * POST /api/bir/chat — the entry point routes/api.php already points at.
 *
 * Week 2 scope: understand the request and reply. It does not create drafts
 * yet; the response carries the parsed intent so Dev D can show what was
 * understood, and Week 3 wires it to POST /bir/drafts.
 *
 * Employee resolution is Week 3, so a 2316 request returns the name as typed
 * and a note that resolution is pending.
 */
class BirChatController extends Controller
{
    public function __construct(private readonly BirIntentService $intent) {}

    public function message(Request $request)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:500'],
        ]);

        try {
            $intent = $this->intent->parse($validated['message']);
        } catch (RuntimeException $e) {
            // The assistant being down must never take the feature down with it.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() . ' You can still pick a form and period manually.',
            ], 503);
        }

        if ($intent['needs_clarification']) {
            return response()->json([
                'success' => true,
                'data' => [
                    'understood' => false,
                    'intent' => $intent,
                    'reply' => $intent['clarification'] ?? 'Which form and period did you need?',
                ],
                'message' => 'Needs clarification',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'understood' => true,
                'intent' => $intent,
                'reply' => $this->confirmation($intent),
            ],
            'message' => 'Request understood',
        ]);
    }

    /** @param array<string,mixed> $intent */
    private function confirmation(array $intent): string
    {
        if ($intent['employee_query'] !== null) {
            return sprintf(
                'Understood: a %s for %s, covering %s. Looking up the employee is not wired up yet.',
                $intent['form_type'],
                $intent['employee_query'],
                $intent['period'],
            );
        }

        return sprintf(
            'Understood: a %s covering %s.',
            $intent['form_type'],
            $intent['period'],
        );
    }
}
