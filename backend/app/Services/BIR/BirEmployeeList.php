<?php

namespace App\Services\BIR;

use App\Models\Employee;
use App\Services\BIR\Schemas\FieldFormat;

/**
 * The 1601-C employee list: each employee's part of the form. The figures are BirAggregationService's
 * _meta 'employees' rows, frozen on the draft's source_snapshot when it is made; nothing here
 * recomputes them, since item 14 isn't raw gross_pay and the MWE list in settings can change later.
 */
class BirEmployeeList
{
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
