<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BirFormDraft;
use App\Services\BIR\BirConversationService;
use App\Services\BIR\BirConversationState;
use App\Services\BIR\BirDraftValidator;
use App\Services\BIR\BirEmployeeResolver;
use App\Services\BIR\BirIntentService;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * POST /api/bir/chat
 *
 * Without draft_id: understands a request. Sequences parse it, merge it with what
 * earlier messages established, then find the employee if one was named. The merged
 * intent carries a ready-to-use period string for POST /bir/drafts.
 *
 * With draft_id (Week 5): fills in that draft, one missing field at a time. Send no
 * message to get the next question; send the field just asked and the user's message
 * to have it read. PHP only, so it works with the language model down.
 *
 * Each step is a service call — nothing is computed here.
 */
class BirChatController extends Controller
{
    public function __construct(
        private readonly BirIntentService $intent,
        private readonly BirEmployeeResolver $employees,
        private readonly BirConversationService $conversation,
        private readonly BirDraftValidator $validator,
    ) {}

    public function message(Request $request)
    {
        $validated = $request->validate([
            'message' => ['required_without:draft_id', 'nullable', 'string', 'max:500'],
            'reset' => ['sometimes', 'boolean'],
            'draft_id' => ['sometimes', 'integer'],
            'field' => ['sometimes', 'string'],
        ]);

        if (isset($validated['draft_id'])) {
            return $this->draftTurn((int) $validated['draft_id'], $validated['field'] ?? null, $validated['message'] ?? null);
        }

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
     * One turn of filling in a draft. Nothing is saved here: an accepted answer comes back
     * as data.answer for the caller to save through PUT /bir/drafts/{id}.
     */
    private function draftTurn(int $draftId, ?string $field, ?string $message)
    {
        // employee: BirConversationService::context() needs the hire date for a 2316.
        $draft = BirFormDraft::with('employee')->find($draftId);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        if (!in_array($draft->status, BirFormDraft::EDITABLE_STATUSES, true)) {
            return $this->draftReply($draft, ['question' => null, 'answer' => null], "This form is {$draft->status}, so it can't be changed here.");
        }

        $turn = $this->conversation->turn($draft, $field, $message);

        return $this->draftReply($draft, $turn, $turn['reply'] ?? $this->finished($draft));
    }

    /** Once nothing is left to ask: whether anything else still blocks submitting. */
    private function finished(BirFormDraft $draft): string
    {
        $errors = count(array_filter($this->validator->validate($draft), fn (array $entry) => $entry['severity'] === 'error'));

        return $errors === 0
            ? 'That is everything this form needs from you. Check the preview, then use Submit to send it for review.'
            : "That is everything I can ask here, but {$errors} " . ($errors === 1 ? 'issue still needs' : 'issues still need')
                . ' fixing before it can be submitted. They are listed on the draft.';
    }

    /**
     * Same envelope as reply(), plus the draft turn: question is the field now being asked
     * (null when nothing is), answer is { field, value } to save, done means nothing is left.
     *
     * @param  array{question: ?array, answer: ?array}  $turn
     */
    private function draftReply(BirFormDraft $draft, array $turn, string $reply)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'understood' => false,
                'intent' => null,
                'candidates' => [],
                'reply' => $reply,
                'draft_id' => $draft->id,
                'question' => $turn['question'],
                'answer' => $turn['answer'],
                'done' => $turn['question'] === null && $turn['answer'] === null,
            ],
            'message' => $turn['answer'] !== null ? 'Answer read' : ($turn['question'] !== null ? 'Question asked' : 'Nothing left to ask'),
        ]);
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