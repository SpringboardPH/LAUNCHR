<?php

namespace App\Services\BIR;

use App\Services\BIR\Schemas\FieldFormat;
use DateTimeImmutable;

/**
 * Turns what a person typed in the chat into the exact value PUT /bir/drafts/{id}
 * accepts for one field (UpdateBirDraftFieldsRequest), or says why it can't.
 *
 * Reads, never guesses. "150,000" is 150000.00 because that is what was written;
 * "around 150k" is refused because the exact figure was not given. An answer that
 * can't be read is asked again rather than turned into something close.
 *
 * Pure: no database, no model, no Laravel. PUT still checks the result, so this only
 * has to translate common ways of writing a value, not be the last line of defence.
 *
 * A field with a schema 'format' (TIN, RDO, ZIP, address…) is checked against Dev A's
 * FieldFormat here too, so a wrong one is asked again in the chat's own words instead of
 * coming back from PUT as an error. A TIN typed without dashes is written with them.
 */
final class BirAnswerParser
{
    private const YES = ['yes', 'y', 'oo', 'opo', 'oho', 'meron', 'mayroon', 'true', 'correct', 'yeah', 'yep'];

    private const NO = ['no', 'n', 'hindi', 'wala', 'none', 'false', 'nope'];

    /** Words that mean the amount is approximate, so it is asked again. */
    private const VAGUE = ['about', 'around', 'approx', 'approximately', 'roughly', 'mga', 'siguro', 'more or less', '~'];

    /**
     * First words that make free text a question about the box rather than its answer
     * ("saan galing ito?"). The parser checks them only for text boxes; any other type can't
     * mistake a question for an answer, since a question never reads as an amount, date or
     * yes/no. The chat also uses them (looksLikeQuestion) to tell a question about a box
     * from a muddled answer.
     */
    private const QUESTION_WORDS = [
        'what', "what's", 'whats', 'where', 'why', 'how', 'who', 'when', 'which', 'explain',
        'ano', 'anong', 'saan', 'bakit', 'paano', 'sino', 'kailan', 'alin', 'paki', 'pakiexplain',
        'can', 'could', 'does', 'do', 'is', 'are',
    ];

    /**
     * First words of a request to change a box ("change item 12 to …", "palitan ang item 12
     * ng …"). Never read as an answer, whatever box is open: the chat handles it as a change.
     */
    private const CHANGE_WORDS = [
        'change', 'update', 'set', 'edit', 'correct', 'replace', 'fix',
        'palitan', 'pakipalitan', 'ibahin', 'baguhin', 'pakibago', 'gawing', 'i-update', 'iupdate',
    ];

    /** Command words whose typos are fixed before the command is read (withCommandSpelled()). */
    private const COMMAND_WORDS = ['change', 'update', 'palitan', 'pakipalitan', 'baguhin', 'open', 'buksan', 'ipakita', 'pakita', 'undo'];

    /** Accepted date spellings. MM/DD/YYYY, not DD/MM/YYYY, because that is how BIR forms write dates. */
    private const DATE_FORMATS = ['Y-m-d', 'm/d/Y', 'F j, Y', 'F j Y', 'M j, Y', 'M j Y', 'j F Y', 'j M Y'];

    /**
     * @param  array<string, mixed>  $field  one schema field (key, label, type, options)
     * @return array{ok: bool, value: mixed, problem: ?string}
     */
    public static function parse(string $answer, array $field): array
    {
        $answer = trim($answer);

        if ($answer === '') {
            return self::problem('Please type an answer.');
        }

        $result = match ($field['type']) {
            'decimal' => self::amount($answer),
            'boolean' => self::yesNo($answer),
            'enum' => self::option($answer, $field['options'] ?? []),
            'integer' => self::wholeNumber($answer),
            'month' => self::month($answer),
            'date' => self::date($answer),
            'string', 'text' => self::text($answer),
            default => self::problem("\"{$field['label']}\" can't be entered in the chat."),
        };

        return $result['ok'] && isset($field['format']) ? self::formatted($result['value'], $field) : $result;
    }

