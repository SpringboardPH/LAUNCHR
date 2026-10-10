<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use InvalidArgumentException;

/**
 * Drives the conversation once a draft exists: what still needs asking (Week 4),
 * asking for it and saving the answers (Week 5), explaining figures (Week 6).
 *
 * This class knows about drafts and the database. The decision about what is
 * missing lives in BirMissingFields, which knows about neither, so it can be
 * tested on plain arrays and keeps working with the language model switched off.
 */
class BirConversationService
{
    /**
     * Fields this draft still needs from a person, in the order to ask them.
     * Run again after every answer: an answer can make new fields required.
     *
     * @return array<int, array<string, mixed>> see BirMissingFields::detect()
     */
    public function missingFields(BirFormDraft $draft): array
    {
        return BirMissingFields::detect(
            $this->schemaFor($draft->form_type),
            $draft->fields ?? [],
            $this->context($draft),
        );
    }

    /**
     * The next thing to ask on this draft, or null when nothing it needs from a person
     * is missing. Always the first of missingFields() on the draft as saved, so an answer
     * that makes new fields required shows up in the very next question.
     *
     * @return array{field: string, item: ?string, label: string, type: string, options: ?array, reason: string, guidance: ?string, text: string}|null
     */
    public function nextQuestion(BirFormDraft $draft): ?array
    {
        $missing = $this->missingFields($draft)[0] ?? null;
        if ($missing === null) {
            return null;
        }

        $schema = array_column($this->schemaFor($draft->form_type), null, 'key');
        $field = $schema[$missing['key']];

        return [
            'field' => $field['key'],
            'item' => $field['item'],
            'label' => $field['label'],
            'type' => $field['type'],
            'options' => $field['options'] ?? null,
            'reason' => $missing['reason'],
            'guidance' => $field['guidance'] ?? null,
            'text' => $this->questionText($missing, $field, $schema),
        ];
    }

    /**
     * One turn of filling in a draft. A message that answers the question still open ($key)
     * is read with BirAnswerParser; no message gets the next question. Nothing is saved
     * here: an accepted answer goes back to the caller, which saves it through PUT, where it
     * is checked again and its history kept. reply is null once nothing is left to ask.
     *
     * unread is true when a message came in that isn't a readable answer: a muddled answer,
     * or a question about a box. The caller can then have it sorted (Week 6); reply already
     * holds what to say if it can't be.
     *
     * @return array{question: ?array, answer: ?array{field: string, value: mixed}, reply: ?string, unread: bool}
     */
    public function turn(BirFormDraft $draft, ?string $key, ?string $message): array
    {
        $next = $this->nextQuestion($draft);

        if ($message === null) {
            return ['question' => $next, 'answer' => null, 'reply' => $next['text'] ?? null, 'unread' => false];
        }

        // Only an answer to the question still open is read. If that field was filled in the
        // meantime (in the preview, say), the message is never applied to whatever comes next.
        if ($next !== null && $key === $next['field']) {
            $parsed = BirAnswerParser::parse($message, $next);

            if (!$parsed['ok']) {
                return ['question' => $next, 'answer' => null, 'reply' => $parsed['problem'], 'unread' => true];
            }

            return [
                'question' => null,
                'answer' => ['field' => $key, 'value' => $parsed['value']],
                'reply' => "Got it: {$next['label']}: " . self::display($parsed['value'], $next['type']) . '.',
                'unread' => false,
            ];
        }

        return ['question' => $next, 'answer' => null, 'reply' => $next['text'] ?? null, 'unread' => true];
    }

    /**
     * The draft's boxes for the model to choose from: key => "Item 22: label". Signature
     * boxes are left out, since they are signed on paper and never discussed here.
     *
     * @return array<string, string>
     */
    public function boxes(string $formType): array
    {
        $boxes = [];
        foreach ($this->schemaFor($formType) as $field) {
            if ($field['source'] !== 'manual') {
                $boxes[$field['key']] = ($field['item'] !== null ? "Item {$field['item']}: " : '') . $field['label'];
            }
        }

        return $boxes;
    }

