<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;

/**
 * Reading forms in the chat (BirChatCommands sends questions here): what a box holds on any
 * saved form, by its item number or its name, and a form's versions.
 *
 * - "what is item 25A on form #64v2?", "what is the company TIN on Maria Santos's 2316 for
 *   2026?" (BirBoxMatcher; when no box's name fits, the model may pick one from the form's
 *   list of boxes, never anything else).
 * - "how many versions does form #64 have?", "what version is form #72?", "how many times
 *   was the March 2026 1601-C amended?": every version of the form, with its label, what
 *   it is (original, amendment or version) and its status, and how many amendments are left.
 *
 * Nothing here changes a form. Replies use BirChatCommands::result().
 */
class BirChatLookup
{
    /** Words asking for what a box holds rather than what it means. */
    private const VALUE_WORDS = '/\b(value|laman|nakalagay|how much|magkano|amount|figure|show|give|tell me|check|what\'?s in|what is in|ano ang nasa|ano yung nasa)\b/i';

    private const SOURCE_WORDS = '/\b(where|saan|galing|come from|came from|why|bakit|source)\b/i';

    /** A question about what a box means, or a request for a new form: never read as a lookup by name. */
    private const NOT_A_LOOKUP = '/\b(mean|means|meaning|ibig sabihin|explain|paliwanag|make|generate|prepare|create|gawa|gumawa|need|kailangan)\b/i';

    /** A question about a form's versions or how often it was amended. */
    public const VERSIONS = '/\\b(how many (?:versions|amendments|corrections|times)|ilang (?:version|amendment|beses)|(?:what|which|anong) version|(?:list|show)(?: me)?(?: all)?(?: the)? versions|version history|versions of)\\b/i';

    public function __construct(
        private readonly BirFormFinder $finder,
        private readonly BirConversationService $conversation,
        private readonly BirExplanationService $explanations,
        private readonly BirIntentService $intent,
    ) {}

    /**
     * "How many versions does form #64 have?" — every version of the form, oldest first.
     * Details that fit several versions of one form are enough; several separate forms are
     * listed for the user to pick.
     */
    public function versions(string $message, ?BirFormDraft $current, BirConversationState $state): array
    {
        $ref = $this->finder->reference($message);
        $asked = $ref['number'] !== null ? $this->target($ref, $current, $message, $state) : null;
        if (is_array($asked)) {
            return $asked;
        }
        if ($asked === null && ($ref['named'] || $ref['person'] !== BirEmployeeResolver::NOT_NAMED)) {
            $found = $this->finder->resolve(['version' => null, 'status' => null, 'latest' => false] + $ref, $current);
            if (is_array($found)) {
                $roots = $found['needs'] === 'form' ? $this->finder->forms(['version' => null, 'status' => null, 'latest' => false] + $ref)
                    ->groupBy(fn (BirFormDraft $d) => BirFormNames::rootId($d)) : collect();
                if ($roots->count() !== 1) {
                    if ($found['ask']) {
                        $state->setPending(['message' => $message, 'needs' => $found['needs']]);
                    }

                    return BirChatCommands::result($found['reply'], null, null, null, $found['candidates'], stay: true);
                }
                $found = $roots->first()->first();
            }
            $asked = $found;
        }
        $asked ??= $current;
        if ($asked === null) {
            return BirChatCommands::result('Which form do you mean? Give its number, for example "how many versions does form #64 have?", or describe it, like "the March 2026 1601-C".', stay: true);
        }

        $chain = $this->finder->chain($asked);
        $made = $chain->max('version') - 1;
        $lead = $ref['number'] !== null && preg_match('/\\b(?:what|which|anong) version\\b/i', $message)
            ? 'Form ' . BirFormNames::name($asked) . ' is ' . ($asked->version <= 1 ? 'the original' : BirFormNames::versionWord($asked)) . ' of ' . BirFormNames::describe($asked) . ".\n" : '';
        $lines = $chain->map(fn (BirFormDraft $d) => '- form ' . BirFormNames::name($d) . ': ' . BirFormNames::versionWord($d) . ', ' . BirFormNames::statusWord($d))->implode("\n");
        $limit = BirFormFinder::amendmentLimit($asked->form_type);
        $times = $made === 1 ? 'once' : "{$made} times";
        $summary = match (true) {
            $made === 0 => (BirFormNames::isAmendment($asked) ? 'It has not been amended.' : 'It has not been corrected.'),
            BirFormNames::isAmendment($asked) => "It has been amended {$times}" . ($limit !== null ? '; ' . max(0, $limit - $made) . " of {$limit} amendments are left." : '.'),
            default => "It has been corrected {$times}.",
        };
        $count = $chain->count() === 1 ? '1 version' : $chain->count() . ' versions';

        return BirChatCommands::result($lead . ucfirst(BirFormNames::describe($asked)) . " has {$count}:\n{$lines}\n{$summary}", stay: true);
    }

