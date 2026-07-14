<?php

namespace Tests\Unit;

use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PayrollServiceContributionsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('zeroBasisProvider')]
    public function test_zero_or_negative_contribution_basis_yields_zero(string $method): void
    {
        $this->assertSame(0.0, PayrollService::$method(0, 2));
        $this->assertSame(0.0, PayrollService::$method(-100, 2));
    }

    public static function zeroBasisProvider(): array
    {
        return [
            'SSS EE' => ['calculateSSS'],
            'SSS ER' => ['calculateSSS_ER'],
            'PhilHealth EE' => ['calculatePhilHealth'],
            'PhilHealth ER' => ['calculatePhilHealth_ER'],
        ];
    }

    public function test_sss_ee_uses_seeded_bracket_table(): void
    {
        $this->seed(\Database\Seeders\SystemSettingsSeeder::class);

        // ₱20,000 basis matches the seeded bracket with ee=1000 (halved for semi-monthly)
        $this->assertEquals(500.0, PayrollService::calculateSSS(20000, 2));
    }
}
