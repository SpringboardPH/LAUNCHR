<?php

namespace App\Services\BIR;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Payroll-derived totals for the BIR forms. Returns only 'payroll'-sourced
 * schema fields; settings/user fields are the mapper's job. Money comes back
 * as plain decimal strings ("55010.00"), the draft value format in
 * docs/bir-api-contract.md §7, and is never null (contract §3 invariant 1).
 *
 * Basic salary is reported net of the EE share, since item 19 (1601-C) and
 * item 36 (2316) already count that share. Matches payroll's own withholding
 * and the accountant's MWE 2316; their taxable and below-threshold 2316
 * samples count it twice — unconfirmed with the accountant.
 */
class BirAggregationService
{
    /**
     * The date that decides which month/year a payroll belongs to. Unconfirmed with the
     * accountant — if they file on payment date, set this to 'paid_at' and
     * COUNTED_STATUSES to ['paid'] (finalized-but-unpaid rows have no paid_at).
     */
    public const PERIOD_DATE = 'cutoff_end';

    /** Payrolls in any other status are left out of the totals and reported in _meta. */
    public const COUNTED_STATUSES = ['finalized', 'paid'];

    /** Non-MWE employees at or under this annual taxable pay are exempt (1601-C item 23, 2316 item 29). */
    private const EXEMPT_ANNUAL_CEILING = 250_000;

    /** 13th month and other benefits are non-taxable up to this much a year; the rest is taxable. */
    private const THIRTEENTH_MONTH_CAP = 90_000;

    /** TRAIN graduated annual rates from 2023: [over, tax on that amount, rate on the excess]. */
    private const ANNUAL_TAX_TABLE = [
        [8_000_000, 2_202_500, 0.35],
        [2_000_000, 402_500, 0.30],
        [800_000, 102_500, 0.25],
        [400_000, 22_500, 0.20],
        [250_000, 0, 0.15],
    ];

    private const EE_CONTRIBUTIONS = ['SSS EE Contribution', 'PhilHealth EE Contribution', 'Pag-IBIG EE Contribution'];

    /** Reduce earned pay, same as PayrollController's taxable base. */
    private const ATTENDANCE_DEDUCTIONS = ['Late', 'Undertime', 'Absent', 'Half Day'];

    /** Undeclared-salary excess — off the books, never BIR compensation. */
    private const UNDECLARED_ALLOWANCE = 'Allowance';

    /** Written onto a payroll by ThirteenthMonthController::pushToPayroll(). */
    private const THIRTEENTH_MONTH = '13th Month Pay';

    /** Pay on top of basic, matched by label prefix. Regular holidays are paid inside basic, so only the special-holiday premium shows up here. */
    private const HOLIDAY_PAY = ['Special Holiday'];
    private const OVERTIME_PAY = ['Overtime Pay', 'Rest Day Pay', 'Rest Day OT Pay'];
    private const NIGHT_DIFFERENTIAL = ['Night Differential'];

