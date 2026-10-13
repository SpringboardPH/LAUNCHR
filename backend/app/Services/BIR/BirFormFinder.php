<?php

namespace App\Services\BIR;

use App\Helpers\SystemClock;
use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Support\Collection;

/**
 * Finds the saved form a message is about, whatever its status: "item 6 on Maria Santos's
 * 2316 for 2026", "the filed September 2026 1601-C", "form #12", "amendment 1 of the March
 * 2026 1601-C", "2316 for 920-919-920-00001 2024".
 *
 * Read in PHP, never by the model, so it works with the model switched off and can't land
 * on a form nobody named. A form is identified by its number (the draft id, "form #12");
 * the other details narrow the saved forms down: form type, year and month, the employee
 * (BirEmployeeResolver::mentionedIn()), status ("filed", "pending", "draft", "returned"),
 * version ("version 2", "amendment 1", "the original") and "latest". When more than one
 * saved form still fits, they are listed by number and the user picks one: two drafts for
 * the same employee and year are two forms, and guessing between them once read the wrong
 * one.
 */
class BirFormFinder
{
    /** Month names and short forms, English and Filipino. "may" counts only before a year. */
    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'enero' => 1, 'february' => 2, 'feb' => 2, 'pebrero' => 2,
        'march' => 3, 'marso' => 3, 'april' => 4, 'apr' => 4, 'abril' => 4, 'may' => 5, 'mayo' => 5,
        'june' => 6, 'jun' => 6, 'hunyo' => 6, 'july' => 7, 'jul' => 7, 'hulyo' => 7,
        'august' => 8, 'aug' => 8, 'agosto' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9, 'setyembre' => 9,
        'october' => 10, 'oct' => 10, 'oktubre' => 10, 'november' => 11, 'nov' => 11, 'nobyembre' => 11,
        'december' => 12, 'dec' => 12, 'disyembre' => 12,
    ];

    /** How each status is said to the user. */
    public const STATUS_WORDS = [
        'draft' => 'a draft',
        'pending' => 'waiting for review',
        'approved' => 'approved',
        'finalized' => 'filed',
    ];

    /**
     * How people say each status, checked in this order. "returned" is a draft the reviewer
     * sent back (it has a rejection reason), so it is only those drafts.
     */
    private const STATUS_SAID = [
        'returned' => '/\b(returned|ibinalik|sent back)\b/i',
        'pending' => '/\b(pending|submitted|waiting for (?:review|approval)|for (?:review|approval)|under review)\b/i',
        'finalized' => '/\b(filed|finali[sz]ed|nai-?file|naka-?file)\b/i',
        'approved' => '/\b(approved|aprubado)\b/i',
        'draft' => '/\b(drafts?)\b(?!\s*(?:no\.?|number)?\s*#?\s*\d)/i',
    ];

    /** "form #12", "draft 12", "form no. 12", "form number 12": a form's number. */
    public const FORM_NUMBER = '/\b(?:form|draft)\s*(?:no\.?|number)?\s*(#)?\s*(\d{1,6})\b(?!-?c\b)/i';

    /** At most this many forms are listed when several fit. */
    private const LISTED = 8;

    public function __construct(private readonly BirEmployeeResolver $employees) {}

    /**
     * The form number a text gives ("form #12", "draft 12"), the last one if several, or null.
     * "form 2316" and "form 1601-C" name a form type, not a number.
     */
    public static function formNumber(string $text): ?int
    {
        preg_match_all(self::FORM_NUMBER, $text, $all, PREG_SET_ORDER);
        $numbers = array_filter($all, fn (array $m) => $m[1] === '#' || !in_array($m[2], ['2316', '1601'], true));

        return $numbers === [] ? null : (int) end($numbers)[2];
    }

    /** The text with any form number taken out, so "draft #12" is never read as item 12 or the year. */
    public static function withoutFormNumber(string $text): string
    {
        return preg_replace_callback(self::FORM_NUMBER, fn (array $m) => $m[1] === '#' || !in_array($m[2], ['2316', '1601'], true) ? ' ' : $m[0], $text);
    }

    /**
     * What a message says about which form. named is true when it gives anything beyond a
     * box. A named person settles the form as the 2316, the only form with one.
     *
     * @return array{number: ?int, form_type: ?string, year: ?int, month: ?int, employee: ?Employee, person: string, candidates: array, query: ?string, status: ?string, version: ?int, latest: bool, named: bool}
     */
    public function reference(string $text): array
    {
        $number = self::formNumber($text);
        $text = self::withoutFormNumber($text);
        $person = $this->employees->mentionedIn($text);
        $rest = preg_replace(BirEmployeeResolver::TIN_PATTERN, ' ', $text);
        $rest = preg_replace('/(?:\bitem|\bbox|#)\s*\d{1,2}[a-z]?\b/i', ' ', $rest);

        $formType = match (true) {
            (bool) preg_match('/1601/', $rest) => Form1601CSchema::FORM_TYPE,
            (bool) preg_match('/2316/', $rest) => Form2316Schema::FORM_TYPE,
            $person['status'] !== BirEmployeeResolver::NOT_NAMED => Form2316Schema::FORM_TYPE,
            default => null,
        };
        $rest = preg_replace('/1601-?c?|2316/i', ' ', $rest);

        $version = match (true) {
            (bool) preg_match('/\b(?:version|v)\s*(\d{1,2})\b/i', $rest, $m) => (int) $m[1],
            (bool) preg_match('/\bamendment\s*(?:no\.?\s*|#\s*)?(\d{1,2})\b/i', $rest, $m) => (int) $m[1] + 1,
            (bool) preg_match('/\b(original|first version)\b/i', $rest) => 1,
            default => null,
        };
        $rest = preg_replace('/\b(?:version|v|amendment)\s*(?:no\.?\s*|#\s*)?\d{1,2}\b/i', ' ', $rest);
        $status = null;
        foreach (self::STATUS_SAID as $said => $pattern) {
            if (preg_match($pattern, $rest)) {
                $status = $said;
                break;
            }
        }
        [$year, $month] = self::period($rest);
        $latest = preg_match('/\b(latest|newest|most recent)\b/i', $rest) === 1;

        return [
            'number' => $number,
            'form_type' => $formType,
            'year' => $year,
            'month' => $formType === Form2316Schema::FORM_TYPE ? null : $month,
            'employee' => $person['employee'],
            'person' => $person['status'],
            'candidates' => $person['candidates'],
            'query' => $person['query'],
            'status' => $status,
            'version' => $version,
            'latest' => $latest,
            'named' => $number !== null || $formType !== null || $year !== null || $month !== null
                || $status !== null || $version !== null || $latest,
        ];
    }

    /** True when the reference picks out particular forms, not just a form type. */
    public static function specific(array $ref): bool
    {
        return $ref['number'] !== null || $ref['year'] !== null || $ref['month'] !== null
            || $ref['person'] !== BirEmployeeResolver::NOT_NAMED || $ref['status'] !== null || $ref['version'] !== null || $ref['latest'];
    }

    /**
     * Every saved form the reference fits, newest period first, then highest version.
     * Two drafts for the same employee and year are both here: they are two forms.
     *
     * @return Collection<int, BirFormDraft>
     */
    public function forms(array $ref): Collection
    {
        $query = BirFormDraft::with('employee')->where('form_type', $ref['form_type']);

        if ($ref['form_type'] === Form2316Schema::FORM_TYPE) {
            if ($ref['employee'] !== null) {
                $query->where('employee_id', $ref['employee']->id);
            }
            if ($ref['year'] !== null) {
                $query->where('period', (string) $ref['year']);
            }
        } else {
            match (true) {
                $ref['year'] !== null && $ref['month'] !== null => $query->where('period', sprintf('%04d-%02d', $ref['year'], $ref['month'])),
                $ref['year'] !== null => $query->where('period', 'like', $ref['year'] . '-%'),
                $ref['month'] !== null => $query->where('period', 'like', sprintf('%%-%02d', $ref['month'])),
                default => null,
            };
        }
        if ($ref['status'] === 'returned') {
            $query->where('status', 'draft')->whereNotNull('rejection_reason');
        } elseif ($ref['status'] !== null) {
            $query->where('status', $ref['status']);
        }
        if ($ref['version'] !== null) {
            $query->where('version', $ref['version']);
        }

        $forms = $query->orderByDesc('period')->orderByDesc('version')->orderByDesc('id')->get();
        if ($ref['latest']) {
            $highest = $forms->groupBy(fn (BirFormDraft $d) => "{$d->period}|{$d->employee_id}")->map(fn (Collection $g) => $g->max('version'));
            $forms = $forms->filter(fn (BirFormDraft $d) => $d->version === $highest["{$d->period}|{$d->employee_id}"])->values();
        }

        return $forms;
    }

    /**
     * Every version of the form this draft is a version of, oldest first.
     *
     * @return Collection<int, BirFormDraft>
     */
    public function versions(BirFormDraft $draft): Collection
    {
        return BirFormDraft::with('employee')
            ->where('form_type', $draft->form_type)
            ->where('period', $draft->period)
            ->where('employee_id', $draft->employee_id)
            ->orderBy('version')
            ->get();
    }

    /** A correction of this filed form that isn't filed yet, the newest one, or null. */
    public function openCorrection(BirFormDraft $filed): ?BirFormDraft
    {
        return $this->versions($filed)
            ->filter(fn (BirFormDraft $d) => $d->version > $filed->version && $d->status !== 'finalized')
            ->last();
    }

    /**
     * The one saved form a reference names, or a reply saying what is missing (ask: true
     * when a short answer would settle it; needs: what that answer gives, "form" for a form
     * number, "detail" for a year or a person). The open form counts when nothing in the
     * reference contradicts it: "what is item 6 for Maria?" on Maria Santos's 2316 means
     * that one, and so does the rest it leaves out.
     *
     * $lead, when given, heads the list of forms that fit ("More than one form fits…").
     *
     * @return BirFormDraft|array{reply: string, candidates: array, ask: bool, needs: string}
     */
    public function resolve(array $ref, ?BirFormDraft $current, ?string $lead = null): BirFormDraft|array
    {
        if ($ref['number'] !== null) {
            $draft = BirFormDraft::with('employee')->find($ref['number']);
            if ($draft === null) {
                return self::ask("There is no form #{$ref['number']}. Which form do you mean?", true, needs: 'form');
            }
            if (!$this->agrees($ref, $draft, withStatus: true)) {
                return self::ask("Form #{$draft->id} is " . self::describe($draft) . ' (' . self::versionNote($draft) . "), which doesn't match the rest of what you said. Which form do you mean?", true, needs: 'form');
            }

            return $draft;
        }

        if ($current !== null && $this->agrees($ref, $current)) {
            if ($this->agrees($ref, $current, withStatus: true) && !$ref['latest']) {
                return $current;
            }
            $ref['form_type'] ??= $current->form_type;
            $ref['year'] ??= (int) substr($current->period, 0, 4);
            if ($current->form_type === Form1601CSchema::FORM_TYPE) {
                $ref['month'] ??= (int) substr($current->period, 5, 2);
            }
            if ($ref['employee'] === null && $current->employee !== null) {
                [$ref['employee'], $ref['person']] = [$current->employee, BirEmployeeResolver::MATCHED];
            }
        }

        if ($ref['form_type'] === null) {
            return self::ask('Which form do you mean: the 1601-C or the 2316? Give its number, like "form #12", or describe it, like "the September 2026 1601-C".', true, needs: 'form');
        }
        if ($ref['person'] === BirEmployeeResolver::AMBIGUOUS) {
            $names = implode(', ', array_map(fn (array $c) => "{$c['name']} ({$c['employee_id']})", $ref['candidates']));

            return self::ask("More than one person matches that: {$names}. Which one did you mean?", true, $ref['candidates']);
        }
        if ($ref['person'] === BirEmployeeResolver::NOT_FOUND) {
            return self::ask(preg_match(BirEmployeeResolver::TIN_PATTERN, (string) $ref['query'])
                ? "No employee has the TIN {$ref['query']}. Please check the number."
                : "I could not find anyone matching \"{$ref['query']}\". Could you check the spelling, or give their employee ID or TIN?", false);
        }
        if ($ref['form_type'] === Form2316Schema::FORM_TYPE && $ref['employee'] === null) {
            return self::ask('Whose 2316? Give the employee\'s name, ID or TIN, or the form\'s number, like "form #12".', true);
        }

        $forms = $this->forms($ref);
        if ($forms->isEmpty()) {
            $status = match ($ref['status']) {
                null => '',
                'draft' => ' draft',
                'returned' => ' returned',
                'pending' => ' pending',
                default => ' ' . self::STATUS_WORDS[$ref['status']],
            };
            $version = $ref['version'] !== null ? " (version {$ref['version']})" : '';

            return self::ask("There is no{$status} " . substr(self::wanted($ref), 2) . "{$version} saved. Ask for it to prepare one, or check the details.", false);
        }

        return $forms->count() === 1 && $lead === null
            ? $forms->first()
            : ($forms->count() === 1 ? $forms->first() : self::ask(self::listing($forms, $lead ?? self::SEVERAL), true, needs: 'form'));
    }

    private const SEVERAL = 'More than one form fits. Which one do you mean?';

    /** "More than one form fits. Which one? - form #7: …" */
    public static function listing(Collection $forms, string $lead = self::SEVERAL): string
    {
        $lines = $forms->take(self::LISTED)->map(fn (BirFormDraft $d) => '- ' . self::line($d))->implode("\n");
        $more = $forms->count() > self::LISTED
            ? "\n…and " . ($forms->count() - self::LISTED) . ' more. Add a detail such as the period, the person, or "filed" or "draft".'
            : '';

        return "{$lead}\n{$lines}{$more}\nSay its number, for example \"form #{$forms->first()->id}\".";
    }

    /** "form #7: Maria Santos's 2316 for 2026, version 1, filed". */
    public static function line(BirFormDraft $draft): string
    {
        return "form #{$draft->id}: " . self::describe($draft) . ", version {$draft->version}, " . self::statusWord($draft);
    }

    /** True when nothing the reference names contradicts this form (status and version too, if asked). */
    private function agrees(array $ref, BirFormDraft $draft, bool $withStatus = false): bool
    {
        $month = $draft->form_type === Form1601CSchema::FORM_TYPE ? (int) substr($draft->period, 5, 2) : null;
        $sameEmployee = match ($ref['person']) {
            BirEmployeeResolver::NOT_NAMED => true,
            BirEmployeeResolver::MATCHED => $ref['employee']?->id === $draft->employee_id,
            BirEmployeeResolver::AMBIGUOUS => in_array($draft->employee_id, array_column($ref['candidates'], 'id'), true),
            default => false,
        };

        return ($ref['form_type'] === null || $ref['form_type'] === $draft->form_type)
            && ($ref['year'] === null || $ref['year'] === (int) substr($draft->period, 0, 4))
            && ($ref['month'] === null || $ref['month'] === $month)
            && $sameEmployee
            && (!$withStatus || (($ref['status'] === null || $ref['status'] === $draft->status
                    || ($ref['status'] === 'returned' && $draft->status === 'draft' && $draft->rejection_reason !== null))
                && ($ref['version'] === null || $ref['version'] === $draft->version)));
    }

    /** @return array{reply: string, candidates: array, ask: bool, needs: string} */
    private static function ask(string $reply, bool $ask, array $candidates = [], string $needs = 'detail'): array
    {
        return compact('reply', 'candidates', 'ask', 'needs');
    }

    /**
     * How many times a form may be amended after it is filed, or null for no limit. The
     * limit is the workflow's (BirFormDraft::AMENDMENT_LIMITS, Dev B), so the chat says
     * exactly what "Start a correction" will allow; before that rule exists, there is none.
     */
    public static function amendmentLimit(string $formType): ?int
    {
        $limits = defined(BirFormDraft::class . '::AMENDMENT_LIMITS') ? BirFormDraft::AMENDMENT_LIMITS : [];

        return $limits[$formType] ?? null;
    }

    /** "amendment 2" on a 1601-C, "correction version 2" on a 2316. */
    public static function correctionName(BirFormDraft $draft): string
    {
        return self::isAmendment($draft) ? 'amendment ' . ($draft->version - 1) : "correction version {$draft->version}";
    }

    /** A corrected 1601-C is an amendment, counted against its month's limit. */
    public static function isAmendment(BirFormDraft $draft): bool
    {
        return $draft->form_type === Form1601CSchema::FORM_TYPE;
    }

    /** "Maria Santos's 2316 for 2026" / "the September 2026 1601-C". */
    public static function describe(BirFormDraft $draft): string
    {
        if ($draft->form_type === Form2316Schema::FORM_TYPE) {
            $name = trim(($draft->employee?->first_name ?? '') . ' ' . ($draft->employee?->last_name ?? ''));

            return ($name !== '' ? self::possessive($name) : 'the') . " 2316 for {$draft->period}";
        }

        return 'the ' . self::monthName($draft->period) . ' 1601-C';
    }

    /** "form #12, version 1, filed". */
    public static function versionNote(BirFormDraft $draft): string
    {
        return "form #{$draft->id}, version {$draft->version}, " . self::statusWord($draft);
    }

    /** "filed", "waiting for review", "a draft", or "returned for changes" for a draft the reviewer sent back. */
    public static function statusWord(BirFormDraft $draft): string
    {
        return $draft->status === 'draft' && $draft->rejection_reason !== null
            ? 'returned for changes'
            : (self::STATUS_WORDS[$draft->status] ?? $draft->status);
    }

    /** "2026-09" → "September 2026". */
    public static function monthName(string $period): string
    {
        return preg_match('/^(\d{4})-(\d{2})$/', $period, $m) ? date('F', mktime(0, 0, 0, (int) $m[2], 1)) . " {$m[1]}" : $period;
    }

    /** What a reference asks for, in words: "a 2316 for Maria Santos for 2026", "a 1601-C for September 2026". */
    public static function wanted(array $ref): string
    {
        $who = $ref['employee'] !== null ? ' for ' . trim($ref['employee']->first_name . ' ' . $ref['employee']->last_name) : '';
        $when = match (true) {
            $ref['year'] !== null && $ref['month'] !== null => ' for ' . self::monthName(sprintf('%04d-%02d', $ref['year'], $ref['month'])),
            $ref['month'] !== null => ' for ' . date('F', mktime(0, 0, 0, $ref['month'], 1)),
            $ref['year'] !== null => " for {$ref['year']}",
            default => '',
        };

        return "a {$ref['form_type']}{$who}{$when}";
    }

    private static function possessive(string $name): string
    {
        return str_ends_with(mb_strtolower($name), 's') ? "{$name}'" : "{$name}'s";
    }

    /**
     * The year and month a text gives: "2026", "September 2026", "09/2026", "2026-09",
     * "this year", "last month", "ngayong taon". Null for whatever it doesn't give.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function period(string $text): array
    {
        $lower = mb_strtolower($text);
        $year = null;
        $month = null;
        $today = SystemClock::today();

        if (preg_match('/(?<![\d-])(\d{1,2})\/(\d{4})(?![\d-])/', $lower, $m)) {
            [$month, $year] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/(?<![\d-])(\d{4})-(\d{1,2})(?![\d-])/', $lower, $m)) {
            [$year, $month] = [(int) $m[1], (int) $m[2]];
        }
        if ($year === null && preg_match('/(?<![\d-])((?:19|20)\d{2})(?![\d-])/', $lower, $m)) {
            $year = (int) $m[1];
        }
        if ($month === null) {
            foreach (preg_split('/[^a-z]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                if (isset(self::MONTHS[$word]) && ($word !== 'may' || preg_match('/\bmay\s+(?:19|20)\d{2}\b/', $lower))) {
                    $month = self::MONTHS[$word];
                    break;
                }
            }
        }

        if (preg_match('/\b(this|current) year\b|\bngayong taon\b/', $lower)) {
            $year = $today->year;
        } elseif (preg_match('/\b(last|previous) year\b|\bnakaraang taon\b/', $lower)) {
            $year = $today->year - 1;
        }
        if (preg_match('/\b(this|current) month\b|\bngayong buwan\b/', $lower)) {
            [$year, $month] = [$today->year, $today->month];
        } elseif (preg_match('/\b(last|previous) month\b|\bnakaraang buwan\b/', $lower)) {
            $last = $today->copy()->startOfMonth()->subMonth();
            [$year, $month] = [$last->year, $last->month];
        }
        if ($month !== null && ($month < 1 || $month > 12)) {
            $month = null;
        }

        return [$year, $month];
    }
}
