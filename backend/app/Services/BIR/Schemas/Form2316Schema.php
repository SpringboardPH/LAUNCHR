<?php

namespace App\Services\BIR\Schemas;

/**
 * BIR Form 2316 (Sep 2021 ENCS) fields, numbered as printed. Same field
 * shape and source/pdf_anchor conventions as Form1601CSchema.
 */
class Form2316Schema
{
    public const FORM_TYPE = '2316';

    /** @return array<int, array<string, mixed>> */
    public static function fields(): array
    {
        return [

            // ── Part I — Employee Information ────────────────────────────────
            [
                'key' => 'tax_year', 'item' => '1', 'label' => 'For the Year (YYYY)',
                'type' => 'integer', 'source' => 'user', 'required' => true,
                'rule' => '4-digit year', 'pdf_anchor' => '2316.1',
            ],
            [
                'key' => 'period_from', 'item' => '2', 'label' => 'For the Period From (MM/DD)',
                'type' => 'string', 'source' => 'user', 'required' => false,
                'rule' => 'required only when the employee did not work the full year at this employer',
                'pdf_anchor' => '2316.2.from',
            ],
            [
                'key' => 'period_to', 'item' => '2', 'label' => 'For the Period To (MM/DD)',
                'type' => 'string', 'source' => 'user', 'required' => false,
                'rule' => 'required with period_from', 'pdf_anchor' => '2316.2.to',
            ],
            [
                'key' => 'employee_tin', 'item' => '3', 'label' => 'TIN',
                'type' => 'string', 'source' => 'payroll', 'required' => true,
                'rule' => 'from employees.tin_number, format ###-###-###-###', 'pdf_anchor' => '2316.3',
            ],
            [
                'key' => 'employee_last_name', 'item' => '4', 'label' => "Employee's Name — Last Name",
                'type' => 'string', 'source' => 'payroll', 'required' => true,
                'rule' => 'from employees.last_name', 'pdf_anchor' => '2316.4.last',
            ],
            [
                'key' => 'employee_first_name', 'item' => '4', 'label' => "Employee's Name — First Name",
                'type' => 'string', 'source' => 'payroll', 'required' => true,
                'rule' => 'from employees.first_name', 'pdf_anchor' => '2316.4.first',
            ],
            [
                'key' => 'employee_middle_name', 'item' => '4', 'label' => "Employee's Name — Middle Name",
                'type' => 'string', 'source' => 'user', 'required' => false,
                'rule' => 'GAP: employees has no middle_name column today', 'pdf_anchor' => '2316.4.middle',
            ],
            [
                'key' => 'employee_rdo_code', 'item' => '5', 'label' => 'RDO Code',
                'type' => 'string', 'source' => 'user', 'required' => true,
                'rule' => 'GAP: no rdo_code column on employees; differs from company_rdo_code', 'pdf_anchor' => '2316.5',
            ],
            [
                'key' => 'basic_salary_annual', 'item' => null,
                'label' => 'Basic Salary (incl. the exempt P250,000 & below, or the Statutory MW of the MWE)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '>= 0; annual sum from payrolls for the tax year', 'pdf_anchor' => '2316.basic_salary_box',
            ],
            [
                'key' => 'employee_registered_address', 'item' => '6', 'label' => 'Registered Address',
                'type' => 'text', 'source' => 'user', 'required' => true,
                'rule' => 'GAP: employees has no address column today', 'pdf_anchor' => '2316.6',
            ],
            [
                'key' => 'employee_registered_zip', 'item' => '6A', 'label' => 'ZIP Code',
                'type' => 'string', 'source' => 'user', 'required' => false,
                'rule' => '4 digits', 'pdf_anchor' => '2316.6A',
            ],
            [
                'key' => 'employee_home_address', 'item' => '6B', 'label' => 'Local Home Address',
                'type' => 'text', 'source' => 'user', 'required' => false,
                'rule' => 'optional, only if different from registered address', 'pdf_anchor' => '2316.6B',
            ],
            [
                'key' => 'employee_home_zip', 'item' => '6C', 'label' => 'ZIP Code',
                'type' => 'string', 'source' => 'user', 'required' => false,
                'rule' => '4 digits', 'pdf_anchor' => '2316.6C',
            ],
            [
                'key' => 'employee_foreign_address', 'item' => '6D', 'label' => 'Foreign Address',
                'type' => 'text', 'source' => 'user', 'required' => false,
                'rule' => 'optional, resident-foreign employees only', 'pdf_anchor' => '2316.6D',
            ],
            [
                'key' => 'employee_birthdate', 'item' => '7', 'label' => 'Date of Birth (MM/DD/YYYY)',
                'type' => 'date', 'source' => 'user', 'required' => true,
                'rule' => 'GAP: employees has no birthdate column today', 'pdf_anchor' => '2316.7',
            ],
            [
                'key' => 'employee_contact_number', 'item' => '8', 'label' => 'Contact Number',
                'type' => 'string', 'source' => 'payroll', 'required' => false,
                'rule' => 'from employees.phone', 'pdf_anchor' => '2316.8',
            ],
            [
                'key' => 'is_mwe', 'item' => null,
                'label' => 'Minimum Wage Earner (MWE) whose compensation is exempt from withholding tax',
                'type' => 'boolean', 'source' => 'user', 'required' => true,
                'rule' => 'GAP: no MWE flag or minimum-wage table; must be asked, defaults false', 'pdf_anchor' => '2316.mwe_checkbox',
            ],
            [
                'key' => 'mwe_daily_rate', 'item' => '9', 'label' => 'Statutory Minimum Wage rate per day',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => 'required when is_mwe = true', 'pdf_anchor' => '2316.9',
            ],
            [
                'key' => 'mwe_monthly_rate', 'item' => '10', 'label' => 'Statutory Minimum Wage rate per month',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => 'required when is_mwe = true', 'pdf_anchor' => '2316.10',
            ],

            // ── Part II — Employer Information (Present) ─────────────────────
            [
                'key' => 'employer_type', 'item' => '11', 'label' => 'Type of Employer',
                'type' => 'enum', 'source' => 'settings', 'required' => true,
                'rule' => 'defaults main', 'pdf_anchor' => '2316.11', 'options' => ['main', 'secondary'],
            ],
            [
                'key' => 'present_employer_tin', 'item' => '12', 'label' => 'TIN',
                'type' => 'string', 'source' => 'settings', 'required' => true,
                'rule' => 'company_tin', 'pdf_anchor' => '2316.12',
            ],
            [
                'key' => 'present_employer_name', 'item' => '13', "label" => "Employer's Name",
                'type' => 'string', 'source' => 'settings', 'required' => true,
                'rule' => 'company_name', 'pdf_anchor' => '2316.13',
            ],
            [
                'key' => 'present_employer_address', 'item' => '14', 'label' => 'Registered Address',
                'type' => 'text', 'source' => 'settings', 'required' => true,
                'rule' => 'company_address', 'pdf_anchor' => '2316.14',
            ],
            [
                'key' => 'present_employer_zip', 'item' => '14A', 'label' => 'ZIP Code',
                'type' => 'string', 'source' => 'settings', 'required' => true,
                'rule' => 'company_zip', 'pdf_anchor' => '2316.14A',
            ],

            // ── Part III — Employer Information (Previous) ───────────────────
            // Mid-year hires only; LAUNCHR must ask, not derive.
            [
                'key' => 'previous_employer_tin', 'item' => '16', 'label' => 'TIN',
                'type' => 'string', 'source' => 'user', 'required' => false,
                'rule' => 'required when the employee was hired mid-year and had a prior employer', 'pdf_anchor' => '2316.16',
            ],
            [
                'key' => 'previous_employer_name', 'item' => '17', 'label' => "Employer's Name",
                'type' => 'string', 'source' => 'user', 'required' => false,
                'rule' => 'required with previous_employer_tin', 'pdf_anchor' => '2316.17',
            ],
            [
                'key' => 'previous_employer_address', 'item' => '18', 'label' => 'Registered Address',
                'type' => 'text', 'source' => 'user', 'required' => false,
                'rule' => 'optional even when previous_employer_tin is present', 'pdf_anchor' => '2316.18',
            ],
            [
                'key' => 'previous_employer_zip', 'item' => '18A', 'label' => 'ZIP Code',
                'type' => 'string', 'source' => 'user', 'required' => false,
                'rule' => '4 digits', 'pdf_anchor' => '2316.18A',
            ],

            // ── Part IV-B, Section A — Non-Taxable/Exempt Compensation Income ─
            [
                'key' => 'nontax_mwe_basic', 'item' => '29', 'label' => 'Basic Salary (MWE) / Statutory Minimum Wage',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false,
                'rule' => '>= 0; required when is_mwe = true', 'pdf_anchor' => '2316.29',
            ],
            [
                'key' => 'nontax_mwe_holiday', 'item' => '30', 'label' => 'Holiday Pay (MWE)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.30',
            ],
            [
                'key' => 'nontax_mwe_overtime', 'item' => '31', 'label' => 'Overtime Pay (MWE)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.31',
            ],
            [
                'key' => 'nontax_mwe_night_diff', 'item' => '32', 'label' => 'Night Shift Differential (MWE)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.32',
            ],
            [
                'key' => 'nontax_mwe_hazard', 'item' => '33', 'label' => 'Hazard Pay (MWE)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.33',
            ],
            [
                'key' => 'nontax_thirteenth_month', 'item' => '34',
                'label' => '13th Month Pay and Other Benefits (maximum of P90,000)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= min(thirteenth_month_records.basic_pay total for the year, 90000)', 'pdf_anchor' => '2316.34',
            ],
            [
                'key' => 'nontax_de_minimis', 'item' => '35', 'label' => 'De Minimis Benefits',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false,
                'rule' => '>= 0, defaults 0 — same GAP as Form1601CSchema item 18', 'pdf_anchor' => '2316.35',
            ],
            [
                'key' => 'nontax_statutory_contributions', 'item' => '36',
                'label' => 'SSS, GSIS, PHIC & PAG-IBIG Contributions and Union Dues (Employee share only)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => "= annual sum of deductions['SSS EE Contribution'] + ['PhilHealth EE Contribution'] + ['Pag-IBIG EE Contribution']",
                'pdf_anchor' => '2316.36',
            ],
            [
                'key' => 'nontax_other_mwe_compensation', 'item' => '37', 'label' => 'Salaries and Other Forms of Compensation',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false,
                'rule' => '>= 0, defaults 0; MWE-only additional non-taxable pay', 'pdf_anchor' => '2316.37',
            ],
            [
                'key' => 'nontax_total', 'item' => '38',
                'label' => 'Total Non-Taxable/Exempt Compensation Income (Sum of Items 29 to 37)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= sum(29..37)', 'pdf_anchor' => '2316.38',
            ],

            // ── Part IV-B, Section B — Taxable Compensation Income (Regular) ──
            [
                'key' => 'tax_basic_salary', 'item' => '39', 'label' => 'Basic Salary',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '>= 0; annual taxable basic salary', 'pdf_anchor' => '2316.39',
            ],
            [
                'key' => 'tax_representation', 'item' => '40', 'label' => 'Representation',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.40',
            ],
            [
                'key' => 'tax_transportation', 'item' => '41', 'label' => 'Transportation',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.41',
            ],
            [
                'key' => 'tax_cola', 'item' => '42', 'label' => 'Cost of Living Allowance (COLA)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.42',
            ],
            [
                'key' => 'tax_housing', 'item' => '43', 'label' => 'Fixed Housing Allowance',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.43',
            ],
            [
                'key' => 'tax_overtime', 'item' => '44A', 'label' => 'Overtime Pay',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false,
                'rule' => ">= 0; from annual sum of allowances entries labelled 'Overtime Pay'", 'pdf_anchor' => '2316.44A',
            ],
            [
                'key' => 'tax_commission', 'item' => '44B', 'label' => 'Commission',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.44B',
            ],
            [
                'key' => 'tax_profit_sharing', 'item' => '45', 'label' => 'Profit Sharing',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.45',
            ],
            [
                'key' => 'tax_directors_fees', 'item' => '46', 'label' => "Fees Including Director's Fees",
                'type' => 'decimal', 'source' => 'payroll', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.46',
            ],
            [
                'key' => 'tax_thirteenth_month_excess', 'item' => '47', 'label' => 'Taxable 13th Month Benefits',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= max(0, annual thirteenth_month_records total - 90000)', 'pdf_anchor' => '2316.47',
            ],
            [
                'key' => 'tax_hazard_pay', 'item' => '48', 'label' => 'Hazard Pay',
                'type' => 'decimal', 'source' => 'payroll', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.48',
            ],
            [
                'key' => 'tax_other_unlabeled_49', 'item' => '49', 'label' => 'TODO_VERIFY — label not recovered from extraction',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => 'TODO_VERIFY against printed form before M1; provisionally treated as a manual catch-all',
                'pdf_anchor' => '2316.49',
            ],
            [
                'key' => 'tax_others_desc', 'item' => '50', 'label' => 'Others (specify)',
                'type' => 'string', 'source' => 'user', 'required' => false, 'rule' => 'free text',
                'pdf_anchor' => '2316.50.desc',
            ],
            [
                'key' => 'tax_others_amount', 'item' => '50', 'label' => 'Others — amount',
                'type' => 'decimal', 'source' => 'user', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.50.amount',
            ],
            [
                'key' => 'tax_others_51a_desc', 'item' => '51A', 'label' => 'Others (specify), row A',
                'type' => 'string', 'source' => 'user', 'required' => false, 'rule' => 'free text',
                'pdf_anchor' => '2316.51A.desc',
            ],
            [
                'key' => 'tax_others_51a_amount', 'item' => '51A', 'label' => 'Others, row A — amount',
                'type' => 'decimal', 'source' => 'user', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.51A.amount',
            ],
            [
                'key' => 'tax_others_51b_desc', 'item' => '51B', 'label' => 'Others (specify), row B',
                'type' => 'string', 'source' => 'user', 'required' => false, 'rule' => 'free text',
                'pdf_anchor' => '2316.51B.desc',
            ],
            [
                'key' => 'tax_others_51b_amount', 'item' => '51B', 'label' => 'Others, row B — amount',
                'type' => 'decimal', 'source' => 'user', 'required' => false, 'rule' => '>= 0, defaults 0',
                'pdf_anchor' => '2316.51B.amount',
            ],
            [
                'key' => 'tax_regular_total', 'item' => '52',
                'label' => 'Total Taxable Compensation Income Regular (Sum of Items 39 to 51B)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => 'TODO_VERIFY item number (see class docblock); = sum(39..51B)', 'pdf_anchor' => '2316.52',
            ],

            // ── Part IV-A — Summary ───────────────────────────────────────────
            [
                'key' => 'gross_compensation_present', 'item' => '19',
                'label' => 'Gross Compensation Income from Present Employer (Sum of Items 38 and 52)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= 38 + 52', 'pdf_anchor' => '2316.19',
            ],
            [
                'key' => 'less_nontaxable_present', 'item' => '20',
                'label' => 'Less: Total Non-Taxable/Exempt Compensation Income from Present Employer (from Item 38)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= item 38', 'pdf_anchor' => '2316.20',
            ],
            [
                'key' => 'taxable_income_present', 'item' => '21',
                'label' => 'Taxable Compensation Income from Present Employer (Item 19 Less Item 20) (from Item 52)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= 19 - 20; should equal item 52', 'pdf_anchor' => '2316.21',
            ],
            [
                'key' => 'taxable_income_previous_employer', 'item' => '22',
                'label' => 'Add: Taxable Compensation Income from Previous Employer, if applicable',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => 'required when the employee was hired mid-year with a previous employer', 'pdf_anchor' => '2316.22',
            ],
            [
                'key' => 'gross_taxable_income', 'item' => '23', 'label' => 'Gross Taxable Compensation Income (Sum of Items 21 and 22)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= 21 + 22', 'pdf_anchor' => '2316.23',
            ],
            [
                'key' => 'tax_due', 'item' => '24', 'label' => 'Tax Due',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => 'GAP: PayrollService has no annual withholding bracket yet', 'pdf_anchor' => '2316.24',
            ],
            [
                'key' => 'taxes_withheld_present', 'item' => '25A', 'label' => 'Amount of Taxes Withheld — Present Employer',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => "= annual sum of deductions['Withholding Tax'] across this employer's payrolls for the year",
                'pdf_anchor' => '2316.25A',
            ],
            [
                'key' => 'taxes_withheld_previous', 'item' => '25B', 'label' => 'Amount of Taxes Withheld — Previous Employer, if applicable',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => 'required when taxable_income_previous_employer > 0', 'pdf_anchor' => '2316.25B',
            ],
            [
                'key' => 'total_taxes_withheld_adjusted', 'item' => '26',
                'label' => 'Total Amount of Taxes Withheld as adjusted (Sum of Items 25A and 25B)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => 'TODO_VERIFY item number (see class docblock); = 25A + 25B', 'pdf_anchor' => '2316.26',
            ],
            [
                'key' => 'pera_tax_credit', 'item' => '27', 'label' => '5% Tax Credit (PERA Act of 2008)',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => '>= 0, defaults 0 — no PERA tracking in LAUNCHR today', 'pdf_anchor' => '2316.27',
            ],
            [
                'key' => 'total_taxes_withheld_final', 'item' => '28', 'label' => 'Total Taxes Withheld (Sum of Items 26 and 27)',
                'type' => 'decimal', 'source' => 'payroll', 'required' => true,
                'rule' => '= 26 + 27', 'pdf_anchor' => '2316.28',
            ],

            // ── Declaration / signatures ──────────────────────────────────────
            [
                'key' => 'is_substituted_filing', 'item' => null, 'label' => 'Qualified under substituted filing of ITR',
                'type' => 'boolean', 'source' => 'user', 'required' => false,
                'rule' => 'declaration the user makes, not derivable — defaults false', 'pdf_anchor' => '2316.substituted_filing',
            ],
            [
                'key' => 'employee_signature_date', 'item' => '53', 'label' => 'Employee Signature over Printed Name, Date Signed',
                'type' => 'date', 'source' => 'user', 'required' => false,
                'rule' => 'wet/e-signature — outside system scope', 'pdf_anchor' => '2316.53',
            ],
            [
                'key' => 'employer_signature_date', 'item' => '54',
                'label' => 'Present Employer/Authorized Agent Signature over Printed Name, Date Signed',
                'type' => 'date', 'source' => 'user', 'required' => false,
                'rule' => 'wet/e-signature — outside system scope', 'pdf_anchor' => '2316.54',
            ],
            [
                'key' => 'employee_ctc_or_id', 'item' => '55', 'label' => 'CTC/Valid ID No. of Employee',
                'type' => 'string', 'source' => 'user', 'required' => false,
                'rule' => 'required only under substituted filing', 'pdf_anchor' => '2316.55',
            ],
            [
                'key' => 'ctc_place_of_issue', 'item' => '56', 'label' => 'Place of Issue',
                'type' => 'string', 'source' => 'user', 'required' => false,
                'rule' => 'required with employee_ctc_or_id', 'pdf_anchor' => '2316.56.place',
            ],
            [
                'key' => 'ctc_amount_paid', 'item' => '56', 'label' => 'Amount paid, if CTC',
                'type' => 'decimal', 'source' => 'user', 'required' => false,
                'rule' => 'required only when employee_ctc_or_id is a CTC number', 'pdf_anchor' => '2316.56.amount',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> fields keyed by 'key' */
    public static function byKey(): array
    {
        return collect(self::fields())->keyBy('key')->all();
    }

    /** Fields whose value BirAggregationService::annualCompensation() must produce. */
    public static function payrollDerivedKeys(): array
    {
        return collect(self::fields())->where('source', 'payroll')->pluck('key')->all();
    }

    /** Fields flagged TODO_VERIFY — surface these in code review, don't silently trust them. */
    public static function unverifiedKeys(): array
    {
        return collect(self::fields())
            ->filter(fn (array $f) => str_contains($f['rule'], 'TODO_VERIFY'))
            ->pluck('key')
            ->all();
    }
}
