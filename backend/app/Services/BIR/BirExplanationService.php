<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Services\BIR\Schemas\Form2316Schema;

/**
 * Answers "what does this box mean?" and "where did this figure come from?" (Week 6),
 * and holds the fixed replies for questions the assistant doesn't answer.
 *
 * Every answer is read off a record, never worked out: the meaning is the schema's help
 * text, and the source is what the draft recorded — its origin, any override (who, when,
 * and what it replaced), and the payroll rows frozen onto it when it was created. No
 * figure is recalculated here, and the language model writes none of it.
 */
class BirExplanationService
{
    /** A tax, rate or salary question: declined, and pointed to accounting. */
    public const DECLINE = "I can't answer tax questions or give out pay figures. Please check with accounting. I can explain what a box on this form means or where its figure came from.";

    /** A request for a different form while a draft is being filled in. */
    public const NEW_REQUEST = 'That sounds like a different form. Click "New conversation" to start it.';

    /** Anything else typed while a draft is being filled in. */
    public const OTHER = 'I can explain what a box on this form means or where its figure came from. For anything else, please check with accounting.';

    /** Anything that isn't a form request, before any draft exists. */
    public const NOT_A_REQUEST = 'I prepare drafts of BIR Forms 1601-C and 2316. Tell me which form and period you need, for example "the August 2026 1601-C".';

    /** A question about a box that didn't say which box. */
    public const WHICH_BOX = 'Which box do you mean? Give its item number, for example "item 22".';

    /** Payroll rows are listed one by one up to this many; more are summarised. */
    private const LISTED_ROWS = 6;

    public function __construct(private readonly BirConversationService $conversation) {}

    /** "What does this box mean?" — the schema's help text, word for word. */
    public function meaning(BirFormDraft $draft, string $key): string
    {
        $field = $this->conversation->field($draft->form_type, $key);
        $name = BirConversationService::boxName($field);

        return ($field['guidance'] ?? null) !== null
            ? "{$name}: {$field['guidance']}"
            : "There is no help text for {$name} yet. Please ask accounting what goes there.";
    }

    /** "Where did this figure come from?" — the draft's own record of it. */
    public function source(BirFormDraft $draft, string $key): string
    {
        $field = $this->conversation->field($draft->form_type, $key);
        $name = BirConversationService::boxName($field);
        $entry = ($draft->fields ?? [])[$key] ?? [];
        $value = $entry['value'] ?? null;

        if ($value === null || ($entry['origin'] ?? 'pending') === 'pending') {
            return "{$name} is empty: nothing has been entered or recorded for it yet.";
        }

        $shown = BirConversationService::display($value, $field['type']);

        if (!empty($entry['edited'])) {
            $who = $entry['edited_by']['name'] ?? 'someone';
            $when = !empty($entry['edited_at']) ? ' on ' . date('F j, Y', strtotime($entry['edited_at'])) : '';
            $replaced = ($entry['system_value'] ?? null) !== null
                ? 'The figure it replaced was ' . BirConversationService::display($entry['system_value'], $field['type']) . '.'
                : 'Nothing was recorded there before.';

            return "{$name} is {$shown}. It was typed in by {$who}{$when}. {$replaced}";
        }

        return match ($entry['origin']) {
            'user' => "{$name} is {$shown}. A person entered it while filling in this draft; it doesn't come from payroll.",
            'settings' => "{$name} is {$shown}, taken from the company settings when this draft was created.",
            'payroll' => "{$name} is {$shown}. It comes from payroll: " . $this->payrollRecords($draft)
                . ' These records were saved with the draft when it was created, so later payroll changes don\'t affect it.'
                . (($field['guidance'] ?? null) !== null ? " What this box holds: {$field['guidance']}" : ''),
            default => "{$name} is {$shown}.",
        };
    }

    /** The payroll records the draft was built from, as saved on source_snapshot. */
    private function payrollRecords(BirFormDraft $draft): string
    {
        $rows = collect($draft->source_snapshot['rows'] ?? []);

        if ($rows->isEmpty()) {
            return 'no finalized or paid payroll records were found for this period, so its payroll figures are zero.';
        }

        $records = $rows->count() . ' ' . $rows->pluck('status')->unique()->implode(' or ')
            . ' payroll record' . ($rows->count() === 1 ? '' : 's');

        $records .= $draft->form_type === Form2316Schema::FORM_TYPE
            ? ' for ' . (trim(($draft->employee?->first_name ?? '') . ' ' . ($draft->employee?->last_name ?? '')) ?: 'this employee')
            : ' for ' . $rows->pluck('employee_id')->unique()->count() . ' employee' . ($rows->pluck('employee_id')->unique()->count() === 1 ? '' : 's');

        if ($rows->count() <= self::LISTED_ROWS) {
            return $records . ': ' . $rows->map(fn (array $row) => self::cutoff($row) . " (record #{$row['id']})")->implode('; ') . '.';
        }

        return $records . ', with cutoffs from ' . date('F j, Y', strtotime($rows->first()['cutoff_start']))
            . ' to ' . date('F j, Y', strtotime($rows->last()['cutoff_end'])) . '.';
    }

    /** @param array<string, mixed> $row */
    private static function cutoff(array $row): string
    {
        return date('M j', strtotime($row['cutoff_start'])) . ' to ' . date('M j, Y', strtotime($row['cutoff_end']));
    }
}
