<?php

namespace App\Services\BIR;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Finds the employee a user meant, in Laravel — never in the model.
 *
 * The language model has no access to the employees table. Asked to identify
 * someone it would invent a plausible person, so it returns the name exactly
 * as typed and this class does the searching.
 *
 * Four tiers, tried in order, stopping at the first that matches anything.
 * A tier that returns several people ends the search: the user is asked to
 * choose rather than the system falling through to a looser tier, which would
 * let a typo beat an exact match.
 *
 * Separated employees are included deliberately. A 2316 is issued to people
 * who left during the year — excluding them would break the ordinary case.
 */
class BirEmployeeResolver
{
    public const MATCHED = 'matched';

    public const AMBIGUOUS = 'ambiguous';

    public const NOT_FOUND = 'not_found';

    /** Maximum candidates shown before asking the user to be more specific. */
    private const MAX_CANDIDATES = 5;

    /** Overall-similarity floor for the fuzzy tier, as a percentage. */
    private const SIMILARITY_THRESHOLD = 85.0;

    /** Below this length, similarity is too blunt — "Ana" and "Ann" would merge. */
    private const MIN_LENGTH_FOR_SIMILARITY = 5;

    /**
     * @return array{status:string, employee:?Employee, candidates:array<int,array<string,mixed>>}
     */
    public function resolve(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return $this->result(self::NOT_FOUND);
        }

        $employees = Employee::query()
            ->select(['id', 'employee_id', 'first_name', 'last_name', 'email', 'status', 'hire_date'])
            ->get();

        foreach ([
            fn () => $this->byEmployeeId($employees, $query),
            fn () => $this->byExactName($employees, $query),
            fn () => $this->byAllTokens($employees, $query),
            fn () => $this->byCloseSpelling($employees, $query),
        ] as $tier) {
            $hits = $tier();

            if ($hits->count() === 1) {
                return $this->result(self::MATCHED, $hits->first());
            }

            if ($hits->count() > 1) {
                return $this->result(self::AMBIGUOUS, null, $hits);
            }
        }

        return $this->result(self::NOT_FOUND);
    }

    /** Tier 1: "EMP003" is unambiguous. */
    private function byEmployeeId(Collection $employees, string $query): Collection
    {
        $needle = $this->normalize($query);

        return $employees->filter(
            fn (Employee $e) => $this->normalize((string) $e->employee_id) === $needle
        )->values();
    }

    /** Tier 2: the whole name, ignoring case, punctuation and spacing. */
    private function byExactName(Collection $employees, string $query): Collection
    {
        $needle = $this->normalize($query);
        $tight = str_replace(' ', '', $needle);

        return $employees->filter(function (Employee $e) use ($needle, $tight) {
            $full = $this->normalize($e->first_name . ' ' . $e->last_name);

            // "dela cruz" and "delacruz" are the same name spelled two ways.
            return $full === $needle || str_replace(' ', '', $full) === $tight;
        })->values();
    }

    /**
     * Tier 3: every word the user typed appears somewhere in the name.
     * "Juan Cruz" finds "Juan Dela Cruz"; "Cruz" alone finds everyone named Cruz.
     */
    private function byAllTokens(Collection $employees, string $query): Collection
    {
        $tokens = array_filter(explode(' ', $this->normalize($query)));

        if ($tokens === []) {
            return collect();
        }

        return $employees->filter(function (Employee $e) use ($tokens) {
            $full = $this->normalize($e->first_name . ' ' . $e->last_name);

            foreach ($tokens as $token) {
                if (! str_contains($full, $token)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /** Tier 4: allow for typos, in either name order. */
    private function byCloseSpelling(Collection $employees, string $query): Collection
    {
        $needle = $this->normalize($query);

        return $employees->filter(function (Employee $e) use ($needle) {
            $full = $this->normalize($e->first_name . ' ' . $e->last_name);
            $reversed = $this->normalize($e->last_name . ' ' . $e->first_name);

            return $this->isClose($needle, $full) || $this->isClose($needle, $reversed);
        })->values();
    }

    /**
     * Two checks, because each misses what the other catches.
     *
     * Edit distance handles insertions, deletions and substitutions well, but
     * counts a transposition as two edits — so "Gracia" for "Garcia" fails a
     * strict distance check despite being one slip of the fingers. Overall
     * similarity catches that without loosening the distance rule for names
     * that are genuinely different.
     */
    private function isClose(string $needle, string $candidate): bool
    {
        if (levenshtein($needle, $candidate) <= $this->tolerance($needle)) {
            return true;
        }

        if (strlen($needle) < self::MIN_LENGTH_FOR_SIMILARITY) {
            return false;
        }

        similar_text($needle, $candidate, $percent);

        return $percent >= self::SIMILARITY_THRESHOLD;
    }

    /** One edit allowed per eight characters, never more than three. */
    private function tolerance(string $value): int
    {
        return min(3, max(1, (int) floor(strlen($value) / 8)));
    }

    /** Lowercase, strip punctuation, collapse whitespace. */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(['.', ',', "'", '-'], '', $value);

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    /**
     * @param  Collection<int,Employee>|null  $candidates
     * @return array{status:string, employee:?Employee, candidates:array<int,array<string,mixed>>}
     */
    private function result(string $status, ?Employee $employee = null, ?Collection $candidates = null): array
    {
        return [
            'status' => $status,
            'employee' => $employee,
            'candidates' => $candidates
                ? $candidates->take(self::MAX_CANDIDATES)->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'employee_id' => $e->employee_id,
                    'name' => trim($e->first_name . ' ' . $e->last_name),
                    'status' => $e->status,
                ])->values()->all()
                : [],
        ];
    }
}