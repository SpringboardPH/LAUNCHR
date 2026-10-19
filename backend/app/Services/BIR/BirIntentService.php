<?php

namespace App\Services\BIR;

use App\Helpers\SystemClock;
use App\Services\BIR\Llm\LlmClientInterface;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;

/**
 * Turns "Generate the August 2026 1601-C" into a form type and a period.
 *
 * Week 3 change: parse() now takes what is already known. A bare reply of
 * "August" means nothing on its own — the model needs to be told a 1601-C for
 * 2026 was already being discussed.
 *
 * The model reads language. It does not decide whether the result is usable —
 * validate() does that in ordinary PHP, because a 1601-C without a month is
 * unusable no matter how confident the model claims to be.
 *
 * Employee names are returned exactly as written. Finding the person is
 * BirEmployeeResolver's job, in Laravel, against the employees table.
 *
 * Week 6: the model also sorts messages — a form request or not (parse), and while a
 * draft is being filled in, an answer or a question about a box (classifyDraftMessage).
 * It only labels them. Every reply the user reads is written in PHP.
 *
 * Fixes from chat testing: PHP no longer takes the model's word on two things it can
 * check itself. A form the user never named is dropped (B5), and a message that only
 * names a period or a form is a form request whatever the label (B5). A year that is
 * refused is reported back, so the user hears why it was asked again (B3). A year or a
 * month the user never gave is dropped too, so "2316 for Maria Santos" asks for the year
 * instead of taking this one.
 */
class BirIntentService
{
    /** What parse() says a message is. Anything but form_request gets a fixed reply, not the model's words. */
    public const KINDS = ['form_request', 'tax_question', 'other'];

    /** What classifyDraftMessage() says a message is, while a draft is being filled in. */
    public const DRAFT_KINDS = ['answer', 'explain_meaning', 'explain_source', 'tax_question', 'new_request', 'other'];

    /** The earliest year a form can be prepared for. The latest is next year. */
    public const FIRST_YEAR = 2000;

    /** Month names, English and Filipino, and the words for month and year ("last month"). */
    private const PERIOD_WORDS = [
        'month', 'year', 'buwan', 'taon',
        'january', 'jan', 'february', 'feb', 'march', 'mar', 'april', 'apr', 'may', 'june', 'jun',
        'july', 'jul', 'august', 'aug', 'september', 'sep', 'sept', 'october', 'oct', 'november', 'nov',
        'december', 'dec', 'enero', 'pebrero', 'marso', 'abril', 'mayo', 'hunyo', 'hulyo', 'agosto',
        'setyembre', 'okt', 'oktubre', 'nobyembre', 'disyembre',
    ];

    /** "last month", "this year", "ngayong taon"…: a period given relative to today. */
    private const RELATIVE_PERIOD = '/\b(this|last|next|current|previous) (year|month)\b|\b(ngayong|nakaraang|karaang|isang|susunod na) (taon|buwan)\b/i';

    /** Correcting the request just understood: "no, September pala", "make it 2025", "si Gino instead". */
    private const CORRECTION = '/^\s*(?:no|nope|hindi|ay|oops|sorry|wait|actually|make it|change it to|i mean)\b|\b(?:pala|instead)\b/i';

    /** Small words that can sit around a period or a form name without making it a question. */
    private const FILLER = [
        'for', 'the', 'of', 'in', 'a', 'an', 'form', 'bir', 'please', 'pls', 'last', 'this', 'next',
        'sa', 'ng', 'para', 'noong', 'nung', 'po', 'ngayong', 'nakaraang',
    ];

    public function __construct(private readonly LlmClientInterface $llm) {}

    /**
     * @param  array<string,mixed>  $known  What earlier messages established
     * @return array{
     *   form_type: string|null, tax_year: int|null, tax_month: int|null,
     *   period: string|null, employee_query: string|null, kind: string,
     *   rejected_year: int|null, needs_clarification: bool, clarification: string|null
     * }
     */
    public function parse(string $message, array $known = []): array
    {
        $raw = $this->llm->structured(
            $this->systemPrompt($known),
            $message,
            $this->responseSchema(),
        );

        return $this->validate($raw, $message, $known);
    }

    /**
     * What the message alone says, for when the model fails: the form and period it names,
     * checked as the model's answer would be. Null unless it names exactly one form.
     *
     * @param  array<string,mixed>  $known
     */
    public function fromMessage(string $message, array $known = []): ?array
    {
        return count(BirMessageFacts::formsSaid($message)) === 1 ? $this->validate([], $message, $known) : null;
    }

