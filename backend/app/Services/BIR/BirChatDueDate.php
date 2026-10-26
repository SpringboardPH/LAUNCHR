<?php

namespace App\Services\BIR;

use App\Helpers\SystemClock;
use App\Models\BirFormDraft;
use Carbon\Carbon;

/**
 * A form's due date in the chat (BirChatCommands sends these here, before box changes):
 *
 * - "change the due date of form #4 to November 15, 2026", "move the deadline of the December
 *   2025 1601-C to 1/12/2026", "extend form #4 by 5 days", with the form open "set the due date
 *   to Jan 15": stored on the form (due_date), so the BIR Forms to-do shows it.
 * - "reset the due date of form #4": back to the usual one (the 10th of the next month for a
 *   1601-C, January 31 of the next year for a 2316; BirFormDraft::dueDate()).
 * - "when is form #4 due?", "is form #4 overdue?", "what's the deadline of the December 2025 1601-C?".
 * - "which forms are overdue?", "what forms are due this month?": the forms not filed yet.
 *
 * A filed form's due date stays as it is. Every change is in the form's history (Auditable).
 */
final class BirChatDueDate
{
    /** "due on January 15", "due by 1/15": a date follows, so not "the tax still due on form #4". */
    private const DUE_ON = '\bdue\s+(?:on|by)\s+(?:the\s+)?(?:\d|[a-z]+\.?\s+\d)';

    /** Naming a due date, never a box such as "tax still due" or "total amount due". */
    private const DUE = '/\b(?:due\s*dates?|deadlines?|palugit|overdue|past\s+due)\b|\bwhen\s+(?:is|was|are|will)\b.*\bdue\b|\bwhat(?:\'s|s|\s+is|\s+are)\s+(?:\w+\s+)?due\b|\bdue\s+(?:this|next)\s+(?:week|month)\b|\b(?:is|be|are)\s+due\b|' . self::DUE_ON . '|\bkailan\b.*\bdue\b|\bwhen\s+(?:should|must|do|does)\b.*\b(?:filed?|submit(?:ted)?)\b/i';

    /** "by 5 days", "add 2 weeks", "three more days": moved from the date it has now. */
    private const SHIFT = '/\b(\d{1,3}|an?|one|two|three|four|five|six|seven|eight|nine|ten|isang|isa|dalawa(?:ng)?|tatlo(?:ng)?|apat(?:\s+na)?|lima(?:ng)?)\s+(?:more\s+|extra\s+|pang\s+)?(days?|weeks?|araw|linggo)\b/i';

    /** The words around a shift: "by", "add", "more", "give", "extend". */
    private const SHIFT_WORDS = '/\b(?:by|add|more|extra|give|extend|push|move|dagdag(?:an)?|i-?extend)\b/i';

    private const NUMBERS = ['a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'isa' => 1, 'dalawa' => 2, 'tatlo' => 3, 'apat' => 4, 'lima' => 5];

    /** A form named in so many words: "late" is then its deadline, not late deductions. */
    private const FORM_WORDS = '/#\s*\d|\bform\b|1601|2316|\bthis\b|\bit\b/i';

    /** The words of a due date, taken out before the form is looked for ("Noel due" is no name). */
    private const DUE_WORDS = '/\b(?:due\s*dates?|deadlines?|palugit|overdue|past\s+due|due)\b/i';

    /** "what's the deadline for filing the 1601-C?" with no one form in mind. */
    private const RULE = 'A 1601-C is due on the 10th of the month after the one it covers (the November 2025 1601-C by December 10, 2025). '
        . 'A year\'s 2316s are due by January 31 of the next year. To see or change one form\'s, ask "when is form #4 due?" or "change the due date of form #4 to November 15, 2026".';

    /** Asking to change or move it: taken out with the due words, so "Extend Teresa" is no name. */
    private const VERBS = '/\b(?:change|move|set|extend|update|push|adjust|make|reschedule|give|add|palitan|ilipat|i-?extend|gawing|dagdagan|reset|more|when|should|must)\b|\b(?:have\s+been|be)\s+(?:filed|submitted)\b/i';

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
        return (preg_match(self::DUE, $message) === 1 || (self::shift($message) !== null && preg_match(self::FORM_WORDS, $message) === 1)
            || (preg_match('/\blate\b(?!\s+(?:deductions?|arrivals?|hours?|minutes?|penalt))/i', $message) === 1 && preg_match(self::FORM_WORDS, $message) === 1))
            && BirAnswerParser::itemMentioned($message) === null;
    }

