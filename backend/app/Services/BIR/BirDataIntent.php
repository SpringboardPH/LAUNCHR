<?php

namespace App\Services\BIR;

use App\Helpers\SystemClock;

/**
 * Questions about the records the chat can read: "who are the taxable employees?", "how many
 * forms are still drafts?", "who still needs a 2316 for 2025?", "what is Teresa's TIN?".
 *
 * The model labels such a message "data_question" and fills in a question from the fixed lists
 * below: what it is about (topic), a group of employees, a filter, a detail, an amount, list,
 * count, total, average, highest or lowest, and a form status; and for anything else, conditions
 * on any field ("salary over 500,000", "hired before 2025", "position contains Staff"), a sort
 * and the details to show. That is all it does. It never sees an employee, a payroll or a form;
 * BirDataAnswers and BirDataQuery look everything up and word the reply.
 *
 * read() checks the labels against the message, as BirIntentService does for requests: a
 * year, month, person or form the message doesn't give is dropped, and so is a group of
 * employees the message has no word for. A condition's number or words must be in the message
 * too; one that isn't, or a message with a condition the model gave none for ("higher than
 * 500,000" read as "all employees"), is never answered as if it had none (unread). With the
 * model down, fromMessage() reads the plainest questions about employees in PHP alone.
 */
final class BirDataIntent
{
    public const TOPICS = ['employees', 'employee', 'amounts', 'forms', 'missing_forms', 'company', 'unsupported'];

    public const GROUPS = ['taxable', 'minimum_wage', 'exempt_250k', 'not_taxable'];

    public const FILTERS = ['tax_withheld', 'no_tax_withheld', 'no_tin', 'active', 'inactive', 'hired'];

    public const DETAILS = ['tin', 'hire_date', 'position', 'department', 'status', 'employee_id', 'group', 'salary', 'name', 'address', 'rdo_code', 'zip', 'email', 'phone'];

    public const AMOUNTS = ['compensation', 'tax_withheld', 'taxable_compensation', 'non_taxable_compensation', 'contributions', 'thirteenth_month', 'salary'];

    public const MEASURES = ['list', 'count', 'total', 'average', 'median', 'highest', 'lowest'];

    /** What a condition, a sort or a shown detail can be about. salary is the rate on file; the rest of AMOUNTS are paid in the period. */
    public const FIELDS = ['salary', 'compensation', 'tax_withheld', 'taxable_compensation', 'non_taxable_compensation', 'contributions', 'thirteenth_month',
        'hire_date', 'position', 'department', 'status', 'rate_type', 'group', 'tin', 'name', 'email', 'phone'];

    public const NUMBERS = ['salary', 'compensation', 'tax_withheld', 'taxable_compensation', 'non_taxable_compensation', 'contributions', 'thirteenth_month'];

    public const OPS = ['gt', 'gte', 'lt', 'lte', 'eq', 'neq', 'contains', 'starts', 'missing', 'present'];

    public const STATUSES = ['draft', 'pending', 'approved', 'finalized', 'returned', 'not_filed'];

    /** Words that ask about the records even without a question mark: "list all employees", "total tax withheld". */
    public const ASKS_FOR_DATA = '/\b(who|which|list|show|total|how many|count|is|are|did|does|sino|ilan|alin|magkano|kailan|meron|may)\b/i';

