<?php

namespace App\Services\BIR;

use InvalidArgumentException;

/**
 * Works out which fields on a draft still need a person to supply them, in the
 * order they should be asked.
 *
 * "Missing" is narrower than "empty". Contract invariant 1 makes every empty
 * field origin: pending, and on a fresh 2316 that is 36 user fields — but most
 * of them are optional, or only apply to some employees. A field is missing
 * only when the form is wrong without it:
 *
 *   - it is required outright, or
 *   - its schema required_when condition is met for this draft.
 *
 * A condition that points at a field nobody has answered yet is not met. So a
 * dependent field (previous employer's TIN) never appears before the field it
 * depends on (had a previous employer?) has been answered. The caller is
 * expected to run detect() again after every answer rather than work through
 * one list, which is what makes that ordering come out right.
 *
 * Pure: no database, no model, no Laravel. Everything it needs is passed in.
 * The schema decides what is required, so nothing here names a field.
 */
final class BirMissingFields
{
    /** Required outright by the schema. */
    public const REASON_REQUIRED = 'required';

    /** Required because the field's required_when condition is met. */
    public const REASON_CONDITION = 'condition';

    /**
     * The system should have filled this (payroll or settings) but had nothing to fill it
     * with — no TIN on the employee record, a company setting still on its placeholder.
     * Still asked, but the real fix is the record, not the draft.
     */
    public const REASON_RECORD_GAP = 'record_gap';

    /** Facts about a draft that a required_when may refer to. See BirConversationService::context(). */
    public const CONTEXT_FACTS = ['hired_in_year'];

    private const OPERATORS = ['equals', 'gt', 'present'];

    /**
     * @param  array<int, array<string, mixed>>  $schema  a schema's fields(), in printed-form order
     * @param  array<string, mixed>  $fields  a draft's fields object, keyed by field key
     * @param  array<string, bool>  $context  facts about this draft, keyed by CONTEXT_FACTS
     * @return array<int, array{key: string, item: ?string, label: string, source: string, reason: string, required_when: ?array}>
     */
    public static function detect(array $schema, array $fields, array $context = []): array
    {
        $byKey = array_column($schema, null, 'key');
        $missing = [];

        foreach ($schema as $field) {
            if ($field['source'] === 'manual') {
                continue; // signed by hand after printing — nothing to supply in the system
            }
            if (self::answer($fields[$field['key']] ?? null, $field['type']) !== null) {
                continue;
            }

            $condition = $field['required_when'] ?? null;

            if ($condition !== null) {
                $problem = self::conditionProblem($field['key'], $condition, $byKey);
                if ($problem !== null) {
                    // A typo here would silently stop a required field from ever being
                    // asked. Fail loudly instead; BirMissingFieldsTest also checks the
                    // shipped schemas so this should never reach a user.
                    throw new InvalidArgumentException($problem);
                }
                if (!self::conditionMet($condition, $fields, $byKey, $context)) {
                    continue;
                }
                $reason = self::REASON_CONDITION;
            } elseif ($field['required']) {
                $reason = self::REASON_REQUIRED;
            } else {
                continue; // optional and blank is a valid answer, not a gap
            }

            if (in_array($field['source'], ['payroll', 'settings'], true)) {
                $reason = self::REASON_RECORD_GAP;
            }

            $missing[] = [
                'key' => $field['key'],
                'item' => $field['item'],
                'label' => $field['label'],
                'source' => $field['source'],
                'reason' => $reason,
                'required_when' => $condition,
            ];
        }

        return $missing;
    }

    /**
     * Every required_when problem in a schema, as readable messages. An empty array
     * means the schema is consistent. Used by tests so a schema edit that breaks a
     * condition fails in CI rather than in a conversation.
     *
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<int, string>
     */
    public static function schemaProblems(array $schema): array
    {
        $byKey = array_column($schema, null, 'key');
        $problems = [];

        foreach ($schema as $field) {
            $condition = $field['required_when'] ?? null;
            if ($condition === null) {
                continue;
            }
            if ($field['required']) {
                $problems[] = "{$field['key']} has required => true and a required_when; a conditional field must be required => false.";
            }
            if (($problem = self::conditionProblem($field['key'], $condition, $byKey)) !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * The answer a draft field entry holds, or null if it holds none yet.
     *
     * Checks origin and value both. The contract guarantees they agree (invariant 1),
     * but fixtures and hand-edited rows can break invariants, and when they disagree
     * the safe reading is "not answered" — an extra question beats a blank on a filed form.
     *
     * Booleans may come back from the API as true, "true", "1" or "yes" depending on who
     * sent them, so they are read into a real bool. One that can't be read ("maybe")
     * counts as unanswered: the question is asked again, and anything that depends on it
     * waits rather than being treated as a no.
     */
    private static function answer(mixed $entry, string $type): mixed
    {
        if (!is_array($entry) || ($entry['origin'] ?? 'pending') === 'pending') {
            return null;
        }
        $value = $entry['value'] ?? null;

        if ($value === '' || $value === null) {
            return null;
        }
        if ($type === 'boolean') {
            return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<string, mixed>>  $byKey
     * @param  array<string, bool>  $context
     */
    private static function conditionMet(array $condition, array $fields, array $byKey, array $context): bool
    {
        if (array_key_exists('context', $condition)) {
            return ($context[$condition['context']] ?? false) === true;
        }

        $other = $byKey[$condition['field']];
        $value = self::answer($fields[$condition['field']] ?? null, $other['type']);

        if ($value === null) {
            return false; // unanswered — the field it depends on gets asked first
        }

        if (array_key_exists('equals', $condition)) {
            return $value === $condition['equals'];
        }
        if (array_key_exists('gt', $condition)) {
            // Money arrives as a 2-decimal string ("150000.00"), so compare as a number.
            return is_numeric($value) && (float) $value > (float) $condition['gt'];
        }

        return $condition['present'] === true; // the value is present, by the check above
    }

    /**
     * Why a condition can't be evaluated, or null if it can.
     *
     * @param  mixed  $condition
     * @param  array<string, array<string, mixed>>  $byKey
     */
    private static function conditionProblem(string $key, mixed $condition, array $byKey): ?string
    {
        if (!is_array($condition)) {
            return "{$key}: required_when must be an array.";
        }

        if (array_key_exists('context', $condition)) {
            return in_array($condition['context'], self::CONTEXT_FACTS, true)
                ? null
                : "{$key}: required_when uses unknown context fact '{$condition['context']}'.";
        }

        $other = $condition['field'] ?? null;
        if (!is_string($other) || !isset($byKey[$other])) {
            return "{$key}: required_when refers to unknown field '" . (is_string($other) ? $other : '?') . "'.";
        }
        if ($other === $key) {
            return "{$key}: required_when refers to itself.";
        }

        $operators = array_values(array_intersect(self::OPERATORS, array_keys($condition)));
        if (count($operators) !== 1) {
            return "{$key}: required_when needs exactly one of " . implode(', ', self::OPERATORS) . '.';
        }
        // present => false would be met by an unanswered field, breaking "unanswered
        // is never met" — the rule the asking order depends on.
        if ($operators[0] === 'present' && $condition['present'] !== true) {
            return "{$key}: required_when 'present' only supports true.";
        }
        if ($operators[0] === 'gt' && !is_numeric($condition['gt'])) {
            return "{$key}: required_when 'gt' needs a number.";
        }

        return null;
    }
}
