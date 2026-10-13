<?php

namespace App\Services\BIR;

/**
 * Finds the box a question names in words instead of an item number: "the TIN", "her
 * registered address", "tax withheld by the company", "previous employer's name". Read in
 * PHP from the schema (Dev A's labels and field keys), so it works with the model off and
 * can only ever land on a box the form really has.
 *
 * Each word of the question is compared with each box's label and key, after small words,
 * names and dates are set aside and a few everyday words are read as the form's own
 * ("company" is the present employer, "birthday" the date of birth, "her" the employee).
 * The box matching most words wins; on a tie, the one whose label says least else ("total
 * compensation" is item 14, not item 21's longer label). Several boxes of one item (the
 * three parts of the name) win together. Boxes with the same label stay a tie for the
 * caller to ask which, since the 2316 alone has three TINs and three registered addresses.
 * Boxes without an item number are internal and never matched.
 *
 * Pure: no database, no model.
 */
final class BirBoxMatcher
{
    /** Everyday words and the words the schema uses for them. */
    private const SYNONYMS = [
        'company' => ['employer', 'present'], 'kompanya' => ['employer', 'present'], 'current' => ['present'],
        'former' => ['previous'], 'dating' => ['previous'], 'prior' => ['previous'],
        'her' => ['employee'], 'his' => ['employee'], 'kanyang' => ['employee'], 'niya' => ['employee'],
        'birthday' => ['birth'], 'birthdate' => ['birth'], 'dob' => ['birth'], 'born' => ['birth'], 'kaarawan' => ['birth'],
        'phone' => ['contact'], 'mobile' => ['contact'], 'cellphone' => ['contact'], 'telepono' => ['contact'],
        'zipcode' => ['zip'], 'postal' => ['zip'], 'tirahan' => ['address'], 'pangalan' => ['name'],
        'surname' => ['last', 'name'], 'apelyido' => ['last', 'name'], 'withholding' => ['withheld'], 'kinaltas' => ['withheld'], 'kaltas' => ['withheld'], 'buwis' => ['tax'],
        'sahod' => ['salary'], 'sweldo' => ['salary'], 'amended' => ['amended'], 'sheets' => ['sheet'],
    ];

    /** Words that only help choose between boxes ("her TIN"), never name one on their own. */
    private const HELPERS = ['her', 'his', 'kanyang', 'niya'];

    /** Words that say nothing about which box: questions, small words, the form and the period. */
    private const IGNORED = [
        'what', 'whats', 'which', 'who', 'is', 'are', 'was', 'were', 'be', 'how', 'much', 'many', 'the', 'a', 'an', 'of',
        'on', 'in', 'for', 'to', 'at', 'by', 'from', 'and', 'or', 'with', 'about', 'this', 'that', 'it', 'its', 'there',
        'here', 'me', 'my', 'our', 'your', 'we', 'i', 'you', 'please', 'pls', 'po', 'can', 'could', 'tell', 'show',
        'give', 'check', 'see', 'say', 'says', 'written', 'put', 'value', 'amount', 'figure', 'number', 'box', 'item',
        'field', 'where', 'did', 'does', 'do', 'come', 'came', 'why', 'source', 'form', 'forms', 'bir', 'version',
        'draft', 'filed', 'approved', 'certificate', 'return', 'year', 'month', 'ano', 'anong', 'magkano', 'ilan',
        'saan', 'galing', 'bakit', 'sa', 'ng', 'ni', 'kay', 'si', 'ang', 'mga', 'na', 'yung', 'iyong', 'nito', 'dito',
        'nasa', 'laman', 'nakalagay', 'ba', 'naman', 'lang', 'january', 'february', 'march', 'april', 'may', 'june',
        'july', 'august', 'september', 'october', 'november', 'december', 'sept', 'jan', 'feb', 'apr', 'jun', 'jul',
        'aug', 'sep', 'oct', 'nov', 'dec', 'c',
    ];

    /** Words in labels that tell no boxes apart. */
    private const LABEL_NOISE = ['of', 'the', 'and', 'from', 'to', 'if', 'or', 'a', 'items', 'item', 'sum', 'less', 'add', 'incl'];

