<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Services\BIR\Schemas\FieldFormat;
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
 *
 * Changing a box by its item number ("change item 12 to …") is read here too, in PHP: the
 * item number picks the box and the new value must be in the message, so the model can
 * neither pick the wrong box nor make up a value.
 */
class BirConversationService
{
    /**
     * Fields this draft still needs from a person, in the order to ask them.
     * Run again after every answer: an answer can make new fields required.
     * Fixed and system-filled boxes (1601-C items 2, 13, 13A) are never asked, even on an
     * old draft that left them empty: no answer to them can be saved.
     *
     * @return array<int, array<string, mixed>> see BirMissingFields::detect()
     */
    public function missingFields(BirFormDraft $draft): array
    {
        $schema = $this->schemaFor($draft->form_type);
        $byKey = array_column($schema, null, 'key');

        return array_values(array_filter(
            BirMissingFields::detect($schema, $draft->fields ?? [], $this->context($draft)),
            fn (array $missing) => self::notChangeable($byKey[$missing['key']]) === null,
        ));
    }

    /**
     * The next thing to ask on this draft, or null when nothing it needs from a person
     * is missing. Always the first of missingFields() on the draft as saved, so an answer
     * that makes new fields required shows up in the very next question.
     *
     * @return array{field: string, item: ?string, label: string, type: string, options: ?array, format: ?string, reason: string, guidance: ?string, text: string}|null
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
            'format' => $field['format'] ?? null,
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
                'reply' => self::sentence("Got it: {$next['label']}: " . self::display($parsed['value'], $next['type'])),
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

    /** @return array<int, array<string, mixed>> every field of a form, in form order */
    public function fields(string $formType): array
    {
        return $this->schemaFor($formType);
    }

    /** @return array<string, mixed> one schema field, by key */
    public function field(string $formType, string $key): array
    {
        return array_column($this->schemaFor($formType), null, 'key')[$key];
    }

    /**
     * Every part of an item, in form order: item 4 of the 2316 is the last, first and middle
     * name. Empty if the form has no such item.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fieldsForItem(string $formType, string $item): array
    {
        return array_values(array_filter(
            $this->schemaFor($formType),
            fn (array $field) => $field['item'] !== null && strtoupper($field['item']) === strtoupper($item),
        ));
    }

    /**
     * A request to change one box, read in PHP: "change item 12 to 987-654-321-000",
     * "palitan ang item 4 middle name ng Cruz", "gawing 049 ang item 5".
     *
     * The item number picks the box (a label word picks the part when an item has several);
     * the new value is the text after "to" / "ng" and is read like any answer, format
     * included. Nothing is saved here: answer goes back to the caller, which saves it through
     * PUT like the preview's Change button, so it is checked and recorded with the user's name.
     *
     * $value, when given, is the new value already read off the message (with any trailing
     * "for Maria Santos 2026" taken off by the caller); otherwise it is read here.
     *
     * @return array{answer: ?array{field: string, value: mixed}, reply: string}
     */
    public function change(BirFormDraft $draft, string $message, ?string $value = null): array
    {
        $form = $draft->form_type;
        $item = BirAnswerParser::itemMentioned($message);
        if ($item === null) {
            return ['answer' => null, 'reply' => $this->whichItem($form, $message)];
        }

        $parts = array_values(array_filter($this->fieldsForItem($form, $item), fn (array $f) => $f['source'] !== 'manual'));
        if ($parts === []) {
            return ['answer' => null, 'reply' => "The {$form} has no item {$item} that can be changed here."];
        }
        $field = count($parts) === 1 ? $parts[0] : self::partNamed($parts, $message);
        if ($field === null) {
            $names = implode(', ', array_map(fn (array $f) => $f['label'], $parts));
            return ['answer' => null, 'reply' => "Item {$item} has more than one part: {$names}. Which one? For example: \"change item {$item} " . mb_strtolower(self::shortLabel($parts[count($parts) - 1])) . ' to …".'];
        }

        $name = self::boxName($field);
        if (($refusal = self::notChangeable($field)) !== null) {
            return ['answer' => null, 'reply' => $refusal];
        }
        $value ??= self::newValue($message);
        if ($value === null) {
            return ['answer' => null, 'reply' => "What should {$name} be? For example: \"change item {$item} to …\"."];
        }

        $parsed = BirAnswerParser::parse($value, $field);
        if (!$parsed['ok']) {
            return ['answer' => null, 'reply' => "{$parsed['problem']} Nothing was changed."];
        }

        $entry = ($draft->fields ?? [])[$field['key']] ?? [];
        $had = ($entry['origin'] ?? 'pending') !== 'pending' && ($entry['value'] ?? null) !== null && ($entry['value'] ?? '') !== '';
        $shown = self::display($parsed['value'], $field['type']);
        $reply = $had
            ? "Changed {$name} from " . self::display($entry['value'], $field['type']) . " to {$shown}."
            : self::sentence("Changed {$name} to {$shown}");
        if (in_array($field['source'], ['payroll', 'settings'], true) && $had && empty($entry['edited'])) {
            $reply .= ' This replaces the calculated figure, and the change is recorded with your name.';
        }

        return ['answer' => ['field' => $field['key'], 'value' => $parsed['value']], 'reply' => $reply];
    }

    /**
     * Why a box can't be changed at all, or null if it can: a fixed box always holds its
     * schema value (item 13 No, item 13A blank), and a system-filled one is filled in from
     * the form itself (item 2). PUT refuses both, so the chat says so instead of trying.
     */
    public static function notChangeable(array $field): ?string
    {
        $name = self::boxName($field);

        return match (true) {
            array_key_exists('fixed', $field) => "{$name} is always "
                . ($field['fixed'] === null ? 'blank' : ucfirst(self::display($field['fixed'], $field['type'])))
                . " on this form, so it can't be changed.",
            !empty($field['system_filled']) => "{$name} is filled in automatically: No on the original, Yes on an amended return. It can't be changed.",
            default => null,
        };
    }

