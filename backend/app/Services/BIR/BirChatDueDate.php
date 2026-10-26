<?php

namespace App\Services\BIR;

use App\Helpers\SystemClock;
use App\Models\BirFormDraft;
use Carbon\Carbon;

/**
 * A form's due date in the chat (BirChatCommands sends these here, before box changes):
 *
 * - "change the due date of form #4 to November 15, 2026", "move the deadline of the December
 *   2025 1601-C to 1/12/2026", with the form open "set the due date to Jan 15": stored on the
 *   form (due_date), so the BIR Forms to-do shows it.
 * - "reset the due date of form #4": back to the usual one (the 10th of the next month for a
 *   1601-C, January 31 of the next year for a 2316; BirFormDraft::dueDate()).
 * - "when is form #4 due?", "what's the deadline of the December 2025 1601-C?".
 *
 * A filed form's due date stays as it is. Every change is in the form's history (Auditable).
 */
final class BirChatDueDate
{
    /** "due on January 15", "due by 1/15": a date follows, so not "the tax still due on form #4". */
    private const DUE_ON = '\bdue\s+(?:on|by)\s+(?:the\s+)?(?:\d|[a-z]+\.?\s+\d)';

    /** Naming a due date, never a box such as "tax still due" or "total amount due". */
    private const DUE = '/\b(?:due\s*dates?|deadlines?|palugit)\b|\bwhen\s+(?:is|was|are|will)\b.*\bdue\b|\b(?:is|be|are)\s+due\b|' . self::DUE_ON . '|\bkailan\b.*\bdue\b/i';

    /** "what's the deadline for filing the 1601-C?" with no one form in mind. */
    private const RULE = 'A 1601-C is due on the 10th of the month after the one it covers (the November 2025 1601-C by December 10, 2025). '
        . 'A year\'s 2316s are due by January 31 of the next year. To see or change one form\'s, ask "when is form #4 due?" or "change the due date of form #4 to November 15, 2026".';

    /** Back to the usual due date. */
    private const RESET = '/\b(?:reset|remove|clear|undo|put\s+back|back\s+to|usual|default|normal|original|ibalik)\b/i';

    /** Changing it: a date to set it to follows. */
    private const CHANGE = '/\b(?:change|move|set|extend|update|push|adjust|make|reschedule|palitan|ilipat|i-?extend|gawing)\b|' . self::DUE_ON . '/i';

    public function __construct(
        private readonly BirFormFinder $finder,
        private readonly BirChatLookup $lookup,
    ) {}

    /** True when the message is about a form's due date. */
    public static function asks(string $message): bool
    {
        return preg_match(self::DUE, $message) === 1 && BirAnswerParser::itemMentioned($message) === null;
    }

    /** The reply, or null for a question that names no form ("what's due this week?"). */
    public function handle(string $message, ?BirFormDraft $current, BirConversationState $state): ?array
    {
        [$date, $hasYear, $rest] = self::dateIn($message);
        $changing = $date !== null || (preg_match(self::CHANGE, $message) === 1 && preg_match(self::RESET, $message) !== 1);
        $resetting = $date === null && preg_match(self::RESET, $message) === 1;

        $ref = $this->finder->reference($rest);
        // "the deadline for filing the 1601-C": words that match no one name no form.
        if ($ref['person'] === BirEmployeeResolver::NOT_FOUND && $ref['number'] === null) {
            $ref['person'] = BirEmployeeResolver::NOT_NAMED;
        }
        if (BirFormFinder::specific($ref)) {
            $draft = $this->lookup->target($ref, $current, $rest, $state);
            if (is_array($draft)) {
                return $draft; // "Which one do you mean?", "No form fits…"
            }
        } elseif ($current !== null) {
            $draft = $current;
        } elseif ($changing || $resetting) {
            return BirChatCommands::result('Which form\'s due date? Give its number, for example "change the due date of form #4 to November 15, 2026", or open the form first.');
        } elseif (preg_match('/\b(?:due\s*dates?|deadlines?)\b/i', $message)) {
            return BirChatCommands::result(self::RULE);
        } else {
            return null;
        }

        $name = 'Form ' . BirFormNames::name($draft) . ' (' . BirFormNames::describe($draft) . ')';
        $usual = $this->usual($draft);
        if ($resetting) {
            if ($draft->due_date === null) {
                return BirChatCommands::result("{$name} already has its usual due date, " . self::day($usual) . '.', stay: true);
            }
            $draft->update(['due_date' => null]);

            return BirChatCommands::result("{$name} is back to its usual due date, " . self::day($usual) . '.', stay: true);
        }
        if (!$changing) {
            return BirChatCommands::result($this->when($draft, $name, $usual), stay: true);
        }

        if ($date === null) {
            return BirChatCommands::result('What date should it be due? For example "change the due date of form ' . BirFormNames::label($draft) . ' to November 15, 2026".', stay: true);
        }
        if ($draft->status === 'finalized') {
            return BirChatCommands::result("{$name} is already filed, so its due date stays " . self::day($draft->dueDate()) . '.', stay: true);
        }
        if (!$hasYear) {
            $date = $date->year(($draft->dueDate() ?? $usual)?->year ?? SystemClock::today()->year);
        }
        $ends = self::periodEnd($draft);
        if ($ends !== null && $date->lte($ends)) {
            return BirChatCommands::result('A form can only be due after its period ends (' . self::day($ends) . '). Please give a later date.', stay: true);
        }
        $was = $draft->dueDate();
        if ($was !== null && $was->isSameDay($date)) {
            return BirChatCommands::result("{$name} is already due on " . self::day($date) . '.', stay: true);
        }
        // The usual date needs nothing stored: it follows the rule.
        $draft->update(['due_date' => $usual !== null && $usual->isSameDay($date) ? null : $date->toDateString()]);

        return BirChatCommands::result("{$name} is now due on " . self::day($date) . ($was !== null ? ' (it was ' . self::day($was) . ')' : '')
            . '. Say "reset the due date of form ' . BirFormNames::label($draft) . '" to go back to the usual one.', stay: true);
    }

