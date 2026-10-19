<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;

/**
 * The 1601-C employee list in the chat (BirChatCommands sends questions here):
 *
 * - "who is on form #10?", "list the employees on the March 2026 1601-C": each employee's
 *   pay and tax for the month, the totals, and whether they add up to the form.
 * - "how much tax was withheld from Maria Santos on the March 2026 1601-C?", "Maria's
 *   part of form #10", or on an open 1601-C "how much was Maria paid?": that employee's row.
 *
 * The rows are the ones frozen on the draft when it was made (BirEmployeeList, Dev B), so
 * the chat says what the form was built from, not what the payroll says today. Nothing here
 * changes a form. Replies use BirChatCommands::result().
 */
class BirChatEmployees
{
    /** A question about the employees on a 1601-C as a whole. */
    public const LIST = '/\b(employee list|list of (?:the )?employees|(?:list|show)(?: me)?(?: all)?(?: the)? employees|employees? (?:on|in|of|for|covered)\b|(?:which|what) employees|who(?: is|\'s| are)? (?:on|in|covered)|per employee|each employee|by employee|sino(?: ang)?(?: mga)? (?:empleyado|kasama)|(?:sino|sinu-sino|sino-sino)(?: ang| yung)?(?: mga)? nasa|mga empleyado)/i';

    /** A question about an amount, with or without a question mark. */
    private const HOW_MUCH = '/\\b(?:how much|magkano|ilan)\\b/i';

    /** At most this many employees are listed in one reply. */
    private const LISTED = 15;

    /** Each money column, as the reply names it (the item it adds up to is in BirEmployeeList::ITEMS). */
    private const COLUMNS = [
        'compensation' => 'Compensation',
        'mwe_basic' => 'Minimum wage basic pay',
        'mwe_premium' => 'Minimum wage holiday, overtime and night pay',
        'thirteenth' => '13th month and other benefits (non-taxable part)',
        'ee' => 'SSS, PhilHealth and Pag-IBIG (employee share)',
        'taxable' => 'Taxable compensation',
        'exempt_250k' => 'Exempt (₱250,000 a year or less)',
        'tax_withheld' => 'Tax withheld',
    ];

    private const CATEGORIES = [
        'mwe' => 'minimum wage earner',
        'exempt_250k' => '₱250,000 a year or less',
        'taxable' => 'taxable',
    ];

    public function __construct(
        private readonly BirFormFinder $finder,
        private readonly BirChatLookup $lookup,
        private readonly BirEmployeeResolver $employees,
    ) {}

    /** The reply, or null when the message isn't about a 1601-C's employees. */
    public function answer(string $message, ?BirFormDraft $current, BirConversationState $state): ?array
    {
        $ref = $this->finder->reference($message);
        $asksList = preg_match(self::LIST, $message) === 1;
        $asks = BirAnswerParser::looksLikeQuestion($message) || preg_match(self::HOW_MUCH, $message) === 1;
        // "how much was Gino paid on form #11?": a capitalised first or last name counts in a question.
        if ($ref['person'] === BirEmployeeResolver::NOT_NAMED && $asks) {
            $named = $this->employees->capitalisedName($message);
            if ($named['status'] !== BirEmployeeResolver::NOT_NAMED) {
                [$ref['person'], $ref['employee'], $ref['candidates'], $ref['close'], $ref['query']] = [$named['status'], $named['employee'], $named['candidates'], $named['close'], $named['query']];
            }
        }
        $person = $ref['person'] !== BirEmployeeResolver::NOT_NAMED;

        // Which form type the message is about: said outright, the form its number names, a month
        // (only a 1601-C has one), or the open one.
        $type = match (true) {
            (bool) preg_match('/1601/', $message) => Form1601CSchema::FORM_TYPE,
            (bool) preg_match('/2316/', $message) => Form2316Schema::FORM_TYPE,
            $ref['number'] !== null => BirFormDraft::whereKey($ref['number'])->value('form_type'),
            $ref['month'] !== null => Form1601CSchema::FORM_TYPE,
            default => $current?->form_type,
        };
        if ($asksList && $type !== Form1601CSchema::FORM_TYPE) {
            return $this->notAList($type, $ref, $current);
        }
        if ($type !== Form1601CSchema::FORM_TYPE || !($asksList || ($person && $asks))) {
            return null;
        }

        // A name only spelled close to someone's ("Mario"): ask, never show another person's pay.
        if ($person && $ref['person'] === BirEmployeeResolver::MATCHED && ($ref['close'] ?? false)) {
            $state->setPending(['message' => $message, 'needs' => 'detail', 'suggest' => $ref['employee']->employee_id]);

            return BirChatCommands::result(BirEmployeeResolver::didYouMean((string) $ref['query'], $ref['employee']), null, null, null, $ref['candidates'], stay: true);
        }

        // The person picks a row, not the form: find the 1601-C without them.
        $form = ['form_type' => Form1601CSchema::FORM_TYPE, 'person' => BirEmployeeResolver::NOT_NAMED, 'employee' => null, 'candidates' => []] + $ref;
        $draft = !BirFormFinder::specific($form) && $current?->form_type === Form1601CSchema::FORM_TYPE
            ? $current
            : $this->lookup->target($form, $current, $message, $state);
        if (is_array($draft)) {
            return $draft;
        }

        $list = BirEmployeeList::forDraft($draft);
        $on = BirFormNames::describe($draft) . ' (' . BirFormNames::versionNote($draft) . ')';
        if ($list === null) {
            return BirChatCommands::result(ucfirst($on) . ' was made before the employee list existed, so I don\'t have its employees. A new draft for that month has them.', stay: true);
        }

        return $person ? $this->row($ref, $list, $on, $message, $state) : $this->everyone($list, $on);
    }

