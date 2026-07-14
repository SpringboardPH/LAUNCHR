<?php

namespace Tests\Unit;

use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WithholdingTaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_or_negative_income_yields_zero(): void
    {
        $this->assertSame(0.0, PayrollService::calculateWithholdingTax(0, 'semi_monthly'));
        $this->assertSame(0.0, PayrollService::calculateWithholdingTax(-500, 'semi_monthly'));
    }

    #[DataProvider('bracketGapProvider')]
    public function test_income_landing_in_a_bracket_boundary_gap_is_still_taxed(float $income, float $expectedTax): void
    {
        $this->seed(\Database\Seeders\SystemSettingsSeeder::class);

        // These fall in the ~1-peso gap between the seeded table's whole-peso
        // 'to'/'from' boundaries (e.g. to: 16666, next from: 16667) — previously
        // matched no bracket at all and silently returned ₱0.
        $this->assertEquals($expectedTax, PayrollService::calculateWithholdingTax($income, 'semi_monthly'));
    }

    public static function bracketGapProvider(): array
    {
        return [
            'gap at 16666-16667 boundary' => [16666.50, 937.43],
            'gap at 33332-33333 boundary' => [33332.50, 4270.60],
            'gap at 83332-83333 boundary' => [83332.50, 16770.58],
            'gap at 333332-333333 boundary' => [333332.50, 91770.55],
        ];
    }
}
