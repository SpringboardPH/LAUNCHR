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
 * condition or record_gap.
 */
class BirDraftValidator
{
    public function __construct(private BirConversationService $conversation)
    {
    }

    /** @return array<int, array{field: string, code: string, message: string}> */
    public function validate(BirFormDraft $draft): array
    {
        $schema = $draft->form_type === Form1601CSchema::FORM_TYPE ? Form1601CSchema::byKey() : Form2316Schema::byKey();

        return array_map(fn (array $missing) => [
            'field' => $missing['key'],
            'code' => $missing['reason'],
            'message' => $this->missingMessage($missing, $schema),
        ], $this->conversation->missingFields($draft));
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
