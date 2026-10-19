<?php

namespace App\Services\BIR;

use App\Helpers\SystemClock;
use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The period a question about the records goes by, and each paid employee's figures in it, for
 * BirDataAnswers and BirDataQuery: the month or months named, the year, the open form's period,
 * or the latest month with counted payroll. A month uses the 1601-C's test (taxable pay × 12
 * against P250,000), several months the same over their count, a year the 2316's. Read only.
 */
final class BirDataPeriod
{
    /**
     * The period a question goes by: ['year', 'month' (null for a year), 'period' ("December 2025"),
     * 'label' (what the reply says it went by)]. Null when there is no counted payroll to go by.
     */
    public static function basis(array $q, ?BirFormDraft $draft): ?array
    {
        [$year, $month, $why] = [$q['tax_year'], $q['tax_month'], null];
        // "January to March 2026": those months, of the year named or the latest that has the last of them.
        if (!empty($q['months'])) {
            $year ??= ($last = Payroll::query()->whereIn('status', BirAggregationService::COUNTED_STATUSES)->whereMonth(BirAggregationService::PERIOD_DATE, max($q['months']))
                ->max(BirAggregationService::PERIOD_DATE)) !== null ? (int) substr((string) $last, 0, 4) : SystemClock::now()->year;
            $period = self::monthsName($q['months'], $year);

            return ['year' => $year, 'month' => null, 'months' => $q['months'], 'period' => $period, 'note' => '', 'label' => "the {$period} payroll"];
        }
        if ($year === null && $month !== null) {
            $year = Payroll::query()->whereIn('status', BirAggregationService::COUNTED_STATUSES)->whereMonth(BirAggregationService::PERIOD_DATE, $month)
                ->max(BirAggregationService::PERIOD_DATE);
            $year = $year !== null ? (int) substr((string) $year, 0, 4) : SystemClock::now()->year;
        }
        if ($year === null && $draft !== null) {
            [$year, $month, $why] = [(int) substr($draft->period, 0, 4), strlen($draft->period) === 7 ? (int) substr($draft->period, 5, 2) : null, 'this form\'s period'];
        }
        if ($year === null) {
            $latest = self::latestPaid();
            if ($latest === null) {
                return null;
            }
            [$year, $month, $why] = [$latest->year, $latest->month, 'the latest month with counted payroll'];
        }
        $period = $month !== null ? BirFormNames::monthName(sprintf('%04d-%02d', $year, $month)) : (string) $year;

        $why ??= $month === null && $year >= SystemClock::now()->year ? 'so far' : null; // a year not over yet
        $note = $why !== null ? " ({$why})" : '';

        return ['year' => $year, 'month' => $month, 'months' => null, 'period' => $period, 'note' => $note, 'label' => "the {$period} payroll{$note}"];
    }

    /** "January to March 2026", "January, March and May 2026". */
    public static function monthsName(array $months, int $year): string
    {
        $name = fn (int $m) => date('F', mktime(0, 0, 0, $m, 1));
        if ($months === range(min($months), max($months))) {
            return $name(min($months)) . ' to ' . $name(max($months)) . " {$year}";
        }
        $last = array_pop($months);

        return implode(', ', array_map($name, $months)) . ' and ' . $name($last) . " {$year}";
    }

    /** The months of a year with counted payroll. */
    public static function monthsPaid(int $year): array
    {
        return Payroll::query()->whereIn('status', BirAggregationService::COUNTED_STATUSES)->whereYear(BirAggregationService::PERIOD_DATE, $year)
            ->get([BirAggregationService::PERIOD_DATE])->map(fn (Payroll $p) => (int) $p->{BirAggregationService::PERIOD_DATE}->format('n'))->unique()->sort()->values()->all();
    }

    /** No counted payroll in the period: say so, and which month has the latest. */
    public static function noPayroll(array $basis): string
    {
        $latest = self::latestPaid();

        return "There is no finalized or paid payroll in {$basis['period']}, so I have nothing to go by."
            . ($latest !== null ? ' The latest is in ' . $latest->format('F Y') . '.' : '');
    }

    /** The end of the latest counted payroll cutoff, or null when there is none. */
    public static function latestPaid(): ?Carbon
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
    public static function rows(array $basis): Collection
    {
        $months = $basis['months'] ?? ($basis['month'] !== null ? [$basis['month']] : range(1, 12));
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
        // Several months: their taxable pay, as a year, against P250,000 (an MWE stays one).
        if (($basis['months'] ?? null) !== null) {
            foreach ($rows as $id => $row) {
                $rows[$id]['category'] = $row['category'] === 'mwe' ? 'mwe'
                    : ($row['taxable'] * 12 / count($basis['months']) <= BirAggregationService::EXEMPT_ANNUAL_CEILING ? 'exempt_250k' : 'taxable');
            }
        }
        // A year's group is the 2316's: the year's taxable pay against P250,000.
        if ($basis['month'] === null && ($basis['months'] ?? null) === null) {
            foreach (array_keys($rows) as $id) {
                $rows[$id]['category'] = BirAggregationService::annualCompensation($id, $basis['year'])['_meta']['category'];
            }
        }

        return collect($rows);
    }

