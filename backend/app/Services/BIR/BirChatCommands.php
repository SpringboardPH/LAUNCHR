<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Models\User;

/**
 * The supervisor's chat commands, read in PHP before the model is asked, so they work with
 * it switched off:
 *
 * - Read a box on any saved form, whatever its status: "what is item 25A on Maria Santos's
 *   2316 for 2026?", "item 14 of the September 2026 1601-C", or by its name: "what is the
 *   company TIN on Maria Santos's 2316 for 2026?" (BirBoxMatcher; when no box's name fits,
 *   the model may pick one from the form's list of boxes, never anything else).
 * - Change a box on any form: the open one, or another by its number ("change item 6 on
 *   form #12 to …"). Details alone ("Maria Santos's 2316 for 2026") don't pick a form to
 *   change, since two forms can share them: the forms that fit are listed by number, and a
 *   reply with the number makes the change. Applied at once; "undo" puts it back
 *   (BirChatUndo). One box per message: the reply to a change is one answer for the caller
 *   to save, so "change item 4 to 2 and item 26 to 100" changes nothing and says so. After a
 *   change to another form, the chat goes back to the open one (backFromDetour()).
 * - Open a form by its number or details: "open form #11", "show the latest 1601-C"
 *   (BirChatLookup::open()). "#11 item 25": with the box named, a bare "#11" is the form.
 * - Forms are told apart by their number and details (BirFormFinder): status ("filed",
 *   "pending", "draft"), version ("version 2", "amendment 1") as well as period and person.
 *   When several fit, the chat lists them and asks; it never picks one.
 * - Whether a form can still be changed: a draft can; a form waiting for review or approved
 *   can too, by accounting only, and an approved one goes back to draft to be approved
 *   again; a filed form can only be corrected in a new version, which the
 *   reply offers two ways: the "Start a correction" button, or "yes" to have the chat start
 *   it and make the change. A 1601-C stops at the workflow's amendment limit for its month.
 * - Sending a form for review only when asked in so many words ("submit it for review"), and
 *   "done" leaves it a draft: BirChatSubmit.
 * - Who is on a 1601-C, and one employee's part of it: BirChatEmployees.
 * - Tax advice and pay calculations are declined before anything is looked up
 *   (BirExplanationService::asksForTaxAdvice()), so "how much tax should Bea pay?" is never
 *   read as a request for a box.
 *
 * Like the rest of the chat, nothing is saved here. A change comes back as an answer for the
 * caller to save through PUT, and starting a correction or submitting comes back as an
 * action for the caller to run through the workflow's own endpoints (revise, submit), so
 * every rule there (roles, validation, the amendment limit) applies to the chat as well.
 * Actions are only offered to a caller that said it can run them ($canAct).
 *
 * handle() returns null for a message that is none of these, and the chat carries on as before.
 * Otherwise: reply, draft (the form an answer is for, where the chat moves), answer, action,
 * candidates, and stay (true when the question that was open should be asked again).
 */
class BirChatCommands
{
    private const YES = '/^\s*(yes|y|oo|opo|sige|go|go ahead|yes please|ok|okay)\s*[.!]*\s*$/i';

    private const NO = '/^\s*(no|n|hindi|huwag|wag|cancel|never ?mind)\s*[.!]*\s*$/i';

    private const UNDO = '/^\s*(undo|i-?undo|ibalik|bawiin)\b/i';

    private const NEEDS_NUMBER = "To change a form that isn't open here, I need its number.";

    /** Words of a change command, never part of a box's name. */
    private const COMMAND_WORDS = ['change', 'update', 'set', 'edit', 'correct', 'replace', 'fix', 'palitan', 'pakipalitan', 'baguhin', 'gawing', 'it', 'please'];

    public function __construct(
        private readonly BirFormFinder $finder,
        private readonly BirConversationService $conversation,
        private readonly BirExplanationService $explanations,
        private readonly BirIntentService $intent,
        private readonly BirChatUndo $undo,
        private readonly BirChatLookup $lookup,
        private readonly BirChatEmployees $employees,
        private readonly BirChatSubmit $submit,
    ) {}

