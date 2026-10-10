<?php

namespace Tests\Feature\Bir;

use App\Services\BIR\BirIntentService;
use App\Services\BIR\Llm\FakeLlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Every test here runs against FakeLlmClient: no network, no cost, no
 * flakiness. What is being tested is our validation logic, not the model's
 * language ability — that is checked separately by the phrasing eval.
 *
 * Week 3 change: parse() no longer decides whether clarification is needed,
 * because it only ever sees one message — a reply of "August" looks like it
 * is missing everything. missingFields() reports what is absent, and the
 * controller calls it after merging in what earlier turns established.
 * needs_clarification now carries only the model's own stated confidence.
 */
class BirIntentTest extends TestCase
{
    use RefreshDatabase; // the year checks read SystemClock, which reads system_settings

    private function service(array ...$replies): BirIntentService
    {
        $fake = new FakeLlmClient;

        foreach ($replies as $reply) {
            $fake->willReturn($reply);
        }

        return new BirIntentService($fake);
    }

    private function reply(array $overrides = []): array
    {
        return array_merge([
            'form_type' => '1601-C',
            'tax_year' => 2026,
            'tax_month' => 8,
            'employee_query' => null,
            'confidence' => 'high',
            'clarification' => null,
        ], $overrides);
    }

    public function test_a_complete_1601c_request_is_understood(): void
    {
        $service = $this->service($this->reply());
        $result = $service->parse('Generate the August 2026 1601-C');

        $this->assertSame([], $service->missingFields($result));
        $this->assertFalse($result['needs_clarification']);
        $this->assertSame('1601-C', $result['form_type']);
        $this->assertSame('2026-08', $result['period']);
    }

    public function test_a_complete_2316_request_is_understood(): void
    {
        $service = $this->service($this->reply([
            'form_type' => '2316',
            'tax_month' => null,
            'employee_query' => 'Juan Dela Cruz',
        ]));
        $result = $service->parse("Generate Juan Dela Cruz's 2316 for 2026");

        $this->assertSame([], $service->missingFields($result));
        $this->assertSame('2026', $result['period']);
        $this->assertSame('Juan Dela Cruz', $result['employee_query']);
    }

    public function test_a_1601c_without_a_month_is_not_guessed_at(): void
    {
        $service = $this->service($this->reply(['tax_month' => null]));
        $result = $service->parse('Generate a 1601-C for 2026');

        $this->assertContains('the month', $service->missingFields($result));
        $this->assertNull($result['period']);
    }

    public function test_a_2316_without_an_employee_is_not_guessed_at(): void
    {
        $service = $this->service($this->reply([
            'form_type' => '2316',
            'tax_month' => null,
        ]));
        $result = $service->parse('Generate a 2316 for 2026');

        $this->assertContains('which employee', $service->missingFields($result));
    }

    public function test_an_unknown_form_type_is_rejected(): void
    {
        $service = $this->service($this->reply(['form_type' => '2307']));
        $result = $service->parse('Generate a 2307');

        $this->assertNull($result['form_type']);
        $this->assertContains('which form', $service->missingFields($result));
    }

    public function test_low_confidence_is_reported_even_when_nothing_is_missing(): void
    {
        $service = $this->service($this->reply([
            'confidence' => 'low',
            'clarification' => 'Did you mean August or a different month?',
        ]));
        $result = $service->parse('the 1601-C for last month maybe');

        $this->assertSame([], $service->missingFields($result));
        $this->assertTrue($result['needs_clarification']);
        $this->assertSame('Did you mean August or a different month?', $result['clarification']);
    }

    public function test_a_month_on_a_2316_is_discarded(): void
    {
        $service = $this->service($this->reply([
            'form_type' => '2316',
            'tax_month' => 8,
            'employee_query' => 'Juan Dela Cruz',
        ]));
        $result = $service->parse('Juan Dela Cruz 2316 August 2026');

        $this->assertNull($result['tax_month']);
        $this->assertSame('2026', $result['period']);
    }

