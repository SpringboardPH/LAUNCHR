<?php

namespace App\Http\Controllers\Api;

use App\Helpers\SystemClock;
use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Services\LeaveService;
use Illuminate\Http\Request;

/**
 * Read-only HR data for the cross-system assistant bridge (consumed by Ledgr's assistant).
 *
 * Every method is GET/read-only and returns a deliberate field allowlist — government IDs,
 * bank account, and undeclared salary are never exposed, mirroring the per-employee
 * AssistantService redaction. Guarded by the `service.token` middleware; scope here is
 * HR-wide (any employee), which is acceptable because the caller (Ledgr) is trusted HR.
 */
class HrAssistantController extends Controller
{
    /** Employee directory. Optional ?search= over name / employee_id / position / department. */
    public function employees(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $employees = Employee::query()
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('employee_id', 'like', "%{$search}%")
                        ->orWhere('position', 'like', "%{$search}%")
                        ->orWhere('department', 'like', "%{$search}%");
                });
            })
            ->orderBy('last_name')
            ->limit(500)
            ->get()
            ->map(fn (Employee $e) => $this->profileFields($e));

        return $this->ok(['employees' => $employees]);
    }

    /** One employee's profile (allowlisted). */
    public function employee(int $id)
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return $this->notFound();
        }
        return $this->ok(['employee' => $this->profileFields($employee)]);
    }

    /** Attendance logs for one employee. Defaults to the last 30 days; accepts ?from=&to=. */
    public function employeeAttendance(Request $request, int $id)
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return $this->notFound();
        }

        $to = $request->query('to') ?: SystemClock::today()->format('Y-m-d');
        $from = $request->query('from') ?: SystemClock::today()->copy()->subDays(30)->format('Y-m-d');

        $logs = AttendanceLog::where('employee_id', $employee->id)
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->limit(90)
            ->get(['date', 'clock_in_time', 'clock_out_time', 'status']);

        return $this->ok([
            'employee_id' => $employee->employee_id,
            'range'       => ['from' => $from, 'to' => $to],
            'logs'        => $logs,
        ]);
    }

    /** Leave balances (via the shared LeaveService) plus recent requests for one employee. */
    public function employeeLeave(int $id)
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return $this->notFound();
        }

        $requests = LeaveRequest::where('employee_id', $employee->id)
            ->orderByDesc('start_date')
            ->limit(50)
            ->get(['leave_type', 'start_date', 'end_date', 'days_requested', 'status', 'reason']);

        return $this->ok([
            'employee_id' => $employee->employee_id,
            'balances'    => LeaveService::balanceSummary($employee),
            'requests'    => $requests,
        ]);
    }

    /** Recent payslips for one employee (allowlisted; omits undeclared-salary fields). */
    public function employeePayroll(int $id)
    {
        $employee = Employee::find($id);
        if (!$employee) {
            return $this->notFound();
        }

        $payslips = Payroll::where('employee_id', $employee->id)
            ->orderByDesc('cutoff_end')
            ->limit(12)
            ->get([
                'cutoff_start', 'cutoff_end', 'daily_rate', 'days_worked', 'total_hours',
                'overtime_hours', 'late_minutes', 'undertime_minutes', 'gross_pay',
                'deductions', 'allowances', 'net_pay', 'status', 'paid_at',
            ]);

        return $this->ok([
            'employee_id' => $employee->employee_id,
            'payslips'    => $payslips,
        ]);
    }

    /** Deliberate allowlist — never gov IDs, bank account, or undeclared salary. */
    private function profileFields(Employee $e): array
    {
        return [
            'id'          => $e->id,
            'employee_id' => $e->employee_id,
            'name'        => $e->full_name,
            'email'       => $e->email,
            'phone'       => $e->phone,
            'position'    => $e->position,
            'department'  => $e->department,
            'hire_date'   => optional($e->hire_date)->format('Y-m-d'),
            'salary'      => $e->salary,
            'rate_type'   => $e->rate_type,
            'status'      => $e->status,
        ];
    }

    private function ok(array $data)
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => 'ok']);
    }

    private function notFound()
    {
        return response()->json(['success' => false, 'data' => null, 'message' => 'Employee not found.'], 404);
    }
}
