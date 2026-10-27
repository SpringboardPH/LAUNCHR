<?php

namespace Tests\Feature\Bir;

use App\Models\SystemSettings;
use App\Models\User;
use App\Services\BIR\BirTokenUsage;
use App\Services\BIR\Llm\OllamaClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EnablesBirForms;
use Tests\TestCase;

/**
 * The chat's AI token count (BirTokenUsage): recorded from each provider reply, and shown to
 * everyone who uses the chat as what is left of the admin's budget until it resets.
 */
class BirTokenUsageTest extends TestCase
{
    use EnablesBirForms;
    use RefreshDatabase;

    private User $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 09:00:00'); // a Wednesday
        $this->accounting = User::factory()->create(['role' => 'accounting']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_ollama_reply_is_counted_for_the_user(): void
    {
        Http::fake(['ollama.test/*' => Http::response(['message' => ['content' => '{"kind":"other"}'], 'prompt_eval_count' => 900, 'eval_count' => 100])]);
        $this->actingAs($this->accounting);
        $client = new OllamaClient('https://ollama.test', 'gpt-oss:120b', 'key');
        $client->structured('system', 'hello', ['type' => 'object']);
        $client->structured('system', 'again', ['type' => 'object']);

        $this->assertDatabaseHas('bir_token_usage', ['day' => '2026-10-28', 'user_id' => $this->accounting->id, 'requests' => 2, 'input_tokens' => 1800, 'output_tokens' => 200]);
    }

    public function test_tokens_left_until_the_budget_resets(): void
    {
        // No budget: what was used this week, from Monday.
        $this->actingAs($this->accounting);
        BirTokenUsage::record(3000, 500);
        $this->getJson('/api/bir/token-usage')->assertOk()
            ->assertJsonPath('data.budget', null)->assertJsonPath('data.left', null)
            ->assertJsonPath('data.used', 3500)->assertJsonPath('data.requests', 1)
            ->assertJsonPath('data.since', '2026-10-26')->assertJsonPath('data.resets_at', '2026-11-02T00:00:00+08:00');

        // A budget: what is left; last week's tokens don't count; never below zero.
        SystemSettings::set('bir_token_budget', 10000, null, 'integer');
        Carbon::setTestNow('2026-10-25 09:00:00');
        BirTokenUsage::record(99999, 0);
        Carbon::setTestNow('2026-10-28 09:00:00');
        $this->getJson('/api/bir/token-usage')->assertJsonPath('data.budget', 10000)->assertJsonPath('data.left', 6500);
        BirTokenUsage::record(9000, 0);
        $this->getJson('/api/bir/token-usage')->assertJsonPath('data.left', 0);

        // A monthly budget counts from the 1st; a daily one from today.
        SystemSettings::set('bir_token_period', 'month');
        $this->getJson('/api/bir/token-usage')->assertJsonPath('data.since', '2026-10-01')->assertJsonPath('data.used', 112499);
        SystemSettings::set('bir_token_period', 'day');
        $this->getJson('/api/bir/token-usage')->assertJsonPath('data.used', 12500)->assertJsonPath('data.resets_at', '2026-10-29T00:00:00+08:00');
    }

    public function test_everyone_who_uses_the_chat_sees_it_and_only_admins_set_the_budget(): void
    {
        foreach (['hr', 'accounting', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/api/bir/token-usage')->assertOk();
        }
        $this->actingAs(User::factory()->create(['role' => 'employee']))->getJson('/api/bir/token-usage')->assertForbidden();
        $this->actingAs($this->accounting)->putJson('/api/admin/settings/bir_token_budget', ['value' => '5000', 'type' => 'integer'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->putJson('/api/admin/settings/bir_token_budget', ['value' => '5000', 'type' => 'integer'])->assertOk();
        $this->actingAs($this->accounting)->getJson('/api/bir/token-usage')->assertJsonPath('data.budget', 5000);
    }
}