    /** What the prompt tells the model about questions; BirIntentService adds it to its rules. */
    public static function rules(): string
    {
        return <<<'TEXT'
        - Set kind to "data_question" when the user asks what this system's records say:
          which employees are taxable, minimum wage earners or exempt (P250,000 a year or
          less), how many employees there are, an employee's TIN, hire date or position,
          how much was paid or withheld (already in payroll), the highest or the total,
          which BIR forms are saved and their status, which forms have not been made yet,
          or the company's own TIN, address, email or phone. Fill in "question" for it. A question is a
          data_question even when it names a form or a period ("who still needs a 2316 for
          2025?", "which months of 2025 have no 1601-C?"). "Who pays tax?" asks for the
          taxable employees; "who had tax deducted?" for those with tax withheld. Roles such as
          the CEO, leaves, attendance and anything else not listed here are topic "unsupported".
          Asking what tax should be, how to compute it or how to lower it is still a tax_question.
          For any other condition on employees use "filters", one per condition, each a field,
          an operator and the value as the user gave it: "base salary higher than 500,000" is
          {field: salary, op: gt, value: 500000}; "hired before 2025" is {field: hire_date, op:
          lt, value: "2025"}; "in Finance" is {field: department, op: eq, value: "Finance"};
          "between 20k and 40k" is two filters (gte 20000, lte 40000); "earns more than Noel" is
          {field: salary, op: gt, value: "Noel"}. "Salary", "base salary", "base pay", "rate",
          "sahod" and "sweldo" are salary (the rate on file); what was earned or paid in a period
          ("top earners in 2025", "paid over 300k last year") is compensation. Use "sort_by", "order" and
          "limit" for "top 3 earners", and "show" for the details to list ("with their TIN").
          "who got 13th month in December" is {field: thirteenth_month, op: gt, value: 0}; "name
          starts with T" is {field: name, op: starts, value: "T"}; "Teresa and Noel's salary" is two
          filters {field: name, op: eq} with show [salary]; "in admin" is {field: department, op:
          contains, value: "admin"}.
          Comparing named employees ("compare Teresa's tax with Noel's", "who's been here longer,
          Andres or Carlo?") is a data_question too. "Which month had the most tax?" and "total per
          month" set per to "month".
          If a condition can't be put this way, use topic "unsupported"; never drop it.
          Set the question's confidence to "low" only when you can't tell what is being asked
          (a missing period is fine: the latest payroll is used).
        TEXT;
    }

    /** The "question" property of the model's answer. */
    public static function schema(): array
    {
        $enum = fn (array $values) => ['type' => ['string', 'null'], 'enum' => [...$values, null]];

        return [
            'type' => ['object', 'null'],
            'description' => 'Only for a data_question; otherwise null.',
            'properties' => [
                'topic' => $enum(self::TOPICS) + ['description' => 'employees: which or how many employees; employee: one named employee (a detail, or whether they are in a group and why); amounts: a total, highest or lowest payroll amount; forms: saved BIR forms and their status; missing_forms: forms not made yet; company: the company\'s registration details; unsupported: anything these records cannot answer.'],
                'group' => $enum(self::GROUPS) + ['description' => 'A group of employees the user names: taxable, minimum wage earners, exempt at P250,000 a year or less, or not taxable (the last two together).'],
                'filter' => $enum(self::FILTERS) + ['description' => 'tax_withheld / no_tax_withheld: had tax withheld or none; no_tin: no TIN on file; active / inactive: employment status; hired: hired in the period.'],
                'detail' => $enum(self::DETAILS) + ['description' => 'For employee or company: the detail asked for. group answers "is X taxable?" and "why is X not taxable?".'],
                'amount' => $enum(self::AMOUNTS) + ['description' => 'For amounts: which amount.'],
                'measure' => $enum(self::MEASURES) + ['description' => 'list (who/which), count (how many), total, average, median (the middle figure), highest or lowest.'],
                'status' => $enum(self::STATUSES) + ['description' => 'For forms: pending is waiting for review, finalized is filed, returned is sent back for changes, not_filed is anything not filed yet.'],
                'tax_year' => ['type' => ['integer', 'null'], 'description' => 'The year the question is about, or null.'],
                'tax_month' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 12, 'description' => 'The month the question is about, or null.'],
                'employee' => ['type' => ['string', 'null'], 'description' => 'An employee named in the question, exactly as written, or null.'],
                'filters' => [
                    'type' => ['array', 'null'],
                    'description' => 'Conditions on employees. salary is the salary rate on file (base salary); compensation, tax_withheld and the other amounts are what payroll paid in the period.',
                    'items' => ['type' => 'object', 'properties' => [
                        'field' => ['type' => 'string', 'enum' => self::FIELDS],
                        'op' => ['type' => 'string', 'enum' => self::OPS],
                        'value' => ['type' => ['string', 'number', 'null']],
                    ], 'required' => ['field', 'op']],
                ],
                'sort_by' => $enum(self::FIELDS),
                'order' => $enum(['asc', 'desc']),
                'limit' => ['type' => ['integer', 'null'], 'description' => 'How many to list, only when the user says ("top 3").'],
                'show' => ['type' => ['array', 'null'], 'items' => ['type' => 'string', 'enum' => self::FIELDS], 'description' => 'Details to list for each employee.'],
                'confidence' => $enum(['high', 'low']) + ['description' => 'low only when you cannot tell what is being asked.'],
                'per' => $enum(['month', 'department', 'position']) + ['description' => 'month for a month-by-month breakdown ("per month", "which month had the most"); department or position to count employees by it ("which department has the most employees").'],
            ],
        ];
    }

    /**
     * The model's question, checked against the message. Null when there is none to answer.
     *
     * @param  array<string, mixed>  $top  the rest of the model's answer: its year, month and employee, when the question has none
     * @param  ?array<string, mixed>  $previous  the question answered just before, for a follow-up
     * @return ?array{topic: string, group: ?string, filter: ?string, detail: ?string, amount: ?string, measure: string, status: ?string, form_type: ?string, tax_year: ?int, tax_month: ?int, employee: ?string, dropped_group: ?string, unsure: bool, why: bool, pronoun: bool, filters: list<array>, sort_by: ?string, order: ?string, limit: ?int, show: list<string>, top: bool, nth: ?int, months: ?list<int>, groups: list<string>, unread: bool}
     */
    public static function read(mixed $raw, string $message, array $top = [], ?array $previous = null): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        // "what's the average tax of everyone in December 2025?" with no question from the model: its words.
        // "what's the median salary?" read as something the records can't answer: its words too.
        if ((!in_array($raw['topic'] ?? null, self::TOPICS, true) || (($raw['topic'] ?? null) === 'unsupported' && preg_match('/\bmedian\b/i', $message)))
            && ($guess = BirDataWords::guessQuestion($message)) !== null) {
            $raw = $guess + $raw;
        }
        $pick = fn (string $key, array $values) => in_array($raw[$key] ?? null, $values, true) ? $raw[$key] : null;
        $message = preg_replace('/\b(?:got|get|gets|getting)\s+hired\b/i', 'hired', $message); // "who got hired last?" is no amount

        $year = BirMessageFacts::agreed(self::int($raw['tax_year'] ?? $top['tax_year'] ?? null), BirMessageFacts::yearsSaid($message));
        $month = BirMessageFacts::agreed(self::int($raw['tax_month'] ?? $top['tax_month'] ?? null), BirMessageFacts::monthsSaid($message));
        $relative = preg_match('/\b(this|last|next|current|previous) (year|month)\b|\b(ngayong|nakaraang|karaang|susunod na) (taon|buwan)\b/i', $message) === 1;
        if ($year !== null && BirMessageFacts::yearsSaid($message) === [] && !$relative) {
            $year = null;
        }
        if ($month !== null && BirMessageFacts::monthsSaid($message) === [] && !($relative && preg_match('/month|buwan/i', $message))) {
            $month = null;
        }
        // "this year", "last year": the year the message means, when the model gave none.
        if ($year === null && preg_match('/\b(this|last|current|previous) year\b|\b(ngayong|nakaraang) taon\b/i', $message, $rel)) {
            $year = SystemClock::now()->year - (preg_match('/last|previous|nakaraang/i', $rel[0]) ? 1 : 0);
        }
        if ($year !== null && abs($year - SystemClock::now()->year) > 30) {
            $year = null;
        }
        // "January to March 2026", "Q1": more than one month.
        $months = BirDataWords::monthsAsked($message);
        $month = $months !== [] ? null : $month;
        $employee = $raw['employee'] ?? $top['employee_query'] ?? null;
        $employee = is_string($employee) && trim($employee) !== '' && BirMessageFacts::inMessage($employee, $message) ? trim($employee) : null;
        $forms = BirMessageFacts::formsSaid($message);

        $question = [
            'topic' => $pick('topic', self::TOPICS) ?? 'unsupported',
            'group' => BirDataWords::namesAGroup($message) ? $pick('group', self::GROUPS) : null,
            'dropped_group' => BirDataWords::namesAGroup($message) ? null : $pick('group', self::GROUPS), // asked about, never dropped quietly (BirDataDoubt)
            'unsure' => ($raw['confidence'] ?? null) === 'low',
            'filter' => $pick('filter', self::FILTERS),
            'detail' => $pick('detail', self::DETAILS),
            'amount' => $pick('amount', self::AMOUNTS),
            'measure' => preg_match('/\bmedian\b/i', $message) ? 'median' : ($pick('measure', self::MEASURES) ?? 'list'),
            'status' => $pick('status', self::STATUSES) ?? BirDataWords::STATUS_SAID[is_string($raw['status'] ?? null) ? str_replace(' ', '_', strtolower($raw['status'])) : ''] ?? BirDataWords::statusSaid($message),
            'form_type' => count($forms) === 1 ? $forms[0] : null,
            'tax_year' => $year,
            'tax_month' => $month,
            'months' => $months !== [] ? $months : null,
            'employee' => $employee,
            'why' => preg_match('/\b(why|bakit|how come|paano)\b/i', $message) === 1,
            'message' => mb_substr($message, 0, 200), // for BirDataDoubt's wording checks
            'pronoun' => preg_match('/\b(he|she|his|her|him|they|their|them|niya|nya|siya|sya|kanya|kaniya|this employee)\b/i', $message) === 1,
        ];
        // "how many minimum wage earners and how many taxable": each group answered (BirDataAnswers).
        $groups = BirDataWords::groupsSaid($message);
        $question['groups'] = count($groups) > 1 ? $groups : [];
        $rawFilters = array_filter(is_array($raw['filters'] ?? null) ? $raw['filters'] : [], fn ($f) => $question['groups'] === [] || ($f['field'] ?? null) !== 'group');
        if ($question['groups'] !== []) {
            [$question['group'], $question['dropped_group']] = [null, null];
            // Two groups and no one named: a question about the employees, whatever the model called it.
            if ($question['employee'] === null && in_array($question['topic'], ['unsupported', 'employee'], true)) {
                [$question['topic'], $question['measure']] = ['employees', preg_match('/\bhow\s+many|\bilan\b|\bcount\b/i', $message) ? 'count' : $question['measure']];
            }
        }
        // "is Gino taxable in 2026?": the group the message names, so the answer is a yes or a no.
        if ($question['topic'] === 'employee' && $question['group'] === null && $question['dropped_group'] === null && in_array($question['detail'], [null, 'group'], true)
            && !$question['why'] && BirDataWords::namesAGroup($message)) {
            $question['group'] = BirDataWords::groupSaid($message);
        }
        [$question['filters'], $unread] = self::filters(array_values($rawFilters), $message, $previous['filters'] ?? []);
        // "joined most recently in 2024", "hired in March 2025": that year or month, however the model
        // put it (">= 2024-01-01" alone is broader, and a bound the message doesn't say is dropped).
        $said = BirMessageFacts::yearsSaid($message);
        $aboutHiring = $question['filter'] === 'hired' || $pick('sort_by', self::FIELDS) === 'hire_date' || in_array('hire_date', array_column($rawFilters, 'field'), true);
        if ($aboutHiring && count($said) === 1 && !preg_match(BirDataWords::AMOUNT_WORDS, $message) && preg_match('/\b(?:in|noong|nung|ng|of|for)\s+(?:(?:the\s+)?(?:year\s+)?|[a-z]+\s+)?' . $said[0] . '\b/i', $message)
            && !preg_match('/\b(?:before|after|since|from|until|till|between|prior|later|earlier|bago|pagkatapos|simula|mula|hanggang)\b/i', $message)) {
            $hireless = array_values(array_filter($rawFilters, fn ($f) => ($f['field'] ?? null) !== 'hire_date'));
            [$kept, $unread] = self::filters($hireless, $message, $previous['filters'] ?? []);
            $question['filters'] = [...$kept, ['field' => 'hire_date', 'op' => 'eq', 'value' => $month !== null ? sprintf('%04d-%02d', $said[0], $month) : (string) $said[0]]];
            [$question['filter'], $question['tax_year'], $question['tax_month'], $year, $month] = [$question['filter'] === 'hired' ? null : $question['filter'], null, null, null, null];
        }
        $question['sort_by'] = $pick('sort_by', self::FIELDS);
        $question['order'] = $pick('order', ['asc', 'desc']);
        $limit = self::int($raw['limit'] ?? null);
        $question['limit'] = $limit !== null && $limit > 0 && $limit <= 50 && in_array((float) $limit, BirDataWords::numbersSaid($message), true) ? $limit : null;
        // Only details the message has words for: "top 2 earners" never lists the salary on file too.
        $question['show'] = array_values(array_intersect(is_array($raw['show'] ?? null) ? $raw['show'] : [], self::FIELDS, BirDataWords::detailsNamed($message)));
        $question['nth'] = BirDataWords::nth($message);
        $question['per'] = $pick('per', ['month', 'department', 'position']) ?? (preg_match(BirDataWords::PER_MONTH, $message) ? 'month' : null)
            ?? collect(BirDataWords::PER_FIELD)->keys()->first(fn (string $f) => preg_match(BirDataWords::PER_FIELD[$f], $message) === 1);
        if ($question['per'] === 'month' && preg_match('/\b(?:average|avg|karaniwan)\b/i', $message) && preg_match(BirDataWords::MONTHLY, $message) && !preg_match('/\b(?:which|what|anong|aling?)\s+(?:month|buwan)/i', $message)) {
            [$question['per'], $question['measure']] = [null, 'average'];
        }
        // "who had tax withheld every month of 2025?": people who pass in each month, not a monthly breakdown.
        $question['every'] = preg_match(BirDataWords::EVERY_MONTH, $message) === 1 && preg_match('/^\s*(?:who|which|sino|alin|list|ilan|how many)\b/i', $message) === 1;
        $question['per'] = $question['every'] && $question['per'] === 'month' ? null : $question['per'];
        $question['else'] = preg_match(BirDataWords::ELSE, $message) === 1;
        // "taxable and hired in 2024": the year is the hire date's, not the payroll's.
        if ($question['filter'] === 'hired' && !in_array('hire_date', array_column($question['filters'], 'field'), true)
            && $question['tax_year'] !== null && count(BirMessageFacts::yearsSaid($message)) === 1) {
            $question['filters'][] = ['field' => 'hire_date', 'op' => 'eq', 'value' => $question['tax_month'] !== null ? sprintf('%04d-%02d', $question['tax_year'], $question['tax_month']) : (string) $question['tax_year']];
            [$question['filter'], $question['tax_month']] = [null, null];
        }
        $hired = array_map(fn (array $f) => (int) substr((string) $f['value'], 0, 4), array_filter($question['filters'], fn (array $f) => $f['field'] === 'hire_date'));
        if ($question['tax_year'] !== null && in_array($question['tax_year'], $hired, true) && count(BirMessageFacts::yearsSaid($message)) === 1) {
            [$question['tax_year'], $year] = [null, null];
        }
        // "is Noel's salary more than 15k?" read as a list filtered by name: a yes or no about Noel.
        $names = array_values(array_filter($question['filters'], fn (array $f) => $f['field'] === 'name' && $f['op'] === 'eq'));
        if ($question['employee'] === null && count($names) === 1 && count($question['filters']) > 1 && preg_match('/^\s*(?:is|are|does|do|did|has|was|were)\b|\bba\b/i', $message)) {
            [$question['employee'], $employee, $question['topic']] = [(string) $names[0]['value'], (string) $names[0]['value'], 'employee'];
            $question['filters'] = array_values(array_filter($question['filters'], fn (array $f) => $f !== $names[0]));
        }
        // "who was hired after Gino?": Gino is what they are compared with, not who it is about.
        $against = array_column(array_filter($question['filters'], fn (array $f) => in_array($f['field'], [...self::NUMBERS, 'hire_date'], true) && is_string($f['value']) && !preg_match('/^\d/', $f['value'])), 'value');
        // "is Mina's salary over 20k?" with Mina also as a name condition: she is the subject, once.
        if ($question['employee'] !== null) {
            $question['filters'] = array_values(array_filter($question['filters'], fn (array $f) => !($f['field'] === 'name' && $f['op'] === 'eq' && BirMessageFacts::inMessage((string) $f['value'], $question['employee']))));
        }
        $comparedTo = $question['employee'] !== null && preg_match('/\b(?:after|before|than|kaysa|bago|pagkatapos|since)\s+(?:kay\s+|si\s+|ni\s+)?' . preg_quote(explode(' ', $question['employee'])[0], '/') . '/iu', $message) === 1;
        if ($comparedTo && $question['filters'] === []) {
            $unread = true; // "who was hired after Gino?" with no condition read: never Gino's own answer
        }
        if ($question['employee'] !== null && ($comparedTo || collect($against)->contains(fn (string $v) => stripos($question['employee'], $v) !== false || stripos($v, $question['employee']) !== false))) {
            [$question['employee'], $employee] = [null, null];
            $question['topic'] = $question['topic'] === 'employee' ? 'employees' : $question['topic'];
        }
        // "who's the second newest employee?" read as about one employee, with no one named: about them all.
        if ($question['topic'] === 'employee' && $question['employee'] === null && !$question['pronoun']
            && ($question['sort_by'] !== null || $question['filters'] !== [] || $question['nth'] !== null)) {
            $question['topic'] = 'employees';
        }
        // "who's the second newest employee?": by hire date, whatever the model labelled.
        foreach (BirDataWords::HIRED_ORDER as $order => $words) {
            if (!in_array($question['sort_by'], BirDataIntent::NUMBERS, true) && !preg_match(BirDataWords::AMOUNT_WORDS, $message) && $question['employee'] === null
                && in_array($question['topic'], ['employees', 'unsupported', 'amounts', 'employee'], true) && preg_match($words, $message)) {
                [$question['topic'], $question['sort_by'], $question['order'], $question['top'], $question['measure']] = ['employees', 'hire_date', $order, $question['nth'] === null && $question['limit'] === null, 'list'];
                // "who joined most recently in 2024?": among those hired in 2024.
                if ($question['tax_year'] !== null && count(BirMessageFacts::yearsSaid($message)) === 1 && !in_array('hire_date', array_column($question['filters'], 'field'), true)) {
                    $question['filters'][] = ['field' => 'hire_date', 'op' => 'eq', 'value' => $question['tax_month'] !== null ? sprintf('%04d-%02d', $question['tax_year'], $question['tax_month']) : (string) $question['tax_year']];
                    [$question['tax_year'], $question['tax_month'], $year, $month] = [null, null, null, null];
                }
            }
        }
        // "TIN of all employees", "show everyone's position and hire date", "list all employees": for
        // everyone, only the details the message names (none: their names alone), whatever the model added.
        // "the employees with their TIN": "their" is everyone's, not one person's.
        $everyone = $question['employee'] === null && (!$question['pronoun'] || !preg_match('/\b(?:she|he|her|him|his|siya|sya|niya|nya|kanya)\b/i', $message)) && preg_match(BirDataWords::EVERYONE, $message) === 1
            && $question['filters'] === [] && $question['filter'] === null && $question['groups'] === []
            && $question['sort_by'] === null && $question['per'] === null && $question['limit'] === null && $question['nth'] === null;
        $asked = BirDataWords::detailsNamed($message);
        // "compensation of all employees in 2025": each one's, unless a total is asked for.
        $each = $asked !== [] && in_array($question['measure'], ['total', 'list'], true) && in_array($question['topic'], ['amounts', 'employees'], true) && (!preg_match(BirDataWords::TOTAL, $message) || preg_match('/\b(?:each|every|per\s+(?:employee|person)|bawat|kada)\b/i', $message));
        if ($everyone && (in_array($question['topic'], ['unsupported', 'employee'], true) || ($question['topic'] === 'employees' && $question['measure'] === 'list') || $each)
            && ($asked !== [] || ($question['topic'] === 'employees' && $question['group'] === null))) {
            [$question['topic'], $question['show'], $question['measure'], $question['amount']] = ['employees', $asked, 'list', null];
        }
        $question['top'] = $question['sort_by'] !== null && $question['limit'] === null && $question['nth'] === null && preg_match(BirDataWords::ONE, $message) === 1
            && !preg_match(BirDataWords::SORTING, $message);
        // "top earners in 2025": with a period and no word for the rate, what was paid in it.
        if (($year !== null || $month !== null) && !preg_match('/salar|sahod|sweldo|\brate\b|base/i', $message)) {
            foreach (['amount', 'sort_by'] as $key) {
                $question[$key] = $question[$key] === 'salary' ? 'compensation' : $question[$key];
            }
            // A condition carried from the question just answered stays as it was asked.
            $question['filters'] = array_map(fn (array $f) => $f['field'] === 'salary' && !in_array($f, $previous['filters'] ?? [], false) ? ['field' => 'compensation'] + $f : $f, $question['filters']);
        }

        // An amount the message has no word for ("show me the numbers" read as compensation) is a guess: asked about instead.
        if (!preg_match(BirDataWords::AMOUNT_WORDS, $message)) {
            $question['amount'] = $question['amount'] === null || in_array($question['topic'], ['amounts', 'employees'], true) ? null : $question['amount'];
            $question['sort_by'] = in_array($question['sort_by'], self::NUMBERS, true) && $question['filters'] === [] ? null : $question['sort_by'];
        }
        // "who has the most overtime hours?": something these records don't hold. Never offered an amount instead.
        $unknown = preg_match(BirDataWords::UNKNOWN, $message) === 1 && !preg_match(BirDataWords::AMOUNT_WORDS, $message)
            && !collect($question['filters'])->contains(fn (array $f) => in_array($f['field'], ['department', 'position', 'name'], true) && preg_match(BirDataWords::UNKNOWN, implode(' ', (array) $f['value']))); // "not in sales": a position
        if ($unknown) {
            [$question['amount'], $question['sort_by'], $question['filters'], $unread] = [null, null, [], false];
        }
        // Net or take-home pay is after every deduction: not a figure these records keep, never the gross.
        if (preg_match('/\bnet\s*(?:pay|salary|income|sahod)|take[- ]?home|kinuha\s+sa\s+bulsa/i', $message)) {
            [$unknown, $question['amount'], $question['sort_by'], $question['filters'], $unread] = [true, null, null, [], false];
            $question['topic'] = in_array($question['topic'], ['forms', 'missing_forms', 'company'], true) ? $question['topic'] : 'employees';
        }
        if ($unknown && in_array($question['topic'], ['employees', 'amounts', 'unsupported'], true)) {
            $question['topic'] = 'unsupported';
        }
        // "who is the highest?": an amount question that names no amount, asked about (BirDataDoubt), never "can't answer".
        if (!$unknown && !in_array($question['per'], ['department', 'position'], true) && ($vague = BirDataWords::vague($message)) !== null && ($question['topic'] === 'unsupported' || (in_array($question['topic'], ['employees', 'amounts'], true) && $question['amount'] === null && $question['sort_by'] === null))) {
            [$question['topic'], $question['measure']] = ['amounts', $vague];
        }
        if ($question['topic'] === 'company' && isset(BirDataWords::COMPANY_WORDS[$question['detail']]) && !BirDataWords::saysCompanyWord($question['detail'], $message)) {
            $question['topic'] = 'unsupported';
        }

        // "how about 2024?": what this message doesn't change is the question before. A year
        // alone is the whole year ("and for 2025?" after December 2025).
        if ($previous !== null && BirDataWords::followsUp($message)) {
            if ($year !== null && $month === null) {
                $previous['tax_month'] = null;
            }
            // A new period replaces the old one: a month or a year for months, months for a month.
            if ($month !== null || $year !== null) {
                $previous['months'] = null;
            }
            if ($months !== []) {
                $previous['tax_month'] = null;
            }
            if ($question['group'] !== null) {
                $previous['groups'] = [];
            }
            // "what about Teresa?" after "who are the minimum wage earners?": her group, not a yes or no.
            if ($employee !== null && $question['group'] === null) {
                $previous['group'] = null;
            }
            // "how about the minimum wage earners?" after "who has a salary over 30k?": a new condition, not both.
            // "only the taxable ones" narrows it instead: both.
            $narrows = preg_match(BirDataWords::NARROW, $message) === 1;
            if (!$narrows && ($question['group'] !== null || $question['filter'] !== null || $employee !== null)) {
                $previous['filters'] = [];
            }
            if ($narrows && $question['filters'] !== []) {
                $question['filters'] = array_values(array_unique([...$previous['filters'] ?? [], ...$question['filters']], SORT_REGULAR));
            }
            foreach ($question as $key => $value) {
                if (in_array($key, ['dropped_group', 'unsure', 'message'], true)) {
                    continue; // about this message only
                }
                if ($value === null || $value === [] || ($key === 'topic' && $value === 'unsupported') || ($key === 'measure' && ($raw['measure'] ?? null) === null)) {
                    $question[$key] = $previous[$key] ?? $value;
                }
            }
            // "total?" after "what about their tax?": the amount the question before was about.
            if ($question['amount'] === null && in_array($question['measure'], ['total', 'average', 'highest', 'lowest'], true)) {
                $question['amount'] = collect([...($previous['show'] ?? []), $previous['sort_by'] ?? null, $previous['amount'] ?? null])->first(fn ($f) => in_array($f, self::NUMBERS, true));
            }
        }

        // A condition the model left out but the message says plainly: "more than 26k", "monthly paid".
        if ($question['filters'] === [] && in_array($question['topic'], ['employees', 'amounts'], true) && ($said = BirDataWords::comparisonSaid($message)) !== null) {
            $field = collect([$question['amount'], $question['sort_by']])->first(fn ($f) => in_array($f, self::NUMBERS, true))
                ?? (BirDataWords::guessQuestion('total ' . $message)['amount'] ?? null);
            if ($field !== null) {
                $question['filters'][] = ['field' => $field, 'op' => $said[0], 'value' => $said[1]];
            }
        }
        if (!in_array('rate_type', array_column($question['filters'], 'field'), true) && ($rate = BirDataWords::rateSaid($message)) !== null && in_array($question['topic'], ['employees', 'unsupported'], true)) {
            [$question['topic'], $question['filters'][]] = ['employees', ['field' => 'rate_type', 'op' => 'eq', 'value' => $rate]];
        }
        // "forms waiting for review or approved": every status named.
        $question['statuses'] = $question['topic'] === 'forms' ? BirDataWords::statusesSaid($message) : [];
        if (in_array($question['topic'], ['forms', 'missing_forms', 'company'], true)) {
            $unread = false; // a condition on employees doesn't bear on forms or the company
        }
        // "who has base salary higher than 500,000" with no condition read: never "all employees".
        $none = in_array($question['topic'], ['employees', 'amounts'], true) && $question['filters'] === [] && $question['group'] === null && $question['groups'] === [] && $question['per'] === null && !$question['every']
            && $question['filter'] === null && $question['employee'] === null && $question['sort_by'] === null && !in_array($question['measure'], ['highest', 'lowest'], true);
        // "who got 13th month?" with no condition on an amount read is not a list of everyone either.
        $fields = [...array_column($question['filters'], 'field'), ...$question['show'], $question['sort_by'], $question['amount']];
        // A detail to show ("give me everyone's TIN") is what is asked: then "give" is no amount.
        $amountOnly = $question['topic'] === 'employees' && in_array($question['measure'], ['list', 'count'], true) && $question['group'] === null && $question['groups'] === [] && $question['show'] === []
            && !in_array('rate_type', array_column($question['filters'], 'field'), true)
            && $question['filter'] === null && $question['per'] === null && array_intersect($fields, self::NUMBERS) === []
            && preg_match(BirDataWords::AMOUNT_WORDS, $message) === 1 && !BirDataWords::namesAGroup($message);
        $question['unread'] = $unread || ($none && preg_match(BirDataWords::CONDITION, $message) === 1) || $amountOnly;
        // "who's number one?" read as "employees": with no word for employees either, nothing to list.
        if ($none && !$question['unread'] && $question['topic'] === 'employees' && $question['measure'] === 'list' && $question['show'] === [] && !preg_match(BirDataWords::EMPLOYEE_WORDS, $message)) {
            $question['topic'] = 'unsupported';
        }

        return $question;
    }

