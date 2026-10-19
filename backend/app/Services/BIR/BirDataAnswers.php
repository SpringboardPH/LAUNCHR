<?php

namespace App\Services\BIR;

use App\Helpers\SystemClock;
use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Services\BIR\Schemas\FieldFormat;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Answers a question BirDataIntent read, from the records: the employees table, the payroll the
 * forms are built from (BirAggregationService, so a group here is the group the forms use), the
 * saved forms, and the company's settings. Every reply is worded here; nothing is changed.
 *
 * A question about groups or amounts goes by one period: the one it names, else the open form's,
 * else the latest month with counted payroll, and the reply says which. A month uses the 1601-C's
 * test (the month's taxable pay times 12 against P250,000), a year the 2316's (the year's).
 */
class BirDataAnswers
{
    /** At most this many employees or forms are listed in one reply. */
    private const LISTED = 15;

    public const CAN_ANSWER = 'I can answer questions about your employees (who is taxable, a minimum wage earner or exempt at ₱250,000 a year or less, their TIN or hire date), payroll totals such as compensation and tax withheld, and the saved BIR forms, for example "who are the taxable employees?", "how much tax was withheld in December 2025?" or "which forms are still drafts?".';

    public const CANT = "I can't answer that from the records I can read. " . self::CAN_ANSWER;

    private const NO_PAYROLL = 'There is no finalized or paid payroll for that yet, so I have nothing to go by.';

    /** Each group, said of one employee and of several. */
    public const GROUPS = [
        'taxable' => ['taxable', 'taxable'],
        'mwe' => ['a minimum wage earner', 'minimum wage earners'],
        'exempt_250k' => ['exempt (₱250,000 a year or less)', 'exempt (₱250,000 a year or less)'],
        'not_taxable' => ['not taxable (a minimum wage earner or ₱250,000 a year or less)', 'not taxable (minimum wage earners and those at ₱250,000 a year or less)'],
    ];

    private const AMOUNTS = [
        'compensation' => 'compensation',
        'tax_withheld' => 'tax withheld',
        'taxable_compensation' => 'taxable compensation',
        'non_taxable_compensation' => 'non-taxable compensation',
        'contributions' => 'SSS, PhilHealth and Pag-IBIG (employee share)',
        'thirteenth_month' => '13th month and other benefits',
    ];

    /** Company detail => setting (BirSettingsService::values('1601-C') keys). */
    private const COMPANY = ['name' => 'company_name', 'tin' => 'company_tin', 'rdo_code' => 'rdo_code', 'address' => 'company_address', 'zip' => 'company_zip'];

    public function __construct(private readonly BirEmployeeResolver $employees) {}

    /**
     * @param  array<string, mixed>  $q  from BirDataIntent::read()
     * @return array{reply: string, candidates: array, suggest: ?string}
     */
    public function answer(array $q, ?BirFormDraft $draft = null): array
    {
        // On a 2316, "is he taxable?" is about its employee.
        if ($q['employee'] === null && $q['topic'] === 'employee' && $draft?->form_type === Form2316Schema::FORM_TYPE && $draft->employee !== null) {
            $q['employee'] = $draft->employee->employee_id;
        }
        $person = null;
        if ($q['employee'] !== null && in_array($q['topic'], ['employee', 'employees', 'amounts', 'forms'], true)) {
            $found = $this->employees->resolve($q['employee']);
            if ($found['status'] !== BirEmployeeResolver::MATCHED || $found['close']) {
                return $this->who($q['employee'], $found);
            }
            $person = Employee::withTrashed()->find($found['employee']->id); // the whole record: the resolver reads only names
        }

        $reply = match (true) {
            $q['topic'] === 'company' => $this->company($q),
            $q['topic'] === 'forms' => BirDataForms::saved($q, $person),
            $q['topic'] === 'missing_forms' => BirDataForms::missing($q, $draft, $this->latestPaid()?->year) ?? self::NO_PAYROLL,
            $q['topic'] === 'amounts' || in_array($q['measure'], ['highest', 'lowest'], true) || ($q['amount'] !== null && ($q['measure'] === 'total' || $person !== null))
                => $this->amounts($q, $person, $draft),
            $person !== null && in_array($q['detail'], ['tin', 'hire_date', 'position', 'department', 'status', 'employee_id'], true) => $this->detail($person, $q['detail']),
            $person !== null => $this->group($q, $person, $draft),
            $q['topic'] === 'employee' && ($q['detail'] !== null || $q['group'] !== null) => 'Which employee do you mean? Give their name or employee ID.',
            $q['topic'] === 'employees' => $this->employeesWho($q, $draft),
            default => self::CANT,
        };

        return ['reply' => $reply, 'candidates' => [], 'suggest' => null];
    }

    /**
     * Answers the question and remembers it: for a follow-up, and for "yes" to a "Did you mean
     * …?" it asked, or the name it asked for.
     *
     * @return array{reply: string, candidates: array, suggest: ?string}
     */
    public function respond(array $q, BirConversationState $state, ?BirFormDraft $draft = null): array
    {
        $answer = $this->answer($q, $draft);
        $state->setQuestion(['q' => $q, 'suggest' => $answer['suggest'], 'waiting' => $answer['suggest'] === null && $answer['candidates'] !== []]);

        return $answer;
    }

    /**
     * Right after a question asked which person: "yes" to "Did you mean …?", "no", or the name
     * or employee ID. Null when nothing was asked or the message is none of these.
     */
    public function resume(string $message, BirConversationState $state, ?BirFormDraft $draft = null): ?array
    {
        $held = $state->question();
        if ($held === null || (empty($held['suggest']) && empty($held['waiting']))) {
            return null;
        }
        $state->setQuestion(['q' => $held['q']]);
        if (!empty($held['suggest']) && BirChatCommands::saysNo($message)) {
            $state->setQuestion(['q' => $held['q'], 'waiting' => true]);

            return ['reply' => BirEmployeeResolver::NOT_THEM, 'candidates' => [], 'suggest' => null];
        }
        $code = !empty($held['suggest']) && BirChatCommands::saysYes($message) ? $held['suggest'] : null;
        if ($code === null) {
            $found = $this->employees->resolve($message);
            if ($found['status'] !== BirEmployeeResolver::MATCHED || $found['close']) {
                return null;
            }
            $code = $found['employee']->employee_id;
        }

        return $this->respond(['employee' => $code] + $held['q'], $state, $draft);
    }

    /** A person not found, more than one, or only a close spelling: ask, never guess. */
    private function who(string $typed, array $found): array
    {
        return match (true) {
            $found['status'] === BirEmployeeResolver::MATCHED => ['reply' => BirEmployeeResolver::didYouMean($typed, $found['employee']), 'candidates' => $found['candidates'], 'suggest' => $found['employee']->employee_id],
            $found['status'] === BirEmployeeResolver::AMBIGUOUS => ['reply' => 'More than one person matches that: ' . implode(', ', array_map(fn (array $c) => "{$c['name']} ({$c['employee_id']})", $found['candidates'])) . '. Which one did you mean?', 'candidates' => $found['candidates'], 'suggest' => null],
            default => ['reply' => "I could not find anyone matching \"{$typed}\". Could you check the spelling, or give their employee ID?", 'candidates' => [], 'suggest' => null],
        };
    }

    /** "Who are the taxable employees?", "how many employees have no TIN?". */
    private function employeesWho(array $q, ?BirFormDraft $draft): string
    {
        // Questions the employees table answers, whatever the payroll says.
        if ($q['group'] === null && !in_array($q['filter'], ['tax_withheld', 'no_tax_withheld'], true)) {
            $people = Employee::query()->orderBy('last_name')->orderBy('first_name')->get();
            [$people, $what] = match ($q['filter']) {
                'no_tin' => [$people->filter(fn (Employee $e) => trim((string) $e->tin_number) === ''), ['has no TIN on file', 'have no TIN on file', 'Every employee has a TIN on file']],
                'active' => [$people->where('status', 'active'), ['is active', 'are active', 'No employee is active']],
                'inactive' => [$people->where('status', '!=', 'active'), ['is not active', 'are not active', 'Every employee is active']],
                'hired' => $this->hired($people, $q),
                default => [$people, null],
            };
            $all = Employee::count();
            $lead = match (true) {
                $what === null => $all === 1 ? 'There is 1 employee' : "There are {$all} employees",
                $people->isEmpty() => $what[2],
                default => count($people) . " of {$all} " . ($all === 1 ? 'employee' : 'employees') . ' ' . $what[count($people) === 1 ? 0 : 1],
            };

            return self::names($people->values(), $lead, $q['measure'] === 'count', fn (Employee $e) => self::name($e) . ($e->position ? ", {$e->position}" : ''));
        }

        $basis = $this->basis($q, $draft);
        if ($basis === null) {
            return self::NO_PAYROLL;
        }
        $rows = $this->rows($basis);
        if ($rows->isEmpty()) {
            return $this->noPayroll($basis);
        }
        $picked = $rows->filter(fn (array $r) => self::inGroup($r, $q['group']) && self::passes($r, $q['filter']));
        $what = implode(' and ', array_filter([
            $q['group'] !== null ? self::GROUPS[self::groupKey($q['group'])][$picked->count() === 1 ? 0 : 1] : null,
            match ($q['filter']) { 'tax_withheld' => 'had tax withheld', 'no_tax_withheld' => 'had no tax withheld', default => null },
        ]));
        $be = $picked->count() === 1 ? 'is' : 'are';
        $what = str_starts_with($what, 'had') ? $what : "{$be} {$what}";
        $people = Employee::withTrashed()->whereIn('id', $picked->keys())->get()->keyBy('id');
        $lead = "Going by {$basis['label']}, " . ($picked->isEmpty() ? 'none' : $picked->count()) . " of {$rows->count()} " . ($rows->count() === 1 ? 'employee' : 'employees') . " {$what}";

        return self::names($picked->map(fn (array $r, int $id) => $r + ['person' => $people[$id] ?? null])->values(), $lead, $q['measure'] === 'count',
            fn (array $r) => ($r['person'] ? self::name($r['person']) : "Employee #{$r['id']}") . ': compensation ' . self::money($r['compensation']) . ', tax withheld ' . self::money($r['tax_withheld']));
    }

    /** "Is Teresa taxable?", "why is Noel not taxable?": their group in the period, and why. */
    private function group(array $q, Employee $person, ?BirFormDraft $draft): string
    {
        $basis = $this->basis($q, $draft);
        $row = $basis !== null ? ($this->rows($basis)[$person->id] ?? null) : null;
        $name = trim("{$person->first_name} {$person->last_name}");
        if ($row === null) {
            return "{$name} has no counted payroll " . ($basis !== null ? "in {$basis['period']}" : 'yet') . ', so I can\'t say which group they are in.';
        }

        $yearly = $basis['month'] !== null ? $row['taxable'] * 12 : $row['taxable'];
        $figure = $basis['month'] !== null
            ? 'their taxable compensation for the month, ' . self::money($row['taxable']) . ', comes to ' . self::money($yearly) . ' a year'
            : 'their taxable compensation for the year is ' . self::money($row['taxable']);
        $why = match ($row['category']) {
            'mwe' => 'they are on the minimum wage earner list in the BIR settings',
            'exempt_250k' => "{$figure}, which is ₱250,000 or less",
            default => "{$figure}, which is over ₱250,000",
        };
        $answer = $q['group'] === null || $q['why'] ? '' : (self::inGroup($row, $q['group']) ? 'Yes. ' : 'No. ');

        return "{$answer}Going by {$basis['label']}, {$name} is " . self::GROUPS[$row['category']][0] . ": {$why}. Tax withheld: " . self::money($row['tax_withheld']) . '.';
    }

    /** A total, the highest or the lowest, for everyone, a group, or one person. */
    private function amounts(array $q, ?Employee $person, ?BirFormDraft $draft): string
    {
        $basis = $this->basis($q, $draft);
        if ($basis === null) {
            return self::NO_PAYROLL;
        }
        $amount = $q['amount'] ?? 'compensation';
        $label = self::AMOUNTS[$amount];
        $filter = $q['filter'] === 'tax_withheld' && $amount === 'tax_withheld' ? null : $q['filter']; // "total tax withheld" counts everyone
        $rows = $this->rows($basis);
        if ($rows->isEmpty() && $person === null) {
            return $this->noPayroll($basis);
        }
        $rows = $rows->filter(fn (array $r) => self::inGroup($r, $q['group']) && self::passes($r, $filter));
        $value = fn (array $r) => self::column($r, $amount);

        if ($person !== null && isset($rows[$person->id])) {
            return ucfirst("{$label} for " . self::name($person) . " in {$basis['period']}: " . self::money($value($rows[$person->id])) . '.');
        }
        if ($person !== null) {
            $latest = Payroll::where('employee_id', $person->id)->whereIn('status', BirAggregationService::COUNTED_STATUSES)->max(BirAggregationService::PERIOD_DATE);

            return self::name($person) . " has no counted payroll in {$basis['period']}." . ($latest !== null ? ' Their latest is in ' . Carbon::parse($latest)->format('F Y') . '.' : '');
        }
        $n = $rows->count();
        $whose = match (true) {
            $q['group'] === null => $n === 1 ? 'the 1 employee' : "all {$n} employees",
            $n === 1 => 'the 1 employee who is ' . self::GROUPS[self::groupKey($q['group'])][0],
            default => "the {$n} employees who are " . self::GROUPS[self::groupKey($q['group'])][1],
        };
        if (!in_array($q['measure'], ['highest', 'lowest'], true)) {
            return ucfirst("total {$label} in {$basis['period']}{$basis['note']}, for {$whose}: " . self::money($rows->sum($value)) . '.');
        }
        if ($rows->isEmpty()) {
            return "No employees fit that in {$basis['period']}.";
        }
        $sorted = $q['measure'] === 'highest' ? $rows->sortByDesc($value) : $rows->sortBy($value);
        $people = Employee::withTrashed()->whereIn('id', $sorted->keys()->take(3))->get()->keyBy('id');
        $top = $sorted->take(3)->map(fn (array $r, int $id) => ($people[$id] ?? null ? self::name($people[$id]) : "Employee #{$id}") . ' ' . self::money($value($r)))->values();

        return ucfirst("the {$q['measure']} {$label} in {$basis['period']}{$basis['note']}, among {$whose}: ") . $top[0] . '.'
            . ($top->count() > 1 ? ' Next: ' . $top->slice(1)->implode(', ') . '.' : '');
    }

    /** A detail from the employee's record. */
    private function detail(Employee $person, string $detail): string
    {
        $name = self::name($person);
        $value = match ($detail) {
            'tin' => trim((string) $person->tin_number) !== '' ? FieldFormat::tin($person->tin_number) : null,
            'hire_date' => $person->hire_date?->format('F j, Y'),
            'employee_id' => $person->employee_id,
            default => trim((string) $person->{$detail}) ?: null,
        };
        $what = ['tin' => 'TIN', 'hire_date' => 'hire date', 'employee_id' => 'employee ID'][$detail] ?? $detail;

        return match (true) {
            $value === null => "No {$what} is on file for {$name}.",
            $detail === 'hire_date' => "{$name} was hired on {$value}.",
            $detail === 'status' => "{$name} is {$value}, going by their employee record.",
            default => "The {$what} on file for {$name} is {$value}.",
        };
    }

    /** The company's details from the BIR settings. */
    private function company(array $q): string
    {
        $set = BirSettingsService::values(Form1601CSchema::FORM_TYPE);
        $labels = ['name' => 'registered name', 'tin' => 'TIN', 'rdo_code' => 'RDO code', 'address' => 'registered address', 'zip' => 'ZIP code'];
        $value = fn (string $d) => $set[self::COMPANY[$d]] ?? null;
        if (isset(self::COMPANY[$q['detail']])) {
            $d = $q['detail'];

            return $value($d) !== null ? "The company's {$labels[$d]} is " . rtrim($value($d), '.') . '.' : "The company's {$labels[$d]} isn't set yet. An admin sets it in the BIR settings.";
        }
        $lines = array_map(fn (string $d) => '- ' . ucfirst($labels[$d]) . ': ' . ($value($d) ?? 'not set yet (an admin sets it in the BIR settings)'), array_keys(self::COMPANY));

        return "The company's details in the BIR settings:\n" . implode("\n", $lines);
    }

    /**
     * The period a question goes by: ['year', 'month' (null for a year), 'period' ("December 2025"),
     * 'label' (what the reply says it went by)]. Null when there is no counted payroll to go by.
     */
    private function basis(array $q, ?BirFormDraft $draft): ?array
    {
        [$year, $month, $why] = [$q['tax_year'], $q['tax_month'], null];
        if ($year === null && $month !== null) {
            $year = Payroll::query()->whereIn('status', BirAggregationService::COUNTED_STATUSES)->whereMonth(BirAggregationService::PERIOD_DATE, $month)
                ->max(BirAggregationService::PERIOD_DATE);
            $year = $year !== null ? (int) substr((string) $year, 0, 4) : SystemClock::now()->year;
        }
        if ($year === null && $draft !== null) {
            [$year, $month, $why] = [(int) substr($draft->period, 0, 4), strlen($draft->period) === 7 ? (int) substr($draft->period, 5, 2) : null, 'this form\'s period'];
        }
        if ($year === null) {
            $latest = $this->latestPaid();
            if ($latest === null) {
                return null;
            }
            [$year, $month, $why] = [$latest->year, $latest->month, 'the latest month with counted payroll'];
        }
        $period = $month !== null ? BirFormNames::monthName(sprintf('%04d-%02d', $year, $month)) : (string) $year;

        $why ??= $month === null && $year >= SystemClock::now()->year ? 'so far' : null; // a year not over yet
        $note = $why !== null ? " ({$why})" : '';

        return ['year' => $year, 'month' => $month, 'period' => $period, 'note' => $note, 'label' => "the {$period} payroll{$note}"];
    }

    /** No counted payroll in the period: say so, and which month has the latest. */
    private function noPayroll(array $basis): string
    {
        $latest = $this->latestPaid();

        return "There is no finalized or paid payroll in {$basis['period']}, so I have nothing to go by."
            . ($latest !== null ? ' The latest is in ' . $latest->format('F Y') . '.' : '');
    }

    /** The end of the latest counted payroll cutoff, or null when there is none. */
    private function latestPaid(): ?Carbon
    {
        $date = Payroll::query()->whereIn('status', BirAggregationService::COUNTED_STATUSES)->max(BirAggregationService::PERIOD_DATE);

        return $date !== null ? Carbon::parse($date) : null;
    }

    /**
     * Each paid employee's group and amounts in the period, keyed by employee id; amounts in pesos.
     * A month is the 1601-C's own rows; a year adds up its months, with the group the 2316 gives.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(array $basis): Collection
    {
        $months = $basis['month'] !== null ? [$basis['month']] : range(1, 12);
        $rows = [];
        foreach ($months as $month) {
            foreach (BirAggregationService::monthlyWithholding($basis['year'], $month)['_meta']['employees'] as $r) {
                $id = (int) $r['employee_id'];
                $rows[$id] ??= ['id' => $id, 'category' => $r['category'], 'compensation' => 0.0, 'tax_withheld' => 0.0, 'taxable' => 0.0, 'ee' => 0.0, 'thirteenth' => 0.0];
                foreach (['compensation', 'tax_withheld', 'taxable', 'ee', 'thirteenth'] as $column) {
                    $rows[$id][$column] += (float) $r[$column];
                }
            }
        }
        // A year's group is the 2316's: the year's taxable pay against P250,000.
        if ($basis['month'] === null) {
            foreach (array_keys($rows) as $id) {
                $rows[$id]['category'] = BirAggregationService::annualCompensation($id, $basis['year'])['_meta']['category'];
            }
        }

        return collect($rows);
    }

    private static function column(array $row, string $amount): float
    {
        return match ($amount) {
            'tax_withheld' => $row['tax_withheld'],
            'taxable_compensation' => $row['taxable'],
            'non_taxable_compensation' => $row['compensation'] - $row['taxable'],
            'contributions' => $row['ee'],
            'thirteenth_month' => $row['thirteenth'],
            default => $row['compensation'],
        };
    }

    public static function inGroup(array $row, ?string $group): bool
    {
        return match ($group) {
            null => true,
            'not_taxable' => $row['category'] !== 'taxable',
            default => $row['category'] === self::groupKey($group),
        };
    }

    /** The category a group is ("minimum_wage" is "mwe"); not_taxable is two. */
    public static function groupKey(string $group): string
    {
        return $group === 'minimum_wage' ? 'mwe' : $group;
    }

    private static function passes(array $row, ?string $filter): bool
    {
        return match ($filter) {
            'tax_withheld' => $row['tax_withheld'] > 0,
            'no_tax_withheld' => $row['tax_withheld'] <= 0,
            default => true,
        };
    }

    /** Employees hired in the year (and month) asked, else this year. */
    private function hired(Collection $people, array $q): array
    {
        $year = $q['tax_year'] ?? SystemClock::now()->year;
        $people = $people->filter(fn (Employee $e) => $e->hire_date?->year === $year && ($q['tax_month'] === null || $e->hire_date->month === $q['tax_month']));
        $when = $q['tax_month'] !== null ? BirFormNames::monthName(sprintf('%04d-%02d', $year, $q['tax_month'])) : (string) $year;

        return [$people, ["was hired in {$when}", "were hired in {$when}", "No employee was hired in {$when}"]];
    }

    /** The lead, then up to LISTED lines (a count lists them too when there are few). */
    public static function names(Collection $items, string $lead, bool $count, callable $line): string
    {
        if ($items->isEmpty() || ($count && $items->count() > self::LISTED)) {
            return "{$lead}.";
        }
        $lines = $items->take(self::LISTED)->map(fn ($i) => '- ' . $line($i))->implode("\n");
        $more = $items->count() > self::LISTED ? "\n…and " . ($items->count() - self::LISTED) . ' more.' : '';

        return "{$lead}:\n{$lines}{$more}";
    }

    /** "Teresa Taxable (TST-001)". */
    public static function name(Employee $e): string
    {
        return trim("{$e->first_name} {$e->last_name}") . " ({$e->employee_id})";
    }

    private static function money(float $amount): string
    {
        return BirConversationService::display(round($amount, 2) ?: 0.0, 'decimal');
    }
}
