<?php

namespace App\Http\Controllers\Api;

use App\Helpers\SystemClock;
use App\Http\Controllers\Controller;
use App\Models\BirFormDraft;
use App\Models\User;
use App\Services\BIR\BirAnswerParser;
use App\Services\BIR\BirChatCommands;
use App\Services\BIR\BirConversationService;
use App\Services\BIR\BirConversationState;
use App\Services\BIR\BirDraftValidator;
use App\Services\BIR\BirEmployeeResolver;
use App\Services\BIR\BirExplanationService;
use App\Services\BIR\BirFormNames;
use App\Services\BIR\BirIntentService;
use App\Services\BIR\Schemas\Form1601CSchema;
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
 * Week 6: a message that isn't a readable answer is sorted by the model (a question
 * about a box, a tax question, a new request) and answered in PHP from the draft's
 * records and the schema's help text. The model labels; it never writes a reply.
 * Fixes from chat testing: a refused year is explained (B3), and the model's label only
 * replaces the re-ask with an explanation when the message reads as a question (D4).
 *
 * Fixes from user-side testing: the model never words a reply (its "unsure" question is
 * replaced by a line asking the user to check the summary); "what is item 12 on the 2316?"
 * is answered on either form, with or without a draft; "change item 12 to …" changes a box
 * on the open draft and is never saved as the answer to another question. These three are
 * read in PHP before the model is asked, so they work with it switched off.
 *
 * Supervisor's changes (BirChatCommands): a box on any saved form can be read or changed
 * without opening it; a filed form says how it can be corrected (the button, or "yes");
 * asking to submit sends a form for review (BirChatSubmit); "undo" takes back a change made here. A TIN finds
 * the employee for a 2316. A caller that can run the workflow's revise and submit for the
 * chat sends actions: true; without it, the chat only points to the buttons.
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
        private readonly BirExplanationService $explanations,
        private readonly BirChatCommands $commands,
    ) {}

    public function message(Request $request)
    {
        $validated = $request->validate([
            'message' => ['required_without:draft_id', 'nullable', 'string', 'max:500'],
            'reset' => ['sometimes', 'boolean'],
            'draft_id' => ['sometimes', 'integer'],
            'field' => ['sometimes', 'string'],
            'actions' => ['sometimes', 'boolean'],
        ]);
        $state = new BirConversationState($request->user()->id);
        $canAct = (bool) ($validated['actions'] ?? false);

        if (isset($validated['draft_id'])) {
            return $this->draftTurn((int) $validated['draft_id'], $validated['field'] ?? null, $validated['message'] ?? null, $state, $request, $canAct);
        }

        if ($validated['reset'] ?? false) {
            $state->reset();
        }

        $message = $validated['message'];

        // Reading or changing a box on a saved form, "yes", "submit it for review" and "undo": PHP only.
        if (($command = $this->commands->handle($message, null, $state, $request->user(), $canAct)) !== null) {
            return $this->commandReply($command, null, $request->user());
        }

        // "What is item 12 on the 2316?" needs no draft: the form's help text answers it.
        if (($item = BirAnswerParser::itemMentioned($message)) !== null && self::asksAbout($message)) {
            return $this->reply($state->get(), false, $this->aboutItem($message, $item, $state->get()['form_type'] ?? null));
        }

        try {
            $parsed = $this->intent->parse($message, $state->get());
        } catch (RuntimeException $e) {
            // The assistant being down must never take the feature down with it.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() . ' You can still pick a form and period manually.',
            ], 503);
        }

        // A TIN names the employee whatever the model made of it: "2316 for 920-919-920-00001 2024".
        if (preg_match(BirEmployeeResolver::TIN_PATTERN, $message, $tin)) {
            $parsed['employee_query'] = $tin[0];
            if (str_contains($message, '2316')) {
                $parsed['kind'] = 'form_request';
            }
        }

        // Not a form request: a fixed reply, and nothing is remembered from it.
        if ($parsed['kind'] !== 'form_request') {
            return $this->reply($state->get(), false, $parsed['kind'] === 'tax_question'
                ? BirExplanationService::DECLINE
                : BirExplanationService::NOT_A_REQUEST);
        }

        $merged = $state->merge($parsed);
        $missing = $this->intent->missingFields($merged);

        // A year no form can be prepared for: say so, then ask for the year, rather than
        // asking as if none was given.
        if ($parsed['rejected_year'] !== null) {
            return $this->reply($merged, false, $this->refusedYear($parsed['rejected_year']) . ' '
                . $this->question($this->intent->missingFields(['tax_year' => null] + $merged)));
        }

        // What's missing is asked in PHP's words. The model never words a reply: when it was
        // unsure but nothing is missing, the summary asks the user to check it instead, and
        // nothing is created until they click Create draft.
        if ($missing !== []) {
            return $this->reply($merged, false, $this->question($missing));
        }
        $check = $parsed['needs_clarification'] ? ' Please check this is right before creating the draft.' : '';

        // Only 2316 names an employee; 1601-C is company-wide.
        if (($merged['form_type'] ?? null) === Form2316Schema::FORM_TYPE) {
            return $this->withEmployee($merged, $state, $check);
        }

        $state->forget();

        return $this->reply($merged, true, sprintf(
            'Understood: a %s covering %s.%s',
            $merged['form_type'],
            $merged['period'],
            $check,
        ));
    }

    /** Resolve the typed name against the employees table. */
    private function withEmployee(array $merged, BirConversationState $state, string $check = '')
    {
        $match = $this->employees->resolve((string) $merged['employee_query']);

        if ($match['status'] === BirEmployeeResolver::NOT_FOUND && preg_match(BirEmployeeResolver::TIN_PATTERN, (string) $merged['employee_query'])) {
            return $this->reply($merged, false, "No employee has the TIN {$merged['employee_query']}. Please check the number, or give their name or employee ID.");
        }

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
        // The name on file, for the summary card ("maria santos" was typed; this is who was found).
        $merged['employee_name'] = trim($employee->first_name . ' ' . $employee->last_name) . " ({$employee->employee_id})";

        return $this->reply($merged, true, sprintf(
            'Understood: a %s for %s, covering %s.%s',
            $merged['form_type'],
            $merged['employee_name'],
            $merged['period'],
            $check,
        ));
    }

    /** Whether this user may still change this form: it isn't filed, and it isn't under review or approved unless they are accounting. */
    private static function editableBy(BirFormDraft $draft, User $user): bool
    {
        return in_array($draft->status, BirFormDraft::EDITABLE_STATUSES, true) && !BirChatCommands::reviewedFor($draft, $user);
    }

    /**
     * One turn of filling in a draft. Nothing is saved here: an accepted answer comes back
     * as data.answer for the caller to save through PUT /bir/drafts/{id}.
     */
    private function draftTurn(int $draftId, ?string $field, ?string $message, BirConversationState $state, Request $request, bool $canAct)
    {
        // employee: BirConversationService::context() needs the hire date for a 2316.
        $draft = BirFormDraft::with('employee')->find($draftId);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        // A locked form asks nothing and saves nothing, but can still be explained. So does a
        // form under review or approved for anyone but accounting: PUT would refuse the answer.
        $editable = self::editableBy($draft, $request->user());
        $locked = BirChatCommands::reviewedFor($draft, $request->user())
            ? 'Form ' . BirFormNames::name($draft) . ' is ' . BirFormNames::statusWord($draft) . ', and only accounting can change it now. You can still ask what a box means or where its figure came from.'
            : 'Form ' . BirFormNames::name($draft) . ' is ' . BirFormNames::statusWord($draft) . ", so it can't be changed here. You can still ask what a box means or where its figure came from.";

        // "change item 12 to …" (here or on any form), reading a box on another form, "yes",
        // "submit it for review", "done", "undo": read in PHP, whatever question is open. Never an answer.
        if ($message !== null && ($command = $this->commands->handle($message, $draft, $state, $request->user(), $canAct)) !== null) {
            return $this->commandReply($command, $draft, $request->user());
        }

        // Just after the chat's "yes" started a correction: the change it was asked for.
        if ($message === null && ($resumed = $this->commands->resume($draft, $state)) !== null) {
            return $this->commandReply($resumed, $draft, $request->user());
        }

        // "What is item 12?" / "where did item 25A come from?": the item number picks the box.
        if ($message !== null && ($item = BirAnswerParser::itemMentioned($message)) !== null && self::asksAbout($message)) {
            $open = $editable ? $this->conversation->turn($draft, null, null)['question'] : null;

            return $this->draftReply($draft, ['question' => $open, 'answer' => null], self::thenAsk($this->aboutItem($message, $item, $draft->form_type, $draft), $open));
        }

        $turn = $editable
            ? $this->conversation->turn($draft, $field, $message)
            : ['question' => null, 'answer' => null, 'reply' => $locked, 'unread' => $message !== null];

        if ($turn['unread']) {
            $turn['reply'] = $this->sorted($draft, $turn, (string) $message) ?? $turn['reply'];
        }
        if ($turn['answer'] !== null) {
            $this->commands->rememberAnswer($state, $draft, $turn['answer']); // "undo" can take it back
        }

        return $this->draftReply($draft, $turn, $turn['reply'] ?? $this->finished($draft, $canAct));
    }

    /**
     * A command's result as a reply. An answer goes with the form it is for, so the caller
     * saves it there and the chat moves there. Otherwise the chat stays where it was, and
     * asks its open question again when the command says to.
     *
     * @param  array{reply: ?string, draft: ?BirFormDraft, answer: ?array, action: ?array, candidates: array, stay: bool}  $command
     */
    private function commandReply(array $command, ?BirFormDraft $current, User $user)
    {
        if ($command['draft'] !== null && $command['answer'] !== null) {
            return $this->draftReply($command['draft'], ['question' => null, 'answer' => $command['answer']], (string) $command['reply']);
        }
        if ($current === null) {
            return $this->reply([], false, $command['reply'], $command['candidates'], $command['action']);
        }

        $open = $command['stay'] && self::editableBy($current, $user) ? $this->conversation->turn($current, null, null)['question'] : null;

        return $this->draftReply($current, ['question' => $open, 'answer' => null], $command['reply'] === null ? null : self::thenAsk($command['reply'], $open), $command['action'], $command['candidates']);
    }

    /**
     * A message that wasn't a readable answer: sorted by the model, answered in PHP. Null
     * keeps turn()'s own reply (the re-ask, or the next question), which is also all that
     * happens with the model down, so filling in a draft never depends on it.
     *
     * @param  array{question: ?array}  $turn
     */
    private function sorted(BirFormDraft $draft, array $turn, string $message): ?string
    {
        $open = $turn['question']['field'] ?? null;

        try {
            $sorted = $this->intent->classifyDraftMessage($message, $draft->form_type, $this->conversation->boxes($draft->form_type), $open);
        } catch (RuntimeException) {
            return null;
        }

        $box = $sorted['field'] ?? $open;

        // Explaining the open box only replaces the re-ask when the message asks for it.
        // "around 150k" is a muddled answer whatever the label, and the parser's own message
        // says what to type instead.
        if (in_array($sorted['kind'], ['explain_meaning', 'explain_source'], true) && $box === $open
            && !BirAnswerParser::looksLikeQuestion($message)) {
            return null;
        }

        $reply = match ($sorted['kind']) {
            'explain_meaning' => $box !== null ? $this->explanations->meaning($draft, $box) : BirExplanationService::WHICH_BOX,
            'explain_source' => $box !== null ? $this->explanations->source($draft, $box) : BirExplanationService::WHICH_BOX,
            'tax_question' => BirExplanationService::DECLINE,
            'new_request' => BirExplanationService::NEW_REQUEST,
            'other' => BirExplanationService::OTHER,
            default => null, // a muddled answer: keep the re-ask
        };

        // The side question is answered; then the open question is asked again.
        return $reply !== null && $turn['question'] !== null ? "{$reply}\n\n{$turn['question']['text']}" : $reply;
    }

    /** Once nothing is left to ask: whether anything else still blocks submitting. */
    private function finished(BirFormDraft $draft, bool $canAct = false): string
    {
        $errors = count(array_filter($this->validator->validate($draft), fn (array $entry) => $entry['severity'] === 'error'));

        return $errors === 0
            ? 'That is everything this form needs from you. Check the preview, then ' . ($canAct ? 'say "submit it for review" or click Submit for review' : 'click Submit for review') . ' when it is ready. Until then it stays a draft.'
            : "That is everything I can ask here, but {$errors} " . ($errors === 1 ? 'issue still needs' : 'issues still need')
                . ' fixing before it can be submitted. They are listed on the draft.';
    }

    /**
     * Same envelope as reply(), plus the draft turn: question is the field now being asked
     * (null when nothing is), answer is { field, value } to save, done means nothing is left.
     * action, when set, is a workflow step for the caller to run first ({type: revise|submit,
     * draft_id}); reply is shown once it succeeds.
     *
     * @param  array{question: ?array, answer: ?array}  $turn
     */
    private function draftReply(BirFormDraft $draft, array $turn, ?string $reply, ?array $action = null, array $candidates = [])
    {
        return response()->json([
            'success' => true,
            'data' => [
                'understood' => false,
                'intent' => null,
                'candidates' => $candidates,
                'reply' => $reply,
                'draft_id' => $draft->id,
                'question' => $turn['question'],
                'answer' => $turn['answer'],
                'action' => $action,
                'done' => $turn['question'] === null && $turn['answer'] === null,
            ],
            'message' => $turn['answer'] !== null ? 'Answer read' : ($turn['question'] !== null ? 'Question asked' : 'Nothing left to ask'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $intent
     * @param  array<int,array<string,mixed>>  $candidates
     */
    private function reply(array $intent, bool $understood, ?string $reply, array $candidates = [], ?array $action = null)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'understood' => $understood,
                'intent' => $intent,
                'candidates' => $candidates,
                'reply' => $reply,
                'action' => $action,
            ],
            'message' => $understood ? 'Request understood' : 'Needs clarification',
        ]);
    }

    /**
     * The help text of an item, on the form the message names, else the draft's, else the
     * one this conversation is about. On the open draft, "where…?" gets the box's source;
     * any other form only its meaning, since only a draft has figures.
     */
    private function aboutItem(string $message, string $item, ?string $formType, ?BirFormDraft $draft = null): string
    {
        $named = match (true) {
            str_contains($message, '1601') => Form1601CSchema::FORM_TYPE,
            str_contains($message, '2316') => Form2316Schema::FORM_TYPE,
            default => $formType,
        };
        if ($named === null) {
            return BirExplanationService::WHICH_FORM;
        }

        $asksSource = preg_match('/\b(where|saan|galing|come from|came from|why|bakit|source)\b/i', $message) === 1;
        if ($draft !== null && $named === $draft->form_type && $asksSource) {
            $parts = $this->conversation->fieldsForItem($named, $item);
            if ($parts !== []) {
                return implode("\n\n", array_map(fn (array $f) => $this->explanations->source($draft, $f['key']), $parts));
            }
        }

        $meaning = $this->explanations->meaningOfItem($named, $item);
        if ($meaning === null) {
            return "The {$named} has no item {$item}.";
        }

        return $asksSource && ($draft === null || $named !== $draft->form_type)
            ? "{$meaning}\n\n" . BirExplanationService::SOURCE_NEEDS_DRAFT
            : $meaning;
    }

    /** A message that asks about something: a question mark, or a question word anywhere in it. */
    private static function asksAbout(string $message): bool
    {
        return BirAnswerParser::looksLikeQuestion($message)
            || preg_match('/\b(what|whats|ano|anong|explain|mean|means|meaning|ibig sabihin|paliwanag|where|saan|galing|why|bakit|paano|how)\b/i', $message) === 1;
    }

    /** A reply, then the question still open, so the user is never left without one. */
    private static function thenAsk(string $reply, ?array $open): string
    {
        return $open !== null ? "{$reply}\n\n{$open['text']}" : $reply;
    }

    /** Why a year was refused. Years from FIRST_YEAR up to next year are accepted. */
    private function refusedYear(int $year): string
    {
        return $year > SystemClock::now()->year
            ? "I can't prepare a form for {$year} because that year hasn't happened yet."
            : "I can't prepare a form for {$year}. I prepare forms from " . BirIntentService::FIRST_YEAR . ' onward.';
    }

    /** @param array<int,string> $missing */
    private function question(array $missing): string
    {
        if ($missing === []) {
            return 'Which form and period did you need?';
        }

        // One month per 1601-C: saying so stops "every month of 2025" going round in circles.
        $note = in_array('the month', $missing, true) ? ' I prepare one 1601-C at a time.' : '';

        if (count($missing) === 1) {
            return 'Could you tell me ' . $missing[0] . '?' . $note;
        }

        $last = array_pop($missing);

        return 'Could you tell me ' . implode(', ', $missing) . ' and ' . $last . '?' . $note;
    }
}