<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;

/**
 * Checks a draft before it can be submitted, and on demand for the prompting loop.
 *
 * Each error is { field, code, message }. field is always a key in the draft's
 * fields. For missing fields, code is BirMissingFields' reason: required,
 * condition or record_gap. An amount that isn't plain decimal text is
 * invalid_amount. A total that doesn't add up is total_mismatch.
 */
class BirDraftValidator
{
    /**
     * Totals on the 1601-C, written out by hand from Form1601CSchema's rules. The schema's
     * rule text is prose and its item numbers aren't unique, so it is never parsed.
     * Each total => [fields added, fields subtracted, the items it covers in words].
     */
    private const TOTALS_1601C = [
        'total_nontaxable_compensation' => [['mwe_statutory_wage', 'mwe_premium_pay', 'thirteenth_month_and_benefits',
            'de_minimis_benefits', 'statutory_contributions_ee', 'other_nontaxable_compensation'], [], 'items 15 to 20'],
        'total_taxable_compensation' => [['total_compensation'], ['total_nontaxable_compensation'], 'item 14 less item 21'],
        'net_taxable_compensation' => [['total_taxable_compensation'], ['exempt_250k_compensation'], 'item 22 less item 23'],
        'taxes_withheld_for_remittance' => [['total_taxes_withheld', 'prior_month_adjustment'], [], 'items 25 and 26'],
        'total_remittances_made' => [['previously_remitted_tax', 'other_remittances'], [], 'items 28 and 29'],
        'tax_still_due' => [['taxes_withheld_for_remittance'], ['total_remittances_made'], 'item 27 less item 30'],
        'total_penalties' => [['surcharge', 'interest', 'compromise'], [], 'items 32 to 34'],
        'total_amount_due' => [['tax_still_due', 'total_penalties'], [], 'items 31 and 35'],
    ];

    /** Totals on the 2316, the same way: written out from Form2316Schema's rules, never parsed. */
    private const TOTALS_2316 = [
        'gross_compensation_present' => [['nontax_total', 'tax_regular_total'], [], 'items 38 and 52'],
        'less_nontaxable_present' => [['nontax_total'], [], 'item 38'],
        'taxable_income_present' => [['gross_compensation_present'], ['less_nontaxable_present'], 'item 19 less item 20'],
        'gross_taxable_income' => [['taxable_income_present', 'taxable_income_previous_employer'], [], 'items 21 and 22'],
        'total_taxes_withheld_adjusted' => [['taxes_withheld_present', 'taxes_withheld_previous'], [], 'items 25A and 25B'],
        'total_taxes_withheld_final' => [['total_taxes_withheld_adjusted', 'pera_tax_credit'], [], 'items 26 and 27'],
        'nontax_total' => [['nontax_mwe_basic', 'nontax_mwe_holiday', 'nontax_mwe_overtime', 'nontax_mwe_night_diff',
            'nontax_mwe_hazard', 'nontax_thirteenth_month', 'nontax_de_minimis', 'nontax_statutory_contributions',
            'nontax_other_mwe_compensation'], [], 'items 29 to 37'],
        'tax_regular_total' => [['tax_basic_salary', 'tax_representation', 'tax_transportation', 'tax_cola', 'tax_housing',
            'tax_others_44a_amount', 'tax_others_44b_amount', 'tax_commission', 'tax_profit_sharing', 'tax_directors_fees',
            'tax_thirteenth_month_excess', 'tax_hazard_pay', 'tax_overtime', 'tax_others_51a_amount', 'tax_others_51b_amount'],
            [], 'items 39 to 51B'],
    ];

    public function __construct(private BirConversationService $conversation)
    {
    }

    /** @return array<int, array{field: string, code: string, message: string}> */
    public function validate(BirFormDraft $draft): array
    {
        $schema = $draft->form_type === Form1601CSchema::FORM_TYPE ? Form1601CSchema::byKey() : Form2316Schema::byKey();

        $errors = array_map(fn (array $missing) => [
            'field' => $missing['key'],
            'code' => $missing['reason'],
            'message' => $this->missingMessage($missing, $schema),
        ], $this->conversation->missingFields($draft));

        $totals = $draft->form_type === Form1601CSchema::FORM_TYPE ? self::TOTALS_1601C : self::TOTALS_2316;
        $errors = [
            ...$errors,
            ...$this->invalidAmountErrors($draft->fields ?? [], $schema),
            ...$this->totalErrors($totals, $draft->fields ?? [], $schema),
        ];

        // Form order, so the list reads top to bottom like the printed form.
        $position = array_flip(array_keys($schema));
        usort($errors, fn (array $a, array $b) => $position[$a['field']] <=> $position[$b['field']]);

        return $errors;
    }

