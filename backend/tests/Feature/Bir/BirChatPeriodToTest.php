<?php

namespace Tests\Feature\Bir;

use App\Models\BirFormDraft;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\BirSampleSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A 2316 for a year that isn't over yet (one an employee asked for early): the chat first asks
 * up to what date it covers, item 2 "To", once (team, 10/2026). BirSampleSeeder's 2316 drafts
 * are for 2025, so "today" is set in 2025 for them to count as the current year.
 */
class BirChatPeriodToTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(BirSampleSeeder::class);
    }

    public function test_the_chat_asks_for_the_to_date_first_and_once(): void
    {
        Carbon::setTestNow('2025-10-20 09:00:00');
        $draft = $this->draft();

        $opened = $this->chat(['draft_id' => $draft->id, 'reset' => true]);
        $this->assertSame('period_to', $opened->json('data.question.field'));
        $this->assertSame(['year' => 2025, 'value' => '12/31'], $opened->json('data.question.picker'));
        $this->assertStringContainsString('Up to what date should it cover?', $opened->json('data.reply'));

        // The date picker sends MM/DD; the chat reads it as the answer, and the page saves it.
        $answered = $this->chat(['draft_id' => $draft->id, 'field' => 'period_to', 'message' => '10/20']);
        $this->assertSame(['field' => 'period_to', 'value' => '10/20'], $answered->json('data.answer'));
        $this->actingAs($this->hr())->putJson("/api/bir/drafts/{$draft->id}", ['fields' => ['period_to' => '10/20']])->assertOk();

        $this->assertSame('10/20', $draft->fresh()->fields['period_to']['value']);
        $this->assertNotSame('period_to', $this->chat(['draft_id' => $draft->id])->json('data.question.field'));
    }

    public function test_keeping_12_31_counts_as_an_answer(): void
    {
        Carbon::setTestNow('2025-10-20 09:00:00');
        $draft = $this->draft();

        $this->actingAs($this->hr())->putJson("/api/bir/drafts/{$draft->id}", ['fields' => ['period_to' => '12/31']])->assertOk();

        $this->assertNotSame('period_to', $this->chat(['draft_id' => $draft->id])->json('data.question.field'));
    }

    public function test_not_asked_once_the_year_is_over(): void
    {
        Carbon::setTestNow('2026-01-15 09:00:00');

        $this->assertNotSame('period_to', $this->chat(['draft_id' => $this->draft()->id, 'reset' => true])->json('data.question.field'));
    }

    public function test_not_asked_on_a_form_already_submitted(): void
    {
        Carbon::setTestNow('2025-10-20 09:00:00');
        $draft = $this->draft();
        $draft->update(['status' => 'pending']);

        $this->assertNotSame('period_to', $this->chat(['draft_id' => $draft->id, 'reset' => true])->json('data.question.field'));
    }

    private function draft(): BirFormDraft
    {
        return BirFormDraft::where('form_type', '2316')->where('period', '2025')->firstOrFail();
    }

    private function hr(): User
    {
        return User::firstWhere('email', 'hr@springboardph.com');
    }

    private function chat(array $body)
    {
        return $this->actingAs($this->hr())->postJson('/api/bir/chat', $body + ['actions' => true])->assertOk();
    }
}
