<?php

namespace App\Services\BIR;

use App\Models\Employee;

/**
 * When the chat isn't sure what a question about the records asks, it asks before answering,
 * the way "Did you mean …?" does for a name, instead of answering a question nobody asked:
 *
 * - "who are the high earners?": the model named a group the message has no word for. Taxable
 *   employees, or all of them? (A total keeps everyone: "total tax withheld" names no group.)
 * - "who is the highest?": highest what? Compensation, tax withheld or the salary rate.
 * - "total salary in 2025": the salary rate on file, or what payroll paid in 2025?
 * - the model itself was unsure: the chat says how it read the question and waits for "yes".
 *
 * Choices are numbered, and offered as candidates the panel shows as buttons (clicking one sends
 * its number); BirDataAnswers::resume() reads the reply. Every line here is PHP's.
 */
final class BirDataDoubt
{
    public const NOT_IT = 'Okay, I didn\'t look anything up. Could you ask it another way? For example "who has a salary over 30,000?" or "how much tax was withheld in December 2025?".';

    /**
     * What to ask instead of answering, or null when the question is clear.
     *
     * @return ?array{reply: string, choices: list<array{label: string, keys: list<string>, q: array}>, confirm: bool}
     */
    public static function check(array $q): ?array
    {
        if (in_array($q['per'] ?? null, ['department', 'position'], true)) {
            return null; // "which department has the most employees?" counts people, no amount to ask about
        }
        $about = in_array($q['topic'], ['employees', 'amounts'], true) && $q['employee'] === null;
        $amounts = array_merge(array_filter([$q['amount'], $q['sort_by']]), array_column($q['filters'], 'field'));

        // A group the model read but the message has no word for: never quietly "all employees".
        if ($about && $q['topic'] === 'employees' && ($q['dropped_group'] ?? null) !== null && $q['filters'] === [] && $q['filter'] === null && $q['group'] === null) {
            $group = $q['dropped_group'];

            return self::choose('Do you mean', [
                ['label' => 'the employees who are ' . BirDataAnswers::GROUPS[BirDataAnswers::groupKey($group)][1], 'keys' => [explode('_', $group)[0], 'tax', 'minimum', 'exempt'], 'q' => ['group' => $group] + $q],
                ['label' => 'all employees', 'keys' => ['all', 'everyone', 'lahat'], 'q' => $q],
            ]);
        }
        // "who is the highest?": the amount it is about.
        // "how much did Mina get?": the same, for one person.
        if (in_array($q['topic'], ['employees', 'amounts'], true) && ($q['topic'] === 'amounts' || in_array($q['measure'], ['total', 'average', 'median', 'highest', 'lowest'], true))
            && array_intersect($amounts, BirDataIntent::NUMBERS) === [] && !($q['employee'] !== null && $q['topic'] === 'employees')) {
            return self::choose('Which amount do you mean:', [
                ['label' => 'compensation (what payroll paid)', 'keys' => ['compensation', 'paid', 'pay', 'earn', 'sahod'], 'q' => ['amount' => 'compensation'] + $q],
                ['label' => 'tax withheld', 'keys' => ['tax', 'withheld', 'kaltas', 'buwis'], 'q' => ['amount' => 'tax_withheld'] + $q],
                ['label' => 'salary on file (the rate)', 'keys' => ['salary', 'rate', 'sweldo', 'base'], 'q' => ['amount' => 'salary'] + $q],
            ]);
        }
        // "total salary in 2025": a rate on file has no period; what was paid in it does.
        // With a group or tax withheld named, the period is theirs: "how many of them are taxable in 2025?".
        if ($about && in_array('salary', $amounts, true) && ($q['tax_year'] !== null || $q['tax_month'] !== null)
            && $q['group'] === null && ($q['groups'] ?? []) === [] && !in_array($q['filter'], ['tax_withheld', 'no_tax_withheld'], true)) {
            $period = $q['tax_month'] !== null ? BirFormNames::monthName(sprintf('%04d-%02d', $q['tax_year'] ?? 0, $q['tax_month'])) : (string) $q['tax_year'];
            $paid = $q;
            foreach (['amount', 'sort_by'] as $key) {
                $paid[$key] = $q[$key] === 'salary' ? 'compensation' : $q[$key];
            }
            $paid['filters'] = array_map(fn (array $f) => $f['field'] === 'salary' ? ['field' => 'compensation'] + $f : $f, $q['filters']);

            return self::choose('Do you mean', [
                ['label' => 'the salary rate on file', 'keys' => ['rate', 'file', 'salary', 'base'], 'q' => ['tax_year' => null, 'tax_month' => null] + $q],
                ['label' => "what payroll paid in {$period} (compensation)", 'keys' => ['paid', 'compensation', 'payroll', 'binayad'], 'q' => $paid],
            ]);
        }
        // "who are the high earners?": everyone in order isn't who is "high". The top few, or say an amount.
        if ($about && $q['topic'] === 'employees' && $q['measure'] === 'list' && $q['sort_by'] !== null && $q['limit'] === null && $q['filters'] === []
            && $q['group'] === null && preg_match('/\b(high|higher|top|big|rich|low|lower|small|malaki|mataas|maliit|mababa)\b/i', $q['message'] ?? '')) {
            $label = str_replace('_', ' ', $q['sort_by'] === 'salary' ? 'salary on file' : $q['sort_by']);

            return self::choose('How should I pick them:', [
                ['label' => "the top 3 by {$label}", 'keys' => ['top', 'three'], 'q' => ['limit' => 3] + $q],
                ['label' => 'everyone, ' . ($q['order'] === 'asc' ? 'lowest' : 'highest') . ' first', 'keys' => ['everyone', 'all', 'lahat'], 'q' => $q],
            ], ' Or give an amount, for example "over 30,000".');
        }
        // The model wasn't sure: say how the question was read, and wait for "yes".
        if ($q['unsure'] ?? false) {
            return ['reply' => 'Just to check: do you want ' . self::describe($q) . '? Say yes, or ask it another way.', 'choices' => [], 'confirm' => true];
        }

        return null;
    }