    /**
     * "total tax withheld per month in 2025", "which month had the most tax?": the amount month by
     * month for the year (of a group, or one employee, when one is named), or the month at the top or bottom.
     */
    public static function perMonth(array $q, ?BirFormDraft $draft, ?Employee $person = null): string
    {
        $year = $q['tax_year'] ?? ($draft !== null ? (int) substr($draft->period, 0, 4) : null) ?? self::latestPaid()?->year;
        if ($year === null) {
            return BirDataAnswers::NO_PAYROLL;
        }
        $amount = in_array($q['amount'], ['salary', null], true) ? 'compensation' : $q['amount'];
        $column = ['compensation' => 'compensation', 'tax_withheld' => 'tax_withheld', 'contributions' => 'ee', 'thirteenth_month' => 'thirteenth', 'taxable_compensation' => 'taxable'][$amount] ?? null;
        $label = ['compensation' => 'compensation', 'tax_withheld' => 'tax withheld', 'contributions' => 'SSS, PhilHealth and Pag-IBIG (employee share)', 'thirteenth_month' => '13th month and other benefits', 'taxable_compensation' => 'taxable compensation'][$amount] ?? null;
        if ($column === null) {
            return BirDataAnswers::CANT;
        }
        $totals = [];
        foreach (range(1, 12) as $m) {
            $rows = self::rows(['year' => $year, 'month' => $m, 'months' => null]);
            if ($rows->isNotEmpty() && ($person === null || isset($rows[$person->id]))) {
                $totals[$m] = round($rows->filter(fn (array $r) => BirDataAnswers::inGroup($r, $q['group']) && ($person === null || $r['id'] === $person->id))->sum($column), 2);
            }
        }
        if ($totals === []) {
            return ($person !== null ? BirDataAnswers::name($person) . ' has no' : 'There is no') . " finalized or paid payroll in {$year}.";
        }
        $money = fn (float $v) => BirConversationService::display($v ?: 0.0, 'decimal');
        // "which months had more than 25k tax withheld?": the months over (or under) it.
        $conditions = array_filter($q['filters'] ?? [], fn (array $f) => in_array($f['field'], [$amount, 'salary'], true) && is_numeric($f['value']) && in_array($f['op'], ['gt', 'gte', 'lt', 'lte', 'eq'], true));
        foreach ($conditions as $f) {
            $totals = array_filter($totals, fn (float $v) => match ($f['op']) { 'gt' => $v > $f['value'], 'gte' => $v >= $f['value'], 'lt' => $v < $f['value'], 'lte' => $v <= $f['value'], default => abs($v - $f['value']) < 0.005 });
        }
        // A comparison the model gave no amount for ("less than 75k"): never every month instead.
        if ($conditions === [] && preg_match('/\b(?:over|above|more than|higher than|greater than|less than|lower than|below|under|at least|at most|mahigit|kulang)\b/i', $q['message'] ?? '')) {
            return BirDataAnswers::UNREAD;
        }
        $than = implode(' and ', array_map(fn (array $f) => ['gt' => 'over', 'gte' => 'of at least', 'lt' => 'under', 'lte' => 'of at most', 'eq' => 'of exactly'][$f['op']] . ' ' . $money((float) $f['value']), $conditions));
        if ($conditions !== []) {

            return $totals === [] ? "No month of {$year} had {$label} {$than}." : "Months of {$year} with {$label} {$than} (" . count($totals) . "):\n"
                . implode("\n", array_map(fn (int $m, float $v) => '- ' . date('F', mktime(0, 0, 0, $m, 1)) . ': ' . $money($v), array_keys($totals), $totals));
        }
        $month = fn (int $m) => date('F', mktime(0, 0, 0, $m, 1));
        $whose = match (true) {
            $person !== null => ' for ' . BirDataAnswers::name($person),
            $q['group'] !== null => ' of the employees who are ' . BirDataAnswers::GROUPS[BirDataAnswers::groupKey($q['group'])][1],
            default => '',
        };
        if (in_array($q['measure'], ['highest', 'lowest'], true)) {
            $best = $q['measure'] === 'highest' ? max($totals) : min($totals);
            $at = array_keys(array_filter($totals, fn (float $v) => $v === $best));

            return 'The month of ' . $year . ' with the ' . $q['measure'] . " total {$label}{$whose} is " . BirDataForms::andList(array_map($month, $at)) . ': ' . $money($best) . (count($at) > 1 ? ' each.' : '.');
        }
        $lines = implode("\n", array_map(fn (int $m, float $v) => '- ' . $month($m) . ': ' . $money($v), array_keys($totals), $totals));

        return ucfirst($label) . "{$whose} month by month in {$year}:\n{$lines}\nTotal: " . $money(array_sum($totals)) . '.';
    }
}
