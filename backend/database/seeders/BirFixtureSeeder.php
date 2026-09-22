<?php

namespace Database\Seeders;

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
        // No-op until the bir_form_drafts table exists (Week 2). Fixtures
        // are served in-memory via fixtures() in the meantime.
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
                    'return_period' => ['value' => '07/2026', 'source' => 'user', 'edited' => false],
                    'company_tin' => ['value' => '000-000-000-000', 'source' => 'settings', 'edited' => false],
                    'gross_compensation' => ['value' => null, 'source' => 'payroll', 'edited' => false],
                    'total_taxes_withheld' => ['value' => null, 'source' => 'payroll', 'edited' => false],
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
                    'return_period' => ['value' => '06/2026', 'source' => 'user', 'edited' => false],
                    'company_tin' => ['value' => '000-000-000-000', 'source' => 'settings', 'edited' => false],
                    'gross_compensation' => ['value' => '842,300.00', 'source' => 'payroll', 'edited' => false],
                    'total_taxes_withheld' => ['value' => '61,204.15', 'source' => 'payroll', 'edited' => true],
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
                    'tax_year' => ['value' => 2025, 'source' => 'user', 'edited' => false],
                    'employee_tin' => ['value' => '111-222-333-000', 'source' => 'payroll', 'edited' => false],
                    'gross_compensation' => ['value' => '480,000.00', 'source' => 'payroll', 'edited' => false],
                    'tax_withheld' => ['value' => '28,450.00', 'source' => 'payroll', 'edited' => false],
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
                    'tax_year' => ['value' => 2025, 'source' => 'user', 'edited' => false],
                    'employee_tin' => ['value' => '444-555-666-000', 'source' => 'payroll', 'edited' => false],
                    'gross_compensation' => ['value' => '612,000.00', 'source' => 'payroll', 'edited' => false],
                    'tax_withheld' => ['value' => '39,880.00', 'source' => 'payroll', 'edited' => true],
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
                    'return_period' => ['value' => '05/2026', 'source' => 'user', 'edited' => false],
                    'company_tin' => ['value' => '000-000-000-000', 'source' => 'settings', 'edited' => false],
                    'gross_compensation' => ['value' => '795,150.00', 'source' => 'payroll', 'edited' => false],
                    'total_taxes_withheld' => ['value' => '55,010.00', 'source' => 'payroll', 'edited' => true],
                ],
                'validation_errors' => [
                    ['field' => 'total_taxes_withheld', 'message' => 'Does not match calculated payroll total.'],
                ],
            ],
        ];
    }
}
