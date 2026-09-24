<?php

namespace App\Services\BIR;

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
 * monthlyWithholding() is real. annualCompensation() is still a STUB with
 * fabricated numbers until Week 3 — keep its signature and keys identical.
 */
class BirAggregationService
{
    /** Drafts aren't paid compensation yet; they're excluded and reported in _meta. */
    private const COUNTED_STATUSES = ['finalized', 'paid'];

    /** Non-MWE employees at or under this projected annual taxable pay go to 1601-C item 23. */
    private const EXEMPT_ANNUAL_CEILING = 250_000;

    private const EE_CONTRIBUTIONS = ['SSS EE Contribution', 'PhilHealth EE Contribution', 'Pag-IBIG EE Contribution'];

    /** Reduce earned pay, same as PayrollController's taxable base. */
    private const ATTENDANCE_DEDUCTIONS = ['Late', 'Undertime', 'Absent', 'Half Day'];

    /** Undeclared-salary excess — off the books, never BIR compensation. */
    private const UNDECLARED_ALLOWANCE = 'Allowance';

    /** Pay on top of basic. For MWEs this is item 16 instead of item 15. Matched by prefix. */
    private const PREMIUM_ALLOWANCES = ['Overtime Pay', 'Rest Day Pay', 'Rest Day OT Pay', 'Special Holiday', 'Night Differential'];

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