    /** @return array<string, mixed> one schema field, by key */
    public function field(string $formType, string $key): array
    {
        return array_column($this->schemaFor($formType), null, 'key')[$key];
    }

    /** How a box is named to the user: "Item 22, Taxable income…", in quotes. */
    public static function boxName(array $field): string
    {
        return '"' . ($field['item'] !== null ? "Item {$field['item']}, {$field['label']}" : $field['label']) . '"';
    }

    /** A value as the user should read it. Dates are spelled out so 05/06 can't be misread. */
    public static function display(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? 'yes' : 'no',
            'date' => date('F j, Y', strtotime($value)),
            'decimal' => '₱' . number_format((float) $value, 2),
            default => (string) $value,
        };
    }

    /**
     * One plain-language question: what is needed, why, and how to answer. Built from
     * the schema and the missing-field reason, so no field is named here.
     *
     * @param  array<string, mixed>  $missing  one entry of missingFields()
     * @param  array<string, mixed>  $field  its schema field
     * @param  array<string, array<string, mixed>>  $schema  keyed by field key
     */
    private function questionText(array $missing, array $field, array $schema): string
    {
        $name = self::boxName($field);

        $why = match ($missing['reason']) {
            BirMissingFields::REASON_RECORD_GAP => " should come from the "
                . ($field['source'] === 'settings' ? 'company settings' : 'employee or payroll records')
                . ', but nothing is recorded there. It is best fixed there, but you can enter it here.',
            BirMissingFields::REASON_CONDITION => ' is needed because ' . $this->because($missing['required_when'], $schema) . '.',
            default => ' is needed for this form.',
        };

        $ask = match ($field['type']) {
            'boolean' => 'Yes or no?',
            'decimal' => 'What is the amount in pesos? Enter 0 if there was none.',
            'date' => 'What is the date (MM/DD/YYYY)?',
            'month' => 'Which month (MM/YYYY)?',
            'integer' => 'How many?',
            'enum' => 'Which one: ' . implode(' or ', $field['options'] ?? []) . '?',
            default => 'What is it?',
        };

        return "{$name}{$why} {$ask}";
    }

    /**
     * Why a conditional field became required, in words.
     *
     * @param  array<string, mixed>  $condition  a required_when that missingFields() found met
     * @param  array<string, array<string, mixed>>  $schema
     */
    private function because(array $condition, array $schema): string
    {
        if (($condition['context'] ?? null) === 'hired_in_year') {
            return 'the employee was hired during the year';
        }

        $other = '"' . $schema[$condition['field']]['label'] . '"';

        return match (true) {
            array_key_exists('equals', $condition) && is_bool($condition['equals']) => 'the answer to ' . $other . ' was ' . ($condition['equals'] ? 'yes' : 'no'),
            array_key_exists('equals', $condition) => "{$other} is {$condition['equals']}",
            array_key_exists('gt', $condition) => "an amount was entered for {$other}",
            default => "{$other} was filled in",
        };
    }

    /**
     * Facts about a draft that its fields can't hold but its conditions depend on.
     * Keys are BirMissingFields::CONTEXT_FACTS.
     *
     * hired_in_year: the employee started during the tax year, so they may have
     * worked somewhere else first. January 1 counts as a full year. An unknown
     * hire date counts as true — the cost of that is one yes/no question, while
     * the cost of guessing false is a 2316 missing a previous employer's income.
     *
     * @return array<string, bool>
     */
    public function context(BirFormDraft $draft): array
    {
        if ($draft->form_type !== Form2316Schema::FORM_TYPE) {
            return [];
        }

        $hired = $draft->employee?->hire_date;
        $year = (int) $draft->period;

        return [
            'hired_in_year' => $hired === null
                || ($hired->year === $year && $hired->format('m-d') !== '01-01'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function schemaFor(string $formType): array
    {
        return match ($formType) {
            Form1601CSchema::FORM_TYPE => Form1601CSchema::fields(),
            Form2316Schema::FORM_TYPE => Form2316Schema::fields(),
            default => throw new InvalidArgumentException("Unknown BIR form type: {$formType}"),
        };
    }
}
