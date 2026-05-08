<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\AttendanceLog;
use App\Models\EmployeeSchedule;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    /**
     * Display a listing of payroll records.
     */
    public function index(Request $request)
    {
        $query = Payroll::with('employee');

        if ($request->has('cutoff_start') && $request->has('cutoff_end')) {
            $query->where('cutoff_start', $request->cutoff_start)
                ->where('cutoff_end', $request->cutoff_end);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $records = $query->orderBy('cutoff_end', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $records,
            'message' => 'Payroll records retrieved',
        ]);
    }

    /**
     * Generate payroll for a specific cutoff period.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'cutoff_start' => 'required|date',
            'cutoff_end' => 'required|date',
        ]);

        $start = $request->cutoff_start;
        $end = $request->cutoff_end;

        $employees = Employee::where('status', 'active')->get();
        $generatedPayrolls = [];

        foreach ($employees as $employee) {
            $logs = AttendanceLog::where('employee_id', $employee->id)
                ->whereBetween('date', [$start, $end])
                ->get();

            // Get latest schedule to determine work days and divisor
            $latestSchedule = EmployeeSchedule::getCurrentForEmployee($employee->id);
            $workDays = $latestSchedule?->template?->work_days ?? [1, 2, 3, 4, 5];
            $daysInWeek = count($workDays);

            // HR Rule: Mon-Fri = 261 working days/year, Mon-Sat = 313 working days/year
            $divisor = ($daysInWeek <= 5) ? 261 : 313;

            $baseSalary = (float) $employee->salary;
            $isDaily = $employee->rate_type === 'daily';

            // Daily rate computation:
            //   Monthly: (base_salary * 12) / divisor
            //   Daily:   base_salary as-is (fixed per-day rate)
            $dailyRate = $isDaily ? $baseSalary : ($baseSalary * 12) / $divisor;
            $hourlyRate = $dailyRate / 8;

            $metrics = [
                'total_hours' => 0,
                'overtime_hours' => 0,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'rest_day_hours' => 0,
                'rest_day_ot_hours' => 0,
                'absent_days' => 0,
                'half_days' => 0,
            ];

            $daysWorkedCount = 0;

            foreach ($logs as $log) {
                $date = Carbon::parse($log->date);
                $isRestDay = !in_array($date->dayOfWeek, $workDays);

                $schedule = EmployeeSchedule::getForEmployeeOnDate($employee->id, $date);
                $expectedHours = $schedule?->template?->required_hours_per_day ?? 8;
                $workStart = $schedule?->template?->work_start_time ?? '09:00:00';

                // Track absences and half days for deduction purposes
                if ($log->status === 'absent') {
                    $metrics['absent_days']++;
                    continue;
                }

                if ($log->status === 'half_day') {
                    $metrics['half_days']++;
                    // Still count partial hours but flag separately
                }

                $details = AttendanceService::calculateDetails(
                    $log->clock_in_time,
                    $log->clock_out_time,
                    $expectedHours,
                    $workStart
                );

                if ($isRestDay) {
                    // Rest day: regular hours and OT hours tracked separately
                    $restRegularHours = max(0, $details['hours_worked'] - $details['overtime_hours']);
                    $metrics['rest_day_hours'] += $restRegularHours;
                    $metrics['rest_day_ot_hours'] += $details['overtime_hours'];
                } else {
                    $metrics['total_hours'] += $details['hours_worked'];
                    $metrics['overtime_hours'] += $details['overtime_hours'];
                    $daysWorkedCount++;
                }

                $metrics['late_minutes'] += $details['late_minutes'];
                $metrics['undertime_minutes'] += $details['undertime_minutes'];
            }

            // ── Gross Base ───────────────────────────────────────────────
            // Daily employees: paid per actual day worked
            // Monthly employees: semi-monthly base (salary / 2)
            $grossBase = $isDaily
                ? $dailyRate * $daysWorkedCount
                : $baseSalary / 2;

            // ── Allowances / Premiums ─────────────────────────────────────
            $undeclaredSalary = (float) $employee->undeclared_salary;
            $undeclaredDiff = $undeclaredSalary > $baseSalary ? $undeclaredSalary - $baseSalary : 0;
            
            $undeclaredAllowance = $isDaily
                ? $undeclaredDiff * $daysWorkedCount
                : $undeclaredDiff / 2;

            // OT Pay:          daily_rate * 1.25 / 8 * OT hours
            // Rest Day Pay:    daily_rate * 1.30 / 8 * rest day regular hours
            // Rest Day OT Pay: daily_rate * 1.69 / 8 * rest day OT hours
            $overtimePay = $metrics['overtime_hours'] * ($dailyRate * 1.25 / 8);
            $restDayPay = $metrics['rest_day_hours'] * ($dailyRate * 1.30 / 8);
            $restDayOTPay = $metrics['rest_day_ot_hours'] * ($dailyRate * 1.69 / 8);

            // ── Deductions ────────────────────────────────────────────────
            $lateDeduction = ($metrics['late_minutes'] / 60) * $hourlyRate;
            $undertimeDeduction = ($metrics['undertime_minutes'] / 60) * $hourlyRate;
            $absentDeduction = $metrics['absent_days'] * $dailyRate;
            $halfDayDeduction = $metrics['half_days'] * ($dailyRate / 2);

            // Gov't mandatory contributions — applied every cutoff
            // (HR deducts these on every payslip, not just end-of-month)
            $sss = \App\Services\PayrollService::calculateSSS($baseSalary);
            $philhealth = \App\Services\PayrollService::calculatePhilHealth($baseSalary);
            $pagibig = \App\Services\PayrollService::calculatePagIBIG($baseSalary);

            // ── Totals ────────────────────────────────────────────────────
            $totalAllowances = $overtimePay + $restDayPay + $restDayOTPay + $undeclaredAllowance;
            $totalDeductions = $lateDeduction + $undertimeDeduction
                + $absentDeduction + $halfDayDeduction
                + $sss + $philhealth + $pagibig;

            $finalGross = $grossBase + $totalAllowances;
            $finalNet = $finalGross - $totalDeductions;

                    $deductions = array_filter([
                        'Late' => round($lateDeduction, 2),
                        'Undertime' => round($undertimeDeduction, 2),
                        'Absent' => round($absentDeduction, 2),
                        'Half Day' => round($halfDayDeduction, 2),
                        'SSS EE Contribution' => round($sss, 2),
                        'PhilHealth EE Contribution' => round($philhealth, 2),
                        'Pag-IBIG EE Contribution' => round($pagibig, 2),
                    ], fn($val) => $val > 0);

                    $allowances = array_filter([
                        ['label' => 'Overtime Pay', 'amount' => round($overtimePay, 2)],
                        ['label' => 'Rest Day Pay', 'amount' => round($restDayPay, 2)],
                        ['label' => 'Rest Day OT Pay', 'amount' => round($restDayOTPay, 2)],
                        ['label' => 'Allowance', 'amount' => round($undeclaredAllowance, 2)],
                    ], fn($a) => $a['amount'] > 0);

            $payroll = Payroll::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'cutoff_start' => $start,
                    'cutoff_end' => $end,
                ],
                [
                    'base_salary' => $baseSalary,
                    'daily_rate' => round($dailyRate, 2),
                    'total_hours' => round($metrics['total_hours'] + $metrics['rest_day_hours'], 2),
                    'days_worked' => $daysWorkedCount,
                    'overtime_hours' => round($metrics['overtime_hours'] + $metrics['rest_day_ot_hours'], 2),
                    'late_minutes' => $metrics['late_minutes'],
                    'undertime_minutes' => $metrics['undertime_minutes'],
                    'gross_pay' => round($finalGross, 2),
                    'deductions' => $deductions,
                    'allowances' => array_values($allowances), // Reset indices
                    'net_pay' => round($finalNet, 2),
                    'status' => 'draft',
                    'processed_at' => now(),
                ]
            );

            $generatedPayrolls[] = $payroll->load('employee');
        }

        return response()->json([
            'success' => true,
            'data' => $generatedPayrolls,
            'message' => 'Payroll generated successfully using HR standard rules.',
        ]);
    }

    /**
     * Display a specific payroll record.
     */
    public function show($id)
    {
        $payroll = Payroll::with('employee')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $payroll,
        ]);
    }

    /**
     * Update payroll record (edit fields or change status).
     */
    public function update(Request $request, $id)
    {
        $payroll = Payroll::findOrFail($id);

        $request->validate([
            'status' => 'sometimes|in:draft,finalized,paid',
            'base_salary' => 'sometimes|numeric',
            'gross_pay' => 'sometimes|numeric',
            'net_pay' => 'sometimes|numeric',
            'total_hours' => 'sometimes|numeric',
            'overtime_hours' => 'sometimes|numeric',
            'late_minutes' => 'sometimes|numeric',
            'undertime_minutes' => 'sometimes|numeric',
            'deductions' => 'sometimes|array',
            'allowances' => 'sometimes|array',
        ]);

        $data = $request->all();

        // Normalize deductions if they come as [{label, amount}] from frontend
        if (isset($data['deductions']) && is_array($data['deductions'])) {
            $normalized = [];
            foreach ($data['deductions'] as $key => $value) {
                if (is_array($value) && isset($value['label'])) {
                    $normalized[$value['label']] = $value['amount'];
                } else {
                    $normalized[$key] = $value;
                }
            }
            $data['deductions'] = $normalized;
        }

        $payroll->fill($data);

        if ($request->status === 'paid' && !$payroll->paid_at) {
            $payroll->paid_at = now();
        }

        $payroll->save();

        return response()->json([
            'success' => true,
            'data' => $payroll->load('employee'),
            'message' => 'Payroll record updated successfully.',
        ]);
    }

    /**
     * Export payroll to JSON/PDF (mock).
     */
    public function export($id)
    {
        $payroll = Payroll::with('employee')->findOrFail($id);
        return response()->json([
            'success' => true,
            'data' => $payroll,
            'message' => 'Export data ready',
        ]);
    }
}