    /** "change item 12 to …": a request to change a box, never an answer. */
    public static function looksLikeChange(string $message): bool
    {
        return in_array(strtok(mb_strtolower(trim($message)), " \t,:"), self::CHANGE_WORDS, true);
    }

    /**
     * The box a message names by item number: "item 25A", "box 12", "#12". Null if none.
     * Upper case, as the schemas write item numbers.
     */
    public static function itemMentioned(string $message): ?string
    {
        // "draft #12" and "form #12" are a form's number, never item 12.
        $message = BirFormFinder::withoutFormNumber($message);

        // "itm 5", "iten 5": a slip in "item" too.
        return preg_match('/(?:\bitem|\bitm|\biten|\bitme|\bbox|#)\s*(\d{1,2}[a-z]?)\b/i', $message, $m) ? strtoupper($m[1]) : null;
    }

    /**
     * Text checked against the field's format (Dev A's FieldFormat), in the same words PUT
     * would use. Common spellings are tidied first (FieldFormat::normalize): a TIN's dashes
     * and branch code, an RDO code's case.
     *
     * @param  array<string, mixed>  $field
     */
    private static function formatted(mixed $value, array $field): array
    {
        $format = $field['format'];
        $value = FieldFormat::normalize($field, $value);

        return FieldFormat::matches($format, $value)
            ? self::ok($value)
            : self::problem("\"{$field['label']}\" must be " . FieldFormat::describe($format) . '.');
    }

    /** "₱150,000" → "150000.00". Never rounds: more than two decimals is asked again. */
    private static function amount(string $answer): array
    {
        $lower = mb_strtolower($answer);
        foreach (self::VAGUE as $word) {
            if (str_contains($lower, $word)) {
                return self::problem('Please give the exact amount, for example 150000.00.');
            }
        }

        $plain = preg_replace('/^(₱|php|p)\s*/i', '', str_replace([',', ' '], '', $answer));

        if (preg_match('/^\d+(\.\d+)?[km]$/i', $plain)) {
            return self::problem('Please write the full amount, for example 150000.00 instead of 150k.');
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $plain)) {
            return self::problem('Please enter an amount in pesos, for example 150000.00. Enter 0 if there was none.');
        }

        [$whole, $cents] = array_pad(explode('.', $plain), 2, '');
        $whole = ltrim($whole, '0');