    /** An employee list asked for with no 1601-C to read it from: say which form has one. */
    private function notAList(?string $type, array $ref, ?BirFormDraft $current): array
    {
        $form = $ref['number'] !== null ? BirFormDraft::with('employee')->find($ref['number']) : ($type === Form2316Schema::FORM_TYPE ? $current : null);
        if ($type === Form2316Schema::FORM_TYPE) {
            $which = $form !== null ? 'Form ' . BirFormNames::name($form) . ' is ' . BirFormNames::describe($form) . ', one employee\'s certificate' : 'A 2316 is one employee\'s certificate';

            return BirChatCommands::result("{$which}, so it has no employee list. A 1601-C has one, for example \"who is on the September 2026 1601-C?\"", stay: true);
        }

        return BirChatCommands::result('Which 1601-C? Give its number or its month, for example "who is on form #11?" or "who is on the September 2026 1601-C?"', stay: true);
    }

    /** One employee's part of the form. */
    private function row(array $ref, array $list, string $on, string $message, BirConversationState $state): array
    {
        if ($ref['person'] === BirEmployeeResolver::AMBIGUOUS) {
            $state->setPending(['message' => $message, 'needs' => 'detail']);
            $names = implode(', ', array_map(fn (array $c) => "{$c['name']} ({$c['employee_id']})", $ref['candidates']));

            return BirChatCommands::result("More than one person matches that: {$names}. Which one did you mean?", null, null, null, $ref['candidates'], stay: true);
        }
        if ($ref['person'] === BirEmployeeResolver::NOT_FOUND) {
            return BirChatCommands::result("I could not find anyone matching \"{$ref['query']}\". Could you check the spelling, or give their employee ID or TIN?", stay: true);
        }

        $employee = $ref['employee'];
        $row = collect($list['employees'])->firstWhere('employee_id', $employee->id);
        $name = trim("{$employee->first_name} {$employee->last_name}");
        if ($row === null) {
            return BirChatCommands::result("{$name} has no counted payroll on {$on}, so they are not on its employee list.", stay: true);
        }

        $payrolls = $row['payroll_count'] === 1 ? '1 payroll' : "{$row['payroll_count']} payrolls";
        $tin = $row['tin'] !== null ? ", TIN {$row['tin']}" : '';
        $kind = self::CATEGORIES[$row['category']] ?? $row['category'];
        $lines = [];
        foreach (self::COLUMNS as $column => $label) {
            if ($row[$column] !== '0.00' || in_array($column, ['compensation', 'tax_withheld'], true)) {
                $lines[] = "- {$label} (item " . BirEmployeeList::ITEMS[$column][0] . '): ' . self::money($row[$column]);
            }
        }

        return BirChatCommands::result("{$name}'s part of {$on}, from {$payrolls}{$tin} ({$kind}):\n" . implode("\n", $lines), stay: true);
    }

    /** Every employee on the form, the totals, and whether they add up to it. */
    private function everyone(array $list, string $on): array
    {
        $count = count($list['employees']);
        if ($count === 0) {
            return BirChatCommands::result(ucfirst($on) . ' has no employees: there was no counted payroll that month.', stay: true);
        }

        $people = Employee::withTrashed()->whereIn('id', array_column($list['employees'], 'employee_id'))->get()->keyBy('id');
        $lines = collect($list['employees'])->take(self::LISTED)->map(function (array $row) use ($people) {
            $person = $people[$row['employee_id']] ?? null;
            $name = $row['name'] ?? ($person !== null ? mb_strtoupper("{$person->last_name}, {$person->first_name}") : "Employee #{$row['employee_id']}");
            $tin = $row['tin'] !== null ? " ({$row['tin']})" : '';
            $payrolls = $row['payroll_count'] === 1 ? '1 payroll' : "{$row['payroll_count']} payrolls";

            return "- {$name}{$tin}: {$payrolls}, compensation " . self::money($row['compensation']) . ', tax withheld ' . self::money($row['tax_withheld']);
        })->implode("\n");
        $more = $count > self::LISTED ? "\n…and " . ($count - self::LISTED) . ' more. Ask about one by name, for example "how much tax was withheld from Maria Santos?".' : '';
        $total = 'Total: compensation ' . self::money($list['totals']['compensation']) . ', tax withheld ' . self::money($list['totals']['tax_withheld']) . '.';

        $who = $count === 1 ? '1 employee' : "{$count} employees";

        return BirChatCommands::result(ucfirst($on) . " covers {$who}:\n{$lines}{$more}\n{$total}\n" . self::addsUp($list['differences']), stay: true);
    }

    /** "They add up to the form.", or which items differ and by how much. */
    private static function addsUp(array $differences): string
    {
        if ($differences === []) {
            return 'Their figures add up to the form.';
        }

        $lines = array_map(function (array $d) {
            if ($d['form_value'] === null) {
                return "- Item {$d['item']} is blank on the form; the employees add up to " . self::money($d['employees_total']) . '.';
            }
            $more = str_starts_with($d['difference'], '-') ? 'less' : 'more';

            return "- Item {$d['item']} on the form is " . self::money(ltrim($d['difference'], '-')) . " {$more} than the employees' total of " . self::money($d['employees_total']) . '.';
        }, $differences);

        return "Their figures don't all add up to the form (a changed figure, item 20, or rounding can explain it):\n" . implode("\n", $lines);
    }

    private static function money(string $amount): string
    {
        return BirConversationService::display($amount, 'decimal');
    }
}