        $v = [];
        $v['total_compensation'] = round($employees->sum('compensation'), 2);                 // 14
        // MWE basic net of their EE share — item 19 already counts that share.
        $v['mwe_statutory_wage'] = round($mwe->sum(fn ($e) => $e['compensation'] - $e['premium'] - $e['ee']), 2); // 15
        $v['mwe_premium_pay'] = round($mwe->sum('premium'), 2);                               // 16
        $v['thirteenth_month_and_benefits'] = 0.0; // 17 — GAP: no 13th-month payout record (thirteenth_month_records is the accrual basis)
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
                'draft_payrolls_excluded' => self::monthQuery($year, $month)->where('status', 'draft')->count(),
                // Not a numbered form field; kept out of the field-keyed payload.
                'employee_counts' => [
                    'total' => $employees->count(),
                    'minimum_wage' => $mwe->count(),
                    'taxable' => $taxable->count(),
                    'exempt_250k' => $exempt->count(),
                ],
                'warnings' => self::warnings($employees),
            ],
        ];
    }

    /**
     * One employee's whole calendar year, keyed by Form2316Schema's
     * payroll-derived field keys. → 2316.
     */
    public static function annualCompensation(int $employeeId, int $year): array
    {
        $seed = ($employeeId * 97) + $year;

        $nontax = [
            'nontax_mwe_basic'               => 0.0,
            'nontax_mwe_holiday'             => 0.0,
            'nontax_mwe_overtime'            => 0.0,
            'nontax_mwe_night_diff'          => 0.0,
            'nontax_mwe_hazard'              => 0.0,
            'nontax_thirteenth_month'        => self::fakeAmount($seed, 15_000, 90_000),
            'nontax_de_minimis'              => 0.0,
            'nontax_statutory_contributions' => self::fakeAmount($seed, 12_000, 28_000),
            'nontax_other_mwe_compensation'  => 0.0,
        ];
        $nontax['nontax_total'] = round(array_sum($nontax), 2);

        $taxable = [
            'tax_basic_salary'          => self::fakeAmount($seed, 240_000, 720_000),
            'tax_representation'        => 0.0,
            'tax_transportation'        => 0.0,
            'tax_cola'                  => 0.0,
            'tax_housing'               => 0.0,
            'tax_overtime'              => self::fakeAmount($seed, 0, 25_000),
            'tax_commission'            => 0.0,
            'tax_profit_sharing'        => 0.0,
            'tax_directors_fees'        => 0.0,
            'tax_thirteenth_month_excess' => max(0.0, round($nontax['nontax_thirteenth_month'] - 90_000, 2)),
            'tax_hazard_pay'             => 0.0,
        ];
        $taxable['tax_regular_total'] = round(array_sum($taxable), 2);

        $basicSalaryAnnual = round($taxable['tax_basic_salary'] + $nontax['nontax_mwe_basic'], 2);
        $grossPresent = round($nontax['nontax_total'] + $taxable['tax_regular_total'], 2);
        $taxableFromPresent = round($grossPresent - $nontax['nontax_total'], 2); // == tax_regular_total
        $taxesWithheldPresent = self::fakeAmount($seed, 15_000, 60_000);

        $values = array_merge($nontax, $taxable, [
            // Identity passthrough — rides along as 'payroll'-sourced since the real
            // implementation has the Employee record in scope anyway.
            'employee_tin'               => sprintf('%03d-%03d-%03d-000', 100 + $employeeId % 900, 200 + $employeeId % 700, 300 + $employeeId % 600),
            'employee_last_name'         => 'Dela Cruz',
            'employee_first_name'        => 'Juan',
            'employee_contact_number'    => '0917' . str_pad((string) (1000000 + $seed % 8999999), 7, '0', STR_PAD_LEFT),
            'basic_salary_annual'          => $basicSalaryAnnual,
            'gross_compensation_present'   => $grossPresent,
            'less_nontaxable_present'      => $nontax['nontax_total'],
            'taxable_income_present'       => $taxableFromPresent,
            'gross_taxable_income'         => $taxableFromPresent, // + previous-employer figure (user-sourced)
            'tax_due'                      => self::fakeAmount($seed, 14_000, 58_000), // GAP — see schema note
            'taxes_withheld_present'       => $taxesWithheldPresent,
            'total_taxes_withheld_adjusted' => $taxesWithheldPresent, // + 25B (user-sourced)
            'total_taxes_withheld_final'   => $taxesWithheldPresent,
        ]);

        return self::contractValues(self::onlySchemaKeys($values, Form2316Schema::payrollDerivedKeys())) + [
            '_meta' => [
                'employee_id' => $employeeId,
                'year' => $year,
                'stub' => true,
            ],
        ];
    }

    /** Payrolls count toward the month their cutoff ends in (a Jul 26–Aug 10 cutoff is August's). */
    private static function monthQuery(int $year, int $month): Builder
    {
        return Payroll::query()
            ->whereYear('cutoff_end', $year)
            ->whereMonth('cutoff_end', $month);
    }

    /** One employee's month: BIR compensation, premium pay, EE share, tax withheld, and 1601-C category. */
    private static function employeeMonth(int $employeeId, Collection $rows, bool $isMwe): array
    {
        $compensation = $premium = $ee = $tax = 0.0;

        foreach ($rows as $row) {
            $deductions = $row->deductions ?? [];
            $allowances = $row->allowances ?? [];

            $compensation += (float) $row->gross_pay
                - self::allowanceTotal($allowances, [self::UNDECLARED_ALLOWANCE])
                - self::deductionTotal($deductions, self::ATTENDANCE_DEDUCTIONS);
            $premium += self::allowanceTotal($allowances, self::PREMIUM_ALLOWANCES);
            $ee += self::deductionTotal($deductions, self::EE_CONTRIBUTIONS);
            $tax += (float) ($deductions['Withholding Tax'] ?? 0);
        }

        $taxable = $compensation - $ee;

        return [
            'employee_id' => $employeeId,
            'category' => match (true) {
                $isMwe => 'mwe',
                $taxable * 12 <= self::EXEMPT_ANNUAL_CEILING => 'exempt_250k',
                default => 'taxable',
            },
            'compensation' => $compensation,
            'premium' => $premium,
            'ee' => $ee,
            'tax' => $tax,
            'taxable' => $taxable,
        ];
    }

    /** Stopgap until employees carry an MWE flag: a JSON list of employee IDs in system_settings. */
    private static function mweEmployeeIds(): array
    {
        return array_map('intval', (array) SystemSettings::get('bir_mwe_employee_ids', []));
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

    /** Deterministic pseudo-amount in [$min, $max] for a given $seed. */
    private static function fakeAmount(int $seed, float $min, float $max): float
    {
        $fraction = (sin($seed) + 1) / 2;
        return round($min + $fraction * ($max - $min), 2);
    }
}
