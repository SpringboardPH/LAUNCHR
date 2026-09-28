<?php

namespace Database\Seeders;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\User;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Database\Seeder;

/**
 * Week 1 stand-in for the bir_form_drafts table (created in Week 2).
 * BirFormController reads fixtures() directly until then; once the
 * migration/model land, run() should insert these rows for real via
 * BirFormDraft::create() instead.
 */
class BirFixtureSeeder extends Seeder
{
    public function run(): void
    {
        $preparer = User::where('role', 'hr')->first() ?? User::first();
        $approver = User::where('role', 'admin')->first() ?? User::first();

        if (!$preparer || !$approver) {
            $this->command?->warn('BirFixtureSeeder: no users found — skipping BIR draft fixtures.');
            return;
        }

        // A 2316 is per employee: give the two 2316 fixtures (ids 3 and 4) different employees.
        $employeeIds = Employee::orderBy('id')->limit(2)->pluck('id');
        if ($employeeIds->count() < 2) {
            $this->command?->warn('BirFixtureSeeder: fewer than two employees — the 2316 fixtures will share an employee or have none.');
        }
        $employeeFor = [
            3 => $employeeIds->get(0),
            4 => $employeeIds->get(1) ?? $employeeIds->get(0),
        ];

        $drafts = [];
        foreach (self::fixtures() as $fixture) {
            // version is part of the key: fixtures 3 and 4 share form_type 2316 and period 2025.
            $drafts[] = BirFormDraft::updateOrCreate(
                [
                    'form_type' => $fixture['form_type'],
                    'period' => $fixture['period'],
                    'employee_id' => $employeeFor[$fixture['id']] ?? null,
                    'version' => $fixture['version'],
                ],
                [
                    'status' => $fixture['status'],
                    'prepared_by' => $preparer->id,
                    'approved_by' => $fixture['approved_by'] ? $approver->id : null,
                    'rejection_reason' => $fixture['rejection_reason'],
                    'fields' => $fixture['fields'],
                    'validation_errors' => $fixture['validation_errors'],
                ]
            );
        }

        // A revision points at the finalized version 1 of the same form, period and employee.
        // Create that version 1 if the fixtures don't include it, with every edit reverted
        // so it holds the original figures and the revision holds the corrected ones.
        foreach ($drafts as $revision) {
            if ($revision->version < 2) {
                continue;
            }

            // A reverted value is the calculated one again, so its origin is the schema's source.
            $schema = match ($revision->form_type) {
                Form1601CSchema::FORM_TYPE => Form1601CSchema::byKey(),
                Form2316Schema::FORM_TYPE => Form2316Schema::byKey(),
                default => [],
            };

            $originalFields = collect($revision->fields)->map(fn (array $entry, string $key) => $entry['edited']
                ? array_merge($entry, [
                    'value' => $entry['system_value'],
                    'origin' => $schema[$key]['source'] ?? $entry['origin'],
                    'edited' => false,
                    'system_value' => null,
                    'edited_by' => null,
                    'edited_at' => null,
                ])
                : $entry
            )->all();

            $parent = BirFormDraft::firstOrCreate(
                [
                    'form_type' => $revision->form_type,
                    'period' => $revision->period,
                    'employee_id' => $revision->employee_id,
                    'version' => 1,
                ],
                [
                    'status' => 'finalized',
                    'prepared_by' => $preparer->id,
                    'approved_by' => $approver->id,
                    'fields' => $originalFields,
                    'validation_errors' => [],
                ]
            );

            if ($parent->status !== 'finalized') {
                $this->command?->warn("BirFixtureSeeder: parent of draft {$revision->id} is {$parent->status}, not finalized.");
            }

            $revision->update(['parent_id' => $parent->id]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function fixtures(): array
    {
        return [
            [
                'id' => 1,
                'form_type' => '1601-C',
                'period' => '2026-07',
                'status' => 'draft',
                'version' => 1,
                'prepared_by' => ['id' => 5, 'name' => 'Jane Dela Cruz'],
                'approved_by' => null,
                'rejection_reason' => null,
                'fields' => [
                    'return_period' => ['value' => '07/2026', 'origin' => 'user', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'company_tin' => ['value' => '000-000-000-000', 'origin' => 'settings', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'total_compensation' => ['value' => null, 'origin' => 'pending', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'total_taxes_withheld' => ['value' => null, 'origin' => 'pending', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                ],
                'validation_errors' => [],
            ],
            [
                'id' => 2,
                'form_type' => '1601-C',
                'period' => '2026-06',
                'status' => 'pending',
                'version' => 1,
                'prepared_by' => ['id' => 5, 'name' => 'Jane Dela Cruz'],
                'approved_by' => null,
                'rejection_reason' => null,
                'fields' => [
                    'return_period' => ['value' => '06/2026', 'origin' => 'user', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'company_tin' => ['value' => '000-000-000-000', 'origin' => 'settings', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'total_compensation' => ['value' => '842300.00', 'origin' => 'payroll', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'total_taxes_withheld' => ['value' => '61204.15', 'origin' => 'user', 'edited' => true, 'system_value' => '61024.15', 'edited_by' => ['id' => 5, 'name' => 'Jane Dela Cruz'], 'edited_at' => '2026-07-08T10:22:00+08:00'],
                ],
                'validation_errors' => [],
            ],
            [
                'id' => 3,
                'form_type' => '2316',
                'period' => '2025',
                'status' => 'approved',
                'version' => 1,
                'prepared_by' => ['id' => 5, 'name' => 'Jane Dela Cruz'],
                'approved_by' => ['id' => 2, 'name' => 'Mark Santos'],
                'rejection_reason' => null,
                'fields' => [
                    'tax_year' => ['value' => 2025, 'origin' => 'user', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'employee_tin' => ['value' => '111-222-333-000', 'origin' => 'payroll', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'gross_compensation_present' => ['value' => '480000.00', 'origin' => 'payroll', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'taxes_withheld_present' => ['value' => '28450.00', 'origin' => 'payroll', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                ],
                'validation_errors' => [],
            ],
            [
                'id' => 4,
                'form_type' => '2316',
                'period' => '2025',
                'status' => 'finalized',
                'version' => 2,
                'prepared_by' => ['id' => 5, 'name' => 'Jane Dela Cruz'],
                'approved_by' => ['id' => 2, 'name' => 'Mark Santos'],
                'rejection_reason' => null,
                'fields' => [
                    'tax_year' => ['value' => 2025, 'origin' => 'user', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'employee_tin' => ['value' => '444-555-666-000', 'origin' => 'payroll', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'gross_compensation_present' => ['value' => '612000.00', 'origin' => 'payroll', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'taxes_withheld_present' => ['value' => '39880.00', 'origin' => 'user', 'edited' => true, 'system_value' => '39460.00', 'edited_by' => ['id' => 5, 'name' => 'Jane Dela Cruz'], 'edited_at' => '2026-01-26T14:05:00+08:00'],
                ],
                'validation_errors' => [],
            ],
            [
                'id' => 5,
                'form_type' => '1601-C',
                'period' => '2026-05',
                'status' => 'draft',
                'version' => 1,
                'prepared_by' => ['id' => 5, 'name' => 'Jane Dela Cruz'],
                'approved_by' => null,
                'rejection_reason' => 'Item 25 total tax withheld does not match the payroll register — please recheck May.',
                'fields' => [
                    'return_period' => ['value' => '05/2026', 'origin' => 'user', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'company_tin' => ['value' => '000-000-000-000', 'origin' => 'settings', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'total_compensation' => ['value' => '795150.00', 'origin' => 'payroll', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null],
                    'total_taxes_withheld' => ['value' => '55010.00', 'origin' => 'user', 'edited' => true, 'system_value' => '54872.50', 'edited_by' => ['id' => 5, 'name' => 'Jane Dela Cruz'], 'edited_at' => '2026-06-08T09:47:00+08:00'],
                ],
                'validation_errors' => [
                    ['field' => 'total_taxes_withheld', 'message' => 'Does not match calculated payroll total.'],
                ],
            ],
        ];
    }
}
