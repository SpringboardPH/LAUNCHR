<?php

namespace App\Services\BIR;

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
     * without wiping the form type established two messages ago.
     *
     * @param  array<string,mixed>  $intent
     * @return array<string,mixed> the merged intent
     */
    public function merge(array $intent): array
    {
        $merged = $this->get();

        foreach (['form_type', 'tax_year', 'tax_month', 'employee_query', 'employee_id'] as $field) {
            if (($intent[$field] ?? null) !== null) {
                $merged[$field] = $intent[$field];
            }
        }

        Cache::put($this->key(), $merged, now()->addMinutes(self::TTL_MINUTES));

        return $merged;
    }

    /** Called once a request is fully understood, so the next one starts clean. */
    public function forget(): void
    {
        Cache::forget($this->key());
    }

    private function key(): string
    {
        return "bir:conversation:{$this->userId}";
    }
}