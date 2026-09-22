<?php

namespace App\Services\Bir;

use App\Services\Bir\Schemas\Form1601CSchema;
use App\Services\Bir\Schemas\Form2316Schema;

/**
 * STUB — every number here is fabricated, correctly shaped only, so Dev B/C/D
 * can build against real-looking data before the real math exists.
 *
 * TODO (Week 2/3, Dev A): replace both bodies with real queries against
 * payrolls/thirteenth_month_records/employees. Keep signatures and returned
 * keys identical.
 *
 * Returns only 'payroll'-sourced schema fields; settings/user fields are the
 * mapper's job.
 */
class BirAggregationService
{
    /**
     * Company-wide totals for one calendar month, keyed by Form1601CSchema's
     * payroll-derived field keys. → 1601-C.
     */
    public static function monthlyWithholding(int $year, int $month): array
    {
        // Deterministic per (year, month) so callers can tell "wrong period" apart from "fake number".
        $seed = ($year * 12) + $month;

        $values = [
            'has_taxes_withheld'            => true,
            'total_compensation'            => self::fakeAmount($seed, 1_800_000, 2_600_000),
            'mwe_statutory_wage'             => self::fakeAmount($seed, 40_000, 90_000),
            'mwe_premium_pay'                => self::fakeAmount($seed, 5_000, 20_000),
            'thirteenth_month_and_benefits'  => self::fakeAmount($seed, 30_000, 120_000),
            'de_minimis_benefits'            => 0.0, // GAP — see schema note
            'statutory_contributions_ee'     => self::fakeAmount($seed, 60_000, 110_000),
            'total_nontaxable_compensation'  => null, // computed below, after the pieces exist
            'total_taxable_compensation'     => null,
            'exempt_250k_compensation'       => self::fakeAmount($seed, 10_000, 40_000),
            'net_taxable_compensation'       => null,
            'total_taxes_withheld'           => self::fakeAmount($seed, 150_000, 320_000),
            'taxes_withheld_for_remittance'  => null,
            'total_remittances_made'         => 0.0,
            'tax_still_due'                  => null,
            'total_penalties'                => 0.0,
            'total_amount_due'               => null,
        ];

        $values['total_nontaxable_compensation'] = round(
            $values['mwe_statutory_wage'] + $values['mwe_premium_pay']
            + $values['thirteenth_month_and_benefits'] + $values['de_minimis_benefits']
            + $values['statutory_contributions_ee'], 2
        );
        $values['total_taxable_compensation'] = round(
            $values['total_compensation'] - $values['total_nontaxable_compensation'], 2
        );
        $values['net_taxable_compensation'] = round(
            $values['total_taxable_compensation'] - $values['exempt_250k_compensation'], 2
        );
        $values['taxes_withheld_for_remittance'] = $values['total_taxes_withheld'];
        $values['tax_still_due'] = round(
            $values['taxes_withheld_for_remittance'] - $values['total_remittances_made'], 2
        );
        $values['total_amount_due'] = round($values['tax_still_due'] + $values['total_penalties'], 2);

        return self::onlySchemaKeys($values, Form1601CSchema::payrollDerivedKeys()) + [
            '_meta' => [
                'period' => sprintf('%04d-%02d', $year, $month),
                'stub' => true,
                // Not a numbered form field; kept out of the field-keyed payload.
                'employee_counts' => [
                    'total' => 40 + ($seed % 15),
                    'minimum_wage' => 3 + ($seed % 4),
                    'taxable' => 30 + ($seed % 10),
                    'exempt_250k' => 7 + ($seed % 3),
                ],
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

        return self::onlySchemaKeys($values, Form2316Schema::payrollDerivedKeys()) + [
            '_meta' => [
                'employee_id' => $employeeId,
                'year' => $year,
                'stub' => true,
            ],
        ];
    }

    /** Filters to the schema's payroll-derived keys; throws outside production if any are missing. */
    private static function onlySchemaKeys(array $values, array $schemaKeys): array
    {
        $missing = array_diff($schemaKeys, array_keys($values));
        if ($missing && !app()->isProduction()) {
            throw new \RuntimeException(
                'BirAggregationService stub is missing keys the schema expects: ' . implode(', ', $missing)
            );
        }

        return array_intersect_key($values, array_flip($schemaKeys));
    }

    /** Deterministic pseudo-amount in [$min, $max] for a given $seed. */
    private static function fakeAmount(int $seed, float $min, float $max): float
    {
        $fraction = (sin($seed) + 1) / 2;
        return round($min + $fraction * ($max - $min), 2);
    }
}
