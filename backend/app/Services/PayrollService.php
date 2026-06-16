<?php

namespace App\Services;

class PayrollService
{
    /**
     * Calculate SSS Employee Contribution (PH 2024/2025)
     * Fetches from system_settings table to allow HR updates
     */
    public static function calculateSSS(float $monthlySalary, int $periods = 2): float
    {
        $setting = \App\Models\SystemSettings::where('key', 'sss_contribution_table')->first();

        if ($setting && $setting->value) {
            $table = is_array($setting->value) ? $setting->value : json_decode($setting->value, true);

            if (is_array($table)) {
                foreach ($table as $bracket) {
                    $aboveMin = $monthlySalary >= $bracket['min'];
                    $belowMax = $bracket['max'] === null || $monthlySalary <= $bracket['max'];

                    if ($aboveMin && $belowMax) {
                        return (float) ($bracket['ee'] / $periods);
                    }
                }
            }
        }

        // Fallback if table missing: 5% of salary capped at MSC 35,000
        $msc = min(ceil($monthlySalary / 500) * 500, 35000);
        return round(($msc * 0.05) / $periods, 2);
    }

    /**
     * Calculate SSS Employer Contribution (ER) (PH 2024/2025)
     * For viewing only - no impact on employee's net pay
     * Fetches from system_settings table to allow HR updates
     */
    public static function calculateSSS_ER(float $monthlySalary, int $periods = 2): float
    {
        $setting = \App\Models\SystemSettings::where('key', 'sss_contribution_table')->first();

        if ($setting && $setting->value) {
            $table = is_array($setting->value) ? $setting->value : json_decode($setting->value, true);

            if (is_array($table)) {
                foreach ($table as $bracket) {
                    $aboveMin = $monthlySalary >= $bracket['min'];
                    $belowMax = $bracket['max'] === null || $monthlySalary <= $bracket['max'];

                    if ($aboveMin && $belowMax) {
                        return (float) (($bracket['er'] ?? 0) / $periods);
                    }
                }
            }
        }

        // Fallback if table missing: 5% of salary capped at MSC 35,000
        $msc = min(ceil($monthlySalary / 500) * 500, 35000);
        return round(($msc * 0.05) / $periods, 2);
    }

    /**
     * Calculate PhilHealth Employee Contribution (PH 2024)
     * 5% total, 2.5% EE share
     */
    public static function calculatePhilHealth(float $monthlySalary, int $periods = 2): float
    {
        if ($monthlySalary < 10000) return (500.00 * 0.5) / $periods;
        if ($monthlySalary > 100000) return (5000.00 * 0.5) / $periods;

        return (($monthlySalary * 0.05) * 0.5) / $periods;
    }

    /**
     * Calculate PhilHealth Employer Contribution (ER) (PH 2024)
     * For viewing only - no impact on employee's net pay
     * 5% total, 2.5% ER share
     */
    public static function calculatePhilHealth_ER(float $monthlySalary, int $periods = 2): float
    {
        if ($monthlySalary < 10000) return (500.00 * 0.5) / $periods;
        if ($monthlySalary > 100000) return (5000.00 * 0.5) / $periods;

        return (($monthlySalary * 0.05) * 0.5) / $periods;
    }

    /**
     * Calculate Pag-IBIG Employee Contribution (PH 2024)
     * 2% of salary, capped at 10k salary (200 pesos)
     */
    public static function calculatePagIBIG(float $monthlySalary, int $periods = 2): float
    {
        $base = min($monthlySalary, 10000);
        return ($base * 0.02) / $periods;
    }

    /**
     * Calculate Pag-IBIG Employer Contribution (ER) (PH 2024)
     * For viewing only - no impact on employee's net pay
     * 2% of salary, capped at 10k salary (200 pesos)
     */
    public static function calculatePagIBIG_ER(float $monthlySalary, int $periods = 2): float
    {
        $base = min($monthlySalary, 10000);
        return ($base * 0.02) / $periods;
    }

    /**
     * Calculate Withholding Tax (PH TRAIN Law 2023-2027)
     * Calculated based on semi-monthly taxable income
     * Formula: Taxable Income = Gross Pay - (SSS + PhilHealth + Pag-IBIG)
     */
    public static function calculateWithholdingTax(float $taxableIncome): float
    {
        if ($taxableIncome <= 10417) {
            return 0;
        } elseif ($taxableIncome <= 16666) {
            return ($taxableIncome - 10417) * 0.15;
        } elseif ($taxableIncome <= 33332) {
            return 937.50 + ($taxableIncome - 16667) * 0.20;
        } elseif ($taxableIncome <= 83332) {
            return 4270.83 + ($taxableIncome - 33333) * 0.25;
        } elseif ($taxableIncome <= 333332) {
            return 16770.83 + ($taxableIncome - 83333) * 0.30;
        } else {
            return 91770.83 + ($taxableIncome - 333333) * 0.35;
        }
    }
}
