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
    public const MONTHS = [
        'january' => 1, 'jan' => 1, 'enero' => 1, 'february' => 2, 'feb' => 2, 'pebrero' => 2,
        'march' => 3, 'marso' => 3, 'april' => 4, 'apr' => 4, 'abril' => 4, 'may' => 5, 'mayo' => 5,
        'june' => 6, 'jun' => 6, 'hunyo' => 6, 'july' => 7, 'jul' => 7, 'hulyo' => 7,
        'august' => 8, 'aug' => 8, 'agosto' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9, 'setyembre' => 9,
        'october' => 10, 'oct' => 10, 'oktubre' => 10, 'november' => 11, 'nov' => 11, 'nobyembre' => 11,
        'december' => 12, 'dec' => 12, 'disyembre' => 12,
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

    /**
     * A form's number as people write it: "form #12", "draft 12", "form no. 12", and labels
     * with a version, "#64v2", "64v2", "form #64 v2". Groups: 1 "#", 2 the number, 3 the
     * version of a label.
     */
    public const FORM_NUMBER = '/(?:\b(?:form|draft)\s*(?:no\.?|number)?\s*(#)?\s*|(?<![\w#])(?=#?\d{1,6}\s*v\d)(#)?)(\d{1,6})(?:\s*v(\d{1,2}))?\b(?!-?c\b)/i';

    public function __construct(private readonly BirEmployeeResolver $employees) {}

    /**
     * The form number a text gives ("form #12", "draft 12"), the last one if several, or null.
     * "form 2316" and "form 1601-C" name a form type, not a number.
     */
    public static function formNumber(string $text): ?int
    {
        return self::formLabel($text)['number'];
    }

    /**
     * The form number a text gives and the version its label names ("#64v2": 64 and 2), the
     * last one if several. "form 2316" and "form 1601-C" name a form type, not a number.
     *
     * @return array{number: ?int, version: ?int}
     */
    public static function formLabel(string $text): array
    {
        preg_match_all(self::FORM_NUMBER, $text, $all, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $found = array_filter($all, [self::class, 'isFormNumber']);
        $last = $found === [] ? null : end($found);

        return ['number' => $last === null ? null : (int) $last[3], 'version' => isset($last[4]) ? (int) $last[4] : null];
    }

    /** The text with any form number taken out, so "draft #12" is never read as item 12 or the year. */
    public static function withoutFormNumber(string $text): string
    {
        return preg_replace_callback(self::FORM_NUMBER, fn (array $m) => self::isFormNumber($m) ? ' ' : $m[0], $text, -1, $count, PREG_UNMATCHED_AS_NULL);
    }

    /** "form #2316" is form 2316; "form 2316" without the "#" is the form type. */
    private static function isFormNumber(array $m): bool
    {
        return ($m[1] ?? $m[2]) === '#' || isset($m[4]) || !in_array($m[3], ['2316', '1601'], true);
    }

    /**
     * What a message says about which form. named is true when it gives anything beyond a
     * box. A named person settles the form as the 2316, the only form with one.
     *
     * @return array{number: ?int, form_type: ?string, year: ?int, month: ?int, employee: ?Employee, person: string, candidates: array, query: ?string, status: ?string, version: ?int, latest: bool, named: bool}
     */
    public function reference(string $text): array
    {
        $label = self::formLabel($text);
        $number = $label['number'];
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
            default => $label['version'],
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
            // With no period given, "the latest 1601-C" is the newest month's (a person's newest year).
            if ($ref['year'] === null && $ref['month'] === null && $forms->isNotEmpty()) {
                $newest = $forms->first()->period;
                $forms = $forms->where('period', $newest)->values();
            }
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

    /**
     * Every version of one form, oldest first: the forms that share this one's original.
     * (Two separate drafts for the same period are two forms, each with its own versions.)
     *
     * @return Collection<int, BirFormDraft>
     */
    public function chain(BirFormDraft $draft): Collection
    {
        $root = BirFormNames::rootId($draft);

        return $this->versions($draft)->filter(fn (BirFormDraft $d) => BirFormNames::rootId($d) === $root)->values();
    }

    /** A correction of this filed form that isn't filed yet, the newest one, or null. */
    public function openCorrection(BirFormDraft $filed): ?BirFormDraft
    {
        return $this->chain($filed)
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
            // "#64v2" or "form 64 version 2": that version of form 64.
            if ($ref['version'] !== null && $ref['version'] !== $draft->version) {
                $chain = $this->chain($draft);
                $draft = $chain->firstWhere('version', $ref['version']);
                if ($draft === null) {
                    return self::ask('Form ' . BirFormNames::label($chain->first()) . " has no version {$ref['version']}. "
                        . BirFormNames::listing($chain, 'Its versions are:'), true, needs: 'form');
                }
            }
            if (!$this->agrees($ref, $draft, withStatus: true)) {
                return self::ask('Form ' . BirFormNames::label($draft) . ' is ' . BirFormNames::describe($draft) . ' (' . BirFormNames::versionNote($draft) . "), which doesn't match the rest of what you said. Which form do you mean?", true, needs: 'form');
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
                default => ' ' . BirFormNames::STATUS_WORDS[$ref['status']],
            };
            $version = $ref['version'] !== null ? " (version {$ref['version']})" : '';

            return self::ask("There is no{$status} " . substr(BirFormNames::wanted($ref), 2) . "{$version} saved. Ask for it to prepare one, or check the details.", false);
        }

        return $forms->count() === 1 && $lead === null
            ? $forms->first()
            : ($forms->count() === 1 ? $forms->first() : self::ask(BirFormNames::listing($forms, $lead ?? BirFormNames::SEVERAL), true, needs: 'form'));
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
