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
            return $this->ask($parsed, $merged, $missing);
        }

        // Only 2316 names an employee; 1601-C is company-wide.
        if (($merged['form_type'] ?? null) === Form2316Schema::FORM_TYPE) {
            return $this->withEmployee($merged, $state);
        }

        $state->forget();

        return $this->understood($merged, sprintf(
            'Understood: a %s covering %s.',
            $merged['form_type'],
            $this->period($merged),
        ));
    }

    /** Resolve the typed name against the employees table. */
    private function withEmployee(array $merged, BirConversationState $state)
    {
        $match = $this->employees->resolve((string) $merged['employee_query']);

        if ($match['status'] === BirEmployeeResolver::NOT_FOUND) {
            return response()->json([
                'success' => true,
                'data' => [
                    'understood' => false,
                    'intent' => $merged,
                    'candidates' => [],
                    'reply' => sprintf(
                        'I could not find anyone matching "%s". Could you check the spelling, or give their employee ID?',
                        $merged['employee_query'],
                    ),
                ],
                'message' => 'Employee not found',
            ]);
        }

        if ($match['status'] === BirEmployeeResolver::AMBIGUOUS) {
            $names = collect($match['candidates'])
                ->map(fn (array $c) => "{$c['name']} ({$c['employee_id']})")
                ->implode(', ');

            return response()->json([
                'success' => true,
                'data' => [
                    'understood' => false,
                    'intent' => $merged,
                    'candidates' => $match['candidates'],
                    'reply' => "More than one person matches that: {$names}. Which one did you mean?",
                ],
                'message' => 'Several employees match',
            ]);
        }

        $employee = $match['employee'];
        $merged['employee_id'] = $employee->id;
        $state->forget();

        return $this->understood($merged, sprintf(
            'Understood: a %s for %s (%s), covering %s.',
            $merged['form_type'],
            trim($employee->first_name . ' ' . $employee->last_name),
            $employee->employee_id,
            $this->period($merged),
        ));
    }

    /** Ask for whatever is still missing, preferring the model's own wording. */
    private function ask(array $parsed, array $merged, array $missing)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'understood' => false,
                'intent' => $merged,
                'candidates' => [],
                'reply' => $parsed['clarification'] ?? $this->question($missing),
            ],
            'message' => 'Needs clarification',
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

    private function understood(array $intent, string $reply)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'understood' => true,
                'intent' => $intent,
                'candidates' => [],
                'reply' => $reply,
            ],
            'message' => 'Request understood',
        ]);
    }

    /** Rebuild the period string after merging, since parse() only saw one message. */
    private function period(array $intent): string
    {
        if ($intent['form_type'] === Form2316Schema::FORM_TYPE) {
            return (string) $intent['tax_year'];
        }

        return sprintf('%04d-%02d', $intent['tax_year'], $intent['tax_month']);
    }
}