<?php

namespace App\Services;

use App\Helpers\SystemClock;
use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LaunchAssist engine — a read-only, employee-scoped chat assistant backed by Ollama
 * (minimax-m2.5:cloud) running locally on the Pi.
 *
 * Security spine: scope comes ONLY from the authenticated user's employee record and is
 * injected server-side into every tool. No tool accepts an employee id from the model, so
 * an employee can never reach another employee's data — even under prompt injection.
 */
class AssistantService
{
    private const MAX_ITERATIONS = 5;

    // System settings safe to expose via get_work_policy. Never wildcard SystemSettings —
    // that would leak the SSS/tax tables, OTP config, etc.
    private const POLICY_KEYS = [
        'work_start_time', 'work_end_time', 'work_days', 'late_threshold_minutes',
        'required_hours_per_day', 'overtime_threshold_hours', 'leave_include_weekends',
        'payroll_frequency',
    ];

    public function reply(User $user, array $messages): string
    {
        $employee = $user->employee;
        if (!$employee) {
            return "I couldn't find an employee record linked to your account, so I can't look "
                . "up your HR data yet. Please contact HR to have your profile linked.";
        }

        $convo = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($employee)]],
            array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $messages),
        );

        $host = rtrim(config('services.ollama.host'), '/');
        $model = config('services.ollama.model');
        $toolsUsed = [];

        try {
            for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
                $resp = Http::timeout(120)->post("{$host}/api/chat", [
                    'model'    => $model,
                    'stream'   => false,
                    'messages' => $convo,
                    'tools'    => $this->toolSchemas(),
                ]);

                if ($resp->failed()) {
                    Log::warning('assistant ollama error', ['status' => $resp->status()]);
                    return $this->unavailable();
                }

                $msg = $resp->json('message') ?? [];
                $calls = $msg['tool_calls'] ?? [];

                if (empty($calls)) {
                    // Final answer — ignore the reasoning `thinking` field, use content only.
                    return trim($msg['content'] ?? '') ?: $this->unavailable();
                }

                $convo[] = $msg; // append the assistant turn (with tool_calls) verbatim
                foreach ($calls as $call) {
                    $name = $call['function']['name'] ?? '';
                    $args = $call['function']['arguments'] ?? [];
                    $toolsUsed[] = $name;
                    $convo[] = [
                        'role'      => 'tool',
                        'tool_name' => $name,
                        'content'   => json_encode($this->executeTool($name, $args, $employee)),
                    ];
                }
            }

            // Hit the iteration cap without a final answer.
            return "I wasn't able to finish working that out. Could you try rephrasing or asking "
                . "something more specific?";
        } catch (Throwable $e) {
            Log::warning('assistant exception', ['error' => $e->getMessage()]);
            return $this->unavailable();
        } finally {
            Log::info('assistant', [
                'user_id'  => $user->id,
                'employee' => $employee->id,
                'question' => collect($messages)->last()['content'] ?? '',
                'tools'    => $toolsUsed,
            ]);
        }
    }

    private function unavailable(): string
    {
        return "Sorry — I'm having trouble reaching the assistant right now. Please try again in a moment.";
    }

    private function systemPrompt(Employee $employee): string
    {
        $today = SystemClock::today()->format('l, F j, Y');
        return implode("\n", [
            "You are LaunchAssist, the built-in HR assistant for the LAUNCHR HR system.",
            "You are speaking with {$employee->full_name} (employee #{$employee->employee_id}).",
            "Today's date is {$today}.",
            "",
            "Rules:",
            "- You are READ-ONLY. You cannot make changes, submit requests, or take actions.",
            "- You can only access THIS employee's own records plus public company info, via the tools.",
            "- If asked about another employee's data (salary, attendance, anything), politely decline.",
            "- Use the tools to ground every answer in real data — never invent numbers or dates.",
            "- Be concise and friendly. Amounts are in Philippine Pesos (₱).",
        ]);
    }

    /**
     * Dispatch a tool call. Every query is scoped by $employee->id — args never carry an
     * employee id, so scope cannot be overridden by the model.
     */
    private function executeTool(string $name, array $args, Employee $employee): array
    {
        return match ($name) {
            'get_my_profile'        => $this->getProfile($employee),
            'get_my_leave_balances' => LeaveService::balanceSummary($employee),
            'get_my_leave_requests' => $this->getLeaveRequests($employee, $args),
            'get_my_attendance'     => $this->getAttendance($employee, $args),
            'get_my_payslips'       => $this->getPayslips($employee),
            'get_my_schedule'       => ['schedule' => $employee->schedule],
            'get_company_calendar'  => $this->getCalendar($args),
            'list_leave_types'      => $this->getLeaveTypes(),
            'get_work_policy'       => $this->getWorkPolicy(),
            default                 => ['error' => "Unknown tool: {$name}"],
        };
    }

    private function getProfile(Employee $employee): array
    {
        // Deliberately omit government IDs, bank account, and undeclared salary.
        return [
            'name'       => $employee->full_name,
            'employee_id'=> $employee->employee_id,
            'email'      => $employee->email,
            'phone'      => $employee->phone,
            'position'   => $employee->position,
            'department' => $employee->department,
            'hire_date'  => optional($employee->hire_date)->format('Y-m-d'),
            'salary'     => $employee->salary,
            'rate_type'  => $employee->rate_type,
            'status'     => $employee->status,
        ];
    }

    private function getLeaveRequests(Employee $employee, array $args): array
    {
        $q = LeaveRequest::where('employee_id', $employee->id);
        if (!empty($args['status'])) {
            $q->where('status', $args['status']);
        }
        return ['requests' => $q->orderByDesc('start_date')->limit(50)->get([
            'leave_type', 'start_date', 'end_date', 'days_requested', 'status', 'reason',
        ])->toArray()];
    }

    private function getAttendance(Employee $employee, array $args): array
    {
        $to = !empty($args['to']) ? $args['to'] : SystemClock::today()->format('Y-m-d');
        $from = !empty($args['from'])
            ? $args['from']
            : SystemClock::today()->copy()->subDays(30)->format('Y-m-d');

        return ['logs' => AttendanceLog::where('employee_id', $employee->id)
            ->whereBetween('date', [$from, $to])
            ->orderByDesc('date')
            ->limit(90)
            ->get(['date', 'clock_in_time', 'clock_out_time', 'status'])
            ->toArray(),
            'range' => ['from' => $from, 'to' => $to]];
    }

    private function getPayslips(Employee $employee): array
    {
        // Own pay is in scope; omit undeclared_salary fields.
        return ['payslips' => Payroll::where('employee_id', $employee->id)
            ->orderByDesc('cutoff_end')
            ->limit(12)
            ->get([
                'cutoff_start', 'cutoff_end', 'daily_rate', 'days_worked', 'total_hours',
                'overtime_hours', 'late_minutes', 'undertime_minutes', 'gross_pay',
                'deductions', 'allowances', 'net_pay', 'status', 'paid_at',
            ])->toArray()];
    }

    private function getCalendar(array $args): array
    {
        $from = !empty($args['from']) ? $args['from'] : SystemClock::today()->format('Y-m-d');
        $to = !empty($args['to'])
            ? $args['to']
            : SystemClock::today()->copy()->addDays(90)->format('Y-m-d');

        return ['events' => CalendarEvent::with('type:id,name')
            ->whereBetween('event_date', [$from, $to])
            ->orderBy('event_date')
            ->get(['calendar_event_type_id', 'event_date', 'end_date', 'title', 'description', 'counts_as_absence'])
            ->map(fn ($e) => [
                'date'    => optional($e->event_date)->format('Y-m-d'),
                'end_date'=> optional($e->end_date)->format('Y-m-d'),
                'title'   => $e->title,
                'type'    => $e->type?->name,
                'counts_as_absence' => $e->counts_as_absence,
            ])->toArray()];
    }

    private function getLeaveTypes(): array
    {
        return ['leave_types' => LeaveType::where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name', 'description', 'default_days', 'is_paid'])
            ->toArray()];
    }

    private function getWorkPolicy(): array
    {
        $policy = [];
        foreach (self::POLICY_KEYS as $key) {
            $policy[$key] = SystemSettings::get($key);
        }
        return $policy;
    }

    private function toolSchemas(): array
    {
        $fn = fn ($name, $desc, $props = []) => [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $desc,
                'parameters' => ['type' => 'object', 'properties' => (object) $props, 'required' => []],
            ],
        ];
        $date = ['type' => 'string', 'description' => 'Date as YYYY-MM-DD'];

        return [
            $fn('get_my_profile', "The employee's own profile: name, position, department, hire date, salary, rate type."),
            $fn('get_my_leave_balances', "The employee's leave balances (total, used, remaining) per leave type for the current cycle."),
            $fn('get_my_leave_requests', "The employee's own leave requests.", [
                'status' => ['type' => 'string', 'description' => 'Optional filter: pending, approved, or rejected'],
            ]),
            $fn('get_my_attendance', "The employee's attendance logs (clock in/out, status). Defaults to the last 30 days.", [
                'from' => $date, 'to' => $date,
            ]),
            $fn('get_my_payslips', "The employee's recent payslips (gross, deductions, net pay, days worked)."),
            $fn('get_my_schedule', "The employee's current work schedule (days and shift times)."),
            $fn('get_company_calendar', "Public company calendar events (holidays, events). Defaults to the next 90 days.", [
                'from' => $date, 'to' => $date,
            ]),
            $fn('list_leave_types', "The available leave types the company offers."),
            $fn('get_work_policy', "General company work policy (work hours, work days, late threshold, payroll frequency)."),
        ];
    }
}
