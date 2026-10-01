<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BIR\BirConversationState;
use App\Services\BIR\BirEmployeeResolver;
use App\Services\BIR\BirIntentService;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * POST /api/bir/chat
 *
 * Sequences the three steps of understanding a request: parse it, merge it
 * with what earlier messages established, then find the employee if one was
 * named. Each step is a service call — nothing is computed here.
 *
 * The merged intent carries a ready-to-use period string, so the caller can
 * pass it straight to POST /bir/drafts without rebuilding it.
 *
 * Week 3 scope: the request is understood and the employee identified. Draft
 * creation follows in Week 5.
 */
class BirChatController extends Controller
{
    public function __construct(
        private readonly BirIntentService $intent,
        private readonly BirEmployeeResolver $employees,
    ) {}

    public function message(Request $request)
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'reset' => ['sometimes', 'boolean'],
        ]);

        $state = new BirConversationState($request->user()->id);

        if ($validated['reset'] ?? false) {
            $state->forget();
        }

        try {
            $parsed = $this->intent->parse($validated['message'], $state->get());
        } catch (RuntimeException $e) {
            // The assistant being down must never take the feature down with it.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() . ' You can still pick a form and period manually.',
            ], 503);
        }

        $merged = $state->merge($parsed);
        $missing = $this->intent->missingFields($merged);

        if ($missing !== [] || $parsed['needs_clarification']) {
            return $this->reply($merged, false, $parsed['clarification'] ?? $this->question($missing));
        }

        // Only 2316 names an employee; 1601-C is company-wide.
        if (($merged['form_type'] ?? null) === Form2316Schema::FORM_TYPE) {
            return $this->withEmployee($merged, $state);
        }

        $state->forget();

        return $this->reply($merged, true, sprintf(
            'Understood: a %s covering %s.',
            $merged['form_type'],
            $merged['period'],
        ));
    }

    /** Resolve the typed name against the employees table. */
    private function withEmployee(array $merged, BirConversationState $state)
    {
        $match = $this->employees->resolve((string) $merged['employee_query']);

        if ($match['status'] === BirEmployeeResolver::NOT_FOUND) {
            return $this->reply($merged, false, sprintf(
                'I could not find anyone matching "%s". Could you check the spelling, or give their employee ID?',
                $merged['employee_query'],
            ));
        }

        if ($match['status'] === BirEmployeeResolver::AMBIGUOUS) {
            $names = collect($match['candidates'])
                ->map(fn (array $c) => "{$c['name']} ({$c['employee_id']})")
                ->implode(', ');

            return $this->reply(
                $merged,
                false,
                "More than one person matches that: {$names}. Which one did you mean?",
                $match['candidates'],
            );
        }

        $employee = $match['employee'];

        // Re-merge so the resolved id is both returned and remembered.
        $merged = $state->merge(['employee_id' => $employee->id]);
        $state->forget();

        return $this->reply($merged, true, sprintf(
            'Understood: a %s for %s (%s), covering %s.',
            $merged['form_type'],
            trim($employee->first_name . ' ' . $employee->last_name),
            $employee->employee_id,
            $merged['period'],
        ));
    }

    /**
     * @param  array<string,mixed>  $intent
     * @param  array<int,array<string,mixed>>  $candidates
     */
    private function reply(array $intent, bool $understood, string $reply, array $candidates = [])
    {
        return response()->json([
            'success' => true,
            'data' => [
                'understood' => $understood,
                'intent' => $intent,
                'candidates' => $candidates,
                'reply' => $reply,
            ],
            'message' => $understood ? 'Request understood' : 'Needs clarification',
        ]);
    }

    /** @param array<int,string> $missing */
    private function question(array $missing): string
    {
        if ($missing === []) {
            return 'Which form and period did you need?';
        }

        if (count($missing) === 1) {
            return 'Could you tell me ' . $missing[0] . '?';
        }

        $last = array_pop($missing);

        return 'Could you tell me ' . implode(', ', $missing) . ' and ' . $last . '?';
    }
}