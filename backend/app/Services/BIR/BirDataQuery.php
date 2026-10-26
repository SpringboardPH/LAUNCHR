<?php

namespace App\Services\BIR;

use App\Helpers\SystemClock;
use App\Models\BirFormDraft;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * BirDataAnswers' questions with conditions: "who has a base salary higher than 500,000?",
 * "who was hired before 2025?", "employees in Finance with their TIN", "top 3 earners in 2025",
 * "average salary of the taxable employees". One row per employee holds everything the chat may
 * read about them: their record (salary rate, hire date, position, department, status, pay basis,
 * TIN) and, when the question needs it, what payroll paid them in the period and their group.
 * The conditions, sort, limit and details come from BirDataIntent, already checked against the
 * message; this only applies them. Read only.
 */
final class BirDataQuery
{
    /** Amounts paid in the period, and the group: these need payroll. */
    private const PAY = ['compensation', 'tax_withheld', 'taxable_compensation', 'non_taxable_compensation', 'contributions', 'thirteenth_month', 'group'];

    /** True when the question needs this: conditions, a sort, details to show, an average, or the salary rate. */
    public static function handles(array $q): bool
    {
        return $q['filters'] !== [] || $q['sort_by'] !== null || $q['show'] !== [] || in_array($q['measure'], ['average', 'median'], true) || $q['amount'] === 'salary' || ($q['nth'] ?? null) !== null
            || ($q['group'] !== null && in_array($q['filter'], ['no_tin', 'active', 'inactive', 'hired'], true)) // "taxable among the inactive"
            || ($q['every'] ?? false) || ($q['else'] ?? false);
    }

