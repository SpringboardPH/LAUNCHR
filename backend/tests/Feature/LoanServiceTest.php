<?php

namespace Tests\Feature;

use App\Services\LoanService;
use Tests\TestCase;

class LoanServiceTest extends TestCase
{
    public function test_compute_schedule_applies_flat_add_on_interest()
    {
        [$totalPayable, $installment] = LoanService::computeSchedule(10000, 0.05, 4);

        $this->assertEquals(10500.00, $totalPayable);
        $this->assertEquals(2625.00, $installment);
    }

    public function test_compute_schedule_with_zero_interest_for_cash_advance()
    {
        [$totalPayable, $installment] = LoanService::computeSchedule(6000, 0, 3);

        $this->assertEquals(6000.00, $totalPayable);
        $this->assertEquals(2000.00, $installment);
    }
}
