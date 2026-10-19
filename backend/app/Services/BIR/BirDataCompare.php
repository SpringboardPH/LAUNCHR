<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Two or more employees side by side: "compare Teresa and Noel's salary", "does she earn more than
 * Noel?" (on Teresa's 2316), "who's been with us longer, Andres or Carlo?", "show Hazel's and Gino's
 * TIN". Each person's figure, and for an amount or a hire date which comes first. What is compared
 * is the model's field when it gave one, else the message's own words for it. Read only.
 */
final class BirDataCompare
{
    /** A comparison in so many words. */
    private const WORDS = '/\b(than|vs\.?|versus|compare[ds]?|comparison|kaysa|mas|or|o|longer|higher|lower|more|less|both|and|at|tsaka|pati)\b/i';

    /** The message's own word for what is compared, when the model gave no field. */
    private const FIELD_WORDS = [
        'hire_date' => '/hire|hired|joined|longer|senior|tenure|matagal|nauna|start|employed since/i',
        'tin' => '/\btin\b/i',
        'position' => '/position|job|title|trabaho/i',
        'department' => '/department|dept|departamento/i',
        'tax_withheld' => '/tax|withh|kaltas|buwis/i',
        'contributions' => '/\bsss\b|philhealth|pag-?ibig|contribut/i',
        'salary' => '/salar|sahod|sweldo|\brate\b|base/i',
        'compensation' => '/earn|paid|\bpay\b|compensat|kita|income|binayad/i',
    ];

    /** True when the message compares employees it names ("Teresa or Noel"): a question about the records, whatever the label. */
    public static function asked(string $message): bool
    {
        return preg_match(self::WORDS, $message) === 1 && app(BirEmployeeResolver::class)->namedIn($message)->count() >= 2;
    }

