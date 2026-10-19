<?php

namespace App\Services\BIR;

use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;

/**
 * What a message itself says: the forms it names, its years and months, and whether a name
 * appears in it. BirIntentService checks the model's reading against these, so the message
 * always wins: a form type, year, month or person the model made up is never acted on or
 * repeated back as if the user had typed it.
 */
final class BirMessageFacts
{
    /** The model's value when the message agrees, else the message's own when it says exactly one, else null. */
    public static function agreed(?int $fromModel, array $said): ?int
    {
        return match (true) {
            $said === [] => $fromModel,
            in_array($fromModel, $said, true) => $fromModel,
            count($said) === 1 => $said[0],
            default => null,
        };
    }

    /** The forms a message names: 1601-C (or "remittance"), 2316 (or "certificate", "sertipiko"). */
    public static function formsSaid(string $message): array
    {
        return array_values(array_filter([
            preg_match('/1601|remittance/i', $message) ? Form1601CSchema::FORM_TYPE : null,
            preg_match('/2316|certificate|sertipiko/i', $message) ? Form2316Schema::FORM_TYPE : null,
        ]));
    }

    /** The four-digit years a message gives (also inside MM/YYYY and YYYY-MM). */
    public static function yearsSaid(string $message): array
    {
        preg_match_all('/(?<!\d)((?:19|20)\d{2})(?!\d)/', preg_replace(BirEmployeeResolver::TIN_PATTERN, ' ', $message), $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /** The months a message names: words ("Agosto", "Sept"; "may" only before a year), MM/YYYY or YYYY-MM. */
    public static function monthsSaid(string $message): array
    {
        $lower = mb_strtolower($message);
        $months = [];
        foreach (preg_split('/[^a-z]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (isset(BirFormFinder::MONTHS[$word]) && ($word !== 'may' || preg_match('/\bmay\s+(?:19|20)\d{2}\b/', $lower))) {
                $months[] = BirFormFinder::MONTHS[$word];
            }
        }
        preg_match_all('/\b(\d{1,2})\/(?:19|20)\d{2}\b|\b(?:19|20)\d{2}-(\d{1,2})\b/', $message, $m);
        foreach ([...$m[1], ...$m[2]] as $n) {
            if ($n !== '' && (int) $n >= 1 && (int) $n <= 12) {
                $months[] = (int) $n;
            }
        }

        return array_values(array_unique($months));
    }

    /**
     * True when a word in the message is this month's name typed with a slip ("novmber", "agust",
     * "decmber", "Mrch"): one in a word of 4 letters or more, two from 8. It confirms the model's
     * month; nearMonth() is the only guess, and only when the model gave none.
     */
    public static function spellsMonth(string $message, int $month): bool
    {
        $names = array_keys(array_filter(BirFormFinder::MONTHS, fn (int $m, string $name) => $m === $month && strlen($name) >= 4, ARRAY_FILTER_USE_BOTH));
        foreach (preg_split('/[^a-z]+/', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            foreach ($names as $name) {
                if (strlen($word) >= 4 && self::slips($word, $name) <= (strlen($word) >= 8 ? 2 : 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The one month a misspelled word in the message is closest to ("Agosot", "Mrch"), when the
     * model read none: one slip in a word of 4 letters or more, two from 8. Null when no word, or
     * words for two months, are that close. The caller asks the user to check what it guessed.
     */
    public static function nearMonth(string $message): ?int
    {
        $found = [];
        foreach (preg_split('/[^a-z]+/', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            foreach (BirFormFinder::MONTHS as $name => $month) {
                if (strlen($word) >= 4 && strlen($name) >= 4 && $word !== $name && self::slips($word, $name) <= (strlen($word) >= 8 ? 2 : 1)) {
                    $found[$month] = true;
                }
            }
        }

        return count($found) === 1 ? array_key_first($found) : null;
    }

    /** Typing slips between two words: letters added, dropped, changed, or two swapped ("opne"). */
    public static function slips(string $a, string $b): int
    {
        $d = [];
        for ($i = 0; $i <= strlen($a); $i++) {
            for ($j = 0; $j <= strlen($b); $j++) {
                $d[$i][$j] = match (true) {
                    $i === 0 => $j,
                    $j === 0 => $i,
                    default => min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1)),
                };
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[strlen($a)][strlen($b)];
    }

    /** True when every word of a name is in the message, allowing a typo or two in longer words. */
    public static function inMessage(string $name, string $message): bool
    {
        $words = fn (string $s) => preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY);
        $said = $words($message);
        foreach ($words($name) as $part) {
            $found = false;
            foreach ($said as $w) {
                if ($w === $part || str_starts_with($w, $part) || (mb_strlen($part) > 3 && levenshtein($part, $w) <= (mb_strlen($part) > 5 ? 2 : 1))) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }
}
