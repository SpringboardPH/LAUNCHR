<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * One return per period: POST /bir/drafts refuses a second original for the same form type and
 * period (and employee, on a 2316) with 409, whatever the first one's status or version, and
 * points at the latest version of the one already there. Corrections go through revise.
 */
class BirDuplicateDraftTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private User $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounting = User::factory()->create(['role' => 'accounting']);
    }

    public function test_a_second_1601c_for_the_same_month_is_refused_and_points_at_the_first(): void
    {
        $id = $this->create(['form_type' => '1601-C', 'period' => '2026-09'])->assertCreated()->json('data.id');

        $this->create(['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => "A 1601-C for 09/2026 already exists (form #{$id}, draft). Open it instead.",
                'data' => ['existing' => ['id' => $id, 'label' => "#{$id}", 'status' => 'draft', 'version' => 1]],
            ]);

        $this->assertSame(1, BirFormDraft::count());
    }

    public function test_a_1601c_for_a_different_month_is_created(): void
    {
        $this->create(['form_type' => '1601-C', 'period' => '2026-09'])->assertCreated();
        $this->create(['form_type' => '1601-C', 'period' => '2026-08'])->assertCreated();

        $this->assertSame(2, BirFormDraft::count());
    }

    public function test_a_second_2316_for_the_same_employee_and_year_is_refused(): void
    {
        $maria = $this->employee('EMP-DUP1', 'Maria', 'Santos');
        $id = $this->create(['form_type' => '2316', 'period' => '2026', 'employee_id' => $maria->id])->assertCreated()->json('data.id');
        BirFormDraft::whereKey($id)->update(['status' => 'pending']);

        $this->create(['form_type' => '2316', 'period' => '2026', 'employee_id' => $maria->id])
            ->assertStatus(409)
            ->assertJsonPath('message', "A 2316 for Maria Santos for 2026 already exists (form #{$id}, pending). Open it instead.")
            ->assertJsonPath('data.existing', ['id' => $id, 'label' => "#{$id}", 'status' => 'pending', 'version' => 1]);

        $this->assertSame(1, BirFormDraft::count());
    }

    public function test_a_2316_for_a_different_employee_or_year_is_created(): void
    {
        $maria = $this->employee('EMP-DUP1', 'Maria', 'Santos');
        $juan = $this->employee('EMP-DUP2', 'Juan', 'Cruz');

        $this->create(['form_type' => '2316', 'period' => '2026', 'employee_id' => $maria->id])->assertCreated();
        $this->create(['form_type' => '2316', 'period' => '2026', 'employee_id' => $juan->id])->assertCreated();
        $this->create(['form_type' => '2316', 'period' => '2025', 'employee_id' => $maria->id])->assertCreated();

        $this->assertSame(3, BirFormDraft::count());
    }

    public function test_a_filed_form_points_to_a_correction_instead(): void
    {
        $id = $this->create(['form_type' => '1601-C', 'period' => '2026-09'])->assertCreated()->json('data.id');
        BirFormDraft::whereKey($id)->update(['status' => 'finalized']);

        $this->create(['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertStatus(409)
            ->assertJsonPath('message', "A 1601-C for 09/2026 was already filed (form #{$id}). Start a correction instead.")
            ->assertJsonPath('data.existing.status', 'finalized');
    }

    public function test_the_latest_version_is_the_one_pointed_at(): void
    {
        $v1 = $this->create(['form_type' => '1601-C', 'period' => '2026-09'])->assertCreated()->json('data.id');
        BirFormDraft::whereKey($v1)->update(['status' => 'finalized']);
        $v2 = $this->actingAs($this->accounting)->postJson("/api/bir/drafts/{$v1}/revise")->assertCreated()->json('data.id');

        $this->create(['form_type' => '1601-C', 'period' => '2026-09'])
            ->assertStatus(409)
            ->assertJsonPath('message', "A 1601-C for 09/2026 already exists (form #{$v1}v2, draft). Open it instead.")
            ->assertJsonPath('data.existing', ['id' => $v2, 'label' => "#{$v1}v2", 'status' => 'draft', 'version' => 2]);

        $this->assertSame(2, BirFormDraft::count());
    }

    public function test_a_bad_body_is_422_before_any_409(): void
    {
        $this->create(['form_type' => '1601-C', 'period' => '2026-09'])->assertCreated();

        $this->create(['form_type' => '1601-C', 'period' => '2026-9'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('period');
        $maria = $this->employee('EMP-DUP1', 'Maria', 'Santos');
        $this->create(['form_type' => '1601-C', 'period' => '2026-09', 'employee_id' => $maria->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_id');

        $this->assertSame(1, BirFormDraft::count());
    }

    private function create(array $body): TestResponse
    {
        return $this->actingAs($this->accounting)->postJson('/api/bir/drafts', $body);
    }

    private function employee(string $code, string $first, string $last): Employee
    {
        return Employee::create([
            'employee_id' => $code, 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($code) . '@example.com', 'position' => 'Staff', 'hire_date' => '2025-03-01',
            'salary' => 30000, 'status' => 'active', 'rate_type' => 'monthly', 'tin_number' => '123-456-789-000',
        ]);
    }
}