    /** @return ?array{reply: ?string, draft: ?BirFormDraft, answer: ?array, action: ?array, candidates: array, stay: bool} */
    public function handle(string $message, ?BirFormDraft $current, BirConversationState $state, User $user, bool $canAct): ?array
    {
        // "2026" after "Which year?", "#9" after a list of forms, "item 3" after a list of
        // boxes: finish the command that asked.
        $pending = $state->pending();
        $state->setPending(null);
        if ($pending !== null && ($extra = $this->completion($message, $pending['needs'])) !== null) {
            // An item number for a change goes right after the command word, before the new value.
            $message = !empty($pending['insert']) && str_starts_with($extra, 'item ')
                ? preg_replace('/^(\s*\S+)/u', '$1 ' . $extra, $pending['message'], 1)
                : "{$pending['message']} {$extra}";
        }

        $offer = $state->offer();
        if ($offer !== null && empty($offer['accepted'])) {
            $state->setOffer(null);
            if (preg_match(self::YES, $message)) {
                return $this->accept($offer, $current, $state, $user, $canAct);
            }
            if (preg_match(self::NO, $message)) {
                return self::result('Okay, nothing was changed.');
            }
        }

        $message = self::bareFormNumber($message);
        $state->setReturnTo(null); // only the message that changes another form sets it
        $item = BirAnswerParser::itemMentioned($message);

        $result = match (true) {
            (bool) preg_match(self::UNDO, $message) => $this->undo->undo($item, $current, $state, $user),
            BirAnswerParser::looksLikeChange($message) => $this->change($message, $current, $state, $user, $canAct),
            BirExplanationService::asksForTaxAdvice($message) => self::result(BirExplanationService::DECLINE, stay: true),
            BirChatSubmit::asksForWorkflow($message) => $this->submit->workflow($message, $current, $state),
            BirChatSubmit::asksForStatus($message) => $this->submit->status($message, $current, $state),
            BirChatSubmit::asksToSchedule($message) => $this->submit->later($message, $current, $state),
            BirChatSubmit::asksToSubmit($message) => $this->submit->submit($message, $current, $state, $canAct),
            (bool) preg_match(BirChatLookup::VERSIONS, $message) => $this->lookup->versions($message, $current, $state),
            BirChatLookup::asksToOpen($message) && ($opened = $this->lookup->open($message, $current, $state)) !== null => $opened,
            default => $this->submit->done($message, $current)
                ?? $this->employees->answer($message, $current, $state)
                ?? $this->lookup->lookup($message, $item, $current, $state),
        };

        // A reply that asks for a number, an item or a year waits for it: the open question
        // is asked again after, not in the same breath.
        if ($result !== null && $state->pending() !== null) {
            $result['stay'] = false;
        }
        // A change saved on another form: once it is, the chat goes back to this one.
        if ($result !== null && $result['answer'] !== null && $current !== null && $result['draft']?->id !== $current->id) {
            $state->setReturnTo($current->id, $result['draft']->id);
        }

        return $result;
    }

    /**
     * Just after a change to another form was saved: back to the form that was open before,
     * so "submit it for review" next means that form again. Null when there is none to go back to.
     */
    public function backFromDetour(BirFormDraft $saved, BirConversationState $state): ?array
    {
        $return = $state->returnTo();
        if ($return === null || $return['from'] !== $saved->id) {
            return null;
        }
        $state->setReturnTo(null);
        $back = BirFormDraft::with('employee')->find($return['to']);

        return $back === null ? null : self::result('Back to form ' . BirFormNames::name($back) . ' (' . BirFormNames::describe($back) . ').', $back, stay: true);
    }

    /**
     * "#11 item 25": when the box is named by "item" or "box", a bare "#11" is the form's
     * number, if it comes first, right after the box, or after a word like "on" or "of".
     * One inside a value ("change item 6 to Unit #12, …") is left alone.
     */
    private static function bareFormNumber(string $message): string
    {
        if (!preg_match('/\b(?:item|box)\s*\d/i', $message)) {
            return $message;
        }

        return preg_replace_callback('/(?<![\w#])#\s*(\d{1,6}(?:v\d{1,2})?)\b/i', function (array $m) use ($message) {
            $before = mb_strtolower(rtrim(substr($message, 0, $m[0][1])));
            $form = $before === '' || preg_match('/(?:^|\s)(?:on|of|in|sa|ng|from|for|at|' . implode('|', self::COMMAND_WORDS) . ')$|\b(?:item|box)\s*\d{1,2}[a-z]?$/u', $before);

            return $form ? "form #{$m[1][0]}" : $m[0][0];
        }, $message, -1, $count, PREG_OFFSET_CAPTURE);
    }

