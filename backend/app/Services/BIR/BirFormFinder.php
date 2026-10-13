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
 * 2316 for 2026", "the September 2026 1601-C", "2316 for 920-919-920-00001 2024".
 *
 * Read in PHP, never by the model, so it works with the model switched off and can't land
 * on a form nobody named. The form type, year and month are read off the words; the
 * employee is BirEmployeeResolver::mentionedIn()'s job. A form is a form type, period and
 * employee; its versions (v1, then each correction or amendment) share those three, and
 * the latest version is the one a change goes to.
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

    public function __construct(private readonly BirEmployeeResolver $employees) {}

    /**
     * What a message says about which form. named is true when it gives anything beyond a
     * box: a form type, a period or a person. A named person settles the form as the 2316,
     * the only form with one.
     *
     * @return array{form_type: ?string, year: ?int, month: ?int, employee: ?Employee, person: string, candidates: array, query: ?string, named: bool}
     */
    public function reference(string $text): array
    {
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
        [$year, $month] = self::period($rest);

        return [
            'form_type' => $formType,
            'year' => $year,
            'month' => $formType === Form2316Schema::FORM_TYPE ? null : $month,
            'employee' => $person['employee'],
            'person' => $person['status'],
            'candidates' => $person['candidates'],
            'query' => $person['query'],
            'named' => $formType !== null || $year !== null || $month !== null,
        ];
    }

    /** True when the reference picks out one form's period or person, not just a form type. */
    public static function specific(array $ref): bool
    {
        return $ref['year'] !== null || $ref['month'] !== null || $ref['person'] !== BirEmployeeResolver::NOT_NAMED;
    }

    /**
     * The latest version of every saved form the reference fits, newest period first.
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

        return $query->get()
            ->groupBy(fn (BirFormDraft $d) => "{$d->period}|{$d->employee_id}")
            ->map(fn (Collection $versions) => $versions->sortByDesc('version')->first())
            ->sortByDesc(fn (BirFormDraft $d) => $d->period . str_pad((string) $d->id, 10, '0', STR_PAD_LEFT))
            ->values();
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

    /** The newest version of the form this draft belongs to: where a change goes. */
    public function latest(BirFormDraft $draft): BirFormDraft
    {
        return $this->versions($draft)->last() ?? $draft;
    }

    /**
     * The one saved form a reference names, its latest version, or a reply saying what is
     * missing (ask: true when a short answer, a year or a name, would settle it). Whatever
     * the reference leaves out is taken from the open form when the rest agrees with it, so
     * "change item 6 for Maria" on Maria Santos's 2316 means that one.
     *
     * @return BirFormDraft|array{reply: string, candidates: array, ask: bool}
     */
    public function resolve(array $ref, ?BirFormDraft $current): BirFormDraft|array
    {
        if ($current !== null && $this->agrees($ref, $current)) {
            $ref['form_type'] ??= $current->form_type;
            $ref['year'] ??= (int) substr($current->period, 0, 4);
            if ($current->form_type === '1601-C') {
                $ref['month'] ??= (int) substr($current->period, 5, 2);
            }
            if ($ref['employee'] === null && $current->employee !== null) {
                [$ref['employee'], $ref['person']] = [$current->employee, BirEmployeeResolver::MATCHED];
            }
        }

        if ($ref['form_type'] === null) {
            return self::ask('Which form do you mean: the 1601-C or the 2316? For example: "item 14 on the September 2026 1601-C".', false);
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
        if ($ref['form_type'] === '2316' && $ref['employee'] === null) {
            return self::ask('Whose 2316? Give the employee\'s name, ID or TIN.', true);
        }

        $forms = $this->forms($ref);
        if ($forms->isEmpty()) {
            return self::ask('There is no ' . substr(self::wanted($ref), 2) . ' saved yet. Ask for it to prepare one.', false);
        }
        if ($forms->count() > 1) {
            $periods = $forms->map(fn (BirFormDraft $d) => $d->form_type === '2316' ? $d->period : self::monthName($d->period))->unique()->values()->all();
            $last = array_pop($periods);
            $list = $periods === [] ? $last : implode(', ', $periods) . " and {$last}";
            $which = $ref['form_type'] === '2316' ? 'Which year?' : ($ref['year'] === null && $ref['month'] !== null ? 'Which year?' : 'Which month?');

            return self::ask("There are {$ref['form_type']}s" . ($ref['employee'] !== null ? ' for ' . trim($ref['employee']->first_name . ' ' . $ref['employee']->last_name) : '') . " for {$list}. {$which}", true);
        }

        return $forms->first();
    }

    /** True when nothing the reference names contradicts the open form. */
    private function agrees(array $ref, BirFormDraft $current): bool
    {
        $month = $current->form_type === '1601-C' ? (int) substr($current->period, 5, 2) : null;
        $sameEmployee = match ($ref['person']) {
            BirEmployeeResolver::NOT_NAMED => true,
            BirEmployeeResolver::MATCHED => $ref['employee']?->id === $current->employee_id,
            BirEmployeeResolver::AMBIGUOUS => in_array($current->employee_id, array_column($ref['candidates'], 'id'), true),
            default => false,
        };

        return ($ref['form_type'] === null || $ref['form_type'] === $current->form_type)
            && ($ref['year'] === null || $ref['year'] === (int) substr($current->period, 0, 4))
            && ($ref['month'] === null || $ref['month'] === $month)
            && $sameEmployee;
    }

    /** @return array{reply: string, candidates: array, ask: bool} */
    private static function ask(string $reply, bool $ask, array $candidates = []): array
    {
        return compact('reply', 'candidates', 'ask');
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

    /** "draft #12, version 1, filed". */
    public static function versionNote(BirFormDraft $draft): string
    {
        return "#{$draft->id}, version {$draft->version}, " . (self::STATUS_WORDS[$draft->status] ?? $draft->status);
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