    public static function answer(array $q, BirDataAnswers $answers, ?BirFormDraft $draft): string
    {
        $named = array_column($q['filters'], 'field');
        $filters = [...array_filter(self::fromLabels($q), fn (array $f) => !in_array($f['field'], $named, true)), ...$q['filters']];
        $filters = array_map(fn (array $f) => self::against($f), array_values($filters));
        if (in_array(null, $filters, true)) {
            return BirDataAnswers::UNREAD;
        }
        $filters = self::anyOf($filters);
        // "who was paid every month?": paid at all, in each month.
        if (($q['every'] ?? false) && array_intersect(array_column($filters, 'field'), self::PAY) === []) {
            $filters[] = ['field' => 'compensation', 'op' => 'gt', 'value' => 0.0];
        }
        $amount = $q['amount'] ?? (in_array($q['sort_by'], BirDataIntent::NUMBERS, true) ? $q['sort_by'] : null)
            ?? (collect($filters)->first(fn (array $f) => in_array($f['field'], BirDataIntent::NUMBERS, true))['field'] ?? null);
        $fields = array_filter([...array_column($filters, 'field'), $q['sort_by'], ...$q['show'], in_array($q['measure'], ['total', 'average', 'median', 'highest', 'lowest'], true) ? $amount : null]);
        $pay = array_intersect($fields, self::PAY) !== [];
        $amount ??= $pay ? 'compensation' : 'salary';

        $rows = collect();
        $basis = null;
        if ($pay) {
            $basis = BirDataPeriod::basis($q, $draft);
            if ($basis === null) {
                return BirDataAnswers::NO_PAYROLL;
            }
            $rows = BirDataPeriod::rows($basis);
            if ($rows->isEmpty()) {
                return BirDataPeriod::noPayroll($basis);
            }
        }
        $records = Employee::query()->orderBy('last_name')->orderBy('first_name')->get()
            ->filter(fn (Employee $e) => !$pay || isset($rows[$e->id]))
            ->map(fn (Employee $e) => self::record($e, $rows[$e->id] ?? null))->values();
        // "the drivers" read as a department: the position, when no department is so but a position is.
        foreach ($filters as $i => $f) {
            $other = ['department' => 'position', 'position' => 'department'][$f['field']] ?? null;
            $is = ['op' => $f['op'] === 'neq' ? 'eq' : $f['op']] + $f; // "not in sales": whether anyone is
            if ($other !== null && in_array($f['op'], ['eq', 'neq', 'contains', 'starts'], true) && !$records->contains(fn (array $r) => self::passes($r, $is))
                && $records->contains(fn (array $r) => self::passes($r, ['field' => $other] + $is))) {
                $filters[$i]['field'] = $other;
            }
        }
        // "below the average": the average of everyone's figure.
        foreach ($filters as $i => $f) {
            if (in_array($f['field'], BirDataIntent::NUMBERS, true) && in_array(strtolower((string) $f['value']), ['average', 'avg', 'mean'], true)) {
                // A salary is on file for everyone, paid in the period or not: its average is everyone's.
                $values = ($f['field'] === 'salary' ? Employee::query()->pluck('salary') : $records->pluck($f['field']))->filter(fn ($v) => $v !== null);
                [$filters[$i]['value'], $filters[$i]['who']] = [$values->isEmpty() ? 0.0 : $values->avg(), 'the average'];
            }
        }
        // "more than Noel": Noel's own figure, from his row.
        foreach ($filters as $i => $f) {
            if (isset($f['who_id']) && $f['field'] !== 'hire_date') {
                $theirs = $f['field'] === 'salary' ? (float) Employee::whereKey($f['who_id'])->value('salary') : ($records->firstWhere('id', $f['who_id'])[$f['field']] ?? null);
                if ($theirs === null) {
                    return "{$f['who']} has no " . BirDataConditions::LABELS[$f['field']] . ($pay ? " in {$basis['period']}" : ' on file') . ' to compare with.';
                }
                $filters[$i]['value'] = (float) $theirs;
            }
        }
        $picked = $records->filter(fn (array $r) => collect($filters)->every(fn (array $f) => self::passes($r, $f) && (!isset($f['who_id']) || $r['id'] !== $f['who_id'])))->values();
        // "who else": the person in view is left out.
        $before = $picked;
        $picked = $picked->reject(fn (array $r) => in_array($r['id'], $q['exclude'] ?? [], true))->values();
        if ($picked->isEmpty() && $before->isNotEmpty()) {
            return 'No one else: of the employees' . BirDataConditions::words($filters) . ', there is only ' . BirDataForms::andList($before->map(fn (array $r) => BirDataAnswers::name($r['person']))->all()) . '.';
        }

        $lead = $pay ? 'Going by ' . $basis['label'] . ', ' : '';
        $which = BirDataConditions::words($filters);
        // "every month of 2025": the conditions on pay hold in each month with payroll, not just in total.
        if (($q['every'] ?? false) && $pay) {
            $months = $basis['months'] ?? ($basis['month'] !== null ? [$basis['month']] : BirDataPeriod::monthsPaid($basis['year']));
            $monthly = collect($months)->map(fn (int $m) => BirDataPeriod::rows(['year' => $basis['year'], 'month' => $m, 'months' => null]));
            $onPay = array_filter($filters, fn (array $f) => in_array($f['field'], self::PAY, true));
            $picked = $picked->filter(fn (array $r) => $monthly->every(fn (Collection $rows) => isset($rows[$r['id']])
                && collect($onPay)->every(fn (array $f) => self::passes(self::record($r['person'], $rows[$r['id']]), $f))))->values();
            $which .= ' in every month of ' . $basis['period'] . ' with payroll';
        }
        $whose = $picked->count() === $records->count() && $which === '' ? 'all ' . $records->count() . ' employees'
            : 'the ' . $picked->count() . ' ' . ($picked->count() === 1 ? 'employee' . BirDataConditions::words($filters, true) : 'employees' . $which);

        if ($picked->isEmpty()) {
            return ucfirst($lead . 'there is no employee' . BirDataConditions::words($filters, true) . '.') . self::nearest($records, $filters);
        }
        // "the median salary": the middle figure, or the two middle ones averaged.
        if ($q['measure'] === 'median') {
            $values = $picked->pluck($amount)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->sort()->values();
            $mid = intdiv($values->count(), 2);
            $median = $values->isEmpty() ? 0.0 : ($values->count() % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2);

            return ucfirst($lead . 'the median ' . BirDataConditions::LABELS[$amount] . " for {$whose}: " . self::money($median) . ($amount === 'salary' ? ' (rates as on file)' : '') . '.');
        }
        if (in_array($q['measure'], ['total', 'average'], true)) {
            $sum = $picked->sum(fn (array $r) => (float) $r[$amount]);
            // "average monthly compensation of Bea": over the months with payroll, not over people.
            $monthly = $q['measure'] === 'average' && $pay && preg_match(BirDataWords::MONTHLY, $q['message'] ?? '') === 1;
            $months = $monthly ? count($basis['months'] ?? ($basis['month'] !== null ? [$basis['month']] : BirDataPeriod::monthsPaid($basis['year']))) : 0;
            $value = match (true) {
                $monthly => $sum / max(1, $months),
                $q['measure'] === 'average' => $sum / $picked->count(),
                default => $sum,
            };

            return ucfirst($lead . ($monthly ? 'the average monthly ' : ($q['measure'] === 'average' ? 'the average ' : 'the total ')) . BirDataConditions::LABELS[$amount] . " for {$whose}: " . self::money($value) . ($amount === 'salary' ? ' (rates as on file)' : '') . '.');
        }
        if (in_array($q['measure'], ['highest', 'lowest'], true)) {
            $sorted = self::sorted($picked, $amount, $q['measure'] === 'highest' ? 'desc' : 'asc');
            if (($q['nth'] ?? null) !== null) {
                return self::nth($sorted, $amount, $q['nth'], ucfirst($lead . 'the ' . self::ORDINAL[$q['nth']] . " {$q['measure']} " . BirDataConditions::LABELS[$amount] . " among {$whose}"));
            }
            // Ties at the top are named together: "Noel and Mina, ₱0.00 each".
            $tied = $sorted->filter(fn (array $r) => (float) $r[$amount] === (float) $sorted[0][$amount])->values();
            $first = $tied->count() > 1
                ? $tied->map(fn (array $r) => BirDataAnswers::name($r['person']))->implode(', ') . ', ' . self::shown($tied[0], $amount, false) . ' each'
                : BirDataAnswers::name($sorted[0]['person']) . ' ' . self::shown($sorted[0], $amount, false);
            $next = $sorted->slice($tied->count())->take(max(0, ($q['limit'] ?? 3) - $tied->count()))
                ->map(fn (array $r) => BirDataAnswers::name($r['person']) . ' ' . self::shown($r, $amount, false));

            return ucfirst($lead . "the {$q['measure']} " . BirDataConditions::LABELS[$amount] . " among {$whose}: ") . $first . '.' . ($next->isNotEmpty() ? ' Next: ' . $next->implode(', ') . '.' : '');
        }

        $sortBy = $q['sort_by'];
        if ($sortBy !== null) {
            $picked = self::sorted($picked, $sortBy, $q['order'] ?? (in_array($sortBy, BirDataIntent::NUMBERS, true) ? 'desc' : 'asc'));
        }
        if ($q['limit'] !== null) {
            $picked = $picked->take($q['limit'])->values();
        }
        // "who earns the second most?": that place, ties and all.
        if (($q['nth'] ?? null) !== null && $sortBy !== null) {
            $down = ($q['order'] ?? (in_array($sortBy, BirDataIntent::NUMBERS, true) ? 'desc' : 'asc')) === 'desc';
            $phrase = $sortBy === 'hire_date' ? ($down ? 'most recently hired' : 'earliest hired') : ($down ? 'highest ' : 'lowest ') . BirDataConditions::LABELS[$sortBy];

            return self::nth($picked, $sortBy, $q['nth'], ucfirst($lead . 'the ' . self::ORDINAL[$q['nth']] . " {$phrase} of the " . $records->count() . ' employees'));
        }
        // "who is the newest?": the one at the top, and any tied with them.
        $top = ($q['top'] ?? false) && $sortBy !== null && $picked->isNotEmpty();
        $pool = $picked->count() . ' ' . ($picked->count() === 1 ? 'employee' : 'employees') . $which; // "of the 3 employees hired in 2024"
        if ($top) {
            $key = fn (array $r) => $r[$sortBy] instanceof Carbon ? $r[$sortBy]->toDateString() : $r[$sortBy];
            $picked = $picked->filter(fn (array $r) => $key($r) === $key($picked[0]))->values();
        }
        $fixed = array_column(array_filter($filters, fn (array $f) => $f['op'] === 'eq' && !in_array($f['field'], BirDataIntent::NUMBERS, true) && !in_array($f['field'], [...$q['show'], $sortBy], true)), 'field');
        $columns = array_values(array_unique(array_diff([...$q['show'], ...array_column($filters, 'field'), ...array_filter([$sortBy])], ['name', ...$fixed])));
        // Nothing to show beyond who they are ("employees in Finance"): their names alone.
        $columns = $columns !== [] ? $columns : ($pay ? ['compensation', 'tax_withheld'] : []);
        $desc = ($q['order'] ?? (in_array($sortBy, BirDataIntent::NUMBERS, true) ? 'desc' : 'asc')) === 'desc';
        $head = match (true) {
            $top => 'the ' . match (true) {
                $sortBy === 'hire_date' => $desc ? 'most recently hired' : 'earliest hired',
                in_array($sortBy, BirDataIntent::NUMBERS, true) => ($desc ? 'highest ' : 'lowest ') . BirDataConditions::LABELS[$sortBy],
                default => 'first by ' . BirDataConditions::LABELS[$sortBy],
            } . ' of the ' . $pool . ($picked->count() > 1 ? " ({$picked->count()}, tied)" : ''),
            $q['limit'] !== null && $sortBy !== null => 'top ' . $picked->count() . ' of ' . $records->count() . ' employees by ' . BirDataConditions::LABELS[$sortBy] . (!$desc ? ', lowest first' : '') . $which,
            default => $picked->count() . ' of ' . $records->count() . ' ' . ($records->count() === 1 ? 'employee' : 'employees') . $which,
        };

        return BirDataAnswers::names($picked, ucfirst($lead . $head), $q['measure'] === 'count',
            fn (array $r) => BirDataAnswers::name($r['person']) . ($columns !== [] ? ': ' . implode(', ', array_map(fn (string $c) => self::shown($r, $c), $columns)) : ''));
    }

