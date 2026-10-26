<?php

namespace App\Services\BIR;

use Carbon\Carbon;

/**
 * The conditions of a question about employees, in words: " with a salary on file over
 * ₱30,000.00 and hired before 2025". BirDataQuery, BirDataPeriod and BirDataDoubt use them in
 * their replies, so a reply always says what was answered.
 */
final class BirDataConditions
{
    public const LABELS = [
        'salary' => 'salary on file', 'compensation' => 'compensation', 'tax_withheld' => 'tax withheld',
        'taxable_compensation' => 'taxable compensation', 'non_taxable_compensation' => 'non-taxable compensation',
        'contributions' => 'SSS, PhilHealth and Pag-IBIG (employee share)', 'thirteenth_month' => '13th month and other benefits',
        'hire_date' => 'hire date', 'position' => 'position', 'department' => 'department', 'status' => 'status',
        'rate_type' => 'pay basis', 'group' => 'group', 'tin' => 'TIN', 'name' => 'name', 'email' => 'email', 'phone' => 'phone',
    ];

    private const COMPARE = ['gt' => 'over', 'gte' => 'of at least', 'lt' => 'under', 'lte' => 'of at most', 'eq' => 'of exactly', 'neq' => 'other than'];

    /** " with a salary on file over ₱30,000.00 and hired before 2025", or "" with no conditions. */
    public static function words(array $filters, bool $one = false): string
    {
        // "at least 17,000 and at most 40,000" on one amount: "between".
        foreach ($filters as $i => $low) {
            foreach ($filters as $j => $high) {
                if ($i !== $j && $low['field'] === $high['field'] && in_array($low['field'], BirDataIntent::NUMBERS, true) && !isset($low['who']) && !isset($high['who'])
                    && in_array($low['op'], ['gt', 'gte'], true) && in_array($high['op'], ['lt', 'lte'], true)) {
                    $label = self::LABELS[$low['field']];
                    $between = ($low['field'] === 'salary' ? 'with a ' : 'with ') . "{$label} between " . self::money($low['value']) . ' and ' . self::money($high['value']);
                    $rest = array_values(array_diff_key($filters, [$i => 1, $j => 1]));

                    return ' ' . $between . (($more = self::words($rest, $one)) !== '' ? ' and' . $more : '');
                }
            }
        }
        // "in or after January 1, 2024 and in or before December 31, 2024": hired in 2024.
        foreach ($filters as $i => $low) {
            foreach ($filters as $j => $high) {
                if ($low['field'] === 'hire_date' && $high['field'] === 'hire_date' && !isset($low['who']) && !isset($high['who']) && $low['op'] === 'gte' && $high['op'] === 'lte'
                    && preg_match('/^(\d{4})(?:-01(?:-01)?)?$/', (string) $low['value'], $a) && preg_match('/^(\d{4})(?:-12(?:-31)?)?$/', (string) $high['value'], $b) && $a[1] === $b[1]) {
                    $rest = array_values(array_diff_key($filters, [$i => 1, $j => 1]));

                    return " hired in {$a[1]}" . (($more = self::words($rest, $one)) !== '' ? ' and' . $more : '');
                }
            }
        }
        $parts = array_map(function (array $f) use ($one) {
            $label = self::LABELS[$f['field']];
            $value = $f['value'];

            return match (true) {
                $f['op'] === 'missing' => "with no {$label} on file",
                $f['op'] === 'present' => "with a {$label} on file",
                $f['field'] === 'group' => ($one ? ($f['op'] === 'neq' ? "who isn't " : 'who is ') : ($f['op'] === 'neq' ? "who aren't " : 'who are ')) . BirDataAnswers::GROUPS[BirDataAnswers::groupKey($value)][$one ? 0 : 1],
                $f['field'] === 'tax_withheld' && (float) $value === 0.0 && in_array($f['op'], ['gt', 'lte'], true) => $f['op'] === 'gt' ? 'who had tax withheld' : 'who had no tax withheld',
                $f['field'] === 'hire_date' && isset($f['who']) => 'hired ' . ['gt' => 'after', 'gte' => 'on or after', 'lt' => 'before', 'lte' => 'on or before', 'eq' => 'the same day as', 'neq' => 'on another day than'][$f['op']] . " {$f['who']} (" . self::when((string) $value) . ')',
                ($f['who'] ?? null) === 'the average' => ($f['field'] === 'salary' ? 'with a ' : 'with ') . "{$label} " . self::COMPARE[$f['op']] . ' the average (' . self::money((float) $value) . ')',
                isset($f['who']) => ($f['field'] === 'salary' ? 'with a ' : 'with ') . "{$label} " . self::COMPARE[$f['op']] . " {$f['who']}'s" . (is_numeric($value) ? ' (' . self::money((float) $value) . ')' : ''),
                in_array($f['field'], BirDataIntent::NUMBERS, true) && !is_numeric($value) => ($f['field'] === 'salary' ? 'with a ' : 'with ') . "{$label} " . self::COMPARE[$f['op']] . " {$value}'s",
                in_array($f['field'], BirDataIntent::NUMBERS, true) => ($f['field'] === 'salary' ? 'with a ' : 'with ') . "{$label} " . self::COMPARE[$f['op']] . ' ' . self::money((float) $value),
                $f['field'] === 'hire_date' => 'hired ' . ['gt' => 'after', 'gte' => 'in or after', 'lt' => 'before', 'lte' => 'in or before', 'eq' => 'in', 'neq' => 'outside'][$f['op']] . ' ' . self::when((string) $value),
                $f['field'] === 'status' && $value === 'active' => ($one ? 'who is' : 'who are') . ($f['op'] === 'neq' ? ' not active' : ' active'),
                default => "whose {$label} " . ['eq' => 'is', 'neq' => "isn't", 'contains' => 'contains', 'starts' => 'starts with'][$f['op']] . ' ' . implode(' or ', array_map(fn ($v) => '"' . $v . '"', (array) $value)),
            };
        }, $filters);

        return $parts === [] ? '' : ' ' . implode(' and ', $parts);
    }

    /** "2025", "March 2025", "March 1, 2025". */
    private static function when(string $value): string
    {
        return match (strlen($value)) {
            4 => $value,
            7 => BirFormNames::monthName($value),
            default => Carbon::parse($value)->format('F j, Y'),
        };
    }

    private static function money(float $amount): string
    {
        return BirConversationService::display(round($amount, 2) ?: 0.0, 'decimal');
    }
}
