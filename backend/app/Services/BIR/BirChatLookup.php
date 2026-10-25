<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Services\BIR\Schemas\Form2316Schema;

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
 * - "what is item 21?" with a form open: what the box holds on it, then what it means.
 * - "open form #11", "show the latest 1601-C": the chat moves to that form (open()).
 *
 * Nothing here changes a form. Replies use BirChatCommands::result().
 */
class BirChatLookup
{
    /** Words asking for what a box holds rather than what it means. */
    /** Details from the employee record, and payroll by the month: never a box of their 2316. */
    private const RECORD_WORDS = '/\b(?:net\s*(?:pay|salary|income)|take[- ]?home|salary|sahod|sweldo|base pay|rate|hire[ds]?|hiring|joined|position|job|department|dept|e-?mail|phone|contact|status|active|employee\s*(?:id|number|no\.?)|tin|payroll|per month|monthly|each month)\b/i';

    private const VALUE_WORDS = '/\b(value|laman|nakalagay|how much|magkano|amount|figure|show|give|tell me|check|what\'?s in|what is in|ano ang nasa|ano yung nasa)\b/i';

    private const SOURCE_WORDS = '/\b(where|saan|galing|come from|came from|why|bakit|source|who (?:changed|edited|typed|entered|put)|sino (?:ang )?(?:nagpalit|nagbago|nag-?edit|naglagay))\b/i';

    /** Asking whose a TIN is: "who has", "whose", "which employee", "belongs to", "kanino", "sino". */
    private const WHO_OWNS = '/\b(?:who|whose|whom|which\s+(?:employee|person|staff|one)|belongs?\s+to|owner|kanino|kay\s+sino|sino)\b/i';

    /** A question about what a box means, or a request for a new form: never read as a lookup by name. */
    private const NOT_A_LOOKUP = '/\b(mean|means|meaning|ibig sabihin|explain|paliwanag|make|generate|prepare|create|gawa|gumawa|needs?|needed|kailangan|employee\s*(?:id|number|no\.?|code)|emp\.?\s*id|(?:don\'?t|doesn\'?t|do not|does not)\s+have|without|missing|wala\s+pa|kulang|not\s+yet|hindi\s+pa|(?:which|what|alin)\s+(?:months|forms|employees|ones)|tax\s+group|group|category|kategorya|classification|average|avg|mean|median|combined|monthly|per\s+month|how\s+many|ilan|count|number\s+of)\b/i';

    /** A question about a form's versions or how often it was amended. */
    public const VERSIONS = '/\\b(how many (?:versions|amendments|corrections|times)|ilang (?:version|amendment|beses)|(?:what|which|anong) version|(?:list|show)(?: me)?(?: all)?(?: the)? versions|version history|versions of)\\b/i';

    /** Asking to open a form: "open form #11", "show the latest 1601-C", "buksan ang form #9", "pakita ang latest na 1601-C". */
    private const OPEN = '/^\s*(?:(?:please|pls|kindly|can you|could you)\s+)?(?:paki-?)?(?:open|show(?:\s+me)?|go\s+to|switch\s+to|bring\s+up|pull\s+up|buksan|ipakita|pakita|i-?open)\b/i';

    /** Showing something about a form rather than the form: its employees, versions, PDF. */
    private const NOT_OPEN = '/\b(employees?|empleyado|people|staff|who|sino|versions?|history|pdf|download|print|explain|mean|status|how|much|magkano|paid|sahod|tax|withheld|total|value|forms|drafts|list)\b/i';