    /** "Form #4 (the December 2025 1601-C) is due on January 10, 2026, in 5 days." */
    private function when(BirFormDraft $draft, string $name, ?Carbon $usual): string
    {
        $due = $draft->dueDate();
        if ($due === null) {
            return "{$name} has no due date I can work out.";
        }
        $days = (int) SystemClock::today()->startOfDay()->diffInDays($due->copy()->startOfDay(), false);
        $from = match (true) {
            $draft->status === 'finalized' => ' It is filed.',
            $days === 0 => ' That is today.',
            $days > 0 => ' That is in ' . ($days === 1 ? '1 day' : "{$days} days") . '.',
            default => ' That was ' . ($days === -1 ? '1 day' : -$days . ' days') . ' ago, and it is not filed yet.',
        };
        $set = $draft->due_date !== null && $usual !== null ? ' This date was set by hand; the usual one is ' . self::day($usual) . '.' : '';

        return "{$name} is due on " . self::day($due) . ".{$from}{$set}";
    }

    /** The usual due date, whatever was set by hand. */
    private function usual(BirFormDraft $draft): ?Carbon
    {
        return (clone $draft)->forceFill(['due_date' => null])->dueDate();
    }

    /** The last day of the form's period: a 1601-C's month, a 2316's year. */
    private static function periodEnd(BirFormDraft $draft): ?Carbon
    {
        if (!preg_match('/^(\d{4})(?:-(\d{2}))?$/', (string) $draft->period, $m)) {
            return null;
        }

        return isset($m[2]) ? Carbon::create((int) $m[1], (int) $m[2], 1)->endOfMonth()->startOfDay() : Carbon::create((int) $m[1], 12, 31);
    }

    /**
     * The date the message gives (the last one with a day: "November 15, 2026", "15 Nov",
     * "11/15/2026", "2026-11-15"), and the message without it, so "the December 2025 1601-C"
     * is read as the form. A date with no year comes back in 2000, with false: handle() gives it one.
     *
     * @return array{0: ?Carbon, 1: bool, 2: string}
     */
    private static function dateIn(string $message): array
    {
        $months = implode('|', array_map(fn (string $m) => preg_quote($m, '/'), array_keys(BirFormFinder::MONTHS)));
        $found = [];
        foreach ([
            "/\\b($months)\\.?\\s+(\\d{1,2})(?:st|nd|rd|th)?(?:,?\\s*(\\d{4}))?\\b/i" => fn ($m) => [BirFormFinder::MONTHS[strtolower($m[1][0])], $m[2][0], $m[3][0] ?? ''],
            "/\\b(\\d{1,2})(?:st|nd|rd|th)?\\s+(?:of\\s+)?($months)\\.?(?:,?\\s*(\\d{4}))?\\b/i" => fn ($m) => [BirFormFinder::MONTHS[strtolower($m[2][0])], $m[1][0], $m[3][0] ?? ''],
            '/(?<![\d\/])(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?(?![\d\/])/' => fn ($m) => [$m[1][0], $m[2][0], $m[3][0] ?? ''],
            '/\b(\d{4})-(\d{2})-(\d{2})\b/' => fn ($m) => [$m[2][0], $m[3][0], $m[1][0]],
        ] as $pattern => $parts) {
            if (preg_match_all($pattern, $message, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($all as $m) {
                    [$month, $day, $year] = $parts($m);
                    if (checkdate((int) $month, (int) $day, $year !== '' ? (int) $year : 2000)) {
                        $found[] = [$m[0][1], $m[0][0], Carbon::create($year !== '' ? (int) $year : 2000, (int) $month, (int) $day), $year !== ''];
                    }
                }
            }
        }
        if ($found === []) {
            return [null, false, $message];
        }
        usort($found, fn ($a, $b) => $b[0] <=> $a[0]);
        [$at, $text, $date, $hasYear] = $found[0];
        $rest = preg_replace('/\s+(?:to|on|by|until|sa|ng|into)\s*$/i', '', substr($message, 0, $at)) . ' ' . substr($message, $at + strlen($text));

        return [$date, $hasYear, trim($rest)];
    }

    private static function day(?Carbon $date): string
    {
        return $date?->format('F j, Y') ?? 'not set';
    }
}
