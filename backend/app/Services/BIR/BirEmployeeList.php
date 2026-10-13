<?php

namespace App\Services\BIR;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Services\BIR\Schemas\FieldFormat;

/**
 * The 1601-C employee list: each employee's part of the form. The figures are BirAggregationService's
 * _meta 'employees' rows, frozen on the draft's source_snapshot when it is made; nothing here
 * recomputes them, since item 14 isn't raw gross_pay and the MWE list in settings can change later.
 */
class BirEmployeeList
{
    /** Money column in Al's rows => [1601-C item, field key] it adds up to. */
    public const ITEMS = [
        'compensation' => ['14', 'total_compensation'],
        'mwe_basic' => ['15', 'mwe_statutory_wage'],
        'mwe_premium' => ['16', 'mwe_premium_pay'],
        'thirteenth' => ['17', 'thirteenth_month_and_benefits'],
        'ee' => ['19', 'statutory_contributions_ee'],
        'taxable' => ['22', 'total_taxable_compensation'],
        'exempt_250k' => ['23', 'exempt_250k_compensation'],
        'tax_withheld' => ['25', 'total_taxes_withheld'],
    ];

    /**
     * The frozen list for a 1601-C, its column totals, and the items where the totals differ from
     * the form's current values. Null when the snapshot (its own, or the nearest up the parent
     * chain) has no employee rows: the form was made before they existed. Money is contract
     * decimal text, added up in whole centavos.
     *
     * @return array{employees: list<array<string, mixed>>, totals: array<string, string>, differences: list<array<string, ?string>>}|null
     */
    public static function forDraft(BirFormDraft $draft): ?array
    {
        $snapshot = $draft->sourceSnapshot();
        if (!isset($snapshot['meta']['employees']) || !is_array($snapshot['meta']['employees'])) {
            return null;
        }

        // Made after the rows but before names were frozen: name and tin are null.
        $people = $snapshot['people'] ?? [];
        $sums = array_fill_keys(array_keys(self::ITEMS), 0);
        $employees = [];

        // Built key by key: the JSON column hands keys back in its own order.
        foreach ($snapshot['meta']['employees'] as $row) {
            $id = (int) $row['employee_id'];
            $out = [
                'employee_id' => $id,
                'name' => $people[$id]['name'] ?? null,
                'tin' => $people[$id]['tin'] ?? null,
                'category' => $row['category'] ?? null,
                'payroll_count' => (int) ($row['payroll_count'] ?? 0),
            ];
            foreach (array_keys(self::ITEMS) as $column) {
                $centavos = self::centavos($row[$column] ?? null) ?? 0;
                $sums[$column] += $centavos;
                $out[$column] = self::money($centavos);
            }
            $employees[] = $out;
        }

        $totals = array_map(fn (int $c) => self::money($c), $sums);

        $differences = [];
        foreach (self::ITEMS as $column => [$item, $key]) {
            $form = self::centavos(($draft->fields ?? [])[$key]['value'] ?? null);
            if ($form === $sums[$column]) {
                continue;
            }
            $differences[] = [
                'item' => $item,
                'key' => $key,
                'employees_total' => $totals[$column],
                'form_value' => $form === null ? null : self::money($form),
                'difference' => $form === null ? null : self::money($form - $sums[$column]),
            ];
        }

        return ['employees' => $employees, 'totals' => $totals, 'differences' => $differences];
    }

    /** "30000.5" or "-12.34" in whole centavos, read from the text; null if blank or not an amount. */
    private static function centavos(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', trim($value), $m) !== 1) {
            return null;
        }
        $centavos = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$centavos : $centavos;
    }

    /** Whole centavos as contract decimal text: 3000050 => "30000.50", no thousands separators. */
    private static function money(int $centavos): string
    {
        $sign = $centavos < 0 ? '-' : '';
        $abs = abs($centavos);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Each listed employee's name and TIN as they are now, for store() to freeze beside the rows:
     * [employee id => ['name' => 'VILLANUEVA, ANDRES', 'tin' => '123-456-789-00000' or null]].
     * Soft-deleted employees are included; one deleted outright gets nulls, so every row has an entry.
     */
    public static function people(array $meta): array
    {
        $ids = array_column($meta['employees'] ?? [], 'employee_id');
        $employees = Employee::withTrashed()
            ->whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name', 'tin_number'])
            ->keyBy('id');

        $people = [];
        foreach ($ids as $id) {
            $employee = $employees->get($id);
            $people[$id] = [
                'name' => $employee ? self::name($employee) : null,
                'tin' => $employee ? self::tin($employee->tin_number) : null,
            ];
        }

        return $people;
    }

    /** "VILLANUEVA, ANDRES", in capitals like the forms; just one part if the other is empty. */
    private static function name(Employee $employee): ?string
    {
        $parts = array_filter([trim((string) $employee->last_name), trim((string) $employee->first_name)], fn (string $p) => $p !== '');

        return $parts ? mb_strtoupper(implode(', ', $parts)) : null;
    }

    private static function tin(?string $tin): ?string
    {
        return trim((string) $tin) === '' ? null : FieldFormat::tin($tin);
    }
}
