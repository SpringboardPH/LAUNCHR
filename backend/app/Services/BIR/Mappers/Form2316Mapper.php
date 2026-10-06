<?php

namespace App\Services\BIR\Mappers;

use App\Services\BIR\BirAggregationService;
use App\Services\BIR\BirSettingsService;
use App\Services\BIR\Schemas\Form2316Schema;

/**
 * 2316: one employee's year — payroll totals (including the tax year, period worked
 * and MWE flag) and the employer's registration details — placed into the form's fields.
 * Totals follow the printed formulas (items 19, 20, 21, 23, 26, 28, 38, 52), and tax
 * due is reworked from item 23 so a previous employer's pay (item 22) is taxed too.
 */
class Form2316Mapper extends BirFormMapper
{
    /** Items 29 to 37. */
    private const NONTAXABLE = [
        'nontax_mwe_basic', 'nontax_mwe_holiday', 'nontax_mwe_overtime', 'nontax_mwe_night_diff', 'nontax_mwe_hazard',
        'nontax_thirteenth_month', 'nontax_de_minimis', 'nontax_statutory_contributions', 'nontax_other_mwe_compensation',
    ];

    /** Items 39 to 51B — the amounts only; 44A/B and 51A/B descriptions are text. */
    private const TAXABLE = [
        'tax_basic_salary', 'tax_representation', 'tax_transportation', 'tax_cola', 'tax_housing',
        'tax_others_44a_amount', 'tax_others_44b_amount', 'tax_commission', 'tax_profit_sharing', 'tax_directors_fees',
        'tax_thirteenth_month_excess', 'tax_hazard_pay', 'tax_overtime', 'tax_others_51a_amount', 'tax_others_51b_amount',
    ];

    /** @return array{fields: array<string, array<string, mixed>>, meta: array<string, mixed>} */
    public static function map(int $employeeId, int $year): array
    {
        $aggregation = BirAggregationService::annualCompensation($employeeId, $year);
        $meta = $aggregation['_meta'];
        unset($aggregation['_meta']);

        $values = $aggregation + BirSettingsService::values(Form2316Schema::FORM_TYPE);

        return [
            'fields' => self::totals(self::entries(Form2316Schema::fields(), $values)),
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    public static function totals(array $fields): array
    {
        self::placeBasicPay($fields);

        self::put($fields, 'nontax_total', self::sum($fields, self::NONTAXABLE));              // 38
        self::put($fields, 'tax_regular_total', self::sum($fields, self::TAXABLE));            // 52, with the user's 44A/B and 51A/B
        self::put($fields, 'basic_salary_annual',                                              // feeds 29 / 39
            self::sum($fields, ['nontax_mwe_basic', 'tax_basic_salary']));

        self::put($fields, 'gross_compensation_present',                                       // 19 = 38 + 52
            self::sum($fields, ['nontax_total', 'tax_regular_total']));
        self::put($fields, 'less_nontaxable_present', self::amount($fields, 'nontax_total'));  // 20 = 38
        self::put($fields, 'taxable_income_present',                                           // 21 = 19 - 20
            self::amount($fields, 'gross_compensation_present') - self::amount($fields, 'less_nontaxable_present'));
        self::put($fields, 'gross_taxable_income',                                             // 23 = 21 + 22
            self::sum($fields, ['taxable_income_present', 'taxable_income_previous_employer']));
        self::put($fields, 'tax_due',                                                          // 24
            BirAggregationService::annualTaxDue(self::amount($fields, 'gross_taxable_income')));

        self::put($fields, 'total_taxes_withheld_adjusted',                                    // 26 = 25A + 25B
            self::sum($fields, ['taxes_withheld_present', 'taxes_withheld_previous']));
        self::put($fields, 'total_taxes_withheld_final',                                       // 28 = 26 + 27
            self::sum($fields, ['total_taxes_withheld_adjusted', 'pera_tax_credit']));

        return $fields;
    }

    /**
     * Item 29 holds a non-MWE's basic pay only while their taxable pay for the whole year,
     * including a previous employer's (item 22) and anything entered in 44A/B or 51A/B, is
     * P250,000 or less. Past that, the pay is taxable and belongs in item 39 — otherwise
     * tax due would only cover what was added. Below it again, it moves back to item 29.
     * Pay in item 37 (holiday, overtime, night differential) goes to item 39 with it; the
     * taxable layout would put overtime in item 50, but the totals and tax due are the same.
     * Left alone when someone has changed 29, 37 or 39 by hand.
     */
    private static function placeBasicPay(array &$fields): void
    {
        $mwe = ($fields['is_mwe']['value'] ?? false) === true;
        $edited = array_filter(['nontax_mwe_basic', 'nontax_other_mwe_compensation', 'tax_basic_salary'],
            fn (string $key) => !empty($fields[$key]['edited']));
        if ($mwe || $edited !== []) {
            return;
        }

        $basic = self::sum($fields, ['nontax_mwe_basic', 'tax_basic_salary']);
        $premium = self::amount($fields, 'nontax_other_mwe_compensation');
        $otherTaxable = self::sum($fields, array_diff(self::TAXABLE, ['tax_basic_salary']))
            + self::amount($fields, 'taxable_income_previous_employer');

        if ($basic + $premium + $otherTaxable <= BirAggregationService::EXEMPT_ANNUAL_CEILING) {
            self::put($fields, 'nontax_mwe_basic', $basic);
            self::put($fields, 'tax_basic_salary', 0.0);
        } else {
            self::put($fields, 'nontax_mwe_basic', 0.0);
            self::put($fields, 'nontax_other_mwe_compensation', 0.0);
            self::put($fields, 'tax_basic_salary', $basic + $premium);
        }
    }
}