    /**
     * The boxes a question names, best first. ambiguous is true when the winners belong to
     * different items. Empty fields when no box is named, or when the question has more
     * words that name nothing on the form than words that do.
     *
     * @param  array<int, array<string, mixed>>  $fields  the form's schema fields
     * @param  array<int, string>  $skip  words already spent on the form reference (the person's name)
     * words is how many words were left to name a box with: 0 means the question names no
     * box at all ("2316 for Maria Santos 2026?"), so nothing should guess one.
     *
     * @return array{fields: array<int, array<string, mixed>>, ambiguous: bool, words: int}
     */
    public static function match(array $fields, string $message, array $skip = []): array
    {
        $fields = array_values(array_filter($fields, fn (array $f) => $f['item'] !== null));
        $skip = array_map(self::stem(...), self::words(implode(' ', $skip)));
        $asked = array_values(array_filter(
            self::words($message),
            fn (string $w) => !in_array($w, self::IGNORED, true) && !in_array(self::stem($w), $skip, true) && !ctype_digit($w) && strlen($w) > 1,
        ));
        if ($asked === []) {
            return ['fields' => [], 'ambiguous' => false, 'words' => 0];
        }

        $scores = [];
        $unmatched = count($asked);
        foreach ($fields as $i => $field) {
            $tokens = self::tokens($field);
            $score = 0;
            foreach ($asked as $word) {
                $score += count(array_intersect(self::meanings($word), $tokens));
            }
            $scores[$i] = $score;
        }
        $named = 0;
        foreach ($asked as $word) {
            foreach ($fields as $field) {
                if (array_intersect(self::meanings($word), self::tokens($field)) !== []) {
                    $unmatched--;
                    $named += in_array($word, self::HELPERS, true) ? 0 : 1;
                    break;
                }
            }
        }

        $best = max($scores);
        if ($best === 0 || $named === 0 || $unmatched > count($asked) - $unmatched) {
            return ['fields' => [], 'ambiguous' => false, 'words' => count($asked)];
        }
        $winners = array_values(array_filter($fields, fn (array $f, int $i) => $scores[$i] === $best, ARRAY_FILTER_USE_BOTH));
        $items = fn (array $list) => count(array_unique(array_map(fn (array $f) => (string) $f['item'], $list)));

        // A tie between items goes to the most specific label, unless another winner has the
        // same label: then only the person asking can say which they meant.
        if ($items($winners) > 1) {
            $share = fn (array $f) => $best / max(1, count(self::tokens($f)));
            $top = max(array_map($share, $winners));
            $specific = array_values(array_filter($winners, fn (array $f) => $share($f) === $top));
            $labels = array_count_values(array_map(fn (array $f) => mb_strtolower($f['label']), $winners));
            if ($items($specific) === 1 && max(array_map(fn (array $f) => $labels[mb_strtolower($f['label'])], $specific)) === 1) {
                $item = $specific[0]['item'];
                $winners = array_values(array_filter($winners, fn (array $f) => $f['item'] === $item));
            }
        }

        return ['fields' => $winners, 'ambiguous' => $items($winners) > 1, 'words' => count($asked)];
    }

    /**
     * "Which one do you mean? On the 2316 that could be: item 3, the employee's Taxpayer
     * Identification Number; …" — each told apart by the start of its help text.
     *
     * @param  array<int, array<string, mixed>>  $fields
     */
    public static function whichOne(string $formType, array $fields): string
    {
        $lines = [];
        foreach ($fields as $field) {
            $what = preg_split('/[.,;:]\s/', (string) ($field['guidance'] ?? ''), 2)[0] ?: $field['label'];
            $lines[$field['item'] ?? $field['key']] = '- item ' . ($field['item'] ?? '?') . ': ' . lcfirst(rtrim($what, '.'));
        }

        return "Which one do you mean? On the {$formType} that could be:\n" . implode("\n", $lines)
            . "\nSay the item number, for example \"item " . array_key_first($lines) . '".';
    }

    /** @return array<int, string> lower-case words, possessives dropped ("Maria's" → "maria") */
    private static function words(string $text): array
    {
        $text = preg_replace("/['’]s\b/u", '', mb_strtolower($text));

        return preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    /** @return array<int, string> the words a box answers to: its label and its key, stemmed */
    private static function tokens(array $field): array
    {
        $words = [...self::words($field['label']), ...explode('_', $field['key'])];

        $words = array_filter(array_diff($words, self::LABEL_NOISE), fn (string $w) => !ctype_digit($w));

        return array_values(array_unique(array_map(self::stem(...), $words)));
    }

    /** @return array<int, string> a question word as the schema might say it */
    private static function meanings(string $word): array
    {
        return array_map(self::stem(...), [$word, ...(self::SYNONYMS[$word] ?? [])]);
    }

    /** "taxes" → "tax", "employers" → "employer"; "address" is left alone. */
    private static function stem(string $word): string
    {
        return match (true) {
            strlen($word) > 4 && str_ends_with($word, 'ies') => substr($word, 0, -3) . 'y',
            strlen($word) > 4 && preg_match('/(x|ch|sh)es$/', $word) === 1 => substr($word, 0, -2),
            strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss') => substr($word, 0, -1),
            default => $word,
        };
    }
}