    /** The days a message moves a due date by ("by 5 days", "two more weeks"), or null. */
    private static function shift(string $message): ?int
    {
        if (!preg_match(self::SHIFT, $message, $s) || !preg_match(self::SHIFT_WORDS, $message)) {
            return null;
        }

        return (int) (self::NUMBERS[preg_replace('/(?:ng|\s+na)$/', '', strtolower($s[1]))] ?? $s[1]) * (preg_match('/^(?:weeks?|linggo)$/i', $s[2]) ? 7 : 1);
    }

    /** The reply to a message asks() is true for. */
    public function handle(string $message, ?BirFormDraft $current, BirConversationState $state): array
    {
        [$date, $hasYear, $rest, $bad] = self::dateIn($message);
        $shift = self::shift($message);
        $changing = $date !== null || $shift !== null || (preg_match(self::CHANGE, $message) === 1 && preg_match(self::RESET, $message) !== 1);
        $resetting = !$changing && preg_match(self::RESET, $message) === 1;
        $numbered = preg_match('/#\s*\d/', $message) === 1;
        if ($bad !== null && $date === null) {
            return BirChatCommands::result("\"{$bad}\" isn't a real date. Please check it.", stay: true);
        }

        // "which forms are overdue?", "what forms are due this month?": every form not filed yet.
        if (!$changing && !$resetting && !$numbered && (preg_match('/\bforms\b|\bmga\s+form|\bupcoming\b|\bdeadlines\b|\bdue\s+dates\b|^\s*list\b/i', $message)
            || (preg_match('/^\s*(?:what|which|anong?|alin|any|anything|meron|may)\b/i', $message) && !preg_match('/\b(?:form|draft|it)\b|\bthis\s+(?:form|one|draft)\b|1601|2316/i', $message)))) {
            return BirChatCommands::result($this->dueList($message), stay: true);
        }
        if ($changing && !$numbered && $current === null && preg_match('/\b(?:all|every|lahat)\b/i', $message)) {
            return BirChatCommands::result('I change one form\'s due date at a time. Give its number, for example "change the due date of form #4 to November 15, 2026".');
        }

        $look = trim(preg_replace([self::DUE_WORDS, self::SHIFT, self::VERBS], ' ', $rest)); // what names the form
        $ref = $this->finder->reference($look);
        // "the deadline for filing the 1601-C": words that match no one name no form.
        if ($ref['person'] === BirEmployeeResolver::NOT_FOUND && $ref['number'] === null) {
            $ref['person'] = BirEmployeeResolver::NOT_NAMED;
        }
        if (BirFormFinder::specific($ref)) {
            $draft = $this->lookup->target($ref, $current, $look, $state);
            if (is_array($draft)) {
                return $draft; // "Which one do you mean?", "No form fits…"
            }
        } elseif ($current !== null) {
            $draft = $current;
        } elseif ($changing || $resetting) {
            return BirChatCommands::result('Which form\'s due date? Give its number, for example "change the due date of form #4 to November 15, 2026", or open the form first.');
        } else {
            return BirChatCommands::result(self::RULE); // "when are the 2316s due?": the rule
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
            return BirChatCommands::result(self::overdue($message, $draft) . $this->when($draft, $name, $usual), stay: true);
        }

        $day = $date === null && preg_match('/\b(?:the\s+)?([1-9]|[12]\d|3[01])(?:st|nd|rd|th)\b/i', $rest, $d) ? (int) $d[1] : null;
        if ($date === null && $shift === null && $day === null) {
            return BirChatCommands::result('What date should it be due? For example "change the due date of form ' . BirFormNames::label($draft) . ' to November 15, 2026".', stay: true);
        }
        if ($draft->status === 'finalized') {
            return BirChatCommands::result("{$name} is already filed, so its due date stays " . self::day($draft->dueDate()) . '.', stay: true);
        }
        $was = $draft->dueDate();
        $from = ($was ?? $usual ?? SystemClock::today())->copy();
        $date = match (true) {
            $date === null && $shift !== null => $from->addDays($shift),
            $date === null => $from->day(min($day, $from->daysInMonth)), // "to the 20th": of the month it is due in now
            !$hasYear => $date->year($from->year),
            default => $date,
        };
        $ends = self::periodEnd($draft);
        if ($ends !== null && $date->lte($ends)) {
            return BirChatCommands::result('A form can only be due after its period ends (' . self::day($ends) . '). Please give a later date.', stay: true);
        }
        if ($was !== null && $was->isSameDay($date)) {
            return BirChatCommands::result("{$name} is already due on " . self::day($date) . '.', stay: true);
        }
        // The usual date needs nothing stored: it follows the rule.
        $draft->update(['due_date' => $usual !== null && $usual->isSameDay($date) ? null : $date->toDateString()]);

        return BirChatCommands::result("{$name} is now due on " . self::day($date) . ($was !== null ? ' (it was ' . self::day($was) . ')' : '')
            . '. Say "reset the due date of form ' . BirFormNames::label($draft) . '" to go back to the usual one.', stay: true);
    }

