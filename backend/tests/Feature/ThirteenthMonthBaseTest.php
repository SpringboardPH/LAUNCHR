<?php

namespace Tests\Feature;

use App\Helpers\SystemClock;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Models\ThirteenthMonth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ThirteenthMonthBaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSettings::set('system_date', '2026-12-31');
        SystemSettings::set('system_time', '08:00:00');
        $cache = new \ReflectionProperty(SystemClock::class, 'settingsCache');
        $cache->setValue(null, null);
    }

    private function employee(string $code, string $rateType): Employee
    {
        return Employee::create([
            'employee_id' => $code,
            'first_name' => 'Base',
            'last_name' => $code,
            'email' => strtolower($code) . '@example.com',
            'position' => 'Staff',
            'hire_date' => '2026-01-01',
            'salary' => 20000,
            'rate_type' => $rateType,
            'status' => 'active',
        ]);
    }

    private function payroll(Employee $employee, array $overrides = []): Payroll
    {
        return Payroll::create(array_merge([
            'employee_id' => $employee->id,
            'cutoff_start' => '2026-06-11',
            'cutoff_end' => '2026-06-25',
            'base_salary' => 20000,
            'daily_rate' => 1000,
            'gross_pay' => 0,
            'net_pay' => 0,
            'status' => 'draft',
            'deductions' => [],
            'allowances' => [],
        ], $overrides));
    }

    private function monthRow(string $code): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->getJson('/api/thirteenth-month?year=2026');
        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('employee_id', $code);
        $this->assertNotNull($row, "missing worksheet row for {$code}");

        return $row;
    }

    public function test_month_is_base_plus_allowance_minus_attendance_docks(): void
    {
        $employee = $this->employee('EMP-13-M', 'monthly');
        $this->payroll($employee, [
            'gross_pay' => 17000,
            'net_pay' => 12000,
            'allowances' => [
                ['label' => 'Allowance', 'amount' => 2000],
                ['label' => 'Overtime Pay', 'amount' => 5000],
            ],
            'deductions' => [
                'Late' => 200,
                'Absent' => 1000,
                'Half Day' => 400,
                'Undertime' => 150,
                'Withholding Tax' => 300,
            ],
        ]);

        $line = $this->monthRow('EMP-13-M')['months']['6'];

        $this->assertSame(10250.0, (float) $line['amount']);
        $this->assertSame(10000.0, (float) $line['breakdown'][0]['base']);
        $this->assertSame(2000.0, (float) $line['breakdown'][0]['allowance']);
        $this->assertSame(200.0, (float) $line['breakdown'][0]['late']);
        $this->assertSame(1000.0, (float) $line['breakdown'][0]['absent']);
        $this->assertSame(400.0, (float) $line['breakdown'][0]['half_day']);
        $this->assertSame(150.0, (float) $line['breakdown'][0]['undertime']);
        $this->assertSame(10250.0, (float) $line['breakdown'][0]['total']);

        $admin = User::factory()->create(['role' => 'hr']);
        $this->actingAs($admin)->postJson('/api/thirteenth-month/push-to-payroll', [
            'year' => 2026,
            'cutoff_start' => '2026-06-11',
            'cutoff_end' => '2026-06-25',
            'employee_ids' => [$employee->id],
        ])->assertOk();

        $allowance = collect(Payroll::find($this->payrollId($employee))->allowances)
            ->firstWhere('label', '13th Month Pay');
        $this->assertEquals(854.17, (float) $allowance['amount']);
    }

    public function test_bonus_counts_and_overtime_does_not(): void
    {
        $employee = $this->employee('EMP-13-D', 'daily');
        $this->payroll($employee, [
            'gross_pay' => 5300,
            'net_pay' => 3000,
            'allowances' => [
                ['label' => 'Bonus', 'amount' => 500],
                ['label' => 'Overtime Pay', 'amount' => 800],
            ],
            'deductions' => [
                'Late' => 100,
                'Absent' => 1000,
                'Half Day' => 500,
            ],
        ]);

        $line = $this->monthRow('EMP-13-D')['months']['6']['breakdown'][0];

        $this->assertSame(4000.0, (float) $line['base']);
        $this->assertSame(500.0, (float) $line['allowance']);
        $this->assertSame(2900.0, (float) $line['total']);
    }

    public function test_typed_month_without_payroll_stays_saved(): void
    {
        $employee = $this->employee('EMP-13-T', 'monthly');
        $this->payroll($employee, [
            'gross_pay' => 4000,
        ]);
        ThirteenthMonth::create([
            'employee_id' => $employee->id,
            'year' => 2026,
            'month' => 1,
            'basic_pay' => 222,
            'is_override' => true,
        ]);

        $row = $this->monthRow('EMP-13-T');

        $this->assertTrue($row['months']['1']['is_override']);
        $this->assertSame(222.0, (float) $row['months']['1']['amount']);
        $this->assertSame(4000.0, (float) $row['months']['6']['amount']);
    }

    public function test_release_command_drops_overrides_only_when_payroll_exists(): void
    {
        $employee = $this->employee('EMP-13-R', 'monthly');
        $this->payroll($employee, [
            'total_hours' => 80,
            'daily_rate' => 1000,
            'gross_pay' => 10000,
            'net_pay' => 10000,
        ]);

        ThirteenthMonth::create([
            'employee_id' => $employee->id,
            'year' => 2026,
            'month' => 6,
            'basic_pay' => 111,
            'is_override' => true,
        ]);
        ThirteenthMonth::create([
            'employee_id' => $employee->id,
            'year' => 2026,
            'month' => 1,
            'basic_pay' => 222,
            'is_override' => true,
        ]);

        Artisan::call('thirteenth-month:release-payroll-months', ['year' => 2026]);

        $this->assertNull(
            ThirteenthMonth::where('employee_id', $employee->id)->where('month', 6)->first()
        );
        $january = ThirteenthMonth::where('employee_id', $employee->id)->where('month', 1)->first();
        $this->assertNotNull($january);
        $this->assertEquals(222, (float) $january->basic_pay);

        $june = $this->monthRow('EMP-13-R')['months']['6'];
        $this->assertFalse($june['is_override']);
        $this->assertSame(10000.0, (float) $june['amount']);
    }

    private function payrollId(Employee $employee): int
    {
        return Payroll::where('employee_id', $employee->id)->value('id');
    }
}
