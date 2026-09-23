<?php

namespace App\Services\BIR\Schemas;

/**
 * BIR Form 1601-C (Jan 2018 ENCS) fields, numbered as printed.
 *
 * Scope: Part I + Part II only (items 1-36).
 *
 * Field shape: key, item (as printed), label, type, source, required, rule,
 * pdf_anchor, options (enum only).
 */
class Form1601CSchema
{
    public const FORM_TYPE = '1601-C';

    /** @return array<int, array<string, mixed>> */
    public static function fields(): array
    {
        return [

            // ── Part I — Background Information ─────────────────────────────
            [
                'key' => 'return_period', 'item' => '1', 'label' => 'For the Month (MM/YYYY)',
                'type' => 'month', 'source' => 'user', 'required' => true,
                'rule' => 'valid MM/YYYY; must match the requested aggregation month',
                'pdf_anchor' => '1601C.1',
            ],
            [
                'key' => 'is_amended', 'item' => '2', 'label' => 'Amended Return?',
                'type' => 'boolean', 'source' => 'user', 'required' => true,
                'rule' => 'defaults false', 'pdf_anchor' => '1601C.2',
            ],
            [
                'key' => 'has_taxes_withheld', 'item' => '3', 'label' => 'Any Taxes Withheld?',
                'type' => 'boolean', 'source' => 'payroll', 'required' => true,
                'rule' => 'true iff total_taxes_withheld (item 25) > 0', 'pdf_anchor' => '1601C.3',
            ],
            [
                'key' => 'sheets_attached', 'item' => '4', 'label' => 'Number of Sheet/s Attached',
                'type' => 'integer', 'source' => 'user', 'required' => true,
                'rule' => 'integer >= 0, defaults 0', 'pdf_anchor' => '1601C.4',
            ],
            [
                'key' => 'atc_code', 'item' => '5', 'label' => 'ATC',
                'type' => 'string', 'source' => 'settings', 'required' => true,
                'rule' => 'fixed constant, printed default WW010', 'pdf_anchor' => '1601C.5',
            ],
            [
                'key' => 'company_tin', 'item' => '6', 'label' => 'Taxpayer Identification Number (TIN)',
                'type' => 'string', 'source' => 'settings', 'required' => true,
                'rule' => 'format ###-###-###-###', 'pdf_anchor' => '1601C.6',
            ],
            [
                'key' => 'rdo_code', 'item' => '7', 'label' => 'RDO Code',
                'type' => 'string', 'source' => 'settings', 'required' => true,
                'rule' => '3-digit code', 'pdf_anchor' => '1601C.7',
            ],
            [
                'key' => 'company_name', 'item' => '8',
                'label' => "Withholding Agent's Name (Last, First, Middle for Individual OR Registered Name for Non-Individual)",
                'type' => 'string', 'source' => 'settings', 'required' => true,
                'rule' => 'non-empty', 'pdf_anchor' => '1601C.8',
            ],
            [
                'key' => 'company_address', 'item' => '9', 'label' => 'Registered Address',
                'type' => 'text', 'source' => 'settings', 'required' => true,
                'rule' => 'non-empty', 'pdf_anchor' => '1601C.9',
            ],
            [
                'key' => 'company_zip', 'item' => '9A', 'label' => 'ZIP Code',
                'type' => 'string', 'source' => 'settings', 'required' => true,
                'rule' => '4 digits', 'pdf_anchor' => '1601C.9A',
            ],
            [
                'key' => 'company_contact_number', 'item' => '10', 'label' => 'Contact Number',
                'type' => 'string', 'source' => 'settings', 'required' => false,
                'rule' => 'PH phone format', 'pdf_anchor' => '1601C.10',
            ],
            [
                'key' => 'agent_category', 'item' => '11', 'label' => 'Category of Withholding Agent',
                'type' => 'enum', 'source' => 'settings', 'required' => true,
                'rule' => 'one of the options', 'pdf_anchor' => '1601C.11',
                'options' => ['private', 'government'],
            ],
            [
                'key' => 'company_email', 'item' => '12', 'label' => 'Email Address',
                'type' => 'string', 'source' => 'settings', 'required' => false,
                'rule' => 'valid email', 'pdf_anchor' => '1601C.12',
            ],
            [
                'key' => 'has_tax_relief', 'item' => '13',
                'label' => 'Are there payees availing of tax relief under Special Law or International Tax Treaty?',
                'type' => 'boolean', 'source' => 'user', 'required' => false,
                'rule' => 'defaults false', 'pdf_anchor' => '1601C.13',
            ],
            [
                'key' => 'tax_relief_details', 'item' => '13A', 'label' => 'If yes, specify',
                'type' => 'text', 'source' => 'user', 'required' => false,
                'rule' => 'required when has_tax_relief = true', 'pdf_anchor' => '1601C.13A',
            ],

            // ── Part II — Computation of Tax ─────────────────────────────────
            [
                'key' => 'total_compensation', 'item' => '14', 'label' => 'Total Amount of Compensation',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '>= 0', 'pdf_anchor' => '1601C.14',
            ],
            [
                'key' => 'mwe_statutory_wage', 'item' => '15',
                'label' => 'Statutory Minimum Wage for Minimum Wage Earners (MWEs)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '>= 0, defaults 0', 'pdf_anchor' => '1601C.15',
            ],
            [
                'key' => 'mwe_premium_pay', 'item' => '16',
                'label' => 'Holiday Pay, Overtime Pay, Night Shift Differential Pay, Hazard Pay (for MWEs only)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '>= 0, defaults 0', 'pdf_anchor' => '1601C.16',
            ],
            [
                'key' => 'thirteenth_month_and_benefits', 'item' => '17',
                'label' => '13th Month Pay and Other Benefits',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '>= 0; from thirteenth_month_records, non-taxable portion only (cap 90,000 — see Form2316Schema item 34)',
                'pdf_anchor' => '1601C.17',
            ],
            [
                'key' => 'de_minimis_benefits', 'item' => '18', 'label' => 'De Minimis Benefits',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '>= 0, defaults 0 — GAP: allowances aren\'t classified as de minimis today; defaults 0',
                'pdf_anchor' => '1601C.18',
            ],
            [
                'key' => 'statutory_contributions_ee', 'item' => '19',
                'label' => "SSS, GSIS, PHIC, HDMF Mandatory Contributions & Union Dues (employee's share only)",
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => "= sum of deductions['SSS EE Contribution'] + ['PhilHealth EE Contribution'] + ['Pag-IBIG EE Contribution'] across the month's payrolls",
                'pdf_anchor' => '1601C.19',
            ],
            [
                'key' => 'other_nontaxable_compensation', 'item' => '20',
                'label' => 'Other Non-Taxable Compensation (specify)',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => '>= 0, defaults 0', 'pdf_anchor' => '1601C.20',
            ],
            [
                'key' => 'other_nontaxable_compensation_desc', 'item' => '20',
                'label' => 'Other Non-Taxable Compensation — description',
                'type' => 'string', 'source' => 'user', 'required' => false,
                'rule' => 'required when other_nontaxable_compensation > 0', 'pdf_anchor' => '1601C.20',
            ],
            [
                'key' => 'total_nontaxable_compensation', 'item' => '21',
                'label' => 'Total Non-Taxable Compensation (Sum of Items 15 to 20)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= sum(15..20)', 'pdf_anchor' => '1601C.21',
            ],
            [
                'key' => 'total_taxable_compensation', 'item' => '22',
                'label' => 'Total Taxable Compensation (Item 14 Less Item 21)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= 14 - 21', 'pdf_anchor' => '1601C.22',
            ],
            [
                'key' => 'exempt_250k_compensation', 'item' => '23',
                'label' => 'Less: Taxable compensation not subject to withholding tax (non-MWE, P250,000 & below for the year)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '>= 0, defaults 0', 'pdf_anchor' => '1601C.23',
            ],
            [
                'key' => 'net_taxable_compensation', 'item' => '24',
                'label' => 'Net Taxable Compensation (Item 22 Less Item 23)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= 22 - 23', 'pdf_anchor' => '1601C.24',
            ],
            [
                'key' => 'total_taxes_withheld', 'item' => '25', 'label' => 'Total Taxes Withheld',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => "= sum of deductions['Withholding Tax'] across the month's payrolls; >= 0",
                'pdf_anchor' => '1601C.25',
            ],
            [
                'key' => 'prior_month_adjustment', 'item' => '26',
                'label' => 'Add/(Less): Adjustment of Taxes Withheld from Previous Month/s (from Part IV Schedule 1, Item 4)',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => 'may be negative; defaults 0 — entered directly, Schedule I is out of scope',
                'pdf_anchor' => '1601C.26',
            ],
            [
                'key' => 'taxes_withheld_for_remittance', 'item' => '27',
                'label' => 'Taxes Withheld for Remittance (Sum of Items 25 and 26)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= 25 + 26', 'pdf_anchor' => '1601C.27',
            ],
            [
                'key' => 'previously_remitted_tax', 'item' => '28',
                'label' => 'Less: Tax Remitted in Return Previously Filed, if this is an amended return',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => 'required when is_amended = true', 'pdf_anchor' => '1601C.28',
            ],
            [
                'key' => 'other_remittances', 'item' => '29', 'label' => 'Other Remittances Made (specify)',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => '>= 0, defaults 0', 'pdf_anchor' => '1601C.29',
            ],
            [
                'key' => 'other_remittances_desc', 'item' => '29', 'label' => 'Other Remittances Made — description',
                'type' => 'string', 'source' => 'user', 'required' => false,
                'rule' => 'required when other_remittances > 0', 'pdf_anchor' => '1601C.29',
            ],
            [
                'key' => 'total_remittances_made', 'item' => '30',
                'label' => 'Total Tax Remittances Made (Sum of Items 28 and 29)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false,
                'rule' => '= 28 + 29; only meaningful when is_amended = true', 'pdf_anchor' => '1601C.30',
            ],
            [
                'key' => 'tax_still_due', 'item' => '31',
                'label' => 'Tax Still Due/(Over-remittance) (Item 27 Less Item 30)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= 27 - 30', 'pdf_anchor' => '1601C.31',
            ],
            [
                'key' => 'surcharge', 'item' => '32', 'label' => 'Surcharge',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => '>= 0, defaults 0 — penalty, not something LAUNCHR computes', 'pdf_anchor' => '1601C.32',
            ],
            [
                'key' => 'interest', 'item' => '33', 'label' => 'Interest',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => '>= 0, defaults 0', 'pdf_anchor' => '1601C.33',
            ],
            [
                'key' => 'compromise', 'item' => '34', 'label' => 'Compromise',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => '>= 0, defaults 0', 'pdf_anchor' => '1601C.34',
            ],
            [
                'key' => 'total_penalties', 'item' => '35', 'label' => 'Total Penalties (Sum of Items 32 to 34)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= sum(32..34)', 'pdf_anchor' => '1601C.35',
            ],
            [
                'key' => 'total_amount_due', 'item' => '36',
                'label' => 'TOTAL AMOUNT STILL DUE/(Over-remittance) (Sum of Items 31 and 35)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= 31 + 35', 'pdf_anchor' => '1601C.36',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> fields keyed by 'key' */
    public static function byKey(): array
    {
        return collect(self::fields())->keyBy('key')->all();
    }

    /** Fields whose value BirAggregationService::monthlyWithholding() must produce. */
    public static function payrollDerivedKeys(): array
    {
        return collect(self::fields())->where('source', 'payroll')->pluck('key')->all();
    }
}