    /** No item number given: say which items the message's words could mean, then how to ask. */
    private function whichItem(string $form, string $message): string
    {
        $before = preg_split('/\s+(?:to|ng)\s+/i', $message, 2)[0];
        $words = array_diff(preg_split('/[^a-z0-9]+/', mb_strtolower($before), -1, PREG_SPLIT_NO_EMPTY), self::STOP_WORDS);
        $items = [];
        foreach ($this->schemaFor($form) as $field) {
            $label = preg_split('/[^a-z0-9]+/', mb_strtolower($field['label']), -1, PREG_SPLIT_NO_EMPTY);
            if ($field['item'] !== null && $field['source'] !== 'manual' && array_intersect($words, $label) !== []) {
                $items[$field['item']] = "item {$field['item']}";
            }
        }

        $hint = count($items) >= 1 && count($items) <= 5
            ? ' On the ' . $form . ', that could be ' . self::orList(array_values($items)) . '.'
            : '';

        return "Which box do you want to change? Give its item number.{$hint} For example: \"change item 12 to …\".";
    }

    /** Words that name no box: the command itself and small words around it. */
    private const STOP_WORDS = [
        'change', 'update', 'set', 'edit', 'correct', 'replace', 'fix', 'palitan', 'pakipalitan', 'ibahin',
        'baguhin', 'pakibago', 'gawing', 'the', 'my', 'its', 'of', 'in', 'on', 'item', 'box', 'ang', 'sa', 'na',
        'to', 'ng', 'form', 'please', 'pls', 'po', 'value', 'name',
    ];

    /** Of an item's parts, the one whose label words the message uses ("middle name"); null if not exactly one. */
    private static function partNamed(array $parts, string $message): ?array
    {
        $words = array_diff(preg_split('/[^a-z0-9]+/', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY), self::STOP_WORDS);
        $scores = array_map(function (array $field) use ($words) {
            $label = preg_split('/[^a-z0-9]+/', mb_strtolower(self::shortLabel($field)), -1, PREG_SPLIT_NO_EMPTY);
            return count(array_intersect($words, $label));
        }, $parts);
        $best = max($scores);

        return $best > 0 && count(array_keys($scores, $best)) === 1 ? $parts[array_search($best, $scores, true)] : null;
    }

    /** "Employee's Name — Middle Name" → "Middle Name": the part that tells an item's parts apart. */
    private static function shortLabel(array $field): string
    {
        $pieces = preg_split('/\s+—\s+/u', $field['label']);

        return end($pieces);
    }

    /** The new value: after "to", "ng", ":" or "=", or between "gawing" and "ang item". Null if none. */
    public static function newValue(string $message): ?string
    {
        $value = match (true) {
            (bool) preg_match('/^\s*gawing\s+(.+?)\s+ang\s+(?:item|box|#)/iu', $message, $m) => $m[1],
            (bool) preg_match('/(?:item|box|#)\s*\d{1,2}[a-z]?\b.*?(?:\s(?:to|ng|into)\s|\s*[:=]\s*)(.+)$/iu', $message, $m) => $m[1],
            default => null,
        };
        // Quotes are trimmed as characters (/u): trim() works byte by byte and would cut the
        // first byte off "₱", which shares it with “ ” ‘ ’.
        $value = $value === null ? null : preg_replace('/^[\s"\'“”‘’]+|[\s"\'“”‘’]+$/u', '', $value);

        return $value === '' ? null : $value;
    }

    /** "a, b or c". */
    private static function orList(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? $last : implode(', ', $items) . ' or ' . $last;
    }

    /** A sentence ends with one full stop, even when the value already ends with one ("Inc."). */
    public static function sentence(string $text): string
    {
        return str_ends_with($text, '.') ? $text : "{$text}.";
    }

    /** How a box is named to the user: "Item 22, Taxable income…", in quotes. */
    public static function boxName(array $field): string
    {
        return '"' . ($field['item'] !== null ? "Item {$field['item']}, {$field['label']}" : $field['label']) . '"';
    }

    /** A value as the user should read it, or "empty". */
    public static function shown(mixed $value, array $field): string
    {
        return $value === null || $value === '' ? 'empty' : self::display($value, $field['type']);
    }

    /** True when a stored value is still what the chat saved, for "undo". Amounts compare as numbers. */
    public static function same(mixed $stored, mixed $saved): bool
    {
        if (is_numeric($stored) && is_numeric($saved)) {
            return (float) $stored === (float) $saved;
        }

        return $stored === $saved || (is_scalar($stored) && is_scalar($saved) && (string) $stored === (string) $saved);
    }

    /** A value as the user should read it. Dates as MM/DD/YYYY, the way BIR forms write them. */
    public static function display(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? 'yes' : 'no',
            'date' => date('m/d/Y', strtotime($value)),
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

        $ask = match (true) {
            isset($field['format']) => 'What is it? Write it as ' . FieldFormat::describe($field['format']) . '.',
            default => self::askFor($field),
        };

        return "{$name}{$why} {$ask}";
    }

    /** How to answer a box, by its type. */
    private static function askFor(array $field): string
    {
        return match ($field['type']) {
            'boolean' => 'Yes or no?',
            'decimal' => 'What is the amount in pesos? Enter 0 if there was none.',
            'date' => 'What is the date (MM/DD/YYYY)?',
            'month' => 'Which month (MM/YYYY)?',
            'integer' => 'How many?',
            'enum' => 'Which one: ' . implode(' or ', $field['options'] ?? []) . '?',
            default => 'What is it?',
        };
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
