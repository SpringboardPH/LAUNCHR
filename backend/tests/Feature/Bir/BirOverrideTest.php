<?php

namespace Tests\Feature\Bir;

use App\Helpers\SystemClock;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use Tests\TestCase;

/**
 * Edit tracking on PUT /bir/drafts/{id} (contract §3). Overriding a figure the system
 * supplies keeps the calculated figure in system_value, with who changed it and when.
 * A plain answer to a question only a person answers is not an override.
 */
class BirOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSystemClock();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetSystemClock();
        parent::tearDown();
    }

    public function test_overriding_a_payroll_figure_keeps_the_calculated_figure_with_who_and_when(): void
    {
        $this->payroll(300);
        $this->payroll(300, '2026-09-16', '2026-09-30');
        $id = $this->create1601C();
        $user = $this->accounting();

        $entry = $this->answer($id, ['total_taxes_withheld' => '700.00'])->json('data.fields.total_taxes_withheld');

        $this->assertSame('700.00', $entry['value']);
        $this->assertSame('user', $entry['origin']);
        $this->assertTrue($entry['edited']);
        $this->assertSame('600.00', $entry['system_value']);
        $this->assertSame(['id' => $user->id, 'name' => $user->name], $entry['edited_by']);
        $this->assertNotNull($entry['edited_at']);
    }

    public function test_editing_twice_keeps_the_original_calculated_figure_and_shows_the_latest_editor(): void
    {
        $this->payroll(300);
        $id = $this->create1601C();
        $this->answer($id, ['total_taxes_withheld' => '700.00']);

        $second = User::factory()->create(['role' => 'accounting']);
        $entry = $this->actingAs($second)
            ->putJson("/api/bir/drafts/{$id}", ['fields' => ['total_taxes_withheld' => '800.00']])
            ->assertOk()
            ->json('data.fields.total_taxes_withheld');

        $this->assertSame('800.00', $entry['value']);
        $this->assertSame('300.00', $entry['system_value'], 'Still the calculated figure, not the first answer.');
        $this->assertSame(['id' => $second->id, 'name' => $second->name], $entry['edited_by']);
    }

    public function test_overriding_a_settings_gap_is_an_override_with_no_calculated_figure(): void
    {
        $id = $this->create1601C();

        // The test database has no company settings, so the TIN starts pending.
        $entry = $this->answer($id, ['company_tin' => '123-456-789-000'])->json('data.fields.company_tin');

        $this->assertTrue($entry['edited']);
        $this->assertNull($entry['system_value']);
        $this->assertNotNull($entry['edited_by']);
    }

    public function test_clearing_an_override_wipes_its_history_and_recalculates(): void
    {
        $id = $this->create1601C();
        $this->answer($id, ['surcharge' => '1000.00', 'total_penalties' => '900.00']);

        $entry = $this->answer($id, ['total_penalties' => null])->json('data.fields.total_penalties');

        $this->assertSame('1000.00', $entry['value']);
        $this->assertSame('payroll', $entry['origin']);
        $this->assertFalse($entry['edited']);
        $this->assertNull($entry['system_value']);
        $this->assertNull($entry['edited_by']);
        $this->assertNull($entry['edited_at']);
    }

    public function test_a_plain_answer_to_a_user_question_is_not_an_override(): void
    {
        $id = $this->create1601C();

        $entry = $this->answer($id, ['is_amended' => false])->json('data.fields.is_amended');

        $this->assertFalse($entry['value']);
        $this->assertSame('user', $entry['origin']);
        $this->assertFalse($entry['edited']);
        $this->assertNull($entry['system_value']);
        $this->assertNull($entry['edited_by']);
        $this->assertNull($entry['edited_at']);
    }

    public function test_the_time_of_an_override_comes_from_the_system_clock(): void
    {
        // Real time and the admin-set system time differ, so this shows which one is used.
        Carbon::setTestNow('2026-10-08 12:00:00');
        SystemSettings::set('system_date', '2026-06-08');
        SystemSettings::set('system_time', '09:47:00');
        $this->resetSystemClock();
        $id = $this->create1601C();

        $entry = $this->answer($id, ['total_taxes_withheld' => '700.00'])->json('data.fields.total_taxes_withheld');

        $this->assertSame('2026-06-08T09:47:00+08:00', $entry['edited_at']);
    }

    /** SystemClock caches the settings for the whole process; clear it so tests don't leak into each other. */
    private function resetSystemClock(): void
    {
        (new ReflectionClass(SystemClock::class))->setStaticPropertyValue('settingsCache', null);
    }

    private function answer(int $draftId, array $fields): TestResponse
    {
        return $this->actingAs($this->accounting())
            ->putJson("/api/bir/drafts/{$draftId}", ['fields' => $fields])
            ->assertOk();
    }

    private function payroll(float $tax, string $start = '2026-09-01', string $end = '2026-09-15'): void
    {
        $employee = Employee::firstOrCreate(['employee_id' => 'EMP-BIR-O1'], [
            'first_name' => 'Bir',
            'last_name' => 'Override',
            'email' => 'bir-override@example.com',
            'position' => 'Staff',
            'hire_date' => '2025-01-06',
            'salary' => 30000,
            'status' => 'active',
            'rate_type' => 'monthly',
        ]);

        Payroll::create([
            'employee_id' => $employee->id,
            'cutoff_start' => $start,
            'cutoff_end' => $end,
            'base_salary' => 30000,
            'gross_pay' => 15000,
            'deductions' => ['Withholding Tax' => $tax],
            'allowances' => [],
            'status' => 'finalized',
        ]);
    }

    private function create1601C(): int
    {
        return $this->actingAs($this->accounting())
            ->postJson('/api/bir/drafts', ['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertCreated()
            ->json('data.id');
    }

    private function accounting(): User
    {
        return User::firstWhere('role', 'accounting') ?? User::factory()->create(['role' => 'accounting']);
    }
}