    /**
     * "What is item 25A on Maria Santos's 2316 for 2026?" — what a box holds on any saved form.
     * Null when the message only asks what an item means on a form type ("what is item 14 on
     * the 1601-C?"); the chat's help text answers that. With no item number ($item null) the
     * box is found by name, and only for a question that names a form or asks for a value.
     */
    public function lookup(string $message, ?string $item, ?BirFormDraft $current, BirConversationState $state): ?array
    {
        $ref = $this->finder->reference($message);
        $wantsValue = preg_match(self::VALUE_WORDS, $message) === 1;

        if ($item === null && (preg_match(self::NOT_A_LOOKUP, $message)
            || !($wantsValue || BirAnswerParser::looksLikeQuestion($message))
            || !($wantsValue || BirFormFinder::specific($ref))
            || ($ref['form_type'] ?? $current?->form_type ?? $ref['number']) === null)) {
            return null;
        }

        if (!BirFormFinder::specific($ref)) {
            if (!$wantsValue || ($ref['form_type'] !== null && $current !== null && $ref['form_type'] !== $current->form_type)) {
                return null;
            }
            if ($current === null) {
                return BirChatCommands::result('Which form do you mean? Give its number or describe it with the box, for example "what is item 21 on form #12?" or "on Maria Santos\'s 2316 for 2026".');
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
                    $state->setPending(['message' => $message, 'needs' => 'box']); // "item 12" finishes it
                }

                return BirChatCommands::result($parts, null, null, stay: true);
            }
        } else {
            $parts = array_values(array_filter($this->conversation->fieldsForItem($draft->form_type, $item), fn (array $f) => $f['source'] !== 'manual'));
        }
        if ($parts === []) {
            return BirChatCommands::result("The {$draft->form_type} has no item {$item} kept here.", null, null, stay: true);
        }

        $on = 'On ' . BirFormNames::describe($draft) . ' (' . BirFormNames::versionNote($draft) . ')';
        if (preg_match(self::SOURCE_WORDS, $message)) {
            return BirChatCommands::result("{$on}:\n" . implode("\n\n", array_map(fn (array $f) => $this->explanations->source($draft, $f['key']), $parts)), null, null, stay: true);
        }

        $lines = array_map(fn (array $f) => BirConversationService::boxName($f) . ' is ' . BirConversationService::shown(($draft->fields ?? [])[$f['key']]['value'] ?? null, $f), $parts);
        $reply = count($lines) === 1 ? BirConversationService::sentence("{$on}, {$lines[0]}") : "{$on}:\n" . implode("\n", array_map(fn ($l) => BirConversationService::sentence($l), $lines));

        // A later version that isn't filed yet: say what the filed one holds, if different.
        $filed = $this->finder->versions($draft)->where('status', 'finalized')->where('version', '<', $draft->version)->last();
        if ($draft->status !== 'finalized' && $filed !== null) {
            $was = array_map(fn (array $f) => BirConversationService::shown(($filed->fields ?? [])[$f['key']]['value'] ?? null, $f), $parts);
            $now = array_map(fn (array $f) => BirConversationService::shown(($draft->fields ?? [])[$f['key']]['value'] ?? null, $f), $parts);
            if ($was !== $now) {
                $reply .= "\nThe filed version (form " . BirFormNames::name($filed) . ') has ' . implode('; ', $was) . '.';
            }
        }

        return BirChatCommands::result($reply, null, null, stay: true);
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
    public function target(array $ref, ?BirFormDraft $current, string $message, BirConversationState $state): BirFormDraft|array
    {
        $found = $this->finder->resolve($ref, $current);
        if ($found instanceof BirFormDraft) {
            return $found;
        }
        if ($found['ask']) {
            $state->setPending(['message' => $message, 'needs' => $found['needs']]); // "2026", a name or "#9" finishes it
        }

        return BirChatCommands::result($found['reply'], null, null, null, $found['candidates'], stay: true);
    }
}
