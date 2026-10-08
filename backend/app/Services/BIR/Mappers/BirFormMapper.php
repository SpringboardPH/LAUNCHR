<?php

namespace App\Services\BIR\Mappers;

use App\Services\BIR\BirDraftValidator;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use InvalidArgumentException;

/**
 * Places the calculated figures into a form's fields and keeps the form's own
 * totals consistent. One mapper per form: aggregation answers "what are the
 * numbers", a mapper answers "which box does each number go in".
 *
 * build() returns a new draft's complete fields object — every schema key, each
 * a six-key entry (contract §3) — plus the aggregation's _meta. A filled value's
 * origin is its schema source (payroll or settings); anything the system cannot
 * supply is pending.
 *
 * recalculate() reworks the totals from the values on the form, so item 23 follows
 * item 22, item 52 follows 44A/B and 51A/B, and so on. It runs only when called: the
 * code that saves the user's answers (the draft PUT) has to call it, or the totals stay
 * as built and BirDraftValidator reports them as total_mismatch. A field someone
 * changed by hand (edited) is never overwritten, so a typed-in total that doesn't add
 * up is still left for the validator to flag.
 */
abstract class BirFormMapper
{
    /** @return array{fields: array<string, array<string, mixed>>, meta: array<string, mixed>} */
    public static function build(string $formType, string $period, ?int $employeeId = null): array
    {
        return match ($formType) {
            Form1601CSchema::FORM_TYPE => Form1601CMapper::map((int) substr($period, 0, 4), (int) substr($period, 5, 2)),
            Form2316Schema::FORM_TYPE => Form2316Mapper::map((int) $employeeId, (int) $period),
            default => throw new InvalidArgumentException("Unknown BIR form type: {$formType}"),
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields  a draft's fields object
     * @return array<string, array<string, mixed>>
     */
    public static function recalculate(string $formType, array $fields): array
    {
        return match ($formType) {
            Form1601CSchema::FORM_TYPE => Form1601CMapper::totals($fields),
            Form2316Schema::FORM_TYPE => Form2316Mapper::totals($fields),
            default => throw new InvalidArgumentException("Unknown BIR form type: {$formType}"),
        };
    }

    /**
     * Every schema key as a six-key entry. Missing and empty-string values are pending
     * (contract §3, invariant 1); a filled value takes its schema source as its origin.
     *
     * @param  array<int, array<string, mixed>>  $schema
     * @param  array<string, mixed>  $values
     * @return array<string, array<string, mixed>>
     */
    protected static function entries(array $schema, array $values): array
    {
        $fields = [];
        foreach ($schema as $field) {
            $value = $values[$field['key']] ?? null;
            $filled = $value !== null && $value !== '';

            $fields[$field['key']] = [
                'value' => $filled ? $value : null,
                'origin' => $filled ? $field['source'] : 'pending',
                'edited' => false,
                'system_value' => null,
                'edited_by' => null,
                'edited_at' => null,
            ];
        }

        return $fields;
    }

    /**
     * A field's amount for arithmetic. Only plain decimal text counts ("1234.50", the
     * contract §7 format, BirDraftValidator::AMOUNT_PATTERN); pending, blank or anything
     * else ("1,000", "₱500") counts as 0 and is left for the validator to flag.
     */
    protected static function amount(array $fields, string $key): float
    {
        $value = $fields[$key]['value'] ?? null;
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && preg_match(BirDraftValidator::AMOUNT_PATTERN, $value) === 1 ? (float) $value : 0.0;
    }

    /**
     * Sets a calculated value, unless someone changed the field by hand — an edited value
     * stands, and the totals after it are worked out from it. Money is stored as a plain
     * 2-decimal string, the same as the aggregation's.
     */
    protected static function put(array &$fields, string $key, float|bool $value, string $origin = 'payroll'): void
    {
        if (!empty($fields[$key]['edited'])) {
            return;
        }

        $fields[$key] = array_merge($fields[$key] ?? [
            'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null,
        ], [
            'value' => is_bool($value) ? $value : number_format(round($value, 2) ?: 0.0, 2, '.', ''),
            'origin' => $origin,
        ]);
    }

    /** @param array<int, string> $keys */
    protected static function sum(array $fields, array $keys): float
    {
        return array_sum(array_map(fn (string $key) => self::amount($fields, $key), $keys));
    }
}