    /**
     * The model's conditions, each checked against the message: a number must be one the message
     * gives ("500,000", "30k", "1.5m"), a word or name must be in it, a year must be said. Returns
     * the conditions kept, and whether any was dropped (the question is then not answered).
     *
     * @return array{0: list<array{field: string, op: string, value: mixed}>, 1: bool}
     */
    private static function filters(mixed $raw, string $message, array $before = []): array
    {
        $kept = [];
        $dropped = false;
        foreach (is_array($raw) ? $raw : [] as $f) {
            $field = $f['field'] ?? null;
            $op = $f['op'] ?? null;
            $value = $f['value'] ?? null;
            $value = $field === 'hire_date' && is_numeric($value) ? (string) (int) $value : $value; // 2024 as a number
            if (!in_array($field, self::FIELDS, true) || !in_array($op, self::OPS, true)) {
                $dropped = true;
                continue;
            }
            // "75k" or "Monthly" as the model wrote it: the number, and the word in lowercase.
            if (in_array($field, self::NUMBERS, true) && is_string($value) && !is_numeric($value) && count(BirDataWords::numbersSaid($value)) === 1) {
                $value = BirDataWords::numbersSaid($value)[0];
            }
            $value = in_array($field, ['rate_type', 'status'], true) && is_string($value) ? strtolower($value) : $value;
            $ok = match (true) {
                in_array($op, ['missing', 'present'], true) => true,
                // Carried from the question just answered ("only the taxable ones" after "over 15k").
                in_array(['field' => $field, 'op' => $op, 'value' => is_numeric($value) && in_array($field, self::NUMBERS, true) ? (float) $value : $value], $before, false) => true,
                // "who got 13th month?": more than nothing, whatever the message's numbers.
                in_array($field, self::NUMBERS, true) && is_numeric($value) && (float) $value === 0.0 && in_array($op, ['gt', 'lte', 'eq'], true) => true,
                // One letter "contains" is almost everyone: only a start ("starts with T") is read.
                $op === 'contains' && is_string($value) && mb_strlen(trim($value)) < 2 => false,
                in_array($field, self::NUMBERS, true) && !is_numeric($value) => is_string($value) && trim($value) !== '' && BirMessageFacts::inMessage($value, $message), // "more than Noel"
                in_array($field, self::NUMBERS, true) => in_array((float) $value, BirDataWords::numbersSaid($message), true),
                $field === 'hire_date' && is_string($value) && !preg_match('/^\d/', $value) => BirMessageFacts::inMessage($value, $message), // "hired before Bea"
                $field === 'hire_date' => is_string($value) && preg_match('/^((?:19|20)\d{2})(?:-\d{2}(?:-\d{2})?)?$/', $value, $y) === 1 && in_array((int) $y[1], [...BirMessageFacts::yearsSaid($message),
                    ...(preg_match('/\bthis\s+year|ngayong\s+taon/i', $message) ? [SystemClock::now()->year] : []), ...(preg_match('/\blast\s+year|nakaraang\s+taon/i', $message) ? [SystemClock::now()->year - 1] : [])], true),
                $field === 'group' => in_array($value, self::GROUPS, true) && BirDataWords::namesAGroup($message),
                $field === 'status' => in_array($value, ['active', 'inactive'], true),
                $field === 'rate_type' => in_array($value, ['monthly', 'daily'], true) && preg_match('/month|daily|day|araw|buwan/i', $message) === 1,
                default => (is_string($value) || is_numeric($value)) && trim((string) $value) !== '' && BirMessageFacts::inMessage((string) $value, $message),
            };
            if (!$ok) {
                $dropped = true;
                continue;
            }
            $kept[] = ['field' => $field, 'op' => $op, 'value' => in_array($field, self::NUMBERS, true) && is_numeric($value) ? (float) $value : $value];
        }

        return [$kept, $dropped];
    }

    /**
     * The plainest questions about employees, read in PHP when the model is down: "list all
     * employees", "who are the minimum wage earners?", "ilan ang taxable na empleyado?".
     */
    public static function fromMessage(string $message): ?array
    {
        if (!preg_match('/\b(employees?|empleyado|staff|earners?)\b/i', $message)) {
            return null;
        }
        $count = preg_match('/\b(how many|ilan|number of)\b/i', $message) === 1;

        return self::read(['topic' => 'employees', 'group' => BirDataWords::groupSaid($message), 'measure' => $count ? 'count' : 'list'], $message);
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }
}
