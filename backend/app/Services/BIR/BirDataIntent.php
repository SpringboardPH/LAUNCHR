<?php

namespace App\Services\BIR;

use App\Helpers\SystemClock;

/**
 * Questions about the records the chat can read: "who are the taxable employees?", "how many
 * forms are still drafts?", "who still needs a 2316 for 2025?", "what is Teresa's TIN?".
 *
 * The model labels such a message "data_question" and fills in a question from the fixed lists
 * below: what it is about (topic), a group of employees, a filter, a detail, an amount, list,
 * count, total, highest or lowest, and a form status. That is all it does. It never sees an
 * employee, a payroll or a form; BirDataAnswers looks everything up and words the reply.
 *
 * read() checks the labels against the message, as BirIntentService does for requests: a
 * year, month, person or form the message doesn't give is dropped, and so is a group of
 * employees the message has no word for. With the model down, fromMessage() reads the
 * plainest questions about employees in PHP alone.
 */
final class BirDataIntent
{
    public const TOPICS = ['employees', 'employee', 'amounts', 'forms', 'missing_forms', 'company', 'unsupported'];

    public const GROUPS = ['taxable', 'minimum_wage', 'exempt_250k', 'not_taxable'];

    public const FILTERS = ['tax_withheld', 'no_tax_withheld', 'no_tin', 'active', 'inactive', 'hired'];

    public const DETAILS = ['tin', 'hire_date', 'position', 'department', 'status', 'employee_id', 'group', 'name', 'address', 'rdo_code', 'zip'];

    public const AMOUNTS = ['compensation', 'tax_withheld', 'taxable_compensation', 'non_taxable_compensation', 'contributions', 'thirteenth_month'];

    public const MEASURES = ['list', 'count', 'total', 'highest', 'lowest'];

    public const STATUSES = ['draft', 'pending', 'approved', 'finalized', 'returned', 'not_filed'];

    /**
     * A word for a group of employees; without one (or one of GROUP_SPELLED typed with a slip), a
     * group the model gives is dropped. "tax" alone isn't one: "total tax withheld" names no group.
     */
    private const GROUP_WORDS = '/taxable|minimum|mwe|min\.?\s*wage|exempt|250|\bpays?\s+(?:any\s+|income\s+|withholding\s+)?(?:tax|buwis)|nagbabayad\s+ng\s+(?:tax|buwis)/i';

    private const GROUP_SPELLED = ['taxable', 'nontaxable', 'minimum', 'exempt', 'exempted'];

    /** The words a company detail needs: "who is our CEO?" is not the company's name. */
    private const COMPANY_WORDS = ['name' => '/\bname|pangalan|called\b/i', 'tin' => '/\btin\b/i', 'rdo_code' => '/\brdo\b/i', 'address' => '/address|tirahan|located|location/i', 'zip' => '/\bzip|postal/i'];

    /** "how about 2024?", "e yung minimum wage?": a follow-up to the question just answered. */
    private const FOLLOW_UP = '/^\s*(?:(?:and\s+)?(?:how|what)\s+about|and\s+(?:for|in|the|who|how)|e\s|eh\s|ehh?\s|paano\s+(?:naman\s+)?(?:sa|kung|yung)|ano\s+naman|sa\s|how\s+many\s+of\s+them|only\s+the|yung\s|ilan\s+sila|sino\s+naman)|^\s*(?:how\s+many|ilan|count|bilang)\s*\??\s*$/iu';

    /** A status as the model or the user may say it. */
    private const STATUS_SAID = ['filed' => 'finalized', 'final' => 'finalized', 'submitted' => 'pending', 'for_review' => 'pending', 'under_review' => 'pending', 'unfiled' => 'not_filed', 'open' => 'not_filed'];

