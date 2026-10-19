<?php

namespace App\Services\BIR;

use App\Helpers\SystemClock;
use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;

/**
 * BirDataAnswers' questions about forms: the saved forms ("which forms are still drafts?",
 * "how many 2316s are filed?", "Teresa's forms") and the forms not made yet ("which months
 * of 2025 have no 1601-C?", "who still needs a 2316?"). Read only; soft-deleted drafts are
 * left out, as everywhere.
 */
final class BirDataForms
{
    /** How each status is said: "1 saved form is still a draft", "2 saved forms are still drafts". */
    private const STATUS_WORDS = [
        'draft' => ['is still a draft', 'are still drafts'], 'pending' => ['is waiting for review', 'are waiting for review'],
        'approved' => ['is approved', 'are approved'], 'finalized' => ['is filed', 'are filed'],
        'returned' => ['was returned for changes', 'were returned for changes'], 'not_filed' => ['is not filed yet', 'are not filed yet'],
    ];

    /** "January, February and May". */
    public static function andList(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items) . " and {$last}";
    }

    /** "Which forms are still drafts?", "how many 2316s are filed?", "Teresa's forms". */
    public static function saved(array $q, ?Employee $person): string
    {
        $query = BirFormDraft::with('employee');
        if ($q['form_type'] !== null) {
            $query->where('form_type', $q['form_type']);
        }
        if ($person !== null) {
            $query->where('employee_id', $person->id);
        }
        $statuses = $q['statuses'] ?? [];
        if ($statuses !== []) {
            $query->where(fn ($w) => collect($statuses)->each(fn (string $st) => $st === 'returned'
                ? $w->orWhere(fn ($r) => $r->where('status', 'draft')->whereNotNull('rejection_reason'))
                : $w->orWhere('status', $st)));
            $q['status'] = null;
        }
        match ($q['status']) {
            null => null,
            'returned' => $query->where('status', 'draft')->whereNotNull('rejection_reason'),
            'not_filed' => $query->where('status', '!=', 'finalized'),
            default => $query->where('status', $q['status']),
        };
        if ($q['tax_year'] !== null && $q['tax_month'] !== null) {
            $query->where('period', sprintf('%04d-%02d', $q['tax_year'], $q['tax_month']));
        } elseif ($q['tax_year'] !== null) {
            $query->where(fn ($w) => $w->where('period', (string) $q['tax_year'])->orWhere('period', 'like', "{$q['tax_year']}-%"));
        }
        $forms = $query->orderByDesc('period')->orderBy('form_type')->orderByDesc('version')->get();
        $which = implode(' ', array_filter([
            $q['form_type'] !== null ? "{$q['form_type']}" : null,
            $person !== null ? 'for ' . BirDataAnswers::name($person) : null,
            $q['tax_year'] !== null ? 'for ' . ($q['tax_month'] !== null ? BirFormNames::monthName(sprintf('%04d-%02d', $q['tax_year'], $q['tax_month'])) : $q['tax_year']) : null,
        ]));
        $status = $q['status'] !== null ? ' ' . self::STATUS_WORDS[$q['status']][$forms->count() === 1 ? 0 : 1] : '';
        if ($statuses !== []) {
            $status = ' ' . ($forms->count() === 1 ? 'is ' : 'are ') . str_replace(' and ', ' or ', self::andList(array_map(fn (string $st) => preg_replace('/^(?:is|are|was|were) (?:still )?/', '', self::STATUS_WORDS[$st][$forms->count() === 1 ? 0 : 1]), $statuses)));
        }
        $lead = ($forms->count() === 1 ? '1 saved form' : "{$forms->count()} saved forms") . ($which !== '' ? " ({$which})" : '') . $status;
        if ($forms->isEmpty()) {
            return 'No saved forms' . ($which !== '' ? " ({$which})" : '') . ($q['status'] !== null ? ' ' . self::STATUS_WORDS[$q['status']][1] : '') . '.';
        }

        return BirDataAnswers::names($forms, $lead, $q['measure'] === 'count', fn (BirFormDraft $d) => BirFormNames::line($d));
    }

    /** Months with counted payroll but no 1601-C, and employees paid in the year with no 2316. */
    public static function missing(array $q, ?BirFormDraft $draft, ?int $latestYear): ?string
    {
        $year = $q['tax_year'] ?? ($draft !== null ? (int) substr($draft->period, 0, 4) : null) ?? $latestYear;
        if ($year === null) {
            return null;
        }
        $paid = Payroll::query()->whereYear(BirAggregationService::PERIOD_DATE, $year)->whereIn('status', BirAggregationService::COUNTED_STATUSES);
        $lines = [];
        if ($q['form_type'] !== Form2316Schema::FORM_TYPE) {
            $months = (clone $paid)->get([BirAggregationService::PERIOD_DATE])->map(fn (Payroll $p) => (int) $p->{BirAggregationService::PERIOD_DATE}->format('n'))->unique()->sort()->values();
            $saved = BirFormDraft::where('form_type', Form1601CSchema::FORM_TYPE)->where('period', 'like', "{$year}-%")->pluck('period')->map(fn (string $p) => (int) substr($p, 5, 2))->unique();
            $none = $months->diff($saved)->map(fn (int $m) => date('F', mktime(0, 0, 0, $m, 1)));
            $lines[] = $months->isEmpty() ? "No month of {$year} has counted payroll, so no 1601-C is needed yet."
                : ($none->isEmpty() ? "Every month of {$year} with counted payroll has a 1601-C."
                    : "Months of {$year} with counted payroll but no 1601-C yet ({$none->count()}): " . $none->implode(', ') . '.');
        }
        if ($q['form_type'] !== Form1601CSchema::FORM_TYPE) {
            $ids = (clone $paid)->distinct()->pluck('employee_id');
            $have = BirFormDraft::where('form_type', Form2316Schema::FORM_TYPE)->where('period', (string) $year)->pluck('employee_id');
            $none = Employee::withTrashed()->whereIn('id', $ids->diff($have))->orderBy('last_name')->get();
            $due = $year >= SystemClock::now()->year ? " ({$year} isn't over yet, so its 2316s aren't due)" : '';
            $lines[] = $ids->isEmpty() ? "No one has counted payroll in {$year}, so no 2316 is needed yet."
                : ($none->isEmpty() ? "Every employee paid in {$year} has a 2316 for it."
                    : BirDataAnswers::names($none, "{$none->count()} of {$ids->count()} employees paid in {$year} have no 2316 for it yet{$due}", false, fn (Employee $e) => BirDataAnswers::name($e)));
        }

        // Made but not filed yet: still to be filed too.
        $open = BirFormDraft::with('employee')->where('status', '!=', 'finalized')->where(fn ($w) => $w->where('period', (string) $year)->orWhere('period', 'like', "{$year}-%"))
            ->when($q['form_type'] !== null, fn ($w) => $w->where('form_type', $q['form_type']))->orderBy('period')->get();
        if ($open->isNotEmpty()) {
            $lines[] = BirDataAnswers::names($open, "Saved for {$year} but not filed yet ({$open->count()})", false, fn (BirFormDraft $d) => BirFormNames::line($d));
        }

        return implode("\n\n", $lines);
    }
}
