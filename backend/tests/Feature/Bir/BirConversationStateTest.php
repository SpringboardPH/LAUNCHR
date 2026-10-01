<?php

namespace Tests\Feature\Bir;

use App\Models\User;
use App\Services\BIR\BirConversationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A reply of "August" only makes sense if the system remembers it was already
 * discussing a 1601-C for 2026. These tests cover that carry-over, and the
 * two things that carry-over must not do: lose the period string, or keep
 * values the newly chosen form has no place for.
 */
class BirConversationStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_established_values_survive_the_next_message(): void
    {
        $state = new BirConversationState(1);

        $state->merge([
            'form_type' => '1601-C',
            'tax_year' => 2026,
            'tax_month' => null,
            'employee_query' => null,
        ]);

        $merged = $state->merge([
            'form_type' => null,
            'tax_year' => null,
            'tax_month' => 8,
            'employee_query' => null,
        ]);

        $this->assertSame('1601-C', $merged['form_type']);
        $this->assertSame(2026, $merged['tax_year']);
        $this->assertSame(8, $merged['tax_month']);
    }

    public function test_a_new_value_overwrites_an_old_one(): void
    {
        $state = new BirConversationState(2);

        $state->merge(['form_type' => '1601-C', 'tax_year' => 2026, 'tax_month' => 8, 'employee_query' => null]);
        $merged = $state->merge(['form_type' => null, 'tax_year' => null, 'tax_month' => 9, 'employee_query' => null]);

        $this->assertSame(9, $merged['tax_month']);
    }

    public function test_one_users_conversation_does_not_leak_into_another(): void
    {
        (new BirConversationState(3))->merge([
            'form_type' => '2316',
            'tax_year' => 2026,
            'tax_month' => null,
            'employee_query' => 'Ana Garcia',
        ]);

        $this->assertSame([], (new BirConversationState(4))->get());
    }

    public function test_forgetting_clears_everything(): void
    {
        $state = new BirConversationState(5);

        $state->merge(['form_type' => '1601-C', 'tax_year' => 2026, 'tax_month' => 8, 'employee_query' => null]);
        $state->forget();

        $this->assertSame([], $state->get());
    }

    public function test_the_merged_intent_carries_a_period_for_1601c(): void
    {
        $merged = (new BirConversationState(6))->merge([
            'form_type' => '1601-C',
            'tax_year' => 2026,
            'tax_month' => 8,
            'employee_query' => null,
        ]);

        $this->assertSame('2026-08', $merged['period']);
    }

    public function test_the_merged_intent_carries_a_period_for_2316(): void
    {
        $merged = (new BirConversationState(7))->merge([
            'form_type' => '2316',
            'tax_year' => 2026,
            'tax_month' => null,
            'employee_query' => 'Ana Garcia',
        ]);

        $this->assertSame('2026', $merged['period']);
    }

    public function test_the_period_is_null_until_enough_is_known(): void
    {
        $merged = (new BirConversationState(8))->merge([
            'form_type' => '1601-C',
            'tax_year' => 2026,
            'tax_month' => null,
            'employee_query' => null,
        ]);

        $this->assertNull($merged['period']);
    }

    public function test_switching_to_a_2316_drops_the_month(): void
    {
        $state = new BirConversationState(9);

        $state->merge(['form_type' => '1601-C', 'tax_year' => 2026, 'tax_month' => 8, 'employee_query' => null]);

        $merged = $state->merge([
            'form_type' => '2316',
            'tax_year' => null,
            'tax_month' => null,
            'employee_query' => 'Ana Garcia',
        ]);

        $this->assertNull($merged['tax_month']);
        $this->assertSame('2026', $merged['period']);
    }

    public function test_switching_to_a_1601c_drops_the_employee(): void
    {
        $state = new BirConversationState(10);

        $state->merge([
            'form_type' => '2316',
            'tax_year' => 2026,
            'tax_month' => null,
            'employee_query' => 'Ana Garcia',
            'employee_id' => 4,
        ]);

        $merged = $state->merge([
            'form_type' => '1601-C',
            'tax_year' => null,
            'tax_month' => 8,
            'employee_query' => null,
        ]);

        $this->assertNull($merged['employee_query']);
        $this->assertNull($merged['employee_id']);
        $this->assertSame('2026-08', $merged['period']);
    }

    public function test_a_two_turn_conversation_completes_a_request(): void
    {
        $user = User::factory()->create(['role' => 'accounting']);

        $first = $this->actingAs($user)
            ->postJson('/api/bir/chat', ['message' => 'Generate a 1601-C', 'reset' => true]);

        $first->assertOk();

        $second = $this->actingAs($user)
            ->postJson('/api/bir/chat', ['message' => 'August 2026']);

        $second->assertOk()
            ->assertJsonPath('data.intent.form_type', '1601-C')
            ->assertJsonPath('data.intent.period', '2026-08');
    }

    public function test_the_endpoint_returns_a_period_the_draft_endpoint_can_use(): void
    {
        $user = User::factory()->create(['role' => 'accounting']);

        $response = $this->actingAs($user)
            ->postJson('/api/bir/chat', ['message' => 'Generate the August 2026 1601-C', 'reset' => true]);

        $response->assertOk()
            ->assertJsonPath('data.understood', true)
            ->assertJsonPath('data.intent.period', '2026-08');
    }
}