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
        'tin' => 'a 14-digit TIN like 123-456-789-00000 (9 digits and a 5-digit branch code)',
        'rdo' => 'a 3-character RDO code like 043 or 21A',
        'zip' => 'a 4-digit ZIP code like 1004',
        'phone' => 'a phone number like 09171234567 or (02) 8123-4567',
        'email' => 'an email address like name@company.com',
        'mmdd' => 'a date as MM/DD, like 07/01',
        'address' => 'an address with commas between its parts: house/unit/building no. and street, barangay, city or municipality, like 12 Mabini St., Brgy. San Roque, Quezon City',
    ];

    private const PATTERNS = [
        'tin' => '/^\d{3}-\d{3}-\d{3}-\d{5}$/',
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
            'address' => self::isAddress($value),
            default => preg_match(self::PATTERNS[$format], $value) === 1,
        };
    }

    /**
     * At least three parts separated by commas (street, barangay, city or municipality),
     * each with letters in it, except a house number on its own as the first part ("22,
     * Mabini St., Quezon City"). No house number is required: "Sitio Malinis, Brgy. Lawa,
     * Calamba City" is an address; "blah blah blah" is not.
     */
    private static function isAddress(string $value): bool
    {
        $parts = array_map('trim', explode(',', $value));
        $houseNumber = preg_match('/^#?\d[\d\s\/-]*$/', $parts[0]) === 1;

        return count($parts) >= 3
            && array_filter(
                $houseNumber ? array_slice($parts, 1) : $parts,
                fn (string $part) => preg_match('/\p{L}/u', $part) !== 1,
            ) === [];
    }

    /**
     * A value as it is stored: text in capital letters, the way BIR forms are filled in, and
     * a TIN as all 14 digits. An email address keeps its case; choices, amounts and dates
     * aren't text and are returned as given, as is anything tin() can't read.
     *
     * @param  array<string, mixed>  $field  one schema field
     */
    public static function normalize(array $field, mixed $value): mixed
    {
        if (!is_string($value) || !in_array($field['type'], ['string', 'text'], true) || ($field['format'] ?? null) === 'email') {
            return $value;
        }

        $value = mb_strtoupper(trim($value));

        return ($field['format'] ?? null) === 'tin' ? self::tin($value) : $value;
    }

    /**
     * "123456789000" or "123-456-789-000" → "123-456-789-00000": the 9-digit TIN, then the
     * branch code padded to 5 digits (head office 000 is 00000). Spaces and dashes are
     * ignored; anything that isn't 9 to 14 digits is returned as given, for matches() to refuse.
     */
    public static function tin(string $value): string
    {
        $digits = preg_replace('/[\s-]/', '', $value);
        if (preg_match('/^\d{9,14}$/', $digits) !== 1) {
            return trim($value);
        }

        return implode('-', [
            substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 3),
            str_pad(substr($digits, 9), 5, '0', STR_PAD_LEFT),
        ]);
    }

    /** What the value should look like, for a message: "a 4-digit ZIP code like 1004". */
    public static function describe(string $format): string
    {
        return self::FORMATS[$format] ?? throw new InvalidArgumentException("Unknown field format: {$format}");
    }
}
