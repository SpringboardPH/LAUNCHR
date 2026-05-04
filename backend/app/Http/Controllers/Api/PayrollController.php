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

            $metrics = [
                'total_hours' => 0,
                'overtime_hours' => 0,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
            ];

            foreach ($logs as $log) {
                // Get schedule for this date to determine expected hours/work start
                $date = Carbon::parse($log->date);
                $schedule = EmployeeSchedule::getForEmployeeOnDate($employee->id, $date);
                
                $expectedHours = 9; // default
                $workStart = '09:00:00'; // default
                
                if ($schedule && $schedule->template) {
                    $expectedHours = $schedule->template->required_hours_per_day ?? 9;
                    $workStart = $schedule->template->work_start_time ?? '09:00:00';
                }

                $details = AttendanceService::calculateDetails(
                    $log->clock_in_time,
                    $log->clock_out_time,
                    $expectedHours,
                    $workStart
                );

                $metrics['total_hours'] += $details['hours_worked'];
                $metrics['overtime_hours'] += $details['overtime_hours'];
                $metrics['late_minutes'] += $details['late_minutes'];
                $metrics['undertime_minutes'] += $details['undertime_minutes'];
            }

            // Rate Calculation
            $baseSalary = (float)$employee->salary;
            $isDaily = $employee->rate_type === 'daily';
            
            $dailyRate = $isDaily ? $baseSalary : ($baseSalary / 22);
            $hourlyRate = $dailyRate / 8;
            
            // Base Gross
            if ($isDaily) {
                $daysWorked = $logs->pluck('date')->unique()->count();
                $grossBase = $dailyRate * $daysWorked;
            } else {
                $grossBase = $baseSalary / 2;
            }
            
            // Earnings/Deductions
            $lateDeduction = ($metrics['late_minutes'] / 60) * $hourlyRate;
            $undertimeDeduction = ($metrics['undertime_minutes'] / 60) * $hourlyRate;
            $overtimePay = $metrics['overtime_hours'] * $hourlyRate * 1.25;

            $finalGross = $grossBase + $overtimePay - ($lateDeduction + $undertimeDeduction);

            $payroll = Payroll::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'cutoff_start' => $start,
                    'cutoff_end' => $end,
                ],
                [
                    'base_salary' => $baseSalary,
                    'total_hours' => $metrics['total_hours'],
                    'days_worked' => $daysWorked ?? $logs->pluck('date')->unique()->count(),
                    'overtime_hours' => $metrics['overtime_hours'],
                    'late_minutes' => $metrics['late_minutes'],
                    'undertime_minutes' => $metrics['undertime_minutes'],
                    'gross_pay' => round($grossBase + $overtimePay, 2),
                    'deductions' => [
                        'late' => round($lateDeduction, 2),
                        'undertime' => round($undertimeDeduction, 2),
                    ],
                    'allowances' => [
                        ['label' => 'Overtime Pay', 'amount' => round($overtimePay, 2)]
                    ],
                    'net_pay' => round($finalGross, 2),
                    'status' => 'draft',
                    'processed_at' => now(),
                ]
            );

            $generatedPayrolls[] = $payroll->load('employee');
        }

        return response()->json([
            'success' => true,
            'data' => $generatedPayrolls,
            'message' => 'Payroll generated successfully for ' . count($generatedPayrolls) . ' employees.',
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

        $payroll->fill($request->all());
        
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