    /**
     * Sorts a message typed while a draft is being filled in, that wasn't a readable
     * answer: a muddled answer, a question about a box, a tax question, or a new request.
     * The model also names the box, but only from $boxes; anything else becomes null.
     *
     * @param  array<string, string>  $boxes  field key => "Item 22: label", the draft's boxes
     * @param  ?string  $open  the box the open question is about, for "what does this mean?"
     * @return array{kind: string, field: ?string}
     *
     * @throws \RuntimeException when the provider fails (the caller falls back to re-asking)
     */
    public function classifyDraftMessage(string $message, string $formType, array $boxes, ?string $open): array
    {
        $list = implode("\n", array_map(fn (string $key, string $box) => "{$key} | {$box}", array_keys($boxes), $boxes));
        $openLine = $open !== null ? "The question currently open is about the box \"{$open}\"." : 'No question is open.';

        $raw = $this->llm->structured(
            <<<PROMPT
            You sort one message typed while a person fills in a draft of BIR Form {$formType}.
            {$openLine}

            Kinds:
            - "answer": an attempt to answer the open question.
            - "explain_meaning": asks what a box means or what to put in it.
            - "explain_source": asks where a figure came from, why it is that amount, or how it was worked out.
            - "tax_question": asks for tax advice, tax rates or rules, salaries or other payroll amounts.
            - "new_request": asks for a different form, period or employee.
            - "other": anything else.

            For explain_meaning and explain_source, set field to the key of the box the
            message is about, from the list below. If it names no box, use the open
            question's box. Never invent a key; use null if unsure.
            Messages may mix English and Filipino.
            You only sort the message. You never answer it.

            key | box
            {$list}
            PROMPT,
            $message,
            [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string', 'enum' => self::DRAFT_KINDS],
                    'field' => ['type' => ['string', 'null'], 'enum' => [...array_keys($boxes), null]],
                ],
                'required' => ['kind', 'field'],
            ],
        );

        $kind = in_array($raw['kind'] ?? null, self::DRAFT_KINDS, true) ? $raw['kind'] : 'answer';
        $field = is_string($raw['field'] ?? null) && array_key_exists($raw['field'], $boxes) ? $raw['field'] : null;

