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
 * - "what are the TINs of the employees on the November 2026 1601-C?", "their 13th month on
 *   form #10": only those details, for each employee on it (and the total of an amount).
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
    public const LIST = '/\b(employee list|list of (?:the )?employees|(?:list|show)(?: me)?(?: all)?(?: the)? employees|employees? (?:on|in|of|for|covered)\b|(?:which|what) employees|who(?: is|\'s| are)? (?:on|in|covered)|per employee|each employee|by employee|every employee|(?:everyone|everybody|all of them)\s+(?:on|in)\b|sino(?: ang)?(?: mga)? (?:empleyado|kasama)|(?:sino|sinu-sino|sino-sino)(?: ang| yung)?(?: mga)? nasa|mga empleyado)/i';

    /** Pointing at a form: "this", "on it". "list all employees" points at none and is a question about the records. */
    private const THIS_FORM = '/\\b(?:this|that|ito|iyan|yan|nito|niyan)\\b|\\b(?:on|in|sa)\\s+(?:it|the form|the 2316|the certificate)\\b/i';

    /** A question about an amount, with or without a question mark. */
    private const HOW_MUCH = '/\\b(?:how much|magkano|ilan)\\b/i';

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

    /**
     * Details a question asks for each employee on the form: the form's own columns, then their
     * employee record. Compensation only when no other amount is named ("13th month pays").
     */
    private const ASKED = [
        'tin' => '/\btins?\b|tax\s*identification/i',
        'thirteenth' => '/13th|thirteenth|bonus/i',
        'ee' => '/\bsss\b|philhealth|pag-?ibig|contribution|kontribusyon/i',
        'taxable' => '/taxable\s+(?:compensation|pay|income|amount)/i',
        'mwe_basic' => '/minimum\s+wage\s+(?:basic|pay)|mwe\s+(?:basic|pay)|statutory\s+(?:minimum\s+)?wage/i',
        'mwe_premium' => '/holiday|overtime|night\s*(?:shift|diff)/i',
        'exempt_250k' => '/exempt\s+(?:amount|compensation|pay|part)/i',
        'tax_withheld' => '/tax(?:es)?\s+withheld|withholding|kaltas|buwis|\btax\b/i',
        'compensation' => '/compensation|gross|\bpaid\b|\bearn|kinita|binayad/i',
        'payroll_count' => '/payrolls?\b|cutoffs?/i',
        'category' => '/\b(?:tax\s+)?(?:group|category|categories|classification|kategorya)\b/i',
        'position' => '/position|\bjobs?\b|titles?\b|trabaho/i',
        'department' => '/department|\bdept/i',
        'hire_date' => '/hire|hired|joined/i',
        'salary' => '/salar|sahod|sweldo|\brates?\b/i',
        'employee_id' => '/employee\s*(?:ids?|numbers?|nos?\.?|codes?)|emp\.?\s*ids?/i',
        'email' => '/e-?mails?/i',
        'phone' => '/phone|contact|mobile/i',
    ];

    /** How each asked detail is named in the reply. */
    private const ASKED_LABELS = [
        'tin' => 'TIN', 'payroll_count' => 'payrolls', 'category' => 'group', 'position' => 'position', 'department' => 'department',
        'hire_date' => 'hire date', 'salary' => 'salary on file', 'employee_id' => 'employee ID', 'email' => 'email', 'phone' => 'phone',
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
            return $ref['number'] !== null || preg_match(self::THIS_FORM, $message) ? $this->notAList($type, $ref, $current) : null;
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
        // "the taxable employees for March 2025" with no 1601-C named, and none saved for it, or with a
        // condition the list can't apply ("more than 5,000 tax withheld"): the payroll answers (BirDataAnswers).
        // So does one person over several months, or with no one 1601-C saved for the month (none, or a
        // version and its amendment: the payroll, not a question about which), or several people.
        if (($asksList || $person) && $ref['number'] === null && !preg_match('/1601/', $message) && $current?->form_type !== Form1601CSchema::FORM_TYPE
            && (($asksList && !$person && BirDataWords::hasCondition($message)) || BirDataWords::monthsAsked($message) !== [] || $this->finder->forms($form)->count() !== 1
                || $this->employees->namedIn($message)->count() > 1)) {
            return null;
        }
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

        return $person ? $this->row($ref, $list, $on, $message, $state) : $this->everyone($list, $on, preg_match('/taxable\s+(?:compensation|pay|income|amount)/i', $message) ? null : BirDataWords::groupSaid($message), self::asked($message));
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
        // "Teresa's 13th month on the December 1601-C": that column only (compensation and tax are always shown).
        $want = array_intersect(self::asked($message), array_keys(self::COLUMNS));
        $want = array_diff($want, ['compensation', 'tax_withheld']) === [] ? [] : $want;
        foreach (self::COLUMNS as $column => $label) {
            if ($want !== [] ? in_array($column, $want, true) : ($row[$column] !== '0.00' || in_array($column, ['compensation', 'tax_withheld'], true))) {
                $lines[] = "- {$label} (item " . BirEmployeeList::ITEMS[$column][0] . '): ' . self::money($row[$column]);
            }
        }

        return BirChatCommands::result("{$name}'s part of {$on}, from {$payrolls}{$tin} ({$kind}):\n" . implode("\n", $lines), stay: true);
    }

    /** Every employee on the form, the totals, and whether they add up to it; or only those in a group ("the taxable ones"). */
    private function everyone(array $list, string $on, ?string $group = null, array $asked = []): array
    {
        $count = count($list['employees']);
        if ($count === 0) {
            return BirChatCommands::result(ucfirst($on) . ' has no employees: there was no counted payroll that month.', stay: true);
        }
        if ($group !== null) {
            $all = $count;
            $list['employees'] = array_values(array_filter($list['employees'], fn (array $row) => BirDataAnswers::inGroup($row, $group)));
            $count = count($list['employees']);
            $list['totals'] = ['compensation' => (string) array_sum(array_column($list['employees'], 'compensation')), 'tax_withheld' => (string) array_sum(array_column($list['employees'], 'tax_withheld'))];
            $words = BirDataAnswers::GROUPS[BirDataAnswers::groupKey($group)][$count === 1 ? 0 : 1];
            if ($count === 0) {
                return BirChatCommands::result(ucfirst($on) . " covers {$all} employees; none of them " . ($group === 'minimum_wage' ? 'is a minimum wage earner' : "is {$words}") . '.', stay: true);
            }
        }

        $people = Employee::withTrashed()->whereIn('id', array_column($list['employees'], 'employee_id'))->get()->keyBy('id');
        if ($asked !== []) {
            return $this->details($list, $on, $people, $asked, $group !== null ? ($all ?? $count) : null, $words ?? null);
        }
        $lines = collect($list['employees'])->map(function (array $row) use ($people) {
            $person = $people[$row['employee_id']] ?? null;
            $name = $row['name'] ?? ($person !== null ? mb_strtoupper("{$person->last_name}, {$person->first_name}") : "Employee #{$row['employee_id']}");
            $tin = $row['tin'] !== null ? " ({$row['tin']})" : '';
            $payrolls = $row['payroll_count'] === 1 ? '1 payroll' : "{$row['payroll_count']} payrolls";

            return "- {$name}{$tin}: {$payrolls}, compensation " . self::money($row['compensation']) . ', tax withheld ' . self::money($row['tax_withheld']);
        })->implode("\n");
        $total = 'Total: compensation ' . self::money($list['totals']['compensation']) . ', tax withheld ' . self::money($list['totals']['tax_withheld']) . '.';

        $who = $count === 1 ? '1 employee' : "{$count} employees";
        if ($group !== null) {
            return BirChatCommands::result(ucfirst($on) . " covers {$all} employees; {$count} " . ($count === 1 ? 'is' : 'are') . " {$words}:\n{$lines}\n{$total}", stay: true);
        }

        return BirChatCommands::result(ucfirst($on) . " covers {$who}:\n{$lines}\n{$total}\n" . self::addsUp($list['differences']), stay: true);
    }

    /** The details a message asks for, in the order of ASKED; compensation only when no other amount is. */
    private static function asked(string $message): array
    {
        $asked = array_keys(array_filter(self::ASKED, fn (string $words) => preg_match($words, $message) === 1));
        if (in_array('compensation', $asked, true) && array_intersect($asked, ['thirteenth', 'ee', 'taxable', 'mwe_basic', 'mwe_premium', 'exempt_250k', 'tax_withheld']) !== []
            && !preg_match('/compensation|gross/i', $message)) {
            $asked = array_values(array_diff($asked, ['compensation']));
        }
        // "taxable compensation" is that column, not compensation and tax too.
        if (in_array('taxable', $asked, true) && !preg_match('/\btax(?:es)?\s+withheld|withholding/i', $message)) {
            $asked = array_values(array_diff($asked, ['tax_withheld', 'compensation']));
        }

        return $asked;
    }

    /** "The September 2026 1601-C (…) covers 7 employees. Their TIN and 13th month (item 17):" then one line each, and a total for an amount. */
    private function details(array $list, string $on, $people, array $asked, ?int $all, ?string $words): array
    {
        $name = fn (string $c) => self::ASKED_LABELS[$c] ?? self::lower(self::COLUMNS[$c]);
        $label = fn (string $c) => isset(self::COLUMNS[$c])
            ? preg_replace('/ \((non-taxable part)\)$/', '', self::lower(self::COLUMNS[$c])) . ' (item ' . BirEmployeeList::ITEMS[$c][0] . (str_ends_with(self::COLUMNS[$c], '(non-taxable part)') ? ', the non-taxable part' : '') . ')'
            : self::ASKED_LABELS[$c];
        $cell = function (array $row, ?Employee $person, string $c): string {
            $blank = fn ($v) => trim((string) $v) === '' ? 'none on file' : trim((string) $v);

            return match ($c) {
                'tin' => $row['tin'] ?? ($person !== null && trim((string) $person->tin_number) !== '' ? $person->tin_number : 'no TIN on file'),
                'payroll_count' => $row['payroll_count'] === 1 ? '1 payroll' : "{$row['payroll_count']} payrolls",
                'category' => self::CATEGORIES[$row['category']] ?? (string) $row['category'],
                'hire_date' => $person?->hire_date?->format('F j, Y') ?? 'none on file',
                'salary' => $person?->salary !== null ? BirConversationService::display((string) $person->salary, 'decimal') . ($person->rate_type === 'daily' ? ' a day' : ' a month') : 'none on file',
                'employee_id', 'position', 'department', 'email', 'phone' => $blank($person?->{$c}),
                default => self::money($row[$c]),
            };
        };
        $count = count($list['employees']);
        $lines = collect($list['employees'])->map(function (array $row) use ($people, $asked, $cell, $name) {
            $person = $people[$row['employee_id']] ?? null;
            $who = $row['name'] ?? ($person !== null ? mb_strtoupper("{$person->last_name}, {$person->first_name}") : "Employee #{$row['employee_id']}");
            // One detail: the value alone; several: each named ("TIN 201-…, 13th month … ₱30,000.00").
            $cells = array_map(fn (string $c) => (count($asked) > 1 ? $name($c) . ' ' : '') . $cell($row, $person, $c), $asked);

            return "- {$who}: " . implode(', ', $cells);
        })->implode("\n");
        $money = array_values(array_filter($asked, fn (string $c) => isset(self::COLUMNS[$c])));
        $totals = array_map(fn (string $c) => 'Total ' . $name($c) . ': ' . self::money((string) round(array_sum(array_column($list['employees'], $c)), 2)) . '.', $money);
        $who = $count === 1 ? '1 employee' : "{$count} employees";
        $lead = $all !== null ? ucfirst($on) . " covers {$all} employees; {$count} " . ($count === 1 ? 'is' : 'are') . " {$words}" : ucfirst($on) . " covers {$who}";
        $their = BirDataForms::andList(array_map($label, $asked));

        return BirChatCommands::result("{$lead}. Their {$their}:\n{$lines}" . ($totals !== [] ? "\n" . implode(' ', $totals) : ''), stay: true);
    }

    /** "Compensation" → "compensation", but "SSS, PhilHealth…" stays as it is. */
    private static function lower(string $label): string
    {
        return preg_match('/^[A-Z][a-z]/', $label) ? lcfirst($label) : $label;
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
