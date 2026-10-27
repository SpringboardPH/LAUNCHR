<?php

namespace Tests\Unit;

use App\Services\PayrollService;
use App\Services\StatutoryStamp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StatutoryStampTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_daily_with_zero_docks_scales_ordinary_earnings_by_periods(): void
    {
        $days = 4;
        $rate = 750.0;
        $periods = 2;
        $stamp = StatutoryStamp::forDaily($days, $rate, 0, 0, 0, $periods);

        $this->assertEquals(round($days * $rate * $periods, 2), $stamp->basisPesos());
        $this->assertSharesMatchCalculators($stamp, $periods);
    }

    public function test_for_daily_divides_rest_day_pay_by_rest_day_rate(): void
    {
        $this->assertEquals(1.30, PayrollService::REST_DAY_RATE);

        $restDayPay = 1300.0;
        $periods = 2;
        $stamp = StatutoryStamp::forDaily(0, 1000, 0, 0, $restDayPay, $periods);

        $ordinary = $restDayPay / 1.30;
        $this->assertEquals(round($ordinary * $periods, 2), $stamp->basisPesos());
        $this->assertNotEquals(round($restDayPay * $periods, 2), $stamp->basisPesos());
        $this->assertSharesMatchCalculators($stamp, $periods);
    }

    public function test_for_daily_clamps_ordinary_earnings_at_zero(): void
    {
        $stamp = StatutoryStamp::forDaily(1, 100, 500, 0, 0, 2);

        $this->assertEquals(0.0, $stamp->basisPesos());
        $this->assertSharesMatchCalculators($stamp, 2);
    }

    #[DataProvider('rejectedDailyInputs')]
    public function test_for_daily_rejects_negative_inputs_and_periods_below_one(
        int $days,
        float $rate,
        float $late,
        float $undertime,
        float $rest,
        int $periods,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        StatutoryStamp::forDaily($days, $rate, $late, $undertime, $rest, $periods);
    }

    public static function rejectedDailyInputs(): array
    {
        return [
            'days worked' => [-1, 100.0, 0.0, 0.0, 0.0, 2],
            'daily rate' => [1, -100.0, 0.0, 0.0, 0.0, 2],
            'late' => [1, 100.0, -1.0, 0.0, 0.0, 2],
            'undertime' => [1, 100.0, 0.0, -1.0, 0.0, 2],
            'rest day pay' => [1, 100.0, 0.0, 0.0, -1.0, 2],
            'periods' => [1, 100.0, 0.0, 0.0, 0.0, 0],
        ];
    }

    public function test_for_monthly_salary_does_not_multiply_by_periods(): void
    {
        $salary = 32500.55;
        $periods = 2;
        $stamp = StatutoryStamp::forMonthlySalary($salary, $periods);

        $this->assertEquals($salary, $stamp->basisPesos());
        $this->assertNotEquals(round($salary * $periods, 2), $stamp->basisPesos());
        $this->assertSharesMatchCalculators($stamp, $periods);
    }

    public function test_for_monthly_salary_passes_a_negative_salary_to_the_calculators(): void
    {
        $salary = -4000.0;
        $periods = 2;
        $stamp = StatutoryStamp::forMonthlySalary($salary, $periods);

        $this->assertEquals($salary, $stamp->basisPesos());
        $this->assertSharesMatchCalculators($stamp, $periods);
    }

    public function test_for_monthly_salary_rejects_periods_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StatutoryStamp::forMonthlySalary(1000, 0);
    }

    private function assertSharesMatchCalculators(StatutoryStamp $stamp, int $periods): void
    {
        $basis = $stamp->basisPesos();
        $shares = $stamp->shares();

        $this->assertEquals(PayrollService::calculateSSS($basis, $periods), $shares->sss);
        $this->assertEquals(PayrollService::calculatePhilHealth($basis, $periods), $shares->philhealth);
        $this->assertEquals(PayrollService::calculatePagIBIG($basis, $periods), $shares->pagibig);
    }
}
