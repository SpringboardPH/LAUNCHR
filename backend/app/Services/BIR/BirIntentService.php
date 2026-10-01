<?php

namespace App\Services\BIR;

use App\Services\BIR\Llm\LlmClientInterface;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Support\Carbon;

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
 */
class BirIntentService
{
    public function __construct(private readonly LlmClientInterface $llm) {}

    /**
     * @param  array<string,mixed>  $known  What earlier messages established
     * @return array{
     *   form_type: string|null, tax_year: int|null, tax_month: int|null,
     *   period: string|null, employee_query: string|null,
     *   needs_clarification: bool, clarification: string|null
     * }
     */
    public function parse(string $message, array $known = []): array
    {
        $raw = $this->llm->structured(
            $this->systemPrompt($known),
            $message,
            $this->responseSchema(),
        );

        return $this->validate($raw);
    }

    /** @param array<string,mixed> $known */
    private function systemPrompt(array $known): string
    {
        $today = Carbon::now()->toDateString();
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
        - If the form type or period is unclear, set confidence to "low" and put one
          short question in clarification.
        - Requests may mix English and Filipino. "1601c ng August" is a valid request.

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
                'confidence' => [
                    'type' => 'string',
                    'enum' => ['high', 'low'],
                ],
                'clarification' => [
                    'type' => ['string', 'null'],
                    'description' => 'One short question when confidence is low.',
                ],
            ],
            'required' => ['form_type', 'tax_year', 'tax_month', 'employee_query', 'confidence'],
        ];
    }

    /**
     * Laravel decides what is usable. The model's confidence is a hint, not a verdict.
     *
     * @param  array<string,mixed>  $raw
     */
    private function validate(array $raw): array
    {
        $formType = in_array($raw['form_type'] ?? null, [
            Form1601CSchema::FORM_TYPE,
            Form2316Schema::FORM_TYPE,
        ], true) ? $raw['form_type'] : null;

        $year = is_int($raw['tax_year'] ?? null) ? $raw['tax_year'] : null;
        $month = is_int($raw['tax_month'] ?? null) ? $raw['tax_month'] : null;
        $employee = is_string($raw['employee_query'] ?? null) && trim($raw['employee_query']) !== ''
            ? trim($raw['employee_query'])
            : null;

        // A year outside living memory is a misparse, not a request.
        if ($year !== null && ($year < 2000 || $year > (int) Carbon::now()->year + 1)) {
            $year = null;
        }

        if ($month !== null && ($month < 1 || $month > 12)) {
            $month = null;
        }

        // 2316 is annual — a month here means the model misread something.
        if ($formType === Form2316Schema::FORM_TYPE) {
            $month = null;
        }

        return [
            'form_type' => $formType,
            'tax_year' => $year,
            'tax_month' => $month,
            'period' => $this->period($formType, $year, $month),
            'employee_query' => $employee,
            'needs_clarification' => ($raw['confidence'] ?? 'low') === 'low',
            'clarification' => is_string($raw['clarification'] ?? null) && trim($raw['clarification']) !== ''
                ? trim($raw['clarification'])
                : null,
        ];
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