    /**
     * "more than Noel": the amount compared with is that employee's own (their salary rate, or
     * what they were paid when the field is paid in the period, which passes() reads from their
     * row). Null when the name isn't exactly one employee: the question is then not answered.
     */
    private static function against(array $f): ?array
    {
        if (in_array(strtolower((string) $f['value']), ['average', 'avg', 'mean'], true)) {
            return $f; // worked out from everyone's figures in answer()
        }
        $hired = $f['field'] === 'hire_date' && is_string($f['value']) && !preg_match('/^\d/', $f['value']); // "hired before Bea"
        if (!$hired && (!in_array($f['field'], BirDataIntent::NUMBERS, true) || is_numeric($f['value']) || !is_string($f['value']))) {
            return $f;
        }
        $found = app(BirEmployeeResolver::class)->resolve($f['value']);
        if ($found['status'] !== BirEmployeeResolver::MATCHED || $found['close']) {
            return null;
        }
        $person = Employee::find($found['employee']->id);
        if ($hired) {
            return $person->hire_date === null ? null : ['value' => $person->hire_date->toDateString(), 'who' => trim("{$person->first_name} {$person->last_name}"), 'who_id' => $person->id] + $f;
        }

        return ['who' => trim("{$person->first_name} {$person->last_name}"), 'who_id' => $person->id] + $f;
    }

