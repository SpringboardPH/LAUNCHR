<?php

namespace App\Http\Requests;

use App\Models\BirFormDraft;
use App\Services\BIR\BirDraftValidator;
use App\Services\BIR\Schemas\FieldFormat;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Checks each answer in PUT /bir/drafts/{id} as it is saved, so a bad answer is refused
 * straight away (422 naming the field) instead of only showing up at validate or submit.
 * Every key must be a field on the draft's form, and each value must suit the field's
 * schema type. null is allowed: it clears the field. A field with a schema 'fixed' value
 * (1601-C item 13) is the exception: it is filled in by the system and any answer to it,
 * null included, is refused.
 *
 * This checks single answers only. Whether the draft as a whole is complete and adds up
 * is BirDraftValidator's job.
 */
class UpdateBirDraftFieldsRequest extends FormRequest
{
    private const STRING_MAX = 255;

    private const TEXT_MAX = 1000;

    private const MONTH_PATTERN = '/^(0[1-9]|1[0-2])\/\d{4}$/';

    private const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/';

    /** Yes/no spellings accepted, the same ones BirMissingFields reads. Stored as a real true/false. */
    private const BOOLEANS = ['true' => true, '1' => true, 'yes' => true, 'false' => false, '0' => false, 'no' => false];

    private ?BirFormDraft $draft = null;

    private bool $lookedUp = false;

    public function authorize(): bool
    {
        return true;
    }

    /** The draft being edited, or null if there isn't one. Looked up once and shared with update(). */
    public function draft(): ?BirFormDraft
    {
        if (!$this->lookedUp) {
            $this->draft = BirFormDraft::find((int) $this->route('id'));
            $this->lookedUp = true;
        }

        return $this->draft;
    }

    /**
     * True when the form is pending and the caller's role may not edit pending forms
     * (BirFormDraft::PENDING_EDITOR_ROLES). update() answers that with 403.
     */
    public function refusedByRole(): bool
    {
        return $this->draft()?->status === 'pending'
            && !in_array($this->user()->role, BirFormDraft::PENDING_EDITOR_ROLES, true);
    }

    /**
     * A missing draft, one that can't be edited, or a pending form the caller's role may not
     * edit gets update()'s 404, 400 or 403, not a 422 about its fields, so the checks are
     * skipped for those.
     */
    public function validateResolved()
    {
        $draft = $this->draft();
        if ($draft === null || !in_array($draft->status, BirFormDraft::EDITABLE_STATUSES, true) || $this->refusedByRole()) {
            return;
        }

        parent::validateResolved();
    }

    public function rules(): array
    {
        return [
            'fields' => 'required|array|min:1',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('fields')) {
                    return;
                }

                $schema = $this->schema();
                foreach ((array) $this->input('fields') as $key => $value) {
                    $problem = match (true) {
                        !isset($schema[$key]) => "\"{$key}\" is not a field on the {$this->draft()->form_type}.",
                        // array_key_exists, not isset: the fixed value can be false.
                        array_key_exists('fixed', $schema[$key]) => self::fixedProblem($schema[$key]),
                        default => $this->problem($schema[$key], FieldFormat::normalize($schema[$key], $value)),
                    };
                    if ($problem !== null) {
                        $validator->errors()->add("fields.{$key}", $problem);
                    }
                }
            },
        ];
    }

    /**
     * The answers to save, keyed by field. Yes/no answers become true/false, whole numbers
     * become integers and text is in capitals with a 14-digit TIN (FieldFormat::normalize),
     * so the stored value is always clean. Call after validation.
     *
     * @return array<string, mixed>
     */
    public function answers(): array
    {
        $schema = $this->schema();
        $answers = [];

        foreach ((array) $this->input('fields') as $key => $value) {
            $answers[$key] = match (true) {
                $value === null => null,
                $schema[$key]['type'] === 'boolean' => self::toBoolean($value),
                $schema[$key]['type'] === 'integer' => (int) $value,
                default => FieldFormat::normalize($schema[$key], $value),
            };
        }

        return $answers;
    }

    /** @return array<string, array<string, mixed>> */
    private function schema(): array
    {
        return $this->draft()->form_type === Form1601CSchema::FORM_TYPE ? Form1601CSchema::byKey() : Form2316Schema::byKey();
    }

    /**
     * Why a value doesn't suit its field, or null if it does. An unknown field type is
     * refused rather than let through.
     *
     * @param  array<string, mixed>  $field
     */
    private function problem(array $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $label = "\"{$field['label']}\"";

        return $this->typeProblem($field, $value, $label) ?? $this->formatProblem($field, $value, $label);
    }

    /**
     * A fixed field always has its schema value, so no answer is accepted, whatever it is.
     * A yes/no value is worded Yes or No.
     *
     * @param  array<string, mixed>  $field
     */
    private static function fixedProblem(array $field): string
    {
        $fixed = is_bool($field['fixed']) ? ($field['fixed'] ? 'Yes' : 'No') : (string) $field['fixed'];

        return "\"{$field['label']}\" is always {$fixed} and can't be changed.";
    }

    /**
     * A field with a schema 'format' (TIN, RDO, ZIP, phone, email, MM/DD) must follow it, the
     * same rule BirDraftValidator checks. A blank answer isn't checked: whether a field may be
     * blank is the missing-fields rule's job.
     *
     * @param  array<string, mixed>  $field
     */
    private function formatProblem(array $field, mixed $value, string $label): ?string
    {
        if (!isset($field['format']) || $value === '' || FieldFormat::matches($field['format'], $value)) {
            return null;
        }

        return "{$label} must be " . FieldFormat::describe($field['format']) . '.';
    }

    /** @param  array<string, mixed>  $field */
    private function typeProblem(array $field, mixed $value, string $label): ?string
    {
        return match ($field['type']) {
            'decimal' => self::isAmount($value) ? null
                : "{$label} must be an amount like 1234.50, without commas or a currency sign.",
            'boolean' => self::toBoolean($value) !== null ? null : "{$label} must be yes or no.",
            'enum' => in_array($value, $field['options'] ?? [], true) ? null
                : "{$label} must be one of: " . implode(', ', $field['options'] ?? []) . '.',
            'integer' => is_int($value) || (is_string($value) && ctype_digit($value)) ? null
                : "{$label} must be a whole number.",
            'month' => is_string($value) && preg_match(self::MONTH_PATTERN, $value) === 1 ? null
                : "{$label} must be a month as MM/YYYY, for example 09/2026.",
            'date' => self::isDate($value) ? null : "{$label} must be a date as YYYY-MM-DD, for example 2026-01-31.",
            'string' => is_string($value) && mb_strlen($value) <= self::STRING_MAX ? null
                : "{$label} must be text of at most " . self::STRING_MAX . ' characters.',
            'text' => is_string($value) && mb_strlen($value) <= self::TEXT_MAX ? null
                : "{$label} must be text of at most " . self::TEXT_MAX . ' characters.',
            'manual' => "{$label} is signed on the printed form, not entered here.",
            default => "{$label} can't be entered here.",
        };
    }

    /** Plain decimal text or a JSON number, read the same way as BirDraftValidator's amounts. */
    private static function isAmount(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && preg_match(BirDraftValidator::AMOUNT_PATTERN, $value) === 1;
    }

    /** Named toBoolean, not boolean: Laravel's Request already has a boolean() method. */
    private static function toBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        return is_string($value) ? (self::BOOLEANS[strtolower(trim($value))] ?? null) : null;
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match(self::DATE_PATTERN, $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
