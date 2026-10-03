<?php

namespace Tests\Feature\Bir;

use App\Models\Employee;
use App\Models\Payroll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BirCompareCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/bir-compare');
        File::deleteDirectory($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_template_lists_every_compared_1601c_item_with_a_blank_filed_column(): void
    {
        $this->artisan('bir:compare', ['form' => '1601-C', 'period' => '2026-08', '--template' => true])
            ->expectsOutputToContain('Template written to')
            ->assertSuccessful();

        $rows = $this->csv("{$this->dir}/1601c-2026-08-filed.csv");
        $this->assertSame(['item', 'key', 'label', 'filed'], $rows[0]);
        $this->assertSame(['14', 'total_compensation'], array_slice($rows[1], 0, 2));
        $this->assertSame('', $rows[1][3]);
        $this->assertNotContains('company_tin', array_column($rows, 1)); // settings field, nothing to compare
    }

    public function test_a_filed_sheet_is_compared_item_by_item(): void
    {
        $e = $this->employee('A');
        $this->payroll($e, 10000, ['SSS EE Contribution' => 500, 'Withholding Tax' => 100]);
        $this->artisan('bir:compare', ['form' => '1601-C', 'period' => '2026-08', '--template' => true]);

        // Filed: item 14 matches (written with a comma), item 25 is off by 50, the rest left blank.
        $filed = "{$this->dir}/1601c-2026-08-filed.csv";
        $this->fill($filed, ['total_compensation' => '10,000.00', 'total_taxes_withheld' => '150']);

        $this->artisan('bir:compare', ['form' => '1601-C', 'period' => '2026-08', '--filed' => $filed])
            ->expectsOutputToContain('1 match, 1 differ')
            ->assertSuccessful();

        $report = collect($this->csv("{$this->dir}/1601c-2026-08-comparison.csv"))->keyBy(0);
        $this->assertSame(['14', 'Total Amount of Compensation', '10,000.00', '10,000.00', '', 'match', '', ''], $report['14']);
        $this->assertSame(['-50.00', 'DIFFERS'], array_slice($report['25'], 4, 2));
        $this->assertSame('not filed', $report['19'][5]);
    }

    public function test_a_2316_is_compared_for_one_employee_by_code(): void
    {
        $e = $this->employee('A');
        $this->payroll($e, 10000, ['SSS EE Contribution' => 500]);
        $this->artisan('bir:compare', ['form' => '2316', 'period' => '2026', '--employee' => 'BIR-A', '--template' => true])
            ->assertSuccessful();

        $filed = "{$this->dir}/2316-2026-BIR-A-filed.csv";
        $this->fill($filed, ['gross_compensation_present' => '10000']);

        $this->artisan('bir:compare', ['form' => '2316', 'period' => '2026', '--employee' => 'BIR-A', '--filed' => $filed])
            ->expectsOutputToContain('1 match, 0 differ')
            ->expectsOutputToContain("No '13th Month Pay' in this year's payroll")
            ->assertSuccessful();
    }

    public function test_bad_input_is_refused_with_a_reason(): void
    {
        $this->artisan('bir:compare', ['form' => '1700', 'period' => '2026'])->expectsOutputToContain('Unknown form')->assertFailed();
        $this->artisan('bir:compare', ['form' => '1601-C', 'period' => '2026'])->expectsOutputToContain('YYYY-MM')->assertFailed();
        $this->artisan('bir:compare', ['form' => '2316', 'period' => '2026'])->expectsOutputToContain('--employee')->assertFailed();
        $this->artisan('bir:compare', ['form' => '2316', 'period' => '2026', '--employee' => 'NOPE'])->expectsOutputToContain('No employee')->assertFailed();
        $this->artisan('bir:compare', ['form' => '1601-C', 'period' => '2026-08'])->expectsOutputToContain('--template')->assertFailed();
        $this->artisan('bir:compare', ['form' => '1601-C', 'period' => '2026-08', '--filed' => '/nope.csv'])->expectsOutputToContain("Can't read")->assertFailed();
    }

    /** Write values into the template's "filed" column by key. */
    private function fill(string $path, array $values): void
    {
        $rows = $this->csv($path);
        $handle = fopen($path, 'w');
        foreach ($rows as $i => $row) {
            if ($i > 0 && isset($values[$row[1]])) {
                $row[3] = $values[$row[1]];
            }
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    private function csv(string $path): array
    {
        return array_map('str_getcsv', array_filter(explode("\n", file_get_contents($path))));
    }

    private function employee(string $code): Employee
    {
        return Employee::create([
            'employee_id' => "BIR-{$code}",
            'first_name' => 'Employee',
            'last_name' => $code,
            'email' => "bir-{$code}@example.com",
            'position' => 'Staff',
            'hire_date' => '2026-01-01',
            'salary' => 20000,
            'status' => 'active',
            'rate_type' => 'monthly',
            'tin_number' => '000-000-000-000',
        ]);
    }

    private function payroll(Employee $e, float $gross, array $deductions): Payroll
    {
        return Payroll::create([
            'employee_id' => $e->id,
            'cutoff_start' => '2026-08-01',
            'cutoff_end' => '2026-08-15',
            'base_salary' => 20000,
            'gross_pay' => $gross,
            'deductions' => $deductions,
            'allowances' => [],
            'status' => 'finalized',
        ]);
    }
}
