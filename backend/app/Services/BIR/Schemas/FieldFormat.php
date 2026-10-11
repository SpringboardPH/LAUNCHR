<?php

namespace App\Services\BIR\Schemas;

use InvalidArgumentException;

/**
 * The formats a schema field's text must follow, by name. A field carrying 'format' in
 * Form1601CSchema / Form2316Schema names one of these; the prose 'rule' says the same in
 * words. One place for the rule, so the PUT, the validator and the chat check the same.
 *
 * matches() reads the value as stored: no reformatting. A blank value is not checked here;
 * whether it may be blank is the field's 'required' / 'required_when'.
 */
final class FieldFormat
{
    /** name => what a person is told the value should look like. */
    public const FORMATS = [
        'tin' => 'a TIN like 123-456-789-000 (a 3 to 5 digit branch code)',
        'rdo' => 'a 3-character RDO code like 043 or 21A',
        'zip' => 'a 4-digit ZIP code like 1004',
        'phone' => 'a phone number like 09171234567 or (02) 8123-4567',
        'email' => 'an email address like name@company.com',
        'mmdd' => 'a date as MM/DD, like 07/01',
    ];

    private const PATTERNS = [
        'tin' => '/^\d{3}-\d{3}-\d{3}-\d{3,5}$/',
        // Most RDO codes are 3 digits; some districts are 2 digits and a letter (21A, 43B).
        'rdo' => '/^(\d{3}|\d{2}[A-Z])$/',
        'zip' => '/^\d{4}$/',
        'mmdd' => '/^(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/',
    ];

    public static function matches(string $format, mixed $value): bool
    {
        if (!isset(self::FORMATS[$format])) {
            throw new InvalidArgumentException("Unknown field format: {$format}");
        }
        if (!is_string($value)) {
            return false;
        }

        return match ($format) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            // Spaces, dashes, dots and brackets are how people write numbers; 7 to 13 digits
            // covers landlines, mobiles and +63.
            'phone' => preg_match('/^\+?\d{7,13}$/', preg_replace('/[\s().-]/', '', $value)) === 1,
            'mmdd' => preg_match(self::PATTERNS['mmdd'], $value) === 1
                && checkdate((int) substr($value, 0, 2), (int) substr($value, 3, 2), 2024), // a leap year allows 02/29
            default => preg_match(self::PATTERNS[$format], $value) === 1,
        };
    }

    /** What the value should look like, for a message: "a 4-digit ZIP code like 1004". */
    public static function describe(string $format): string
    {
        return self::FORMATS[$format] ?? throw new InvalidArgumentException("Unknown field format: {$format}");
    }
}
