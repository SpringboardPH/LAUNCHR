<?php

namespace App\Services\BIR;

use App\Models\Employee;

/**
 * BirDataAnswers' yes-or-no about where one employee works: "is she in Administration?" (on her
 * 2316), "is Bea a Developer?", "nasa Finance ba si Carlo?". The department or position is one on
 * file, named in the message; the reply says Yes or No and what the record holds. Read only.
 */
final class BirDataPerson
{
    /** A yes-or-no question: "is she …", "does Bea work in …", "nasa … ba si Carlo". */
    private const YES_NO = '/^\s*(?:is|are|was|does|do|did|nasa|taga|sa|si)\b|\bba\b/i';

    /** The word before a department or position: "in Finance", "a Developer", "nasa Finance". */
    private const BEFORE = '(?:in|at|from|of|under|an?|the|nasa|sa|taga|ng)';

    /**
     * The reply, or null when the message isn't such a question: no yes-or-no, nobody it is about,
     * or no department or position on file named in it.
     */
    public static function place(array $q, ?Employee $person): ?string
    {
        $message = $q['message'] ?? '';
        if ($q['filters'] !== [] || !in_array($q['topic'], ['employee', 'unsupported', 'employees'], true) || !preg_match(self::YES_NO, $message)
            || $q['group'] !== null || $q['amount'] !== null || preg_match(BirDataWords::AMOUNT_WORDS, $message)) {
            return null;
        }
        if ($person === null) {
            $named = app(BirEmployeeResolver::class)->namedIn($message);
            $person = $named->count() === 1 ? Employee::find($named->first()->id) : null;
        }
        [$field, $value] = self::named($message) ?? [null, null];
        // "is Bea in Finance?" with no Finance on file: what the model read it as about, and the words said.
        // With no detail from the model, a capitalised name: "in Administration" a department, "a Driver" a position.
        if ($field === null && preg_match('/\b(' . self::BEFORE . ')\s+(?:the\s+)?(\p{L}[\p{L} &-]*?)(?:\s+(?:department|dept|team|division))?(?:\s+(?:ba|po))?\s*[?.!]*\s*$/iu', $message, $m)
            && (in_array($q['detail'], ['department', 'position'], true) || (preg_match('/^\p{Lu}/u', $m[2]) && !preg_match('/^(?:payroll|list|system|records?|form|bir)\b/i', $m[2])))) {
            [$field, $value] = [in_array($q['detail'], ['department', 'position'], true) ? $q['detail'] : (preg_match('/^an?$/i', $m[1]) ? 'position' : 'department'), trim($m[2])];
        }
        if ($person === null || $field === null) {
            return null;
        }
        $theirs = trim((string) $person->{$field});
        $name = BirDataAnswers::name($person);
        if ($theirs === '') {
            return "No {$field} is on file for {$name}, so I can't say.";
        }
        $yes = strcasecmp($theirs, $value) === 0 || preg_match('/(?<!\p{L})' . preg_quote(preg_replace('/(?:es|s)$/iu', '', $value), '/') . '/iu', $theirs) === 1;

        return $field === 'department'
            ? ($yes ? "Yes. {$name} is in {$theirs}." : "No. {$name} is in {$theirs}, not {$value}.")
            : ($yes ? "Yes. {$name}'s position is {$theirs}." : "No. {$name}'s position is {$theirs}, not {$value}.");
    }

    /**
     * The department or position on file the message names after "in", "a" or "nasa": the whole
     * name, or a word of it it starts ("admin" for Administration, "developers" for Developer).
     *
     * @return ?array{0: string, 1: string}
     */
    public static function named(string $message): ?array
    {
        foreach (['department', 'position'] as $field) {
            $values = Employee::query()->whereNotNull($field)->distinct()->pluck($field)->map(fn ($v) => trim((string) $v))->filter()->unique();
            foreach ($values as $value) {
                if (preg_match('/\b' . self::BEFORE . '\s+' . preg_quote($value, '/') . '\b/iu', $message)) {
                    return [$field, $value];
                }
            }
            foreach ($values as $value) {
                foreach (preg_split('/[^\p{L}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                    if (mb_strlen($word) >= 5 && preg_match('/\b' . self::BEFORE . '\s+(?:the\s+)?(\p{L}{4,})/iu', $message, $m)
                        && (str_starts_with($word, mb_strtolower(preg_replace('/(?:es|s)$/iu', '', $m[1]))) && mb_strlen(preg_replace('/(?:es|s)$/iu', '', $m[1])) >= 4)) {
                        return [$field, $value];
                    }
                }
            }
        }

        return null;
    }
}