        return ['kind' => $kind, 'field' => $field];
    }

    /** @param array<string,mixed> $known */
    private function systemPrompt(array $known): string
    {
        $today = SystemClock::today()->toDateString();
        $c = Form1601CSchema::FORM_TYPE;
        $s = Form2316Schema::FORM_TYPE;

        $prompt = <<<PROMPT
        You interpret requests to generate Philippine BIR tax forms inside an HR system.
        Today is {$today}.

        Two forms exist:
        - "{$c}" — monthly remittance return, company-wide. Needs a year AND a month.
        - "{$s}" — annual certificate, one per employee. Needs a year AND an employee.

        Rules:
        - Resolve relative periods ("last month", "this year", "noong August")
          against today's date.
        - Return the employee exactly as the user wrote it. Do not correct spelling,
          expand initials, or guess who they meant.
        - Never invent a year, a month or an employee that the user did not give.
          Leave the field null instead.
        - Only set form_type when the user names the form (for example "1601-C",
          "2316", "remittance return", "certificate") or it is listed under
          "Earlier messages already established". Never work out the form from a
          month, a year or an employee name. Leave form_type null instead.
        - If the form type or period is unclear, set confidence to "low" and put one
          short question in clarification.
        - Requests may mix English and Filipino. "1601c ng August" is a valid request.
        - Set kind to "form_request" when the user wants one of the two forms prepared,
          "tax_question" for questions about tax rules, rates, salaries or payroll
          amounts, and "other" for anything else. Do not answer such questions.
          A message that only gives a month, a year or a form name ("September 2026")
          is a form_request.

        You only interpret the request. You never calculate amounts and never see
        payroll data.
        PROMPT;

        if ($known !== []) {
            $prompt .= "\n\n" . $this->knownContext($known);
        }

        return $prompt;
    }

    /**
     * Tell the model what earlier turns settled, so a one-word reply makes sense.
     *
     * @param  array<string,mixed>  $known
     */
    private function knownContext(array $known): string
    {
        $lines = [];

        foreach ([
            'form_type' => 'Form',
            'tax_year' => 'Year',
            'tax_month' => 'Month',
            'employee_query' => 'Employee',
        ] as $field => $label) {
            if (($known[$field] ?? null) !== null) {
                $lines[] = "- {$label}: {$known[$field]}";
            }
        }

        if ($lines === []) {
            return '';
        }

        return "Earlier messages already established:\n" . implode("\n", $lines)
            . "\nThe user is likely answering a question about what is still missing."
            . "\nRepeat the established values in your reply unless this message changes them.";
    }

    /** @return array<string,mixed> */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'form_type' => [
                    'type' => ['string', 'null'],
                    'enum' => [Form1601CSchema::FORM_TYPE, Form2316Schema::FORM_TYPE, null],
                    'description' => 'Which form was asked for, or null if unclear.',
                ],
                'tax_year' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Four-digit calendar year, or null.',
                ],
                'tax_month' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'maximum' => 12,
                    'description' => '1-12 for 1601-C. Always null for 2316.',
                ],
                'employee_query' => [
                    'type' => ['string', 'null'],
                    'description' => 'Employee as written by the user. Null for 1601-C.',
                ],
                'kind' => [
                    'type' => 'string',
                    'enum' => self::KINDS,
                ],
                'confidence' => [
                    'type' => 'string',
                    'enum' => ['high', 'low'],
                ],
                'clarification' => [
                    'type' => ['string', 'null'],
                    'description' => 'One short question when confidence is low.',
                ],
            ],
            'required' => ['form_type', 'tax_year', 'tax_month', 'employee_query', 'kind', 'confidence'],
        ];
    }

    /**
     * Laravel decides what is usable. The model's confidence is a hint, not a verdict.
     *
     * @param  array<string,mixed>  $raw
     * @param  array<string,mixed>  $known
     */
    private function validate(array $raw, string $message, array $known): array
    {
        $formType = in_array($raw['form_type'] ?? null, [
            Form1601CSchema::FORM_TYPE,
            Form2316Schema::FORM_TYPE,
        ], true) ? $raw['form_type'] : null;

        // The message wins over the model: when it names exactly one form, that is the form,
        // whatever the model said. The prompt says never to work out the form from a month;
        // this makes it hold even when the model does. "September 2026" alone must ask which
        // form, not assume the 1601-C because it is the only monthly one.
        $formsSaid = BirMessageFacts::formsSaid($message);
        if (count($formsSaid) === 1) {
            $formType = $formsSaid[0];
        } elseif ($formType !== null && $formType !== ($known['form_type'] ?? null) && !in_array($formType, $formsSaid, true)) {
            $formType = null;
        }

        // Same for the period: a year or month the model gives must be one the message says.
        // A model that invents one ("1899" for "the August 2026 1601-C") is overruled by the
        // message, and one the model missed is taken from it when it says exactly one.
        $year = BirMessageFacts::agreed(is_int($raw['tax_year'] ?? null) ? $raw['tax_year'] : null, BirMessageFacts::yearsSaid($message));
        $month = BirMessageFacts::agreed(is_int($raw['tax_month'] ?? null) ? $raw['tax_month'] : null, BirMessageFacts::monthsSaid($message));
        // "10" on its own, answering "which month?" for a 1601-C, is October.
        $bareMonth = ($known['form_type'] ?? null) === Form1601CSchema::FORM_TYPE && ($known['tax_month'] ?? null) === null
            && preg_match('/^\s*(1[0-2]|0?[1-9])\s*[.!]?\s*$/', $message, $n) ? (int) $n[1] : null;
        $month = $bareMonth ?? $month;
        // A misspelled month the model didn't read ("Agosot", "Mrch") on a 1601-C: a guess the user checks.
        $guessedMonth = $month === null && ($formsSaid === [Form1601CSchema::FORM_TYPE] || ($formsSaid === [] && ($known['form_type'] ?? null) === Form1601CSchema::FORM_TYPE))
            ? BirMessageFacts::nearMonth($message) : null;
        $month ??= $guessedMonth;
        if ($year !== null && BirMessageFacts::yearsSaid($message) === [] && preg_match(self::RELATIVE_PERIOD, $message)
            && abs($year - SystemClock::now()->year) > 1) {
            $year = null; // "last year" is never 1899
        }

        // Same rule for the period: kept only if this message gives it (or one relative to
        // today), or an earlier message did. A model filling in the current year is a guess.
        if ($year !== null && $year !== ($known['tax_year'] ?? null) && !self::mentionsYear($message)) {
            $year = null;
        }
        if ($month !== null && $month !== $bareMonth && $month !== ($known['tax_month'] ?? null) && !self::mentionsMonth($message) && !BirMessageFacts::spellsMonth($message, $month)) {
            $month = null;
        }
        $employee = is_string($raw['employee_query'] ?? null) && trim($raw['employee_query']) !== ''
            ? trim($raw['employee_query'])
            : null;
        // A name the model gives must be in the message (small typos allowed): a name it
        // made up is never repeated back as if the user had typed it.
        if ($employee !== null && !BirMessageFacts::inMessage($employee, $message)) {
            $employee = null;
        }

        // A year no form can be prepared for is dropped, and kept aside so the reply can
        // say why the year is asked again instead of acting as if none was given.
        $rejectedYear = null;
        if ($year !== null && ($year < self::FIRST_YEAR || $year > SystemClock::now()->year + 1)) {
            $rejectedYear = $year;
            $year = null;
        }

        if ($month !== null && ($month < 1 || $month > 12)) {
            $month = null;
        }

        // 2316 is annual — a month here means the model misread something.
        if ($formType === Form2316Schema::FORM_TYPE) {
            $month = null;
        }

        // A missing or unknown kind is treated as a form request: the request path then
        // checks the result as before, so a model that ignores kind changes nothing.
        $kind = in_array($raw['kind'] ?? null, self::KINDS, true) ? $raw['kind'] : 'form_request';

        // Nothing but a period or a form name can't be a tax question, whatever the label.
        if ($kind !== 'form_request' && self::onlyNamesAPeriodOrForm($message)) {
            $kind = 'form_request';
        }

        return [
            'form_type' => $formType,
            'tax_year' => $year,
            'tax_month' => $month,
            'period' => $this->period($formType, $year, $month),
            'employee_query' => $employee,
            'kind' => $kind,
            'rejected_year' => $rejectedYear,
            'needs_clarification' => ($raw['confidence'] ?? 'low') === 'low' || $guessedMonth !== null,
            'clarification' => is_string($raw['clarification'] ?? null) && trim($raw['clarification']) !== ''
                ? trim($raw['clarification'])
                : null,
        ];
    }

    /** True when the message corrects the request just understood rather than starting a new one. */
    public static function corrects(string $message): bool
    {
        return preg_match(self::CORRECTION, $message) === 1;
    }

    /** A four-digit year, MM/YYYY, YYYY-MM, or a period relative to today. */
    private static function mentionsYear(string $message): bool
    {
        return preg_match('/\b(19|20)\d{2}\b/', $message) === 1 || preg_match(self::RELATIVE_PERIOD, $message) === 1;
    }

    /** A month's name (English or Filipino), MM/YYYY, YYYY-MM, or a month relative to today. */
    private static function mentionsMonth(string $message): bool
    {
        $words = preg_split('/[^a-z]+/', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY);
        $months = array_diff(self::PERIOD_WORDS, ['month', 'year', 'buwan', 'taon']);

        return array_intersect($words, $months) !== []
            || preg_match('/\b\d{1,2}\/\d{4}\b|\b\d{4}-\d{1,2}\b/', $message) === 1
            || preg_match('/\b(this|last|next|current|previous) month\b|\b(ngayong|nakaraang|karaang|isang|susunod na) buwan\b/i', $message) === 1;
    }

    /**
     * True when every word is part of a period or a form name: "September 2026",
     * "Agosto 2026", "last month", "the 2316 for 2025", "08/2026". At least one word must
     * name a month, a year or a form; "please" alone is not a request.
     */
    public static function onlyNamesAPeriodOrForm(string $message): bool
    {
        $words = preg_split('/[\s,.;:!?]+/', mb_strtolower(trim($message)), -1, PREG_SPLIT_NO_EMPTY);
        $named = false;

        foreach ($words as $word) {
            if (in_array($word, self::PERIOD_WORDS, true) || preg_match('/^(\d{4}|\d{1,2}\/\d{4}|\d{4}-\d{1,2}|1601-?c?|2316)$/', $word)) {
                $named = true;
            } elseif (!in_array($word, self::FILLER, true)) {
                return false;
            }
        }

        return $named;
    }

    /** Period string in the shape POST /bir/drafts expects: YYYY-MM or YYYY. */
    private function period(?string $formType, ?int $year, ?int $month): ?string
    {
        if ($formType === null || $year === null) {
            return null;
        }

        if ($formType === Form1601CSchema::FORM_TYPE) {
            return $month === null ? null : sprintf('%04d-%02d', $year, $month);
        }

        return (string) $year;
    }

    /**
     * What is still missing for the form requested. Public so the controller
     * can check it again after merging in earlier turns.
     *
     * @param  array<string,mixed>  $intent
     * @return array<int,string>
     */
    public function missingFields(array $intent): array
    {
        $missing = [];

        if (($intent['form_type'] ?? null) === null) {
            $missing[] = 'which form';
        }

        if (($intent['tax_year'] ?? null) === null) {
            $missing[] = 'the year';
        }

        if (($intent['form_type'] ?? null) === Form1601CSchema::FORM_TYPE
            && ($intent['tax_month'] ?? null) === null) {
            $missing[] = 'the month';
        }

        if (($intent['form_type'] ?? null) === Form2316Schema::FORM_TYPE
            && ($intent['employee_query'] ?? null) === null) {
            $missing[] = 'which employee';
        }

        return $missing;
    }
}