    /**
     * Company-wide totals for one calendar month, keyed by Form1601CSchema's
     * payroll-derived field keys. → 1601-C.
     */
    public static function monthlyWithholding(int $year, int $month): array
    {
        $mweIds = self::mweEmployeeIds();

        $employees = self::monthQuery($year, $month)
            ->whereIn('status', self::COUNTED_STATUSES)
            ->get(['employee_id', 'gross_pay', 'deductions', 'allowances'])
            ->groupBy('employee_id')
            ->map(fn (Collection $rows, $employeeId) => self::employeeMonth(
                (int) $employeeId,
                $rows,
                in_array((int) $employeeId, $mweIds, true),
            ));

        $mwe = $employees->where('category', 'mwe');
        $exempt = $employees->where('category', 'exempt_250k');
        $taxable = $employees->where('category', 'taxable');
        $excluded = self::excludedPayrolls(self::monthQuery($year, $month));

        $v = [];
        $v['total_compensation'] = round($employees->sum('compensation'), 2);                 // 14
        $v['mwe_statutory_wage'] = round($mwe->sum('basic'), 2);                              // 15
        $v['mwe_premium_pay'] = round($mwe->sum('premium'), 2);                               // 16
        $v['thirteenth_month_and_benefits'] = round($employees->sum('thirteenth'), 2);        // 17
        $v['de_minimis_benefits'] = 0.0;           // 18 — GAP: allowances aren't classified as de minimis
        $v['statutory_contributions_ee'] = round($employees->sum('ee'), 2);                   // 19
        $v['total_nontaxable_compensation'] = round(                                          // 21 (mapper adds user item 20)
            $v['mwe_statutory_wage'] + $v['mwe_premium_pay'] + $v['thirteenth_month_and_benefits']
            + $v['de_minimis_benefits'] + $v['statutory_contributions_ee'], 2
        );
        $v['total_taxable_compensation'] = round($v['total_compensation'] - $v['total_nontaxable_compensation'], 2); // 22
        $v['exempt_250k_compensation'] = round($exempt->sum('taxable'), 2);                   // 23
        $v['net_taxable_compensation'] = round($v['total_taxable_compensation'] - $v['exempt_250k_compensation'], 2); // 24
        $v['total_taxes_withheld'] = round($employees->sum('tax'), 2);                        // 25
        $v['has_taxes_withheld'] = $v['total_taxes_withheld'] > 0;                            // 3
        $v['taxes_withheld_for_remittance'] = $v['total_taxes_withheld'];                     // 27 (mapper adds user item 26)
        $v['total_remittances_made'] = 0.0;                                                   // 30 (items 28-29 are user)
        $v['tax_still_due'] = round($v['taxes_withheld_for_remittance'] - $v['total_remittances_made'], 2); // 31
        $v['total_penalties'] = 0.0;                                                          // 35 (items 32-34 are user)
        $v['total_amount_due'] = round($v['tax_still_due'] + $v['total_penalties'], 2);      // 36

        return self::contractValues(self::onlySchemaKeys($v, Form1601CSchema::payrollDerivedKeys())) + [
            '_meta' => [
                'period' => sprintf('%04d-%02d', $year, $month),
                'excluded_payrolls' => $excluded,
                // Not a numbered form field; kept out of the field-keyed payload.
                'employee_counts' => [
                    'total' => $employees->count(),
                    'minimum_wage' => $mwe->count(),
                    'taxable' => $taxable->count(),
                    'exempt_250k' => $exempt->count(),
                ],
                'warnings' => [
                    // Zeros everywhere would otherwise look like a finished form.
                    ...($employees->isEmpty() ? [sprintf('No finalized or paid payroll for %04d-%02d.', $year, $month)] : []),
                    ...self::excludedWarnings($excluded),
                    ...self::warnings($employees),
                ],
            ],
        ];
    }

    /**
     * One employee's whole calendar year, keyed by Form2316Schema's
     * payroll-derived field keys. → 2316. Only payrolls from this employer:
     * a previous employer's pay and tax (items 22, 25B) are user-entered, and
     * tax due must then be recomputed with annualTaxDue().
     */
    public static function annualCompensation(int $employeeId, int $year): array
    {
        $employee = Employee::findOrFail($employeeId);
        $rows = self::yearQuery($year)
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::COUNTED_STATUSES)
            ->orderBy(self::PERIOD_DATE)
            ->get(['cutoff_start', 'cutoff_end', 'gross_pay', 'deductions', 'allowances']);
        $excluded = self::excludedPayrolls(self::yearQuery($year)->where('employee_id', $employeeId));

        $isMwe = in_array($employeeId, self::mweEmployeeIds(), true);
        $pay = self::classify(self::payTotals($rows), $isMwe, 1);
        $category = $pay['category'];