    /**
     * After the caller started the correction a "yes" asked for: make the change on it.
     * Null unless this draft is that correction.
     */
    public function resume(BirFormDraft $draft, BirConversationState $state): ?array
    {
        $offer = $state->offer();
        if ($offer === null || empty($offer['accepted']) || (int) $draft->parent_id !== (int) $offer['source_id']) {
            return null;
        }
        $state->setOffer(null);

        $change = $this->conversation->change($draft, $offer['message'], $offer['value']);
        $started = 'Started ' . BirFormNames::correctionName($draft) . ', form ' . BirFormNames::name($draft) . ', from ' . BirFormNames::describe($draft)
            . '. The filed version stays as it is.';
        $finish = ' Tell me any other changes. When the ' . (BirFormNames::isAmendment($draft) ? 'amendment' : 'correction') . ' is ready, say "submit it for review".';

        if ($change['answer'] === null) {
            return self::result("{$started} {$change['reply']}{$finish}", $draft);
        }
        $this->undo->remember($state, $draft, $change['answer']);

        return self::result("{$started} {$change['reply']}{$finish}", $draft, $change['answer']);
    }

    /**
     * "change item 6 to …" on the open form, or "change item 6 on form #12 to …" on any other.
     * A form other than the open one is only changed by its number; details alone get the
     * forms that fit, listed by number, and a reply with the number finishes the change.
     */
    private function change(string $message, ?BirFormDraft $current, BirConversationState $state, User $user, bool $canAct): array
    {
        // One box per message: two changes would be two answers to save, and an "and" can also
        // be part of a value ("Unit 4 and 5"), so only a second item number counts ("box 12"
        // only before the value: "P.O. Box 123" is an address).
        $text = BirFormFinder::withoutFormNumber($message);
        $value = BirConversationService::newValue($text);
        $head = $value !== null && ($at = mb_strrpos($text, $value)) !== false ? mb_substr($text, 0, $at) : $text;
        preg_match_all('/\bitem\s*(\d{1,2}[a-z]?)\b/i', $text, $named);
        preg_match_all('/\bbox\s*(\d{1,2}[a-z]?)\b/i', $head, $boxes);
        $items = array_map('strtoupper', [...$named[1], ...$boxes[1]]);
        if (count($items) > 1) {
            return self::result('I can change one box per message, so nothing was changed. Send them one at a time, for example "change item '
                . $items[0] . ' to …", then "change item ' . $items[1] . ' to …".', null, null, stay: true);
        }

        // The form is read off the words around the new value, never the value itself: a TIN
        // or a year typed as the new value must not pick the form.
        $value = BirConversationService::newValue($message);
        $at = $value !== null ? mb_strrpos($message, $value) : false;
        $around = $at !== false ? mb_substr($message, 0, $at) : preg_split('/\s(?:to|into|ng)\s/iu', $message, 2)[0];
        if ($value !== null) {
            [$value, $tail] = $this->withoutReference($value);
            $around .= " {$tail}";
        }
        $ref = $this->finder->reference($around);

        if ($ref['number'] !== null) {
            $found = $this->lookup->target($ref, $current, $message, $state);
            if (!$found instanceof BirFormDraft) {
                return $found; // no such form, or it doesn't fit the rest: already worded
            }
            $target = $found;
        } elseif (!$ref['named'] && $ref['person'] === BirEmployeeResolver::NOT_NAMED) {
            if ($current === null) {
                return self::result(BirExplanationService::OPEN_A_FORM);
            }
            $target = $current;
        } else {
            $found = $this->finder->resolve($ref, $current, self::NEEDS_NUMBER . ' These fit:');
            if (!$found instanceof BirFormDraft || $found->id !== $current?->id) {
                return $this->askForNumber($found, $message, $state);
            }
            $target = $found;
        }

        // "change local home address of form 71 to …": the box by its name, when only one fits.
        if (BirAnswerParser::itemMentioned($message) === null) {
            $named = $this->itemNamed($target, $around, $ref);
            if ($named !== null && str_starts_with($named, 'Which one')) {
                $state->setPending(['message' => $message, 'needs' => 'box', 'insert' => true]);

                return self::result($named, null, null, stay: true);
            }
            if ($named !== null) {
                $message = preg_replace('/^(\s*\S+)/u', '$1 item ' . $named, $message, 1);
            }
        }

        if ($target->status === 'finalized' && ($open = $this->finder->openCorrection($target)) !== null) {
            $state->setPending(['message' => $message, 'needs' => 'form']);
            $label = BirFormNames::label($open);

            return self::result('Form ' . BirFormNames::label($target) . ' (' . BirFormNames::describe($target) . ") is filed, so it can't be changed. Its "
                . BirFormNames::versionWord($open) . ', form ' . BirFormNames::name($open) . ', ' . BirFormNames::statusWord($open)
                . ", is still open. Reply \"form {$label}\" to make the change there.", null, null, stay: true);
        }

        // Draft, pending and approved can be changed (an approved form goes back to draft).
        return in_array($target->status, BirFormDraft::EDITABLE_STATUSES, true)
            ? $this->changeOpen($target, $message, $value, $current, $state, $user)
            : $this->offerCorrection($target, $message, $value, $state, $canAct);
    }