    public function test_an_implausible_year_is_discarded(): void
    {
        $service = $this->service($this->reply(['tax_year' => 1984]));
        $result = $service->parse('1601-C for 1984');

        $this->assertNull($result['tax_year']);
        $this->assertContains('the year', $service->missingFields($result));
    }

    public function test_a_blank_employee_name_counts_as_missing(): void
    {
        $service = $this->service($this->reply([
            'form_type' => '2316',
            'tax_month' => null,
            'employee_query' => '   ',
        ]));
        $result = $service->parse('2316 for 2026');

        $this->assertNull($result['employee_query']);
        $this->assertContains('which employee', $service->missingFields($result));
    }

    public function test_earlier_context_is_given_to_the_model(): void
    {
        $fake = new FakeLlmClient;
        $service = new BirIntentService($fake);

        $service->parse('August', [
            'form_type' => '1601-C',
            'tax_year' => 2026,
        ]);

        $sent = $fake->calls()[0]['system'];

        $this->assertStringContainsString('already established', $sent);
        $this->assertStringContainsString('1601-C', $sent);
    }

    public function test_a_provider_failure_surfaces_as_an_exception(): void
    {
        $fake = (new FakeLlmClient)->willFail();

        $this->expectException(RuntimeException::class);

        (new BirIntentService($fake))->parse('Generate the August 2026 1601-C');
    }

    public function test_the_payroll_figures_are_never_sent_to_the_provider(): void
    {
        $fake = new FakeLlmClient;
        (new BirIntentService($fake))->parse('Generate the August 2026 1601-C');

        $sent = $fake->calls()[0];

        $this->assertSame('Generate the August 2026 1601-C', $sent['message']);
        $this->assertStringNotContainsString('deductions', $sent['system']);
    }

    public function test_a_refused_year_is_kept_aside_so_the_reply_can_say_why(): void
    {
        $result = $this->service($this->reply(['tax_year' => 2030]))->parse('1601-C for August 2030');

        $this->assertNull($result['tax_year'], 'No form is prepared for 2030.');
        $this->assertSame(2030, $result['rejected_year']);
    }

    public function test_a_form_the_user_did_not_name_is_not_taken_from_the_model(): void
    {
        // The model guesses the 1601-C because it is the only monthly form.
        $service = $this->service($this->reply(['tax_month' => 9]));
        $result = $service->parse('September 2026');

        $this->assertNull($result['form_type']);
        $this->assertContains('which form', $service->missingFields($result));
    }

    public function test_a_form_named_in_an_earlier_message_is_kept(): void
    {
        $result = $this->service($this->reply())->parse('August 2026', ['form_type' => '1601-C']);

        $this->assertSame('1601-C', $result['form_type']);
        $this->assertSame('2026-08', $result['period']);
    }

    public function test_a_message_that_only_names_a_period_is_a_form_request_whatever_the_label(): void
    {
        $result = $this->service($this->reply(['kind' => 'tax_question']))->parse('September 2026');
        $this->assertSame('form_request', $result['kind']);

        $result = $this->service($this->reply(['kind' => 'tax_question']))->parse('how much tax was withheld in September 2026?');
        $this->assertSame('tax_question', $result['kind'], 'A real question keeps its label.');
    }

    public function test_what_counts_as_only_a_period_or_a_form(): void
    {
        foreach (['September 2026', 'Agosto 2026', 'the 2316 for 2025', '08/2026', 'para sa 1601-C ng Agosto 2026', 'last month'] as $message) {
            $this->assertTrue(BirIntentService::onlyNamesAPeriodOrForm($message), $message);
        }
        foreach (['thanks', 'please', 'salary for September 2026', 'magkano tax ko sa 2025'] as $message) {
            $this->assertFalse(BirIntentService::onlyNamesAPeriodOrForm($message), $message);
        }
    }
}