        $v = [];
        // Section A — non-taxable. Below the P250,000 line, basic goes to 29 and other pay to 37.
        $v['nontax_mwe_basic'] = $category === 'taxable' ? 0.0 : $pay['basic'];               // 29
        $v['nontax_mwe_holiday'] = $isMwe ? $pay['holiday'] : 0.0;                           // 30
        $v['nontax_mwe_overtime'] = $isMwe ? $pay['overtime'] : 0.0;                         // 31
        $v['nontax_mwe_night_diff'] = $isMwe ? $pay['night_diff'] : 0.0;                     // 32
        $v['nontax_mwe_hazard'] = 0.0;                                                        // 33 — payroll has no hazard pay
        $v['nontax_thirteenth_month'] = $pay['thirteenth'];                                   // 34
        $v['nontax_de_minimis'] = 0.0;                                                        // 35 — GAP, same as 1601-C item 18
        $v['nontax_statutory_contributions'] = $pay['ee'];                                    // 36
        $v['nontax_other_mwe_compensation'] = $category === 'exempt_250k' ? $pay['premium'] : 0.0; // 37
        $v['nontax_total'] = round(array_sum($v), 2);                                         // 38

        // Section B — taxable. Holiday and night differential stay in basic; there's no box of their own.
        $section = [];
        $section['tax_basic_salary'] = $category === 'taxable' ? $pay['basic'] + $pay['premium'] - $pay['overtime'] : 0.0; // 39
        $section['tax_representation'] = 0.0;                                                 // 40
        $section['tax_transportation'] = 0.0;                                                 // 41
        $section['tax_cola'] = 0.0;                                                           // 42
        $section['tax_housing'] = 0.0;                                                        // 43 (44A-B are user)
        $section['tax_commission'] = 0.0;                                                     // 45
        $section['tax_profit_sharing'] = 0.0;                                                 // 46
        $section['tax_directors_fees'] = 0.0;                                                 // 47
        $section['tax_thirteenth_month_excess'] = $pay['thirteenth_excess'];                  // 48
        $section['tax_hazard_pay'] = 0.0;                                                     // 49
        $section['tax_overtime'] = $category === 'taxable' ? $pay['overtime'] : 0.0;          // 50 (51A-B are user)
        $section['tax_regular_total'] = round(array_sum($section), 2);                        // 52 (mapper adds user 44A-B, 51A-B)
        $v += $section;

        $v['basic_salary_annual'] = round($v['nontax_mwe_basic'] + $v['tax_basic_salary'], 2); // feeds 29 / 39
        $v['gross_compensation_present'] = round($v['nontax_total'] + $v['tax_regular_total'], 2); // 19
        $v['less_nontaxable_present'] = $v['nontax_total'];                                  // 20
        $v['taxable_income_present'] = $v['tax_regular_total'];                              // 21
        $v['gross_taxable_income'] = $v['taxable_income_present'];                           // 23 (mapper adds user item 22)
        $v['tax_due'] = $isMwe ? 0.0 : self::annualTaxDue($v['gross_taxable_income']);       // 24
        $v['taxes_withheld_present'] = round($pay['tax'], 2);                                 // 25A
        $v['total_taxes_withheld_adjusted'] = $v['taxes_withheld_present'];                  // 26 (mapper adds user 25B)
        $v['total_taxes_withheld_final'] = $v['total_taxes_withheld_adjusted'];              // 28 (mapper adds user 27)

        $v['employee_tin'] = self::formatTin($employee->tin_number);                          // 3
        $v['employee_last_name'] = (string) $employee->last_name;                             // 4
        $v['employee_first_name'] = (string) $employee->first_name;                           // 4
        $v['employee_contact_number'] = (string) $employee->phone;                            // 8