    /**
     * The item a change names by its box's name (BirBoxMatcher), "Which one…" when several
     * boxes share the name, or null when the words name no box.
     */
    private function itemNamed(BirFormDraft $target, string $around, array $ref): ?string
    {
        $fields = array_values(array_filter($this->conversation->fields($target->form_type), fn (array $f) => $f['source'] !== 'manual'));
        $who = $ref['employee'] !== null ? [$ref['employee']->first_name, $ref['employee']->last_name] : [];
        $match = BirBoxMatcher::match($fields, $around, array_filter([...$who, $ref['query'], ...self::COMMAND_WORDS]));

        return match (true) {
            $match['fields'] === [] => null,
            $match['ambiguous'] => BirBoxMatcher::whichOne($target->form_type, $match['fields']),
            default => (string) $match['fields'][0]['item'],
        };
    }

    /** A change that named a form by its details only: the forms that fit, and that a number is needed. */
    private function askForNumber(BirFormDraft|array $found, string $message, BirConversationState $state): array
    {
        if ($found instanceof BirFormDraft) {
            $state->setPending(['message' => $message, 'needs' => 'form']);

            return self::result(self::NEEDS_NUMBER . ' That sounds like ' . BirFormNames::line($found) . '. Reply "form ' . BirFormNames::label($found) . '" to change it.', null, null, stay: true);
        }
        if ($found['ask']) {
            $state->setPending(['message' => $message, 'needs' => $found['needs']]);
        }

        return self::result($found['reply'], null, null, null, $found['candidates'], stay: true);
    }

    /** A form under review or approved, and a user who isn't allowed to change it (PUT would say 403). */
    public static function reviewedFor(BirFormDraft $draft, User $user): bool
    {
        return in_array($draft->status, BirFormDraft::REVIEWED_STATUSES, true)
            && !in_array($user->role, BirFormDraft::PENDING_EDITOR_ROLES, true);
    }

    /** A change on a draft, a form waiting for review, or an approved one. */
    private function changeOpen(BirFormDraft $target, string $message, ?string $value, ?BirFormDraft $current, BirConversationState $state, User $user): array
    {
        if (self::reviewedFor($target, $user)) {
            return self::result(ucfirst(BirFormNames::describe($target)) . ' (form ' . BirFormNames::name($target) . ') is ' . BirFormNames::statusWord($target)
                . ', and only accounting can change it now. You can still ask what any box holds.', null, null, stay: true);
        }

        $change = $this->conversation->change($target, $message, $value);
        if ($change['answer'] === null) {
            if (BirAnswerParser::itemMentioned($message) === null) {
                // "Which box?": the item number that comes back finishes this same change.
                $state->setPending(['message' => $message, 'needs' => 'box', 'insert' => true]);
            }

            return self::result($change['reply'], null, null, stay: true);
        }
        $this->undo->remember($state, $target, $change['answer']);

        $where = $current === null || $target->id !== $current->id
            ? 'On ' . BirFormNames::describe($target) . ' (' . BirFormNames::versionNote($target) . '): '
            : '';
        $again = $target->status === 'approved'
            ? ' It was approved, so this sends it back to a draft: submit it for review again to have it approved.'
            : '';

        return self::result($where . $change['reply'] . $again . ' Say "undo" to put it back.', $target, $change['answer']);
    }

    /** A filed form: say so, and offer the two ways to correct it, unless its amendments are used up. */
    private function offerCorrection(BirFormDraft $filed, string $message, ?string $value, BirConversationState $state, bool $canAct): array
    {
        $name = ucfirst(BirFormNames::describe($filed));
        $limit = BirFormFinder::amendmentLimit($filed->form_type);
        $made = $filed->version - 1;

        if ($limit !== null && $made >= $limit) {
            return self::result("{$name} is filed and has already been amended {$limit} times, the most allowed for one month, so it can't be changed or amended again.", null, null, stay: true);
        }

        // The new value is checked now, so a wrong one is refused before anything is offered.
        $check = $this->conversation->change($filed, $message, $value);
        if ($check['answer'] === null) {
            return self::result($check['reply'], null, null, stay: true);
        }

        $next = $filed->version + 1;
        $kind = $filed->form_type === '1601-C'
            ? 'amendment ' . ($next - 1) . ($limit !== null ? " of {$limit}" : '')
            : "a correction (version {$next})";
        $left = $limit !== null ? ' It has ' . ($limit - $made) . " of {$limit} amendments left." : '';
        $reply = "{$name} is filed, so it can't be changed.{$left} To fix it, open it and click \"Start a correction\"";

        if (!$canAct) {
            return self::result("{$reply}.", null, null, stay: true);
        }
        $state->setOffer(['source_id' => $filed->id, 'message' => $message, 'value' => $value]);

        return self::result("{$reply}, or reply yes and I'll start {$kind} and make the change there.");
    }

