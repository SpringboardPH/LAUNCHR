<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * POST /bir/drafts/{id}/revise: a new draft numbered after the highest version of the
 * same form (form type, period, employee), so two drafts never share a version.
 */
class BirReviseTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    public function test_revising_a_finalized_form_creates_the_next_version_and_leaves_it_untouched(): void
    {
        $original = $this->finalized('2026-07', 1);

        $this->revise($original->id)
            ->assertCreated()
            ->assertJson(['data' => ['version' => 2, 'parent_id' => $original->id, 'status' => 'draft']]);

        $this->assertSame('finalized', $original->fresh()->status);
        $this->assertSame(1, $original->fresh()->version);
    }

    public function test_revising_the_same_form_twice_gives_v2_then_v3(): void
    {
        $original = $this->finalized('2026-07', 1);

        $this->assertSame(2, $this->revise($original->id)->assertCreated()->json('data.version'));
        $this->assertSame(3, $this->revise($original->id)->assertCreated()->json('data.version'));
    }

    public function test_versions_of_another_period_do_not_count(): void
    {
        $this->finalized('2026-06', 5);
        $july = $this->finalized('2026-07', 1);

        $this->assertSame(2, $this->revise($july->id)->assertCreated()->json('data.version'));
    }

    public function test_a_1601c_can_be_amended_five_times_per_month(): void
    {
        $original = $this->finalized('2026-07', 1);

        foreach ([2, 3, 4, 5, 6] as $version) {
            $this->assertSame($version, $this->revise($original->id)->assertCreated()->json('data.version'));
        }
        $this->revise($original->id)
            ->assertStatus(400)
            ->assertJsonPath('message', 'This 1601-C has already been amended 5 times for 2026-07, the most allowed for one month');
        $this->assertSame(6, BirFormDraft::count());

        // Each month has its own five.
        $this->assertSame(2, $this->revise($this->finalized('2026-08', 1)->id)->assertCreated()->json('data.version'));
    }

    public function test_a_2316_has_no_amendment_limit_and_the_count_is_shown_on_a_1601c(): void
    {
        $filed = BirFormDraft::create([
            'form_type' => '2316', 'period' => '2026', 'status' => 'finalized', 'version' => 6,
            'prepared_by' => $this->accounting()->id,
        ]);
        $this->revise($filed->id)->assertCreated()->assertJsonPath('data.version', 7)->assertJsonPath('data.amendments', null);

        $july = $this->finalized('2026-07', 1);
        $this->revise($july->id)->assertCreated()->assertJsonPath('data.amendments', ['made' => 1, 'limit' => 5]);
        $this->actingAs($this->accounting())->getJson("/api/bir/drafts/{$july->id}")->assertJsonPath('data.amendments', ['made' => 1, 'limit' => 5]);
    }

    public function test_only_a_finalized_form_can_be_revised(): void
    {
        $draft = BirFormDraft::create([
            'form_type' => '1601-C', 'period' => '2026-07', 'status' => 'draft', 'version' => 1,
            'prepared_by' => $this->accounting()->id,
        ]);

        $this->revise($draft->id)->assertStatus(400);
        $this->assertSame(1, BirFormDraft::count());
    }

    private function finalized(string $period, int $version): BirFormDraft
    {
        return BirFormDraft::create([
            'form_type' => '1601-C', 'period' => $period, 'status' => 'finalized', 'version' => $version,
            'prepared_by' => $this->accounting()->id, 'fields' => ['return_period' => ['value' => '07/2026']],
        ]);
    }

    private function revise(int $draftId)
    {
        return $this->actingAs($this->accounting())->postJson("/api/bir/drafts/{$draftId}/revise");
    }

    private function accounting(): User
    {
        return User::firstWhere('role', 'accounting') ?? User::factory()->create(['role' => 'accounting']);
    }
}
