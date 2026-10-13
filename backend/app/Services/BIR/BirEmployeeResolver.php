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
 *
 * Supervisor's changes: a TIN finds the employee too ("2316 for 920-919-920-00001 2024"),
 * matched on its digits so dashes and spaces don't matter, and mentionedIn() finds whoever
 * a whole sentence names ("edit 2316 item 6 for maria santos, change it to …"), so a box
 * on any saved form can be read or changed without the model.
 */
class BirEmployeeResolver
{
    public const MATCHED = 'matched';

    public const AMBIGUOUS = 'ambiguous';

    public const NOT_FOUND = 'not_found';

    /** mentionedIn() only: the message names no one. */
    public const NOT_NAMED = 'not_named';

    /**
     * A TIN as people type it: 3-3-3 digits with the same dash or space between and an
     * optional 3-5 digit branch code, nine digits then the branch code, or all of it run
     * together (12-14 digits). A bare 9-digit number is not taken as a TIN, since it reads
     * as an amount just as well.
     */
    public const TIN_PATTERN = '/(?<![\d-])(?:\d{3}([- ])\d{3}\1\d{3}(?:\1\d{3,5})?|\d{9}[- ]\d{3,5}|\d{12,14})(?![\d-])/';

    /** Words that look like a first or last name but name a month, a form or a command. */
    private const NOT_NAMES = [
        'may', 'june', 'april', 'august', 'item', 'box', 'form', 'change', 'edit', 'update', 'the', 'for',
    ];

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

        if (preg_match(self::TIN_PATTERN, $query, $m) && strlen(preg_replace('/\D/', '', $query)) === strlen(preg_replace('/\D/', '', $m[0]))) {
            return $this->byTin($m[0]);
        }

        $employees = $this->employees();

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

    /**
     * The employee with this TIN. The first nine digits must match; a branch code counts
     * only when both have one, so 920-919-920 finds 920-919-920-00000, and 001 equals 00001.
     *
     * @return array{status:string, employee:?Employee, candidates:array<int,array<string,mixed>>}
     */
    public function byTin(string $tin): array
    {
        $wanted = self::tinParts($tin);
        $hits = $this->employees()->filter(function (Employee $e) use ($wanted) {
            $has = self::tinParts((string) $e->tin_number);

            return $has['base'] !== '' && $has['base'] === $wanted['base']
                && ($has['branch'] === null || $wanted['branch'] === null || $has['branch'] === $wanted['branch']);
        })->values();

        return match ($hits->count()) {
            0 => $this->result(self::NOT_FOUND),
            1 => $this->result(self::MATCHED, $hits->first()),
            default => $this->result(self::AMBIGUOUS, null, $hits),
        };
    }

    /**
     * Whoever a whole sentence names: a TIN, an employee ID, or a name written in it
     * ("for maria santos", "Maria Santos's 2316"). A full name beats a last or first name
     * alone; failing those, the words after "for", "ni" or "kay" are looked up like a typed
     * name, typos allowed. NOT_NAMED when nothing in it names a person.
     *
     * @return array{status:string, employee:?Employee, candidates:array<int,array<string,mixed>>, query:?string}
     */
    public function mentionedIn(string $text): array
    {
        if (preg_match(self::TIN_PATTERN, $text, $m)) {
            return $this->byTin($m[0]) + ['query' => $m[0]];
        }

        $employees = $this->employees();
        $words = preg_split('/\s+/', $this->normalize(preg_replace("/['’]s\b/u", '', $text)), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($words as $word) {
            $hits = $this->byEmployeeId($employees, $word);
            if ($hits->count() === 1) {
                return $this->result(self::MATCHED, $hits->first()) + ['query' => $word];
            }
        }

        // A first or last name alone counts only where a name is expected: after "for",
        // "ni", "kay", "of" or "si", or before 's ("Maria's"), so "hope" or "grace" in a
        // sentence never picks out an employee. A full name counts anywhere.
        preg_match_all("/\b(?:for|ni|kay|of|si)\s+((?:[\p{L}.-]+\s*){1,3})|([\p{L}.-]+(?:\s+[\p{L}.-]+)?)['’]s\b/iu", $text, $slots);
        $named = preg_split('/\s+/', $this->normalize(implode(' ', [...$slots[1], ...$slots[2]])), -1, PREG_SPLIT_NO_EMPTY);

        $has = fn (string $name, array $in) => ($parts = explode(' ', $this->normalize($name))) !== []
            && array_diff($parts, $in) === [] && array_diff($parts, self::NOT_NAMES) !== [];
        foreach ([
            fn (Employee $e) => $has($e->first_name, $words) && $has($e->last_name, $words),
            fn (Employee $e) => $has($e->last_name, $named),
            fn (Employee $e) => $has($e->first_name, $named),
        ] as $tier) {
            $hits = $employees->filter($tier)->values();
            if ($hits->count() === 1) {
                return $this->result(self::MATCHED, $hits->first()) + ['query' => null];
            }
            if ($hits->count() > 1) {
                return $this->result(self::AMBIGUOUS, null, $hits) + ['query' => null];
            }
        }

        // "for mariaa santos": the words after "for" / "ni" / "kay", up to the next keyword.
        if (preg_match('/\b(?:for|ni|kay)\s+([\p{L}][\p{L} .\'-]*?)(?=\s*(?:[,;:?!]|\'s\b|\b(?:item|box|to|ng|for|on|in|sa|of|change|from|na)\b|\d|$))/iu', $text, $m)) {
            $query = trim($m[1], " .'-");
            if ($query !== '' && !BirIntentService::onlyNamesAPeriodOrForm($query)) {
                return $this->resolve($query) + ['query' => $query];
            }
        }

        return $this->result(self::NOT_NAMED) + ['query' => null];
    }

    /** @return Collection<int, Employee> */
    private function employees(): Collection
    {
        return Employee::query()
            ->select(['id', 'employee_id', 'first_name', 'last_name', 'email', 'status', 'hire_date', 'tin_number'])
            ->get();
    }

    /** @return array{base: string, branch: ?int} the first nine digits, and the branch code as a number */
    private static function tinParts(string $tin): array
    {
        $digits = preg_replace('/\D/', '', $tin);

        return ['base' => strlen($digits) >= 9 ? substr($digits, 0, 9) : '', 'branch' => strlen($digits) > 9 ? (int) substr($digits, 9) : null];
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