    /** "Form #4 (the December 2025 1601-C) is due on January 10, 2026. That is in 5 days." */
    private function when(BirFormDraft $draft, string $name, ?Carbon $usual): string
    {
        $due = $draft->dueDate();
        if ($due === null) {
            return "{$name} has no due date I can work out.";
        }
        $from = $draft->status === 'finalized' ? ' It is filed.' : ' That ' . self::fromToday($due) . '.';
        $set = $draft->due_date !== null && $usual !== null ? ' This date was set by hand; the usual one is ' . self::day($usual) . '.' : '';

        return "{$name} is due on " . self::day($due) . ".{$from}{$set}";
    }

    /** "is form #4 overdue?": Yes or No first. */
    private static function overdue(string $message, BirFormDraft $draft): string
    {
        if (!preg_match('/\b(?:overdue|past\s+due|late)\b/i', $message) || ($due = $draft->dueDate()) === null) {
            return '';
        }

        return match (true) {
            $draft->status === 'finalized' => 'No, it is filed. ',
            $due->lt(SystemClock::today()->startOfDay()) => 'Yes. ',
            default => 'No, not yet. ',
        };
    }

    /** "which forms are overdue?", "due this month", "due this week": the forms not filed yet, soonest first. */
    private function dueList(string $message): string
    {
        $today = SystemClock::today()->startOfDay();
        [$which, $keep] = match (true) {
            (bool) preg_match('/\b(?:overdue|past\s+due|late|lampas)\b/i', $message) => ['overdue', fn (Carbon $d) => $d->lt($today)],
            (bool) preg_match('/\bthis\s+week|ngayong\s+linggo/i', $message) => ['due this week', fn (Carbon $d) => $d->between($today, $today->copy()->endOfWeek())],
            (bool) preg_match('/\bnext\s+week/i', $message) => ['due next week', fn (Carbon $d) => $d->between($today->copy()->addWeek()->startOfWeek(), $today->copy()->addWeek()->endOfWeek())],
            (bool) preg_match('/\bthis\s+month|ngayong\s+buwan/i', $message) => ['due this month', fn (Carbon $d) => $d->isSameMonth($today)],
            (bool) preg_match('/\bnext\s+month/i', $message) => ['due next month', fn (Carbon $d) => $d->isSameMonth($today->copy()->addMonthNoOverflow())],
            default => ['not filed yet', fn (Carbon $d) => true],
        };
        $forms = BirFormDraft::with('employee')->where('status', '!=', 'finalized')->get()
            ->map(fn (BirFormDraft $d) => [$d, $d->dueDate()])
            ->filter(fn (array $p) => $p[1] !== null && $keep($p[1]))
            ->sortBy(fn (array $p) => $p[1]->toDateString() . sprintf('%08d', $p[0]->id))->values();
        if ($forms->isEmpty()) {
            return $which === 'not filed yet' ? 'Every saved form is filed.' : "No form that isn't filed yet is {$which}.";
        }
        $lines = $forms->map(fn (array $p) => '- form ' . BirFormNames::name($p[0]) . ': ' . BirFormNames::describe($p[0]) . ', due ' . self::day($p[1])
            . ' (' . self::fromToday($p[1], true) . '), ' . BirFormNames::statusWord($p[0]))->implode("\n");
        $count = $forms->count() === 1 ? '1 form is' : "{$forms->count()} forms are";

        return "{$count} {$which}:\n{$lines}";
    }

