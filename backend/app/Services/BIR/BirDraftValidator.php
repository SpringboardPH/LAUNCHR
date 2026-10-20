<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Services\BIR\Schemas\FieldFormat;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;

/**
 * Checks a draft before it can be submitted, and on demand for the prompting loop.
 *
 * Each error is { field, code, severity, message }. field is always a key in the draft's
 * fields. For missing fields, code is BirMissingFields' reason: required,
 * condition or record_gap. An amount that isn't plain decimal text is
 * invalid_amount. Text that doesn't follow its field's format (TIN, RDO, ZIP, phone,
 * email, MM/DD) is invalid_format. A total that doesn't add up is total_mismatch. Tax withheld that the
 * payroll the draft was built from doesn't support is payroll_mismatch. A draft built
 * from a period with no counted payroll is empty_period. A field with a schema 'fixed'
 * value (1601-C items 13, 13A) that doesn't hold it is fixed_value. A system_filled field
 * (1601-C item 2) that doesn't hold the value the draft's version gives it is system_value.
 * A 2316 whose item 2 "From" is after its "To" is period_order.
 *
 * severity is "error" (blocks submit) or "warning" (shown, never blocks); see WARNING_CODES.
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

    /** The tax-withheld field checked against the draft's payroll snapshot: 1601-C item 25, 2316 item 25A. */
    private const PAYROLL_TAX_FIELDS = [
        Form1601CSchema::FORM_TYPE => 'total_taxes_withheld',
        Form2316Schema::FORM_TYPE => 'taxes_withheld_present',
    ];

    /** The period field an empty_period error points at: item 1 on both forms. */
    private const PERIOD_FIELDS = [
        Form1601CSchema::FORM_TYPE => 'return_period',
        Form2316Schema::FORM_TYPE => 'tax_year',
    ];

    /**
     * Codes that are warnings; every other code is an error. Both stay warnings until the
     * accountant confirms whether 2316 item 25A is 0 after a year-end refund, and whether a
     * nil 1601-C must be filed for a month with no payroll.
     */
    private const WARNING_CODES = [
        'payroll_mismatch',
        'empty_period',
    ];

    /** The money format (contract §7): digits, up to two decimals, an optional leading minus. Shared with UpdateBirDraftFieldsRequest. */
    public const AMOUNT_PATTERN = '/^-?\d+(\.\d{1,2})?$/';

    public function __construct(private BirConversationService $conversation)
    {
    }

    /** @return array<int, array{field: string, code: string, severity: string, message: string}> */
    public function validate(BirFormDraft $draft): array
    {
        $schema = $draft->form_type === Form1601CSchema::FORM_TYPE ? Form1601CSchema::byKey() : Form2316Schema::byKey();

        // A fixed or system-filled field can't be entered, so a missing one is reported as
        // fixed_value or system_value only, not with a missing-field message saying to enter it.
        $missing = array_filter(
            $this->conversation->missingFields($draft),
            fn (array $missing) => !array_key_exists('fixed', $schema[$missing['key']] ?? [])
                && empty($schema[$missing['key']]['system_filled']),
        );
        $errors = array_map(fn (array $missing) => [
            'field' => $missing['key'],
            'code' => $missing['reason'],
            'message' => $this->missingMessage($missing, $schema),
        ], array_values($missing));

        $totals = $draft->form_type === Form1601CSchema::FORM_TYPE ? self::TOTALS_1601C : self::TOTALS_2316;
        $errors = [
            ...$errors,
            ...$this->fixedValueErrors($draft->fields ?? [], $schema),
            ...$this->systemValueErrors($draft, $schema),
            ...$this->invalidAmountErrors($draft->fields ?? [], $schema),
            ...$this->invalidFormatErrors($draft->fields ?? [], $schema),
            ...$this->periodOrderErrors($draft, $schema),
            ...$this->totalErrors($totals, $draft->fields ?? [], $schema),
            ...$this->payrollMismatchErrors($draft, $schema),
            ...$this->emptyPeriodErrors($draft),
        ];

        $errors = array_map(fn (array $error) => [
            'field' => $error['field'],
            'code' => $error['code'],
            'severity' => in_array($error['code'], self::WARNING_CODES, true) ? 'warning' : 'error',
            'message' => $error['message'],
        ], $errors);

        // Form order, so the list reads top to bottom like the printed form.
        $position = array_flip(array_keys($schema));
        usort($errors, fn (array $a, array $b) => $position[$a['field']] <=> $position[$b['field']]);

        return $errors;
    }

    /**
     * One error per field with a schema 'fixed' value that the draft doesn't hold exactly.
     * New drafts are filled with it, and PUT refuses any answer to it, so this catches drafts
     * made before the field was fixed. A missing, pending or blank value is wrong too, except
     * on a fixed blank (null, item 13A), where it is the right answer and only text is wrong.
     * A yes/no value is read the way BirMissingFields reads it, so "yes" counts as true.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<int, array{field: string, code: string, message: string}>
     */
    private function fixedValueErrors(array $fields, array $schema): array
    {
        $errors = [];

        foreach ($schema as $key => $field) {
            if (!array_key_exists('fixed', $field)) {
                continue;
            }

            $entry = $fields[$key] ?? null;
            $value = null;
            if (is_array($entry) && ($entry['origin'] ?? 'pending') !== 'pending' && !$this->isBlank($entry)) {
                $value = $entry['value'];
                if ($field['type'] === 'boolean' && !is_bool($value)) {
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                }
            }
            if ($value === $field['fixed']) {
                continue;
            }

            $fixed = match (true) {
                $field['fixed'] === null => 'blank',
                is_bool($field['fixed']) => $field['fixed'] ? 'Yes' : 'No',
                default => (string) $field['fixed'],
            };
            $errors[] = [
                'field' => $key,
                'code' => 'fixed_value',
                'message' => "\"{$field['label']}\" must be {$fixed}. Create a new draft for this period to fix it.",
            ];
        }

        return $errors;
    }

    /**
     * One error per system_filled field that doesn't hold the value the draft itself gives it.
     * 1601-C item 2 "Amended Return?" is No on version 1 and Yes on every later version. New
     * drafts and revisions are filled with it, and PUT refuses any answer to it, so this catches
     * older drafts. A missing, pending or blank value is wrong too. A system_filled field this
     * check doesn't know the rule for is left alone rather than guessed.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<int, array{field: string, code: string, message: string}>
     */
    private function systemValueErrors(BirFormDraft $draft, array $schema): array
    {
        $errors = [];

        foreach ($schema as $key => $field) {
            if (empty($field['system_filled']) || $key !== 'is_amended') {
                continue;
            }

            $amended = $draft->version > 1;
            $entry = ($draft->fields ?? [])[$key] ?? null;
            $value = null;
            if (is_array($entry) && ($entry['origin'] ?? 'pending') !== 'pending' && !$this->isBlank($entry)) {
                $value = is_bool($entry['value'])
                    ? $entry['value']
                    : filter_var($entry['value'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
            if ($value === $amended) {
                continue;
            }

            $errors[] = [
                'field' => $key,
                'code' => 'system_value',
                'message' => $amended
                    ? "\"{$field['label']}\" must be Yes on an amended return (version {$draft->version})."
                    : "\"{$field['label']}\" must be No on the original return.",
            ];
        }

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
     * One error per field with a schema 'format' whose stored text doesn't follow it, the
     * same rule PUT checks on save (UpdateBirDraftFieldsRequest). This catches values PUT
     * never saw: ones saved before formats were checked, and figures filled from payroll or
     * settings. A blank value isn't checked; the missing-fields rule reports those.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<int, array{field: string, code: string, message: string}>
     */
    private function invalidFormatErrors(array $fields, array $schema): array
    {
        $errors = [];

        foreach ($schema as $key => $field) {
            if (!isset($field['format']) || $this->isBlank($fields[$key] ?? null)
                || FieldFormat::matches($field['format'], $fields[$key]['value'])) {
                continue;
            }
            $errors[] = [
                'field' => $key,
                'code' => 'invalid_format',
                'message' => "\"{$field['label']}\" must be " . FieldFormat::describe($field['format']) . '.',
            ];
        }

        return $errors;
    }

    /**
     * One error on a 2316 whose item 2 "From" is after its "To" (10/15 to 03/31); the same day is
     * fine. Skipped while either is blank or not MM/DD, which other rules report. PUT doesn't refuse
     * it, so From and To can be fixed in either order. Zero-padded MM/DD compares as month, then day.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<int, array{field: string, code: string, message: string}>
     */
    private function periodOrderErrors(BirFormDraft $draft, array $schema): array
    {
        if ($draft->form_type !== Form2316Schema::FORM_TYPE) {
            return [];
        }

        [$from, $to] = [$schema['period_from'], $schema['period_to']];
        $fromValue = ($draft->fields ?? [])['period_from']['value'] ?? null;
        $toValue = ($draft->fields ?? [])['period_to']['value'] ?? null;
        if (!FieldFormat::matches($from['format'], $fromValue) || !FieldFormat::matches($to['format'], $toValue)
            || strcmp($fromValue, $toValue) <= 0) {
            return [];
        }

        return [[
            'field' => 'period_from',
            'code' => 'period_order',
            'message' => "\"{$from['label']}\" ({$fromValue}) is after \"{$to['label']}\" ({$toValue}).",
        ]];
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

    /**
     * One error if the form's tax withheld differs from what the payroll frozen onto
     * source_snapshot at creation withheld. Live payroll is never read, so later payroll
     * edits don't matter. Skipped when there is no snapshot (revisions, seeded fixtures),
     * and when the field is blank or invalid, which other rules already report.
     *
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<int, array{field: string, code: string, message: string}>
     */
    private function payrollMismatchErrors(BirFormDraft $draft, array $schema): array
    {
        $rows = $draft->source_snapshot['rows'] ?? null;
        $field = self::PAYROLL_TAX_FIELDS[$draft->form_type];
        $actual = $this->centavos(($draft->fields ?? [])[$field] ?? null);
        if (!is_array($rows) || $actual === null) {
            return [];
        }

        $withheld = $this->snapshotTaxCentavos($rows);
        // On the 2316, item 25A is what payroll withheld less the year-end refund (Form2316Mapper).
        $refund = $draft->form_type === Form2316Schema::FORM_TYPE
            ? ($this->centavos(($draft->fields ?? [])['year_end_refund'] ?? null) ?? 0)
            : 0;
        if ($withheld - $refund === $actual) {
            return [];
        }

        $lessRefund = $refund !== 0 ? ", less the year-end refund of {$this->money($refund)}" : '';

        return [[
            'field' => $field,
            'code' => 'payroll_mismatch',
            'message' => "\"{$schema[$field]['label']}\" is {$this->money($actual)}, but the payroll this draft was built from withheld {$this->money($withheld)}{$lessRefund}.",
        ]];
    }

    /**
     * One error if the draft was built from a period with no finalized or paid payroll, so
     * every payroll figure on it is zero. An empty snapshot row list is the same condition
     * as the aggregation's "No finalized or paid payroll" warning, without depending on its
     * wording. Skipped when there is no snapshot (revisions, seeded fixtures).
     *
     * @return array<int, array{field: string, code: string, message: string}>
     */
    private function emptyPeriodErrors(BirFormDraft $draft): array
    {
        if (($draft->source_snapshot['rows'] ?? null) !== []) {
            return [];
        }

        return [[
            'field' => self::PERIOD_FIELDS[$draft->form_type],
            'code' => 'empty_period',
            'message' => "There is no finalized or paid payroll for {$draft->period}, so every payroll figure on this form is zero. Finalize or pay that payroll, then create a new draft.",
        ]];
    }

    /**
     * Tax withheld across snapshot rows, summed the way BirAggregationService::payTotals()
     * does (raw amounts, rounded once at the end) so an untouched draft always matches.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function snapshotTaxCentavos(array $rows): int
    {
        $tax = 0.0;
        foreach ($rows as $row) {
            $tax += (float) ($row['deductions']['Withholding Tax'] ?? 0);
        }

        return (int) round(round($tax, 2) * 100);
    }

    /** A field entry's amount in whole centavos, or null if it is blank or not a valid amount. */
    private function centavos(mixed $entry): ?int
    {
        $value = is_array($entry) ? ($entry['value'] ?? null) : null;
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && preg_match(self::AMOUNT_PATTERN, $value) === 1
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
