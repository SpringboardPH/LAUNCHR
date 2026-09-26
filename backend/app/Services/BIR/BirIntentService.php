<?php

namespace App\Services\BIR;

use App\Services\BIR\Llm\LlmClientInterface;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Support\Carbon;

/**
 * Turns "Generate the August 2026 1601-C" into a form type and a period.
 *
 * The model reads language. It does not decide whether the result is usable —
 * validate() does that in ordinary PHP, because a 1601-C without a month is
 * unusable no matter how confident the model claims to be.
 *
 * Employee names are returned exactly as written. Resolving them against the
 * employees table happens in Week 3, in Laravel, never in the model.
 */
class BirIntentService
{
    public function __construct(private readonly LlmClientInterface $llm) {}

    /**
     * @return array{
     *   form_type: string|null, tax_year: int|null, tax_month: int|null,
     *   period: string|null, employee_query: string|null,
     *   needs_clarification: bool, clarification: string|null
     * }
     */
    public function parse(string $message): array
    {
        $raw = $this->llm->structured(
            $this->systemPrompt(),
            $message,
            $this->responseSchema(),
        );

        return $this->validate($raw);
    }

    private function systemPrompt(): string
    {
        $today = Carbon::now()->toDateString();
        $c = Form1601CSchema::FORM_TYPE;
        $s = Form2316Schema::FORM_TYPE;

        return <<<PROMPT
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

        $missing = [];

        if ($formType === null) {
            $missing[] = 'which form';
        }
        if ($year === null) {
            $missing[] = 'the year';
        }
        if ($formType === Form1601CSchema::FORM_TYPE && $month === null) {
            $missing[] = 'the month';
        }
        if ($formType === Form2316Schema::FORM_TYPE && $employee === null) {
            $missing[] = 'which employee';
        }

        $needsClarification = $missing !== [] || ($raw['confidence'] ?? 'low') === 'low';

        return [
            'form_type' => $formType,
            'tax_year' => $year,
            'tax_month' => $month,
            'period' => $this->period($formType, $year, $month),
            'employee_query' => $employee,
            'needs_clarification' => $needsClarification,
            'clarification' => $this->clarification($raw, $missing),
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
     * Prefer the model's own question when it asked one; otherwise build a
     * plain one from what is actually missing.
     *
     * @param  array<string,mixed>  $raw
     * @param  array<int,string>  $missing
     */
    private function clarification(array $raw, array $missing): ?string
    {
        $fromModel = $raw['clarification'] ?? null;

        if (is_string($fromModel) && trim($fromModel) !== '') {
            return trim($fromModel);
        }

        if ($missing === []) {
            return null;
        }

        if (count($missing) === 1) {
            return 'Could you tell me ' . $missing[0] . '?';
        }

        $last = array_pop($missing);

        return 'Could you tell me ' . implode(', ', $missing) . ' and ' . $last . '?';
    }
}