    /**
     * The people's figures side by side, or null when there is nothing to compare by (the question
     * then goes on as usual).
     *
     * @param  Collection<int, Employee>  $people
     */
    public static function answer(array $q, Collection $people, ?BirFormDraft $draft): ?string
    {
        $message = $q['message'] ?? '';
        $field = collect([$q['amount'], $q['detail'] === 'salary' ? 'salary' : null, $q['sort_by'], ...$q['show'], ...array_column($q['filters'], 'field')])
            ->first(fn ($f) => in_array($f, [...BirDataIntent::NUMBERS, 'hire_date', 'tin', 'position', 'department'], true))
            ?? collect(self::FIELD_WORDS)->keys()->first(fn (string $f) => preg_match(self::FIELD_WORDS[$f], $message) === 1);
        if ($field === null) {
            return null;
        }
        // Earned "in 2025" is pay; earned with no period is the salary rate.
        if ($field === 'compensation' && $q['tax_year'] === null && $q['tax_month'] === null && empty($q['months']) && $draft === null) {
            $field = 'salary';
        }
        $people = Employee::whereIn('id', $people->pluck('id'))->orderBy('last_name')->get();
        $label = ['salary' => 'salary on file', 'compensation' => 'compensation', 'tax_withheld' => 'tax withheld', 'contributions' => 'SSS, PhilHealth and Pag-IBIG (employee share)',
            'taxable_compensation' => 'taxable compensation', 'non_taxable_compensation' => 'non-taxable compensation', 'thirteenth_month' => '13th month and other benefits',
            'hire_date' => 'hire date', 'tin' => 'TIN', 'position' => 'position', 'department' => 'department'][$field];

        // What payroll paid needs a period; a record's details don't.
        $lead = '';
        $rows = collect();
        if (!in_array($field, ['salary', 'hire_date', 'tin', 'position', 'department'], true)) {
            $basis = BirDataPeriod::basis($q, $draft);
            if ($basis === null) {
                return BirDataAnswers::NO_PAYROLL;
            }
            $rows = BirDataPeriod::rows($basis);
            $lead = "Going by {$basis['label']}: ";
        }
        $value = fn (Employee $e) => match ($field) {
            'salary' => $e->salary !== null ? (float) $e->salary : null,
            'hire_date' => $e->hire_date,
            'tin', 'position', 'department' => trim((string) ($field === 'tin' ? $e->tin_number : $e->{$field})) ?: null,
            default => isset($rows[$e->id]) ? (float) ([
                'compensation' => $rows[$e->id]['compensation'], 'tax_withheld' => $rows[$e->id]['tax_withheld'], 'contributions' => $rows[$e->id]['ee'],
                'taxable_compensation' => $rows[$e->id]['taxable'], 'non_taxable_compensation' => $rows[$e->id]['compensation'] - $rows[$e->id]['taxable'],
                'thirteenth_month' => $rows[$e->id]['thirteenth'],
            ][$field]) : null,
        };
        $shown = fn (Employee $e, $v) => match (true) {
            $v === null => $field === 'hire_date' || in_array($field, ['tin', 'position', 'department', 'salary'], true) ? "no {$label} on file" : 'no counted payroll in the period',
            $field === 'salary' => BirConversationService::display($v, 'decimal') . ($e->rate_type === 'daily' ? ' a day' : ' a month'),
            $field === 'hire_date' => 'hired ' . $v->format('F j, Y'),
            in_array($field, BirDataIntent::NUMBERS, true) => BirConversationService::display(round($v, 2) ?: 0.0, 'decimal'),
            default => $v,
        };
        $lines = $people->map(fn (Employee $e) => '- ' . BirDataAnswers::name($e) . ': ' . $shown($e, $value($e)))->implode("\n");
        // "the combined salary of Andres and Carlo": added up, not side by side.
        if (in_array($field, BirDataIntent::NUMBERS, true) && preg_match('/\b(?:combined|total|sum|together|altogether|pinagsama|kabuuan|lahat-lahat)\b/i', $message)) {
            $sum = $people->sum(fn (Employee $e) => $value($e) ?? 0.0);

            return ucfirst($lead . 'combined ' . $label . ' of ' . BirDataForms::andList($people->map(fn (Employee $e) => BirDataAnswers::name($e))->all()) . ': '
                . BirConversationService::display(round($sum, 2), 'decimal') . ($field === 'salary' ? ' a month' : '') . ".\n{$lines}");
        }

        return ucfirst($lead . ucfirst($label) . " side by side:\n{$lines}") . self::verdict($people, $value, $field);
    }

    /** "Teresa Taxable's is higher, by ₱17,000.00." / "Andres Villanueva has been here longest." */
    private static function verdict(Collection $people, callable $value, string $field): string
    {
        $known = $people->filter(fn (Employee $e) => $value($e) !== null);
        if ($known->count() < 2 || !in_array($field, [...BirDataIntent::NUMBERS, 'hire_date'], true)) {
            return '';
        }
        $key = fn (Employee $e) => $field === 'hire_date' ? $value($e)->timestamp : $value($e);
        $sorted = $field === 'hire_date' ? $known->sortBy($key)->values() : $known->sortByDesc($key)->values();
        $name = fn (Employee $e) => trim("{$e->first_name} {$e->last_name}");
        if ($key($sorted[0]) == $key($sorted[1])) {
            return $field === 'hire_date' ? "\n{$name($sorted[0])} and {$name($sorted[1])} were hired the same day." : "\n{$name($sorted[0])} and {$name($sorted[1])} have the same.";
        }
        if ($field === 'hire_date') {
            return "\n{$name($sorted[0])} has been here " . ($known->count() > 2 ? 'longest' : 'longer') . '.';
        }
        $gap = BirConversationService::display(round($value($sorted[0]) - $value($sorted[1]), 2), 'decimal');

        return "\n{$name($sorted[0])}'s is " . ($known->count() > 2 ? 'the highest' : 'higher') . ", by {$gap}" . ($known->count() > 2 ? ' over the next' : '') . '.';
    }
}