    public function __construct(
        private readonly BirFormFinder $finder,
        private readonly BirConversationService $conversation,
        private readonly BirExplanationService $explanations,
        private readonly BirIntentService $intent,
        private readonly BirEmployeeResolver $employees,
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
        if (($owner = $this->tinOwner($message, $item)) !== null) {
            return $owner;
        }
        $ref = $this->finder->reference($message);
        $wantsValue = preg_match(self::VALUE_WORDS, $message) === 1;
        // "Hazel's and Gino's TIN" names two people, not one form: a question about the records (BirDataCompare).
        if ($ref['number'] === null && $item === null && $this->employees->namedIn($message)->count() > 1) {
            return null;
        }
        // "what's Mina Lim's 13th month pay?": a box of her 2316, when she has one and the words name
        // one of its boxes. Her record ("salary of Elias?", her TIN) and a month of her pay ("how much
        // tax did Hazel have withheld in March 2026?") are questions about the records instead.
        $theirBox = false;
        if ($ref['number'] === null && BirMessageFacts::formsSaid($message) === [] && $current === null && $ref['month'] === null && BirMessageFacts::monthsSaid($message) === [] && BirDataWords::monthsAsked($message) === []
            && $ref['person'] === BirEmployeeResolver::MATCHED && !($ref['close'] ?? false) && !preg_match(self::RECORD_WORDS, $message)
            && BirFormDraft::where('employee_id', $ref['employee']->id)->where('form_type', Form2316Schema::FORM_TYPE)->exists()
            && ($item !== null || !$this->namesNoBox(['form_type' => Form2316Schema::FORM_TYPE] + $ref, $message))) {
            [$ref['form_type'], $theirBox] = [Form2316Schema::FORM_TYPE, true];
        }
        if ($ref['number'] === null && $item === null && $current === null && !$theirBox && !preg_match('/2316|1601|\b(?:form|draft|item|box)\b/i', $message)
            && $this->employees->namedIn($message)->count() === 1) {
            return null;
        }
        // "is Mina's salary over 20k?": a comparison is a question about the records, not a box to read.
        if ($ref['number'] === null && $item === null && !preg_match('/2316|1601/', $message) && BirDataWords::hasCondition($message)
            && preg_match('/\b(?:over|above|more|higher|greater|less|lower|below|under|than|at least|at most|mahigit|lagpas|kulang)\b/i', $message)) {
            return null;
        }
        // "Gino's salary and position": details from the record, not one box of a form.
        if ($ref['number'] === null && $item === null && !preg_match('/2316|1601/', $message)
            && count(array_filter(BirDataWords::SHOWN, fn (string $words) => preg_match($words, $message) === 1)) > 1) {
            return null;
        }
        // "how much tax was withheld from her in December?" on her 2316: a month of her pay, not another form.
        if ($ref['number'] === null && $item === null && $ref['month'] !== null && $current?->employee_id !== null && !preg_match('/1601/', $message)
            && preg_match('/\b(?:she|he|her|him|his|niya|nya|siya|sya|kanya)\b/i', $message)) {
            return null;
        }
        // "how much tax was withheld from Mina?" with Teresa's 2316 open: never the open form's figure.
        if ($ref['person'] === BirEmployeeResolver::NOT_NAMED && $ref['number'] === null && $current?->employee_id !== null && $item === null) {
            $named = $this->employees->capitalisedName($message);
            if ($named['status'] !== BirEmployeeResolver::NOT_NAMED && $named['employee']?->id !== $current->employee_id) {
                return null;
            }
        }
        // "form #4 25A", "#11 25": a form number, then only an item number.
        if ($item === null && preg_match('/^\s*(?:form\s*|draft\s*)?#?\s*\d{1,6}(?:\s*v\d{1,2})?\s+(\d{1,2}[a-z]?)\s*\??\s*$/i', $message, $code)) {
            $item = strtoupper($code[1]);
        }

        if ($item === null && (preg_match(self::NOT_A_LOOKUP, $message)
            || !($wantsValue || BirAnswerParser::looksLikeQuestion($message) || $ref['number'] !== null || $this->namesTheirBox($ref, $message))
            || !($wantsValue || BirFormFinder::specific($ref))
            || ($ref['form_type'] ?? $current?->form_type ?? $ref['number']) === null)) {
            return null;
        }

        // "what is item 21?" with a form open: its figure there, then what the box means.
        $withMeaning = $item !== null && $current !== null && !$wantsValue && !preg_match(self::NOT_A_LOOKUP, $message)
            && !preg_match(self::SOURCE_WORDS, $message) && preg_match('/^\s*(?:what\'?s|what is|ano ang|ano yung|anong)\b/i', $message);

        if (!BirFormFinder::specific($ref)) {
            if (!($wantsValue || $withMeaning) || ($ref['form_type'] !== null && $current !== null && $ref['form_type'] !== $current->form_type)) {
                return null;
            }
            if ($current === null) {
                return BirChatCommands::result('Which form do you mean? Give its number or describe it with the box, for example "what is item 21 on form #12?" or "on Maria Santos\'s 2316 for 2026".');
            }
            $draft = $current;
        } else {
            // "Do the 1601-C for August 2025", "Can I get the 2316 for Gino for 2026?": naming no
            // box, and with no one saved form that fits, it asks for a form; the request path reads it.
            if ($item === null && !$wantsValue && $ref['form_type'] !== null && $this->namesNoBox($ref, $message)
                && !$this->finder->resolve($ref, $current) instanceof BirFormDraft) {
                return null;
            }
            // "what is Teresa's TIN?" naming no form, and she has none saved: her employee record answers (BirDataAnswers).
            if ($item === null && $ref['number'] === null && BirMessageFacts::formsSaid($message) === [] && $ref['person'] === BirEmployeeResolver::MATCHED
                && !($ref['close'] ?? false) && !BirFormDraft::where('employee_id', $ref['employee']->id)->exists()) {
                return null;
            }
            $found = $this->target($ref, $current, $message, $state);
            if (!$found instanceof BirFormDraft) {
                return $found;
            }
            $draft = $found;
            $withMeaning = false; // a form named in the question: only what it holds
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

        if ($withMeaning && ($meaning = $this->explanations->meaningOfItem($draft->form_type, $item)) !== null) {
            $reply .= "\n\n{$meaning}";
        }

        return BirChatCommands::result($reply, null, null, stay: true);
    }

    /**
     * "who has TIN 301-111-222-00000?", "whose TIN is 301111222?", "kanino ang TIN na …": the
     * employee whose record holds that TIN, by name and ID only. Null when the message gives no
     * TIN, doesn't ask who, or asks about a form's box ("who changed the TIN on form #1").
     */
    private function tinOwner(string $message, ?string $item): ?array
    {
        // Nine digits alone count as a TIN only right after the word ("whose TIN is 301111222").
        if ($item !== null || !(preg_match(BirEmployeeResolver::TIN_PATTERN, $message, $tin) || preg_match('/\btin\b\D{0,12}?(?<![\d-])\K\d{9}(?![\d-])/i', $message, $tin))
            || !preg_match(self::WHO_OWNS, $message) || preg_match(self::SOURCE_WORDS, $message) || preg_match('/#\s*\d|\bform\s*\d/i', $message)) {
            return null;
        }
        $found = $this->employees->byTin($tin[0]);

        return BirChatCommands::result(match ($found['status']) {
            BirEmployeeResolver::MATCHED => "TIN {$tin[0]} belongs to " . BirDataAnswers::name($found['employee']) . '.',
            BirEmployeeResolver::AMBIGUOUS => "More than one employee has TIN {$tin[0]}:\n"
                . collect($found['candidates'])->map(fn (array $c) => "- {$c['name']} ({$c['employee_id']})")->implode("\n"),
            default => "No employee has the TIN {$tin[0]}. Please check the number.",
        }, stay: true);
    }

    /** True when the message asks to open a form, by its number or its details. */
    public static function asksToOpen(string $message): bool
    {
        return preg_match(self::OPEN, $message) === 1 && !preg_match(self::NOT_OPEN, $message)
            && BirAnswerParser::itemMentioned($message) === null;
    }

    /** "open form #11": the chat moves to it. Null when the message names no form ("show me how"). */
    public function open(string $message, ?BirFormDraft $current, BirConversationState $state): ?array
    {
        if ($this->employees->namedIn($message)->count() > 1) {
            return null; // "show Hazel's and Gino's TIN": two people, not a form to open
        }
        $ref = $this->finder->reference($message);
        if (!BirFormFinder::specific($ref) && $ref['form_type'] === null) {
            return null;
        }
        // Read without the open form: "the latest 1601-C" is the newest one, not the open one's latest version.
        $found = $this->target($ref, null, $message, $state);
        if (!$found instanceof BirFormDraft) {
            return $found;
        }

        return BirChatCommands::result('Now on form ' . BirFormNames::name($found) . ' (' . BirFormNames::describe($found) . ', ' . BirFormNames::statusWord($found)
            . '). Ask about any box, or say "change item 12 to …".', $found, stay: true);
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

        // "Teresa's TIN", "TIN ni Teresa": of the boxes that fit, the one about the employee named.
        // "the TIN on Teresa's 2316" names her form, not the box, and still asks.
        $theirs = array_values(array_filter($match['fields'], fn (array $f) => str_starts_with($f['key'], 'employee_')));
        $name = $ref['employee'] !== null ? preg_quote($ref['employee']->first_name, '/') . '|' . preg_quote($ref['employee']->last_name, '/') : null;
        $owns = $name !== null && preg_match("/\\b(?:{$name})(?:'s?|s')\\s+(?!(?:\\w+\\s+)?(?:2316|1601|form|certificate|draft)\\b)|\\b(?:ni|of|kay)\\s+(?:\\w+\\s+)?(?:{$name})\\b/iu", $message) === 1;
        if ($match['ambiguous'] && $owns && count($theirs) === 1) {
            $match = ['fields' => $theirs, 'ambiguous' => false] + $match;
        }
        if ($match['ambiguous']) {
            return BirBoxMatcher::whichOne($draft->form_type, $match['fields']);
        }

        return [...$match['fields'], ...$this->heldElsewhere($draft, $match)];
    }

    /** "Mina's gross compensation on her 2316": a person's form and one of its boxes, though not asked as a question. */
    private function namesTheirBox(array $ref, string $message): bool
    {
        return $ref['form_type'] !== null && $ref['person'] === BirEmployeeResolver::MATCHED && !$this->namesNoBox($ref, $message);
    }

    /** True when the message names no box of the form it refers to, by the boxes' words. */
    private function namesNoBox(array $ref, string $message): bool
    {
        $fields = array_values(array_filter($this->conversation->fields($ref['form_type']), fn (array $f) => $f['source'] !== 'manual'));
        $who = $ref['employee'] !== null ? [$ref['employee']->first_name, $ref['employee']->last_name] : [];

        return BirBoxMatcher::match($fields, $message, array_filter([...$who, $ref['query']]))['fields'] === [];
    }

    /**
     * The amount boxes the question's words fit as well, when the box picked is empty on this
     * form and they aren't: "sahod" on a 2316 picks item 39, which is 0 for pay under P250,000;
     * that pay is in item 29, so both are shown.
     *
     * @return array<int, array<string, mixed>>
     */
    private function heldElsewhere(BirFormDraft $draft, array $match): array
    {
        $amount = fn (array $f) => ($f['type'] ?? null) === 'decimal' ? (float) (($draft->fields ?? [])[$f['key']]['value'] ?? 0) : 0.0;
        $amounts = array_filter($match['fields'], fn (array $f) => ($f['type'] ?? null) === 'decimal');
        if (count($amounts) !== count($match['fields']) || array_filter($match['fields'], fn (array $f) => $amount($f) != 0) !== []) {
            return [];
        }

        return array_values(array_filter($match['tied'] ?? [], fn (array $f) => !in_array($f, $match['fields'], true) && $amount($f) != 0));
    }

    /** The form a reference names (BirFormFinder::resolve()), or the reply saying what is missing. */
    public function target(array $ref, ?BirFormDraft $current, string $message, BirConversationState $state): BirFormDraft|array
    {
        $found = $this->finder->resolve($ref, $current);
        if ($found instanceof BirFormDraft) {
            return $found;
        }
        if ($found['ask']) {
            $state->setPending(['message' => $message, 'needs' => $found['needs'], 'suggest' => $found['suggest'] ?? null]); // "2026", a name, "#9" or "yes" finishes it
        }

        return BirChatCommands::result($found['reply'], null, null, null, $found['candidates'], stay: true);
    }
}
