<?php

namespace App\Services\BIR\Mappers;

use App\Services\BIR\BirAggregationService;
use App\Services\BIR\BirSettingsService;
use App\Services\BIR\Schemas\Form1601CSchema;

/**
 * 1601-C: the month's payroll totals (including the return period) and the company's
 * registration details, placed into the form's fields. Totals follow the printed formulas
 * (items 21, 22, 24, 27, 30, 31, 35, 36).
 */
class Form1601CMapper extends BirFormMapper
{
    /** @return array{fields: array<string, array<string, mixed>>, meta: array<string, mixed>} */
    public static function map(int $year, int $month): array
    {
        $aggregation = BirAggregationService::monthlyWithholding($year, $month);
        $meta = $aggregation['_meta'];
        unset($aggregation['_meta']);

        $values = $aggregation + BirSettingsService::values(Form1601CSchema::FORM_TYPE);

        return [
            'fields' => self::totals(self::entries(Form1601CSchema::fields(), $values)),
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    public static function totals(array $fields): array
    {
        // 21 = 15 to 20, including the user's other non-taxable compensation (item 20).
        self::put($fields, 'total_nontaxable_compensation', self::sum($fields, [
            'mwe_statutory_wage', 'mwe_premium_pay', 'thirteenth_month_and_benefits',
            'de_minimis_benefits', 'statutory_contributions_ee', 'other_nontaxable_compensation',
        ]));
        self::put($fields, 'total_taxable_compensation',                                        // 22 = 14 - 21
            self::amount($fields, 'total_compensation') - self::amount($fields, 'total_nontaxable_compensation'));
        self::put($fields, 'net_taxable_compensation',                                          // 24 = 22 - 23
            self::amount($fields, 'total_taxable_compensation') - self::amount($fields, 'exempt_250k_compensation'));

        self::put($fields, 'has_taxes_withheld', self::amount($fields, 'total_taxes_withheld') > 0); // 3
        self::put($fields, 'taxes_withheld_for_remittance',                                     // 27 = 25 + 26
            self::sum($fields, ['total_taxes_withheld', 'prior_month_adjustment']));
        self::put($fields, 'total_remittances_made',                                            // 30 = 28 + 29
            self::sum($fields, ['previously_remitted_tax', 'other_remittances']));
        self::put($fields, 'tax_still_due',                                                     // 31 = 27 - 30
            self::amount($fields, 'taxes_withheld_for_remittance') - self::amount($fields, 'total_remittances_made'));
        self::put($fields, 'total_penalties', self::sum($fields, ['surcharge', 'interest', 'compromise'])); // 35
        self::put($fields, 'total_amount_due',                                                  // 36 = 31 + 35
            self::amount($fields, 'tax_still_due') + self::amount($fields, 'total_penalties'));

        return $fields;
    }
}
