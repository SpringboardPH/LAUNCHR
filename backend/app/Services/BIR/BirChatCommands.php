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
 * - Change a box on any form without opening it: "edit 2316 item #6 for maria santos,
 *   change it to …". The change goes to the form's latest version; it is applied at once
 *   and remembered, so "undo" puts it back.
 * - Whether a form can still be changed: draft, or waiting for review (accounting only),
 *   can; approved can't; a filed form can only be corrected in a new version, which the
 *   reply offers two ways: the "Start a correction" button, or "yes" to have the chat start
 *   it and make the change. A 1601-C stops at the workflow's amendment limit for its month.
 * - "done" sends the open form for review (an amendment counts once it is submitted).
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

    private const DONE = '/^\s*(done|i\'?m done|tapos na?|finished|that\'?s all|submit( it)?( for review)?)\s*[.!]*\s*$/i';

    private const UNDO = '/^\s*(undo|i-?undo|ibalik|bawiin)\b/i';

    /** Words asking for what a box holds rather than what it means. */
    private const VALUE_WORDS = '/\b(value|laman|nakalagay|how much|magkano|amount|figure|show|give|tell me|check|what\'?s in|what is in|ano ang nasa|ano yung nasa)\b/i';

    private const SOURCE_WORDS = '/\b(where|saan|galing|come from|came from|why|bakit|source)\b/i';

    /** A question about what a box means, or a request for a new form: never read as a lookup by name. */
    private const NOT_A_LOOKUP = '/\b(mean|means|meaning|ibig sabihin|explain|paliwanag|make|generate|prepare|create|gawa|gumawa|need|kailangan)\b/i';

    public function __construct(
        private readonly BirFormFinder $finder,
        private readonly BirConversationService $conversation,
        private readonly BirExplanationService $explanations,
        private readonly BirDraftValidator $validator,
        private readonly BirIntentService $intent,
    ) {}

    /** @return ?array{reply: ?string, draft: ?BirFormDraft, answer: ?array, action: ?array, candidates: array, stay: bool} */
    public function handle(string $message, ?BirFormDraft $current, BirConversationState $state, User $user, bool $canAct): ?array
    {
        // "2026" after "Which year?": finish the command that asked.
        $pending = $state->pending();
        $state->setPending(null);
        if ($pending !== null && $this->completes($message)) {
            $message = "{$pending} {$message}";
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

        $item = BirAnswerParser::itemMentioned($message);

        return match (true) {
            (bool) preg_match(self::UNDO, $message) => $this->undo($item, $current, $state, $user),
            (bool) preg_match(self::DONE, $message) => $this->done($current, $canAct),
            BirAnswerParser::looksLikeChange($message) => $this->change($message, $current, $state, $user, $canAct),
            $item !== null => $this->lookup($message, $item, $current, $state),
            default => $this->lookup($message, null, $current, $state),
        };
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
        $started = 'Started ' . BirFormFinder::correctionName($draft) . " (#{$draft->id}) from " . BirFormFinder::describe($draft)
            . '. The filed version stays as it is.';
        $finish = ' Tell me any other changes, and say done when the ' . (BirFormFinder::isAmendment($draft) ? 'amendment' : 'correction') . ' is finished.';

        if ($change['answer'] === null) {
            return self::result("{$started} {$change['reply']}{$finish}", $draft);
        }
        $this->remember($state, $draft, $change['answer']);

        return self::result("{$started} {$change['reply']}{$finish}", $draft, $change['answer']);
    }

    /** "change item 6 on Maria Santos's 2316 for 2026 to …", or just "change item 6 to …" on the open form. */
    private function change(string $message, ?BirFormDraft $current, BirConversationState $state, User $user, bool $canAct): array
    {
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

        if (!$ref['named'] && $ref['person'] === BirEmployeeResolver::NOT_NAMED) {
            if ($current === null) {
                return self::result(BirExplanationService::OPEN_A_FORM);
            }
            $target = $this->finder->latest($current);
        } else {
            $found = $this->target($ref, $current, $message, $state);
            if (!$found instanceof BirFormDraft) {
                return $found;
            }
            $target = $found;
        }

        return match ($target->status) {
            'draft', 'pending' => $this->changeOpen($target, $message, $value, $current, $state, $user),
            'approved' => self::result(ucfirst(BirFormFinder::describe($target)) . " (#{$target->id}) is approved and waiting to be filed, so it can't be changed. Once it is marked as filed, a correction can be started.", null, null, stay: true),
            default => $this->offerCorrection($target, $message, $value, $state, $canAct),
        };
    }

    /** A change on a draft or a form waiting for review. */
    private function changeOpen(BirFormDraft $target, string $message, ?string $value, ?BirFormDraft $current, BirConversationState $state, User $user): array
    {
        if ($target->status === 'pending' && !in_array($user->role, BirFormDraft::PENDING_EDITOR_ROLES, true)) {
            return self::result(ucfirst(BirFormFinder::describe($target)) . ' is waiting for review, and only accounting can change it now. You can still ask what any box holds.', null, null, stay: true);
        }

        $change = $this->conversation->change($target, $message, $value);
        if ($change['answer'] === null) {
            return self::result($change['reply'], null, null, stay: true);
        }
        $this->remember($state, $target, $change['answer']);

        $where = '';
        if ($current === null || $target->id !== $current->id) {
            $where = 'On ' . BirFormFinder::describe($target) . ' (' . BirFormFinder::versionNote($target) . '): ';
        }
        if ($target->version > 1 && $current !== null && $current->id !== $target->id && $current->status === 'finalized'
            && $current->period === $target->period && $current->employee_id === $target->employee_id) {
            $where = 'The filed version stays as it is; the change was made on ' . BirFormFinder::correctionName($target) . " (#{$target->id}). ";
        }

        return self::result($where . $change['reply'] . ' Say "undo" to put it back.', $target, $change['answer']);
    }

    /** A filed form: say so, and offer the two ways to correct it, unless its amendments are used up. */
    private function offerCorrection(BirFormDraft $filed, string $message, ?string $value, BirConversationState $state, bool $canAct): array
    {
        $name = ucfirst(BirFormFinder::describe($filed));
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
        $filed = BirFormDraft::find($offer['source_id']);
        $latest = $filed !== null ? $this->finder->latest($filed) : null;

        // Someone started a correction or the form moved on since: go again on its latest version.
        if (!$canAct || $filed === null || $filed->status !== 'finalized' || $latest->id !== $filed->id) {
            return $latest !== null
                ? $this->change($offer['message'], $current, $state, $user, $canAct)
                : self::result('That form no longer exists, so nothing was changed.');
        }

        $limit = BirFormFinder::amendmentLimit($filed->form_type);
        if ($limit !== null && $filed->version - 1 >= $limit) {
            return self::result(ucfirst(BirFormFinder::describe($filed)) . " has already been amended {$limit} times, so it can't be amended again.");
        }

        $state->setOffer($offer + ['accepted' => true]);

        return self::result(null, null, null, ['type' => 'revise', 'draft_id' => $filed->id]);
    }

    /** "done": the open form goes for review, through the workflow's submit. */
    private function done(?BirFormDraft $current, bool $canAct): array
    {
        if ($current === null) {
            return self::result('There is no form open here to send for review. Open one, or ask for a new one.');
        }

        $name = ucfirst(BirFormFinder::describe($current));
        if ($current->status !== 'draft') {
            return self::result("{$name} is already " . BirFormFinder::STATUS_WORDS[$current->status] . ', so there is nothing to submit.', null, null, stay: true);
        }

        $errors = array_values(array_filter($this->validator->validate($current), fn (array $e) => $e['severity'] === 'error'));
        if ($errors !== []) {
            $count = count($errors);
            $list = implode("\n", array_map(fn (array $e) => "- {$e['message']}", array_slice($errors, 0, 5)));

            return self::result("It can't be sent for review yet: {$count} " . ($count === 1 ? 'issue needs' : 'issues need') . " fixing first.\n{$list}", null, null, stay: true);
        }

        if (!$canAct) {
            return self::result('Nothing is blocking it. Click "Submit for review" above the preview to send it for review.', null, null, stay: true);
        }

        $note = '';
        if ($current->version > 1) {
            $limit = BirFormFinder::amendmentLimit($current->form_type);
            $note = $limit !== null
                ? ' This is amendment ' . ($current->version - 1) . ': ' . BirFormFinder::monthName($current->period) . ' has now used ' . ($current->version - 1) . " of its {$limit} amendments."
                : " This is correction version {$current->version}.";
        }

        return self::result("Sent {$current->form_type} #{$current->id} for review.{$note}", null, null, ['type' => 'submit', 'draft_id' => $current->id]);
    }

    /** "undo" (the last change made here) or "undo item 6" (the last change to item 6). */
    private function undo(?string $item, ?BirFormDraft $current, BirConversationState $state, User $user): array
    {
        $changes = $state->changes();
        $index = null;
        for ($i = count($changes) - 1; $i >= 0; $i--) {
            if ($item === null || strtoupper((string) $changes[$i]['item']) === $item) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return self::result($item === null
                ? 'There is nothing to undo. I can undo changes made here in the chat; changes made on the form itself have "Restore calculated" there.'
                : "I haven't changed item {$item} in this conversation, so there is nothing to undo.", null, null, stay: true);
        }

        $change = $changes[$index];
        $state->dropChange($index);
        $draft = BirFormDraft::with('employee')->find($change['draft_id']);
        if ($draft === null) {
            return self::result('That form no longer exists, so there is nothing to undo.', null, null, stay: true);
        }

        $field = $this->conversation->field($draft->form_type, $change['field']);
        $name = BirConversationService::boxName($field);
        if (!in_array($draft->status, BirFormDraft::EDITABLE_STATUSES, true)
            || ($draft->status === 'pending' && !in_array($user->role, BirFormDraft::PENDING_EDITOR_ROLES, true))) {
            return self::result("{$draft->form_type} #{$draft->id} is " . BirFormFinder::STATUS_WORDS[$draft->status] . " now, so the change to {$name} can't be undone here.", null, null, stay: true);
        }

        $now = ($draft->fields ?? [])[$change['field']]['value'] ?? null;
        if (BirConversationService::same($now, $change['after']) === false) {
            return self::result("{$name} has been changed again since (it is now " . BirConversationService::shown($now, $field) . '), so I left it as it is.', null, null, stay: true);
        }

        $before = $change['before'];
        $was = $before['value'] ?? null;
        $restore = match (true) {
            ($before['origin'] ?? 'pending') === 'pending' || $was === null => null,
            empty($before['edited']) && in_array($before['origin'], ['payroll', 'settings'], true) => null,
            default => $was,
        };
        $back = match (true) {
            $was === null || ($before['origin'] ?? 'pending') === 'pending' => "It is empty again.",
            $restore === null => 'It is back to the calculated figure, ' . BirConversationService::shown($was, $field) . '.',
            default => BirConversationService::sentence('It is back to ' . BirConversationService::shown($was, $field)),
        };
        $where = $current === null || $current->id !== $draft->id ? ' on ' . BirFormFinder::describe($draft) . " (#{$draft->id})" : '';

        return self::result("Undid the change to {$name}{$where}. {$back}", $draft, ['field' => $change['field'], 'value' => $restore]);
    }

    /**
     * "What is item 25A on Maria Santos's 2316 for 2026?" — what a box holds on any saved form.
     * Null when the message only asks what an item means on a form type ("what is item 14 on
     * the 1601-C?"); the chat's help text answers that. With no item number ($item null) the
     * box is found by name, and only for a question that names a form or asks for a value.
     */
    private function lookup(string $message, ?string $item, ?BirFormDraft $current, BirConversationState $state): ?array
    {
        $ref = $this->finder->reference($message);
        $wantsValue = preg_match(self::VALUE_WORDS, $message) === 1;

        if ($item === null && (preg_match(self::NOT_A_LOOKUP, $message)
            || !($wantsValue || BirAnswerParser::looksLikeQuestion($message))
            || !($wantsValue || BirFormFinder::specific($ref))
            || ($ref['form_type'] ?? $current?->form_type) === null)) {
            return null;
        }

        if (!BirFormFinder::specific($ref)) {
            if (!$wantsValue || ($ref['form_type'] !== null && $current !== null && $ref['form_type'] !== $current->form_type)) {
                return null;
            }
            if ($current === null) {
                return self::result('Which form do you mean? Name it with the box, for example "what is item 21 on Maria Santos\'s 2316 for 2026?"');
            }
            $draft = $current;
        } else {
            $found = $this->target($ref, $current, $message, $state);
            if (!$found instanceof BirFormDraft) {
                return $found;
            }
            $draft = $found;
        }

        if ($item === null) {
            $parts = $this->boxNamed($draft, $message, $ref);
            if ($parts === null) {
                return null; // names no box: the chat carries on as before (the model sorts it)
            }
            if (is_string($parts)) {
                if (str_starts_with($parts, 'Which one')) {
                    $state->setPending($message); // "item 12" finishes it
                }

                return self::result($parts, null, null, stay: true);
            }
        } else {
            $parts = array_values(array_filter($this->conversation->fieldsForItem($draft->form_type, $item), fn (array $f) => $f['source'] !== 'manual'));
        }
        if ($parts === []) {
            return self::result("The {$draft->form_type} has no item {$item} kept here.", null, null, stay: true);
        }

        $on = 'On ' . BirFormFinder::describe($draft) . ' (' . BirFormFinder::versionNote($draft) . ')';
        if (preg_match(self::SOURCE_WORDS, $message)) {
            return self::result("{$on}:\n" . implode("\n\n", array_map(fn (array $f) => $this->explanations->source($draft, $f['key']), $parts)), null, null, stay: true);
        }

        $lines = array_map(fn (array $f) => BirConversationService::boxName($f) . ' is ' . BirConversationService::shown(($draft->fields ?? [])[$f['key']]['value'] ?? null, $f), $parts);
        $reply = count($lines) === 1 ? BirConversationService::sentence("{$on}, {$lines[0]}") : "{$on}:\n" . implode("\n", array_map(fn ($l) => BirConversationService::sentence($l), $lines));

        // A later version that isn't filed yet: say what the filed one holds, if different.
        $filed = $this->finder->versions($draft)->where('status', 'finalized')->where('version', '<', $draft->version)->last();
        if ($draft->status !== 'finalized' && $filed !== null) {
            $was = array_map(fn (array $f) => BirConversationService::shown(($filed->fields ?? [])[$f['key']]['value'] ?? null, $f), $parts);
            $now = array_map(fn (array $f) => BirConversationService::shown(($draft->fields ?? [])[$f['key']]['value'] ?? null, $f), $parts);
            if ($was !== $now) {
                $reply .= "\nThe filed version (version {$filed->version}) has " . implode('; ', $was) . '.';
            }
        }

        return self::result($reply, null, null, stay: true);
    }

    /**
     * The boxes a question names on this form (BirBoxMatcher), or what to say instead: which
     * of several it means, or the decline for a tax question. When no box's name fits, the
     * model may pick one, but only from this form's list of boxes; null when it picks none.
     *
     * @return array<int, array<string, mixed>>|string|null
     */
    private function boxNamed(BirFormDraft $draft, string $message, array $ref): array|string|null
    {
        $fields = array_values(array_filter($this->conversation->fields($draft->form_type), fn (array $f) => $f['source'] !== 'manual'));
        $who = $ref['employee'] !== null ? [$ref['employee']->first_name, $ref['employee']->last_name] : [];
        $match = BirBoxMatcher::match($fields, $message, array_filter([...$who, $ref['query']]));

        if ($match['fields'] === []) {
            if ($match['words'] === 0) {
                return null; // names no box at all: nothing to guess
            }
            try {
                $sorted = $this->intent->classifyDraftMessage($message, $draft->form_type, $this->conversation->boxes($draft->form_type), null);
            } catch (\RuntimeException) {
                return null;
            }
            if ($sorted['kind'] === 'tax_question') {
                return BirExplanationService::DECLINE;
            }
            $field = $sorted['field'] !== null ? $this->conversation->field($draft->form_type, $sorted['field']) : null;
            if ($field === null || $field['item'] === null) {
                return null;
            }
            $match = ['fields' => $this->conversation->fieldsForItem($draft->form_type, $field['item']), 'ambiguous' => false, 'words' => 1];
        }

        return $match['ambiguous'] ? BirBoxMatcher::whichOne($draft->form_type, $match['fields']) : $match['fields'];
    }

    /** The form a reference names (BirFormFinder::resolve()), or the reply saying what is missing. */
    private function target(array $ref, ?BirFormDraft $current, string $message, BirConversationState $state): BirFormDraft|array
    {
        $found = $this->finder->resolve($ref, $current);
        if ($found instanceof BirFormDraft) {
            return $found;
        }
        if ($found['ask']) {
            $state->setPending($message); // "2026" or a picked name finishes it
        }

        return self::result($found['reply'], null, null, null, $found['candidates'], stay: true);
    }

    /** A short reply that adds what a command was missing ("2026", "EMP001"), never a new command. */
    private function completes(string $message): bool
    {
        if (preg_match('/^\s*(?:item|box|#)\s*\d{1,2}[a-z]?\s*[.!?]?\s*$/i', $message)) {
            return true; // "item 12" after "Which one do you mean?"
        }
        if (str_word_count($message) > 5 || BirAnswerParser::looksLikeChange($message) || BirAnswerParser::itemMentioned($message) !== null) {
            return false;
        }
        $ref = $this->finder->reference($message);

        return $ref['named'] || $ref['person'] === BirEmployeeResolver::MATCHED;
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

    private function remember(BirConversationState $state, BirFormDraft $draft, array $answer): void
    {
        $state->rememberChange([
            'draft_id' => $draft->id,
            'field' => $answer['field'],
            'item' => $this->conversation->field($draft->form_type, $answer['field'])['item'],
            'before' => ($draft->fields ?? [])[$answer['field']] ?? [],
            'after' => $answer['value'],
        ]);
    }

    /** Remember an answer to one of the chat's questions, so "undo" can take it back too. */
    public function rememberAnswer(BirConversationState $state, BirFormDraft $draft, array $answer): void
    {
        $this->remember($state, $draft, $answer);
    }

    private static function result(?string $reply, ?BirFormDraft $draft = null, ?array $answer = null, ?array $action = null, array $candidates = [], bool $stay = false): array
    {
        return compact('reply', 'draft', 'answer', 'action', 'candidates', 'stay');
    }
}
