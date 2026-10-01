<?php

namespace App\Services\BIR;

use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers what has already been established across several messages.
 *
 * Without this, every message starts from nothing: the user answers "August"
 * to "which month?" and the system has forgotten it was ever discussing a
 * 1601-C.
 *
 * Stored server-side against the user, never in the browser, and expires after
 * 30 minutes of silence. From Week 5 this moves onto the draft record, which
 * outlives a single sitting — a half-finished 2316 should still be there on
 * Monday.
 */
class BirConversationState
{
    private const TTL_MINUTES = 30;

    /** Fields carried between messages. */
    private const FIELDS = ['form_type', 'tax_year', 'tax_month', 'employee_query', 'employee_id'];

    public function __construct(private readonly int $userId) {}

    /** @return array<string,mixed> */
    public function get(): array
    {
        return Cache::get($this->key(), []);
    }

    /**
     * Merge what was just understood into what was already known.
     *
     * Only non-null values overwrite, so a reply of "August" adds the month
     * without wiping the form type established two messages ago. The cost of
     * that rule is that nothing is ever cleared, so switching form type
     * mid-conversation would otherwise carry across values the new form has
     * no place for — a 2316 inheriting a month, a 1601-C inheriting an
     * employee. forFormType() drops those before they reach the draft
     * endpoint and come back as a confusing validation error.
     *
     * @param  array<string,mixed>  $intent
     * @return array<string,mixed> the merged intent, including the period string
     */
    public function merge(array $intent): array
    {
        $merged = $this->get();

        foreach (self::FIELDS as $field) {
            if (($intent[$field] ?? null) !== null) {
                $merged[$field] = $intent[$field];
            }
        }

        $merged = $this->forFormType($merged);
        $merged['period'] = $this->period($merged);

        Cache::put($this->key(), $merged, now()->addMinutes(self::TTL_MINUTES));

        return $merged;
    }

    /** Called once a request is fully understood, so the next one starts clean. */
    public function forget(): void
    {
        Cache::forget($this->key());
    }

    /**
     * Drop whatever the selected form has no place for.
     *
     * @param  array<string,mixed>  $merged
     * @return array<string,mixed>
     */
    private function forFormType(array $merged): array
    {
        $formType = $merged['form_type'] ?? null;

        if ($formType === Form2316Schema::FORM_TYPE) {
            // Annual certificate — no month.
            $merged['tax_month'] = null;
        }

        if ($formType === Form1601CSchema::FORM_TYPE) {
            // Company-wide monthly return — names no employee.
            $merged['employee_query'] = null;
            $merged['employee_id'] = null;
        }

        return $merged;
    }

    /**
     * The period string POST /bir/drafts expects: YYYY-MM for 1601-C,
     * YYYY for 2316. Null until enough is known to build it.
     *
     * Rebuilt here rather than carried over from parse(), which only ever
     * sees one message and cannot know the year was settled two turns ago.
     *
     * @param  array<string,mixed>  $merged
     */
    private function period(array $merged): ?string
    {
        $formType = $merged['form_type'] ?? null;
        $year = $merged['tax_year'] ?? null;
        $month = $merged['tax_month'] ?? null;

        if ($formType === null || $year === null) {
            return null;
        }

        if ($formType === Form1601CSchema::FORM_TYPE) {
            return $month === null ? null : sprintf('%04d-%02d', $year, $month);
        }

        return (string) $year;
    }

    private function key(): string
    {
        return "bir:conversation:{$this->userId}";
    }
}