    private const ORDINAL = [2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth'];

    /** "Which department has the most employees?", "how many per position?": employees counted by a detail. */
    public static function perValue(array $q): string
    {
        $field = $q['per'];
        $counts = Employee::query()->get([$field])->groupBy(fn (Employee $e) => trim((string) $e->{$field}) ?: "no {$field} on file")->map->count()->sortDesc();
        if ($counts->isEmpty()) {
            return 'There are no employees on file.';
        }
        if (in_array($q['measure'], ['highest', 'lowest'], true)) {
            $best = $q['measure'] === 'highest' ? $counts->max() : $counts->min();
            $at = $counts->filter(fn (int $n) => $n === $best)->keys()->all();

            return 'The ' . $field . ($q['measure'] === 'highest' ? ' with the most' : ' with the fewest') . ' employees: ' . BirDataForms::andList($at) . " ({$best}" . (count($at) > 1 ? ' each' : '') . ').';
        }

        return 'Employees by ' . $field . ":\n" . $counts->map(fn (int $n, string $v) => "- {$v}: {$n}")->implode("\n");
    }

    /** The employees in the nth place of a sorted list, counting tied values as one place. */
    private static function nth(Collection $sorted, string $field, int $n, string $lead): string
    {
        $key = fn (array $r) => $r[$field] instanceof Carbon ? $r[$field]->toDateString() : (string) $r[$field];
        $values = $sorted->map($key)->unique()->values();
        if (!isset($values[$n - 1])) {
            return "{$lead}: there isn't one; only " . $values->count() . ' different ' . ($values->count() === 1 ? 'figure' : 'figures') . ' to rank.';
        }
        $there = $sorted->filter(fn (array $r) => $key($r) === $values[$n - 1])->values();

        return "{$lead}: " . $there->map(fn (array $r) => BirDataAnswers::name($r['person']) . ' ' . self::shown($r, $field, false))->implode(', ') . '.';
    }

    /**
     * One person's details or figures: "Gino's salary and position", and "is Noel's salary more than
     * 15k?" (with conditions: Yes or No first). Amounts paid go by the question's period.
     */
    public static function person(array $q, Employee $person, array $fields, ?BirFormDraft $draft): string
    {
        $filters = array_values(array_filter(array_map(fn (array $f) => self::against($f), $q['filters'])));
        $pay = array_intersect([...$fields, ...array_column($filters, 'field')], self::PAY) !== [];
        $lead = '';
        $row = null;
        if ($pay) {
            $basis = BirDataPeriod::basis($q, $draft);
            if ($basis === null) {
                return BirDataAnswers::NO_PAYROLL;
            }
            $row = BirDataPeriod::rows($basis)[$person->id] ?? null;
            if ($row === null) {
                return BirDataAnswers::name($person) . " has no counted payroll in {$basis['period']}.";
            }
            $lead = "Going by {$basis['label']}, ";
        }
        $record = self::record($person, $row);
        foreach ($filters as $i => $f) {
            if (isset($f['who_id']) && $f['field'] !== 'hire_date') {
                $filters[$i]['value'] = (float) self::record(Employee::find($f['who_id']), $pay ? (BirDataPeriod::rows(BirDataPeriod::basis($q, $draft))[$f['who_id']] ?? null) : null)[$f['field']];
            }
        }
        $answer = $filters === [] ? '' : (collect($filters)->every(fn (array $f) => self::passes($record, $f)) ? 'Yes. ' : 'No. ');
        $fields = array_values(array_unique([...$fields, ...array_column($filters, 'field')]));

        return $answer . ucfirst($lead . BirDataAnswers::name($person) . ': ' . implode(', ', array_map(fn (string $c) => self::shown($record, $c), $fields)) . '.');
    }

    /** The old labels (group, filter) as conditions, so one set of rules applies. */
    public static function fromLabels(array $q): array
    {
        $year = (string) ($q['tax_year'] ?? SystemClock::now()->year);

        return array_values(array_filter([
            $q['group'] !== null ? ['field' => 'group', 'op' => 'eq', 'value' => $q['group']] : null,
            match ($q['filter']) {
                'tax_withheld' => ['field' => 'tax_withheld', 'op' => 'gt', 'value' => 0.0],
                'no_tax_withheld' => ['field' => 'tax_withheld', 'op' => 'lte', 'value' => 0.0],
                'no_tin' => ['field' => 'tin', 'op' => 'missing', 'value' => null],
                'active' => ['field' => 'status', 'op' => 'eq', 'value' => 'active'],
                'inactive' => ['field' => 'status', 'op' => 'neq', 'value' => 'active'],
                // "hired" with no year said is no condition: the newest hire isn't "hired this year".
                'hired' => $q['tax_year'] === null && !preg_match('/this year|ngayong taon/i', $q['message'] ?? '') ? null
                    : ['field' => 'hire_date', 'op' => 'eq', 'value' => $q['tax_month'] !== null ? sprintf('%s-%02d', $year, $q['tax_month']) : $year],
                default => null,
            },
        ]));
    }

    /** Everything the chat may read about one employee; amounts in pesos. */
    private static function record(Employee $e, ?array $row): array
    {
        $blank = fn ($v) => trim((string) $v) === '' ? null : trim((string) $v);

        return [
            'person' => $e, 'id' => $e->id, 'name' => trim("{$e->first_name} {$e->last_name}"),
            'salary' => $e->salary !== null ? (float) $e->salary : null, 'rate_type' => $blank($e->rate_type),
            'hire_date' => $e->hire_date, 'position' => $blank($e->position), 'department' => $blank($e->department),
            'status' => $blank($e->status), 'tin' => $blank($e->tin_number),
            'category' => $row['category'] ?? null, 'group' => $row['category'] ?? null,
            'compensation' => $row['compensation'] ?? null, 'tax_withheld' => $row['tax_withheld'] ?? null,
            'taxable_compensation' => $row['taxable'] ?? null,
            'non_taxable_compensation' => $row !== null ? $row['compensation'] - $row['taxable'] : null,
            'contributions' => $row['ee'] ?? null, 'thirteenth_month' => $row['thirteenth'] ?? null,
        ];
    }

    private static function passes(array $r, array $f): bool
    {
        $v = $r[$f['field']];
        $want = $f['value'];
        if ($f['op'] === 'missing' || $f['op'] === 'present') {
            return ($v === null || $v === '') === ($f['op'] === 'missing');
        }
        if ($v === null) {
            return false;
        }
        if ($f['field'] === 'group') {
            return BirDataAnswers::inGroup($r, $want) === ($f['op'] !== 'neq');
        }
        if (in_array($f['field'], BirDataIntent::NUMBERS, true)) {
            return self::compare((float) $v <=> (float) $want, $f['op']);
        }
        if ($f['field'] === 'hire_date') {
            // "2025" compares years, "2025-03" months, a full date days.
            $format = match (strlen((string) $want)) { 4 => 'Y', 7 => 'Y-m', default => 'Y-m-d' };

            return self::compare(strcmp($v->format($format), substr((string) $want, 0, 10)) <=> 0, $f['op']);
        }

        // "Teresa or Noel": any of them.
        $wants = is_array($want) ? $want : [$want];

        return match ($f['op']) {
            'eq' => collect($wants)->contains(fn ($w) => self::sameText($v, (string) $w)),
            'neq' => !collect($wants)->contains(fn ($w) => self::sameText($v, (string) $w)),
            'contains' => collect($wants)->contains(fn ($w) => stripos($v, (string) $w) !== false),
            'starts' => collect($wants)->contains(fn ($w) => stripos($v, (string) $w) === 0),
            default => false,
        };
    }

    /** "admin" is Administration, "Teresa" is Teresa Taxable: the same, or a word in it starting so. */
    private static function sameText(string $value, string $want): bool
    {
        if (strcasecmp($value, $want) === 0 || ($want !== '' && preg_match('/(?<!\p{L})' . preg_quote($want, '/') . '/iu', $value) === 1)) {
            return true;
        }
        // "developers" is Developer; "acountant" is Accountant: a plural, or one slip in a long word.
        $single = preg_replace('/(?:es|s)$/iu', '', $want);
        foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (($single !== $want && mb_strlen($single) > 2 && str_starts_with($word, mb_strtolower($single)))
                || (mb_strlen($want) >= 6 && BirMessageFacts::slips($word, mb_strtolower($want)) <= 1)) {
                return true;
            }
        }

        return false;
    }