    /** What the prompt tells the model about questions; BirIntentService adds it to its rules. */
    public static function rules(): string
    {
        return <<<'TEXT'
        - Set kind to "data_question" when the user asks what this system's records say:
          which employees are taxable, minimum wage earners or exempt (P250,000 a year or
          less), how many employees there are, an employee's TIN, hire date or position,
          how much was paid or withheld (already in payroll), the highest or the total,
          which BIR forms are saved and their status, which forms have not been made yet,
          or the company's own TIN or address. Fill in "question" for it. A question is a
          data_question even when it names a form or a period ("who still needs a 2316 for
          2025?", "which months of 2025 have no 1601-C?"). "Who pays tax?" asks for the
          taxable employees; "who had tax deducted?" for those with tax withheld. Roles such as
          the CEO, leaves, attendance and anything else not listed here are topic "unsupported".
          Asking what tax should be, how to compute it or how to lower it is still a tax_question.
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
                'measure' => $enum(self::MEASURES) + ['description' => 'list (who/which), count (how many), total, highest or lowest.'],
                'status' => $enum(self::STATUSES) + ['description' => 'For forms: pending is waiting for review, finalized is filed, returned is sent back for changes, not_filed is anything not filed yet.'],
                'tax_year' => ['type' => ['integer', 'null'], 'description' => 'The year the question is about, or null.'],
                'tax_month' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 12, 'description' => 'The month the question is about, or null.'],
                'employee' => ['type' => ['string', 'null'], 'description' => 'An employee named in the question, exactly as written, or null.'],
            ],
        ];
    }

    /**
     * The model's question, checked against the message. Null when there is none to answer.
     *
     * @param  array<string, mixed>  $top  the rest of the model's answer: its year, month and employee, when the question has none
     * @param  ?array<string, mixed>  $previous  the question answered just before, for a follow-up
     * @return ?array{topic: string, group: ?string, filter: ?string, detail: ?string, amount: ?string, measure: string, status: ?string, form_type: ?string, tax_year: ?int, tax_month: ?int, employee: ?string, why: bool}
     */
    public static function read(mixed $raw, string $message, array $top = [], ?array $previous = null): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $pick = fn (string $key, array $values) => in_array($raw[$key] ?? null, $values, true) ? $raw[$key] : null;

        $year = BirMessageFacts::agreed(self::int($raw['tax_year'] ?? $top['tax_year'] ?? null), BirMessageFacts::yearsSaid($message));
        $month = BirMessageFacts::agreed(self::int($raw['tax_month'] ?? $top['tax_month'] ?? null), BirMessageFacts::monthsSaid($message));
        $relative = preg_match('/\b(this|last|next|current|previous) (year|month)\b|\b(ngayong|nakaraang|karaang|susunod na) (taon|buwan)\b/i', $message) === 1;
        if ($year !== null && BirMessageFacts::yearsSaid($message) === [] && !$relative) {
            $year = null;
        }
        if ($month !== null && BirMessageFacts::monthsSaid($message) === [] && !($relative && preg_match('/month|buwan/i', $message))) {
            $month = null;
        }
        if ($year !== null && abs($year - SystemClock::now()->year) > 30) {
            $year = null;
        }
        $employee = $raw['employee'] ?? $top['employee_query'] ?? null;
        $employee = is_string($employee) && trim($employee) !== '' && BirMessageFacts::inMessage($employee, $message) ? trim($employee) : null;
        $forms = BirMessageFacts::formsSaid($message);

        $question = [
            'topic' => $pick('topic', self::TOPICS) ?? 'unsupported',
            'group' => self::namesAGroup($message) ? $pick('group', self::GROUPS) : null,
            'filter' => $pick('filter', self::FILTERS),
            'detail' => $pick('detail', self::DETAILS),
            'amount' => $pick('amount', self::AMOUNTS),
            'measure' => $pick('measure', self::MEASURES) ?? 'list',
            'status' => $pick('status', self::STATUSES) ?? self::STATUS_SAID[is_string($raw['status'] ?? null) ? str_replace(' ', '_', strtolower($raw['status'])) : ''] ?? self::statusSaid($message),
            'form_type' => count($forms) === 1 ? $forms[0] : null,
            'tax_year' => $year,
            'tax_month' => $month,
            'employee' => $employee,
            'why' => preg_match('/\b(why|bakit|how come|paano)\b/i', $message) === 1,
        ];

        if ($question['topic'] === 'company' && isset(self::COMPANY_WORDS[$question['detail']]) && !preg_match(self::COMPANY_WORDS[$question['detail']], $message)) {
            $question['topic'] = 'unsupported';
        }

        // "how about 2024?": what this message doesn't change is the question before. A year
        // alone is the whole year ("and for 2025?" after December 2025).
        if ($previous !== null && self::followsUp($message)) {
            if ($year !== null && $month === null) {
                $previous['tax_month'] = null;
            }
            foreach ($question as $key => $value) {
                if ($value === null || ($key === 'topic' && $value === 'unsupported') || ($key === 'measure' && ($raw['measure'] ?? null) === null)) {
                    $question[$key] = $previous[$key] ?? $value;
                }
            }
        }

        return $question;
    }

    /** A word for a group of employees, or one typed with a slip ("taxbale", "minimun", "exmept"). */
    private static function namesAGroup(string $message): bool
    {
        if (preg_match(self::GROUP_WORDS, $message)) {
            return true;
        }
        foreach (preg_split('/[^a-z]+/', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            foreach (self::GROUP_SPELLED as $group) {
                if (strlen($word) >= 5 && BirMessageFacts::slips($word, $group) <= 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** "which forms are filed?", "not filed yet", "waiting for review": a status the message says. */
    private static function statusSaid(string $message): ?string
    {
        return match (true) {
            (bool) preg_match('/\b(?:not|hindi pa|di pa|un)\s*-?\s*filed\b|\bnot yet filed\b|\b(?:to be|to|for) fil(?:ed|e|ing)\b/i', $message) => 'not_filed',
            (bool) preg_match('/\bfiled\b|\bnai-?file\b/i', $message) => 'finalized',
            (bool) preg_match('/\b(?:waiting for|for|under|pending) review\b|\bpending\b/i', $message) => 'pending',
            (bool) preg_match('/\breturned\b|\bsent back\b|\bibinalik\b/i', $message) => 'returned',
            (bool) preg_match('/\bapproved\b/i', $message) => 'approved',
            (bool) preg_match('/\bdrafts?\b/i', $message) => 'draft',
            default => null,
        };
    }

    /** True for a message that reads as a follow-up to the question just answered. */
    public static function followsUp(string $message): bool
    {
        return preg_match(self::FOLLOW_UP, $message) === 1;
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

        return self::read(['topic' => 'employees', 'group' => self::groupSaid($message), 'measure' => $count ? 'count' : 'list'], $message);
    }

    /** The group of employees a message names in so many words: "minimum wage", "taxable", "not taxable", "exempt". */
    public static function groupSaid(string $message): ?string
    {
        return match (true) {
            (bool) preg_match('/minimum|mwe|min\.?\s*wage/i', $message) => 'minimum_wage',
            (bool) preg_match('/exempt|250/i', $message) => 'exempt_250k',
            (bool) preg_match('/\b(?:non-?|not |hindi )taxable\b|nontaxable/i', $message) => 'not_taxable',
            (bool) preg_match('/\btaxable\b/i', $message) => 'taxable',
            default => null,
        };
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }
}