        return self::ok(($whole === '' ? '0' : $whole) . '.' . str_pad($cents, 2, '0'));
    }

    private static function yesNo(string $answer): array
    {
        $word = preg_replace('/\s+po$/', '', trim(preg_replace('/[^\p{L}\s]/u', '', mb_strtolower($answer))));

        return match (true) {
            in_array($word, self::YES, true) => self::ok(true),
            in_array($word, self::NO, true) => self::ok(false),
            default => self::problem('Please answer yes or no.'),
        };
    }

    /** @param array<int, string> $options */
    private static function option(string $answer, array $options): array
    {
        foreach ($options as $option) {
            if (mb_strtolower($answer) === mb_strtolower($option)) {
                return self::ok($option);
            }
        }

        return self::problem('Please choose one of: ' . implode(', ', $options) . '.');
    }

    private static function wholeNumber(string $answer): array
    {
        $plain = str_replace(',', '', $answer);

        return ctype_digit($plain) ? self::ok((int) $plain) : self::problem('Please enter a whole number, for example 2.');
    }

    /** "08/2026", "8/2026" or "2026-08" → "08/2026". */
    private static function month(string $answer): array
    {
        [$month, $year] = match (true) {
            (bool) preg_match('/^(\d{1,2})\/(\d{4})$/', $answer, $m) => [(int) $m[1], $m[2]],
            (bool) preg_match('/^(\d{4})-(\d{1,2})$/', $answer, $m) => [(int) $m[2], $m[1]],
            default => [0, ''],
        };

        if ($month >= 1 && $month <= 12) {
            return self::ok(sprintf('%02d/%s', $month, $year));
        }

        return self::problem('Please enter the month as MM/YYYY, for example 08/2026.');
    }

    /** "1990-05-31", "05/31/1990" or "May 31, 1990" → "1990-05-31". */
    private static function date(string $answer): array
    {
        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $answer);
            // A warning means PHP rolled an impossible date over (02/30/1990 → March 2),
            // so it is refused rather than saved as a different day.
            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return self::ok($date->format('Y-m-d'));
            }
        }

        return self::problem('Please enter the date as MM/DD/YYYY, for example 05/31/1990.');
    }

    /** Ends with "?" or starts with a question word: "saan galing ito?", "what goes here". */
    public static function looksLikeQuestion(string $message): bool
    {
        $message = trim($message);

        return str_ends_with($message, '?') || in_array(strtok(mb_strtolower($message), " \t,"), self::QUESTION_WORDS, true);
    }

    /** A message that asks about something: a question mark, or a question word anywhere in it. */
    public static function asksAbout(string $message): bool
    {
        return self::looksLikeQuestion($message)
            || preg_match('/\b(what|whats|ano|anong|explain|mean|means|meaning|ibig sabihin|paliwanag|where|saan|galing|why|bakit|paano|how)\b/i', $message) === 1;
    }

    /** A question, or words asking what a box means or where it came from: "wat dos this mean", "pakipaliwanag". */
    public static function asksForExplanation(string $message): bool
    {
        return self::looksLikeQuestion($message)
            || preg_match('/\b(?:mean|means|meaning|ibig sabihin|kahulugan|explain|paliwanag|ipaliwanag|pakipaliwanag|came from|come from|galing)\b/i', $message) === 1;
    }

    /**
     * The message with a slip in its first word fixed, when that word is a command: "chnage item
     * 4 to 2", "opne form #11", "palitn", "opn", "udno". Words of 3 letters or more, one slip only; anything
     * else is left as typed. Submit is never guessed: sending a form for review must be asked plainly.
     */
    public static function withCommandSpelled(string $message): string
    {
        $message = preg_replace('/\b(?:itm|iten|itme)(?=\s*\d)/iu', 'item', $message); // "change itm 5 to 044"
        $first = strtok(mb_strtolower(trim($message)), " \t,:");
        if ($first === false || strlen($first) < 3 || in_array($first, self::COMMAND_WORDS, true)) {
            return $message;
        }
        foreach (self::COMMAND_WORDS as $word) {
            if (BirMessageFacts::slips($first, $word) === 1) {
                return preg_replace('/^\s*\S+/u', $word, $message, 1);
            }
        }

        return $message;
    }

    /**
     * Free text is taken as written, unless it reads as a question about the box, a request
     * to change a box, or names another box ("item 12 should be …"). Those are never saved.
     * "P.O. Box 12" in an address is an address, not box 12.
     */
    private static function text(string $answer): array
    {
        if (self::looksLikeChange($answer) || preg_match('/\bitem\s*\d|(?<!p\.o\.\s)(?<!p\.o\.)(?<!po\s)\bbox\s*\d/i', $answer)) {
            return self::problem('That looks like a request about another box rather than the answer. To change a box, say "change item 12 to …"; otherwise type the answer itself.');
        }
        if (self::looksLikeQuestion($answer)) {
            return self::problem('That looks like a question rather than the answer. Type the answer itself, or ask me what the box means.');
        }

        return self::ok($answer);
    }

    private static function ok(mixed $value): array
    {
        return ['ok' => true, 'value' => $value, 'problem' => null];
    }

    private static function problem(string $problem): array
    {
        return ['ok' => false, 'value' => null, 'problem' => $problem];
    }
}