    /**
     * One error per amount field holding something other than plain decimal text, the
     * agreed money format ("842300.00"): commas, currency signs and words are refused.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<int, array{field: string, code: string, message: string}>
     */
    private function invalidAmountErrors(array $fields, array $schema): array
    {
        $errors = [];

        foreach ($schema as $key => $field) {
            if ($field['type'] !== 'decimal' || $this->isBlank($fields[$key] ?? null) || $this->centavos($fields[$key]) !== null) {
                continue;
            }
            $errors[] = [
                'field' => $key,
                'code' => 'invalid_amount',
                'message' => "\"{$field['label']}\" must be an amount like 1234.50, without commas or a currency sign.",
            ];
        }

        return $errors;
    }

    /**
     * One error per total that doesn't equal the sum of its parts. Compared in whole
     * centavos so rounding can't cause a false error. A total, or a required part, that is
     * still blank is skipped: the missing-fields rule already reports it. An optional part
     * left blank counts as 0, which is what the printed form shows for it. A total with an
     * invalid amount in it is skipped too; invalid_amount already reports that.
     *
     * @param  array<string, array{0: array<int, string>, 1: array<int, string>, 2: string}>  $totals
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<int, array{field: string, code: string, message: string}>
     */
    private function totalErrors(array $totals, array $fields, array $schema): array
    {
        $errors = [];

        foreach ($totals as $total => [$plus, $minus, $items]) {
            $actual = $this->centavos($fields[$total] ?? null);
            if ($actual === null) {
                continue;
            }

            $expected = 0;
            foreach ([[$plus, 1], [$minus, -1]] as [$keys, $sign]) {
                foreach ($keys as $key) {
                    $part = $this->centavos($fields[$key] ?? null);
                    if ($part === null && !$schema[$key]['required'] && $this->isBlank($fields[$key] ?? null)) {
                        $part = 0;
                    }
                    if ($part === null) {
                        continue 3;
                    }
                    $expected += $sign * $part;
                }
            }

            if ($expected !== $actual) {
                $errors[] = [
                    'field' => $total,
                    'code' => 'total_mismatch',
                    'message' => "\"{$schema[$total]['label']}\" should be {$this->money($expected)} ({$items}) but is {$this->money($actual)}.",
                ];
            }
        }

        return $errors;
    }

    /** A field entry's amount in whole centavos, or null if it is blank or not a valid amount. */
    private function centavos(mixed $entry): ?int
    {
        $value = is_array($entry) ? ($entry['value'] ?? null) : null;
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && preg_match('/^-?\d+(\.\d{1,2})?$/', $value) === 1
            ? (int) round((float) $value * 100)
            : null;
    }

    private function isBlank(mixed $entry): bool
    {
        $value = is_array($entry) ? ($entry['value'] ?? null) : null;

        return $value === null || $value === '';
    }

    private function money(int $centavos): string
    {
        return number_format($centavos / 100, 2);
    }

    /**
     * A record_gap is fixed in the employee record or company settings, not on the
     * draft, so its message says where the value should have come from. A condition
     * message says which answer made the field required.
     *
     * @param  array<string, mixed>  $missing  one entry from BirMissingFields::detect()
     * @param  array<string, array<string, mixed>>  $schema
     */
    private function missingMessage(array $missing, array $schema): string
    {
        $label = "\"{$missing['label']}\"";

        if ($missing['reason'] === BirMissingFields::REASON_RECORD_GAP) {
            $where = $missing['source'] === 'settings' ? 'the company settings' : 'the employee or payroll records';

            return "{$label} should come from {$where}, but nothing was found there. Fix the record, or enter it here.";
        }

        if ($missing['reason'] === BirMissingFields::REASON_CONDITION) {
            return "{$label} is required because " . $this->conditionText($missing['required_when'], $schema) . '.';
        }

        return "{$label} is required.";
    }

    /**
     * @param  array<string, mixed>  $condition  a required_when that detect() found met
     * @param  array<string, array<string, mixed>>  $schema
     */
    private function conditionText(array $condition, array $schema): string
    {
        if (($condition['context'] ?? null) === 'hired_in_year') {
            return 'the employee was hired during the year';
        }

        $other = "\"{$schema[$condition['field']]['label']}\"";

        return match (true) {
            array_key_exists('equals', $condition) => "{$other} is " . match ($condition['equals']) { true => 'yes', false => 'no', default => (string) $condition['equals'] },
            array_key_exists('gt', $condition) => "{$other} is above " . number_format((float) $condition['gt'], 2, '.', ''),
            default => "{$other} is filled in",
        };
    }
}