    /** Two names, departments or positions asked for together ("Teresa and Noel") are either one. */
    private static function anyOf(array $filters): array
    {
        $out = [];
        foreach ($filters as $f) {
            $text = in_array($f['op'], ['eq', 'contains', 'starts'], true) && in_array($f['field'], ['name', 'position', 'department'], true);
            $key = $text ? "{$f['field']}|{$f['op']}" : count($out) . '#';
            if (isset($out[$key])) {
                $out[$key]['value'] = [...(array) $out[$key]['value'], $f['value']];
            } else {
                $out[$key] = $f;
            }
        }

        return array_values($out);
    }

    private static function compare(int $order, string $op): bool
    {
        return match ($op) {
            'gt' => $order > 0, 'gte' => $order >= 0, 'lt' => $order < 0, 'lte' => $order <= 0,
            'eq' => $order === 0, 'neq' => $order !== 0, default => false,
        };
    }

    private static function sorted(Collection $records, string $field, string $order): Collection
    {
        $key = fn (array $r) => $r[$field] instanceof Carbon ? $r[$field]->timestamp : $r[$field];

        return ($order === 'desc' ? $records->sortByDesc($key) : $records->sortBy($key))->values();
    }

    /** With nobody over (or under) the amount asked: who comes closest, so the answer isn't a dead end. */
    private static function nearest(Collection $records, array $filters): string
    {
        $f = collect($filters)->first(fn (array $f) => in_array($f['field'], BirDataIntent::NUMBERS, true) && in_array($f['op'], ['gt', 'gte', 'lt', 'lte'], true));
        if ($f === null || $records->isEmpty() || (float) $f['value'] === 0.0) {
            return ''; // "over nothing": nobody is nearer
        }
        $high = in_array($f['op'], ['gt', 'gte'], true);
        // Among those who meet the other conditions: the lowest-paid MWE is no hint for "taxable under 20k".
        $others = array_filter($filters, fn (array $o) => $o !== $f);
        $records = $records->filter(fn (array $r) => $r[$f['field']] !== null && collect($others)->every(fn (array $o) => self::passes($r, $o)));
        $r = self::sorted($records, $f['field'], $high ? 'desc' : 'asc')->first();

        return $r === null ? '' : ' The ' . ($high ? 'highest' : 'lowest') . ' is ' . BirDataAnswers::name($r['person']) . ' at ' . self::shown($r, $f['field'], false) . '.';
    }

    /** One detail as listed: "salary ₱35,000.00 a month", "hired January 15, 2024", "TIN 301-…". */
    private static function shown(array $r, string $field, bool $label = true): string
    {
        $v = $r[$field];
        $text = match (true) {
            $v === null || $v === '' => 'none on file',
            $field === 'salary' => self::money($v) . ($r['rate_type'] === 'daily' ? ' a day' : ' a month'),
            in_array($field, BirDataIntent::NUMBERS, true) => self::money($v),
            $field === 'hire_date' => $v->format('F j, Y'),
            $field === 'group' => BirDataAnswers::GROUPS[$v][0],
            default => (string) $v,
        };
        if (!$label) {
            return $text;
        }

        return match ($field) {
            'hire_date' => $v !== null ? "hired {$text}" : 'no hire date on file',
            'position', 'department' => $text,
            default => $v === null || $v === '' ? 'no ' . BirDataConditions::LABELS[$field] . ' on file' : BirDataConditions::LABELS[$field] . ' ' . $text,
        };
    }

    private static function money(float $amount): string
    {
        return BirConversationService::display(round($amount, 2) ?: 0.0, 'decimal');
    }
}