        return self::contractValues(self::onlySchemaKeys($v, Form2316Schema::payrollDerivedKeys())) + [
            '_meta' => [
                'employee_id' => $employeeId,
                'year' => $year,
                'category' => $category,
                'payroll_count' => $rows->count(),
                // First and last cutoff counted — hints for items 2 (period) on a mid-year hire or separation.
                'payroll_range' => $rows->isEmpty() ? null : [
                    'from' => $rows->first()->cutoff_start->toDateString(),
                    'to' => $rows->last()->cutoff_end->toDateString(),
                ],
                'excluded_payrolls' => $excluded,
                'warnings' => [...self::excludedWarnings($excluded), ...self::annualWarnings($employee, $year, $rows, $pay, $v)],
            ],
        ];
    }

    /** Annual tax due on taxable compensation (2316 item 24). Public so the mapper can recompute once a previous employer's pay (item 22) is added. */
    public static function annualTaxDue(float $taxable): float
    {
        foreach (self::ANNUAL_TAX_TABLE as [$over, $base, $rate]) {
            if ($taxable > $over) {
                return round($base + ($taxable - $over) * $rate, 2);
            }
        }
        return 0.0;
    }

    /** A month's payrolls, every status (a Jul 26–Aug 10 cutoff is August's). Callers add the status filter. */
    public static function monthQuery(int $year, int $month): Builder
    {
        return self::yearQuery($year)->whereMonth(self::PERIOD_DATE, $month);
    }

    /** A year's payrolls, every status (a Dec 26–Jan 10 cutoff belongs to the next year). Callers add the status filter. */
    public static function yearQuery(int $year): Builder
    {
        return Payroll::query()->whereYear(self::PERIOD_DATE, $year);
    }

    /** Payroll count per status left out of the totals, e.g. ['draft' => 2]. */
    private static function excludedPayrolls(Builder $period): array
    {
        return $period->whereNotIn('status', self::COUNTED_STATUSES)
            ->selectRaw('status, count(*) as payrolls')
            ->groupBy('status')
            ->pluck('payrolls', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** One employee's month for the 1601-C; the P250,000 test projects the month over 12. */
    private static function employeeMonth(int $employeeId, Collection $rows, bool $isMwe): array
    {
        return ['employee_id' => $employeeId] + self::classify(self::payTotals($rows), $isMwe, 12);
    }

    /** Raw sums over payroll rows: BIR compensation, its parts, EE share and tax withheld. */
    private static function payTotals(Collection $rows): array
    {
        $t = array_fill_keys(['compensation', 'holiday', 'overtime', 'night_diff', 'thirteenth', 'ee', 'tax'], 0.0);

        foreach ($rows as $row) {
            $deductions = $row->deductions ?? [];
            $allowances = $row->allowances ?? [];

            $t['compensation'] += (float) $row->gross_pay
                - self::allowanceTotal($allowances, [self::UNDECLARED_ALLOWANCE])
                - self::deductionTotal($deductions, self::ATTENDANCE_DEDUCTIONS);
            $t['holiday'] += self::allowanceTotal($allowances, self::HOLIDAY_PAY);
            $t['overtime'] += self::allowanceTotal($allowances, self::OVERTIME_PAY);
            $t['night_diff'] += self::allowanceTotal($allowances, self::NIGHT_DIFFERENTIAL);
            $t['thirteenth'] += self::allowanceTotal($allowances, [self::THIRTEENTH_MONTH]);
            $t['ee'] += self::deductionTotal($deductions, self::EE_CONTRIBUTIONS);
            $t['tax'] += (float) ($deductions['Withholding Tax'] ?? 0);
        }

        return $t;
    }

    /**
     * Splits totals into basic (net of EE), premium, the non-taxable and taxable parts
     * of the 13th month, and the category. $periodsPerYear projects a month to a year.
     */
    private static function classify(array $t, bool $isMwe, int $periodsPerYear): array
    {
        $thirteenth = min($t['thirteenth'], (float) self::THIRTEENTH_MONTH_CAP);
        $premium = $t['holiday'] + $t['overtime'] + $t['night_diff'];
        $taxable = $t['compensation'] - $thirteenth - $t['ee'];

        return array_merge($t, [
            'category' => match (true) {
                $isMwe => 'mwe',
                $taxable * $periodsPerYear <= self::EXEMPT_ANNUAL_CEILING => 'exempt_250k',
                default => 'taxable',
            },
            'basic' => $t['compensation'] - $premium - $t['thirteenth'] - $t['ee'],
            'premium' => $premium,
            'thirteenth' => $thirteenth,
            'thirteenth_excess' => $t['thirteenth'] - $thirteenth,
            'taxable' => $taxable,
        ]);
    }

    /** Stopgap until employees carry an MWE flag: a JSON list of employee IDs in system_settings. */
    private static function mweEmployeeIds(): array
    {
        return array_map('intval', (array) SystemSettings::get('bir_mwe_employee_ids', []));
    }

    /** One line per status left out, so a total that's short has a visible cause. */
    private static function excludedWarnings(array $excluded): array
    {
        return array_map(
            fn (string $status, int $n) => "{$n} payroll(s) in '{$status}' status were not counted.",
            array_keys($excluded),
            $excluded,
        );
    }

    /** Employees whose withholding contradicts their category — surfaced for the hand-count reconciliation. */
    private static function warnings(Collection $employees): array
    {
        return $employees->map(fn (array $e) => match (true) {
            $e['category'] === 'mwe' && $e['tax'] > 0
                => "Employee #{$e['employee_id']} is flagged MWE but had tax withheld.",
            $e['category'] === 'exempt_250k' && $e['tax'] > 0
                => "Employee #{$e['employee_id']} is at or under P250,000 annualized (item 23) but had tax withheld.",
            $e['category'] === 'taxable' && $e['tax'] <= 0
                => "Employee #{$e['employee_id']} is over P250,000 annualized but had no tax withheld.",
            default => null,
        })->filter()->values()->all();
    }

    /** What a reviewer should check before trusting the 2316. */
    private static function annualWarnings(Employee $employee, int $year, Collection $rows, array $pay, array $v): array
    {
        if ($rows->isEmpty()) {
            return ["No finalized or paid payroll for {$year}."];
        }

        $warnings = [];
        if ($v['employee_tin'] === '') {
            $warnings[] = 'The employee has no TIN on file (item 3).';
        }
        if ($pay['thirteenth'] + $pay['thirteenth_excess'] <= 0) {
            $warnings[] = "No '13th Month Pay' in this year's payroll. If it was paid outside payroll, item 34 is short.";
        }
        if ($pay['category'] === 'mwe' && $pay['tax'] > 0) {
            $warnings[] = 'The employee is flagged MWE but had tax withheld.';
        }
        $gap = round($v['tax_due'] - $v['taxes_withheld_present'], 2);
        if ($gap != 0 && $pay['category'] !== 'mwe') {
            $warnings[] = sprintf(
                'Tax due %s vs. withheld %s: %s %s. Needs a year-end adjustment.',
                number_format($v['tax_due'], 2), number_format($v['taxes_withheld_present'], 2),
                number_format(abs($gap), 2), $gap > 0 ? 'under-withheld' : 'over-withheld',
            );
        }
        return $warnings;
    }

    private static function allowanceTotal(array $allowances, array $labelPrefixes): float
    {
        $total = 0.0;
        foreach ($allowances as $allowance) {
            foreach ($labelPrefixes as $prefix) {
                if (str_starts_with($allowance['label'] ?? '', $prefix)) {
                    $total += (float) ($allowance['amount'] ?? 0);
                    break;
                }
            }
        }
        return $total;
    }

    private static function deductionTotal(array $deductions, array $labels): float
    {
        return array_sum(array_map(fn ($label) => (float) ($deductions[$label] ?? 0), $labels));
    }

    /** ###-###-###-branch; the branch code defaults to 000. Anything under 9 digits is returned as stored. */
    public static function formatTin(?string $tin): string
    {
        $digits = preg_replace('/\D/', '', (string) $tin);
        if (strlen($digits) < 9) {
            return trim((string) $tin);
        }
        return implode('-', [substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 3), str_pad(substr($digits, 9), 3, '0')]);
    }

    /** Filters to the schema's payroll-derived keys; throws outside production if any are missing. */
    private static function onlySchemaKeys(array $values, array $schemaKeys): array
    {
        $missing = array_diff($schemaKeys, array_keys($values));
        if ($missing && !app()->isProduction()) {
            throw new \RuntimeException(
                'BirAggregationService is missing keys the schema expects: ' . implode(', ', $missing)
            );
        }

        return array_intersect_key($values, array_flip($schemaKeys));
    }

    /** Money as a plain 2-decimal string; `?: 0.0` stops float noise printing "-0.00". Non-money passes through. */
    private static function contractValues(array $values): array
    {
        return array_map(
            fn ($v) => is_float($v) ? number_format(round($v, 2) ?: 0.0, 2, '.', '') : $v,
            $values
        );
    }
}