    /** "is in 5 days", "is today", "was 3 days ago, and it is not filed yet"; short: "in 5 days", "today", "3 days ago". */
    private static function fromToday(Carbon $due, bool $short = false): string
    {
        $days = (int) SystemClock::today()->startOfDay()->diffInDays($due->copy()->startOfDay(), false);
        $n = abs($days) === 1 ? '1 day' : abs($days) . ' days';

        return match (true) {
            $days === 0 => $short ? 'today' : 'is today',
            $days > 0 => ($short ? '' : 'is ') . "in {$n}",
            default => $short ? "{$n} ago" : "was {$n} ago, and it is not filed yet",
        };
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
     * is read as the form. A date with no year comes back in 2000, with false: handle() gives it
     * one. A date that doesn't exist ("February 30, 2027") comes back as the words typed.
     *
     * @return array{0: ?Carbon, 1: bool, 2: string, 3: ?string}
     */
    private static function dateIn(string $message): array
    {
        $months = implode('|', array_map(fn (string $m) => preg_quote($m, '/'), array_keys(BirFormFinder::MONTHS)));
        [$found, $bad] = [[], null];
        foreach ([
            "/\\b($months)\\.?\\s+(\\d{1,2})(?:st|nd|rd|th)?(?:,?\\s*(\\d{4}))?\\b/i" => fn ($m) => [BirFormFinder::MONTHS[strtolower($m[1][0])], $m[2][0], $m[3][0] ?? '', true],
            "/\\b(\\d{1,2})(?:st|nd|rd|th)?\\s+(?:of\\s+)?($months)\\.?(?:,?\\s*(\\d{4}))?\\b/i" => fn ($m) => [BirFormFinder::MONTHS[strtolower($m[2][0])], $m[1][0], $m[3][0] ?? '', true],
            '/(?<![\d\/])(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?(?![\d\/])/' => fn ($m) => [$m[1][0], $m[2][0], $m[3][0] ?? '', ($m[3][0] ?? '') !== ''],
            '/\b(\d{4})-(\d{2})-(\d{2})\b/' => fn ($m) => [$m[2][0], $m[3][0], $m[1][0], true],
        ] as $pattern => $parts) {
            if (preg_match_all($pattern, $message, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($all as $m) {
                    [$month, $day, $year, $surely] = $parts($m);
                    if (checkdate((int) $month, (int) $day, $year !== '' ? (int) $year : 2000)) {
                        $found[] = [$m[0][1], $m[0][0], Carbon::create($year !== '' ? (int) $year : 2000, (int) $month, (int) $day), $year !== ''];
                    } elseif ($surely && (int) $day >= 1) {
                        $bad = $m[0][0];
                    }
                }
            }
        }
        if ($found === []) {
            return [null, false, $message, $bad];
        }
        usort($found, fn ($a, $b) => $b[0] <=> $a[0]);
        [$at, $text, $date, $hasYear] = $found[0];
        $rest = preg_replace('/\s+(?:to|on|by|until|sa|ng|into)\s*$/i', '', substr($message, 0, $at)) . ' ' . substr($message, $at + strlen($text));

        return [$date, $hasYear, trim($rest), null];
    }

    private static function day(?Carbon $date): string
    {
        return $date?->format('F j, Y') ?? 'not set';
    }
}