    /** The choice a reply picks: its number ("2"), or a word only that choice has ("tax"). */
    public static function pick(string $message, array $choices): ?array
    {
        if (preg_match('/^\s*(?:#|no\.?\s*|option\s*)?([1-9])\s*[.)!]?\s*$/i', $message, $m) && isset($choices[(int) $m[1] - 1])) {
            return $choices[(int) $m[1] - 1]['q'];
        }
        $words = preg_split('/[^a-z]+/', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY);
        $fits = array_values(array_filter($choices, fn (array $c) => array_intersect($c['keys'], $words) !== []));

        return count($fits) === 1 ? $fits[0]['q'] : null;
    }

    /** The question in words: "the employees with a salary on file over ₱30,000.00", "the total tax withheld in 2025". */
    public static function describe(array $q): string
    {
        $period = match (true) {
            !empty($q['months']) => ' in ' . BirDataPeriod::monthsName($q['months'], $q['tax_year'] ?? (int) date('Y')),
            $q['tax_month'] !== null => ' in ' . BirFormNames::monthName(sprintf('%04d-%02d', $q['tax_year'] ?? 0, $q['tax_month'])),
            $q['tax_year'] !== null => " in {$q['tax_year']}",
            default => '',
        };
        $found = $q['employee'] !== null ? Employee::where('employee_id', $q['employee'])->first() : null; // a code from "yes" or a form
        $who = $found !== null ? trim("{$found->first_name} {$found->last_name}") : ($q['employee'] ?? 'that employee');
        $amount = str_replace('_', ' ', (string) ($q['amount'] ?? 'compensation'));
        $conditions = BirDataConditions::words([...BirDataQuery::fromLabels($q), ...$q['filters']]);

        return match ($q['topic']) {
            'company' => "the company's " . ($q['detail'] !== null ? str_replace('_', ' ', $q['detail']) : 'details'),
            'forms' => 'the saved forms' . ($q['form_type'] !== null ? " ({$q['form_type']})" : '') . ($q['employee'] !== null ? " for {$who}" : '') . ($q['status'] !== null ? ' that are ' . str_replace('_', ' ', $q['status'] === 'finalized' ? 'filed' : $q['status']) : '') . $period,
            'missing_forms' => 'the forms not made yet' . ($period !== '' ? " for {$q['tax_year']}" : ''),
            'employee' => match (true) {
                $q['detail'] !== null && $q['detail'] !== 'group' => "{$who}'s " . str_replace('_', ' ', $q['detail']),
                $q['group'] !== null => "whether {$who} is " . BirDataAnswers::GROUPS[BirDataAnswers::groupKey($q['group'])][0] . $period,
                default => "{$who}'s tax group{$period}",
            },
            default => match (true) {
                $q['employee'] !== null => "{$who}'s {$amount}{$period}",
                in_array($q['measure'], ['total', 'average', 'median'], true) => "the {$q['measure']} {$amount}{$period} for the employees{$conditions}",
                in_array($q['measure'], ['highest', 'lowest'], true) => "who had the {$q['measure']} {$amount}{$period}" . ($conditions !== '' ? ", among the employees{$conditions}" : ''),
                $q['measure'] === 'count' => "how many employees{$conditions}{$period}",
                default => "the list of employees{$conditions}{$period}",
            },
        };
    }

    /** "Do you mean 1. …, or 2. …? Say 1 or 2." with the choices kept for the reply. */
    private static function choose(string $lead, array $choices, string $more = ''): array
    {
        $n = count($choices);
        $listed = implode(', ', array_map(fn (array $c, int $i) => ($i === $n - 1 ? 'or ' : '') . ($i + 1) . ". {$c['label']}", $choices, array_keys($choices)));
        $say = $n === 2 ? 'Say 1 or 2.' : 'Say ' . implode(', ', range(1, $n - 1)) . " or {$n}.";

        return ['reply' => "{$lead} {$listed}? {$say}{$more}", 'choices' => $choices, 'confirm' => false];
    }
}
