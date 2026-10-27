<?php

namespace App\Services;

use App\Models\Payroll;
use InvalidArgumentException;

final class EmployeeShares
{
    public function __construct(
        public readonly float $sss,
        public readonly float $philhealth,
        public readonly float $pagibig,
    ) {
    }
}

final class StatutoryStamp
{
    public const SSS_EE_LABEL = 'SSS EE Contribution';

    public const PHILHEALTH_EE_LABEL = 'PhilHealth EE Contribution';

    public const PAGIBIG_EE_LABEL = 'Pag-IBIG EE Contribution';

    private function __construct(
        private readonly float $basisPesos,
        private readonly EmployeeShares $shareLines,
    ) {
    }

    public static function forDaily(
        int $daysWorked,
        float $dailyRate,
        float $latePesos,
        float $undertimePesos,
        float $restDayPay,
        int $periods,
    ): self {
        self::assertNonNegative('daysWorked', $daysWorked);
        self::assertNonNegative('dailyRate', $dailyRate);
        self::assertNonNegative('latePesos', $latePesos);
        self::assertNonNegative('undertimePesos', $undertimePesos);
        self::assertNonNegative('restDayPay', $restDayPay);
        self::assertPeriods($periods);

        $ordinary = max(0, $daysWorked * $dailyRate - $latePesos - $undertimePesos)
            + ($restDayPay / PayrollService::REST_DAY_RATE);

        return self::issue(round($ordinary * $periods, 2), $periods);
    }

    public static function forMonthlySalary(float $baseSalary, int $periods): self
    {
        self::assertPeriods($periods);

        return self::issue($baseSalary, $periods);
    }

    public function basisPesos(): float
    {
        return $this->basisPesos;
    }

    public function shares(): EmployeeShares
    {
        return $this->shareLines;
    }

    public static function keepStoredShares(array $stored, array $incoming): array
    {
        foreach ([self::SSS_EE_LABEL, self::PHILHEALTH_EE_LABEL, self::PAGIBIG_EE_LABEL] as $label) {
            if (array_key_exists($label, $stored)) {
                $incoming[$label] = $stored[$label];
            } else {
                unset($incoming[$label]);
            }
        }

        return $incoming;
    }

    public function writeOnto(Payroll $payroll, array $attributes): bool
    {
        $deductions = $attributes['deductions'] ?? [];
        $lines = [
            self::SSS_EE_LABEL => round($this->shareLines->sss, 2),
            self::PHILHEALTH_EE_LABEL => round($this->shareLines->philhealth, 2),
            self::PAGIBIG_EE_LABEL => round($this->shareLines->pagibig, 2),
        ];
        foreach ($lines as $label => $amount) {
            if ($amount > 0) {
                $deductions[$label] = $amount;
            } else {
                unset($deductions[$label]);
            }
        }
        unset($attributes['contribution_basis']);
        $attributes['deductions'] = $deductions;
        $attributes['contribution_basis'] = $this->basisPesos();
        $payroll->forceFill($attributes);

        return $payroll->save();
    }

    private static function issue(float $basisPesos, int $periods): self
    {
        return new self($basisPesos, new EmployeeShares(
            PayrollService::calculateSSS($basisPesos, $periods),
            PayrollService::calculatePhilHealth($basisPesos, $periods),
            PayrollService::calculatePagIBIG($basisPesos, $periods),
        ));
    }

    private static function assertNonNegative(string $label, int|float $value): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException($label . ' must be greater than or equal to 0.');
        }
    }

    private static function assertPeriods(int $periods): void
    {
        if ($periods < 1) {
            throw new InvalidArgumentException('periods must be greater than or equal to 1.');
        }
    }
}