    /** "yes" to a correction offer: the caller starts it (revise), then resume() makes the change. */
    private function accept(array $offer, ?BirFormDraft $current, BirConversationState $state, User $user, bool $canAct): array
    {
        $filed = BirFormDraft::with('employee')->find($offer['source_id']);

        // Someone started a correction or the form moved on since: go again, which says where.
        if (!$canAct || $filed === null || $filed->status !== 'finalized' || $this->finder->openCorrection($filed) !== null) {
            return $filed !== null
                ? $this->change($offer['message'], $current, $state, $user, $canAct)
                : self::result('That form no longer exists, so nothing was changed.');
        }

        $limit = BirFormFinder::amendmentLimit($filed->form_type);
        if ($limit !== null && $filed->version - 1 >= $limit) {
            return self::result(ucfirst(BirFormNames::describe($filed)) . " has already been amended {$limit} times, so it can't be amended again.");
        }

        $state->setOffer($offer + ['accepted' => true]);

        return self::result(null, null, null, ['type' => 'revise', 'draft_id' => $filed->id]);
    }

    /**
     * What a short reply adds to the command that asked for it, or null when it is a new
     * message: a form number ("#9", "9", "form 9") or "the filed one" after a list of forms,
     * an item number after a list of boxes, a year or a person after "Which year?".
     */
    private function completion(string $message, string $needs): ?string
    {
        $short = str_word_count($message) <= 5 && !BirAnswerParser::looksLikeChange($message);
        // A bare year ("2026") is a year even here; "#2026" or "form 2026" would be a number.
        if ($needs === 'form' && preg_match('/^\s*(?:(form|draft)\s*(?:no\.?|number)?\s*)?(#)?\s*(\d{1,6})(?:\s*v(\d{1,2}))?\s*[.!]?\s*$/i', $message, $m)
            && ($m[1] !== '' || $m[2] !== '' || isset($m[4]) || !preg_match('/^(19|20)\d{2}$/', $m[3]))) {
            return "on form #{$m[3]}" . (isset($m[4]) && $m[4] !== '' ? "v{$m[4]}" : '');
        }
        if ($needs === 'box' && preg_match('/^\s*(?:item|box|#)?\s*(\d{1,2}[a-z]?)\s*[.!?]?\s*$/i', $message, $m)) {
            return 'item ' . strtoupper($m[1]);
        }
        if (!$short || BirAnswerParser::itemMentioned($message) !== null) {
            return null;
        }
        $ref = $this->finder->reference($message);

        return $ref['named'] || $ref['person'] === BirEmployeeResolver::MATCHED ? $message : null;
    }

    /**
     * "12 Mabini St., Quezon City for Maria Santos 2026" → the address, and the words that
     * named the form, which are read with the rest of the reference.
     *
     * @return array{0: string, 1: string}
     */
    private function withoutReference(string $value): array
    {
        if (preg_match('/^(.+?)\s+((?:for|on|in|sa|ni|of|para sa)\s+.+)$/iu', $value, $m)) {
            $ref = $this->finder->reference($m[2]);
            if ($ref['named'] || $ref['person'] === BirEmployeeResolver::MATCHED) {
                return [rtrim($m[1], ' ,'), $m[2]];
            }
        }

        return [$value, ''];
    }

    /** Remember an answer to one of the chat's questions, so "undo" can take it back too. */
    public function rememberAnswer(BirConversationState $state, BirFormDraft $draft, array $answer): void
    {
        $this->undo->remember($state, $draft, $answer);
    }

    /** The shape every command returns (see the class comment). */
    public static function result(?string $reply, ?BirFormDraft $draft = null, ?array $answer = null, ?array $action = null, array $candidates = [], bool $stay = false): array
    {
        return compact('reply', 'draft', 'answer', 'action', 'candidates', 'stay');
    }
}
