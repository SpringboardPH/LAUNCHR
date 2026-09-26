<?php

namespace Tests\Feature\Bir;

use App\Services\BIR\BirIntentService;
use App\Services\BIR\Llm\FakeLlmClient;
use RuntimeException;
use Tests\TestCase;

/**
 * Every test here runs against FakeLlmClient: no network, no cost, no
 * flakiness. What is being tested is our validation logic, not the model's
 * language ability — that is checked separately by the phrasing eval.
 */
class BirIntentTest extends TestCase
{
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
        $result = $this->service($this->reply())->parse('Generate the August 2026 1601-C');

        $this->assertFalse($result['needs_clarification']);
        $this->assertSame('1601-C', $result['form_type']);
        $this->assertSame('2026-08', $result['period']);
    }

    public function test_a_complete_2316_request_is_understood(): void
    {
        $result = $this->service($this->reply([
            'form_type' => '2316',
            'tax_month' => null,
            'employee_query' => 'Juan Dela Cruz',
        ]))->parse("Generate Juan Dela Cruz's 2316 for 2026");

        $this->assertFalse($result['needs_clarification']);
        $this->assertSame('2026', $result['period']);
        $this->assertSame('Juan Dela Cruz', $result['employee_query']);
    }

    public function test_a_1601c_without_a_month_is_not_guessed_at(): void
    {
        $result = $this->service($this->reply(['tax_month' => null]))->parse('Generate a 1601-C for 2026');

        $this->assertTrue($result['needs_clarification']);
        $this->assertNull($result['period']);
        $this->assertStringContainsString('month', $result['clarification']);
    }

    public function test_a_2316_without_an_employee_is_not_guessed_at(): void
    {
        $result = $this->service($this->reply([
            'form_type' => '2316',
            'tax_month' => null,
        ]))->parse('Generate a 2316 for 2026');

        $this->assertTrue($result['needs_clarification']);
        $this->assertStringContainsString('employee', $result['clarification']);
    }

    public function test_an_unknown_form_type_is_rejected(): void
    {
        $result = $this->service($this->reply(['form_type' => '2307']))->parse('Generate a 2307');

        $this->assertTrue($result['needs_clarification']);
        $this->assertNull($result['form_type']);
    }

    public function test_low_confidence_forces_clarification_even_when_complete(): void
    {
        $result = $this->service($this->reply([
            'confidence' => 'low',
            'clarification' => 'Did you mean August or a different month?',
        ]))->parse('the one for last month maybe');

        $this->assertTrue($result['needs_clarification']);
        $this->assertSame('Did you mean August or a different month?', $result['clarification']);
    }

    public function test_a_month_on_a_2316_is_discarded(): void
    {
        $result = $this->service($this->reply([
            'form_type' => '2316',
            'tax_month' => 8,
            'employee_query' => 'Juan Dela Cruz',
        ]))->parse("Juan Dela Cruz 2316 August 2026");

        $this->assertNull($result['tax_month']);
        $this->assertSame('2026', $result['period']);
    }

    public function test_an_implausible_year_is_discarded(): void
    {
        $result = $this->service($this->reply(['tax_year' => 1984]))->parse('1601-C for 1984');

        $this->assertNull($result['tax_year']);
        $this->assertTrue($result['needs_clarification']);
    }

    public function test_a_blank_employee_name_counts_as_missing(): void
    {
        $result = $this->service($this->reply([
            'form_type' => '2316',
            'tax_month' => null,
            'employee_query' => '   ',
        ]))->parse('2316 for 2026');

        $this->assertNull($result['employee_query']);
        $this->assertTrue($result['needs_clarification']);
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
}
