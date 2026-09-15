<?php

namespace Database\Seeders;

use App\Helpers\SystemClock;
use App\Models\AttendanceLog;
use App\Models\CalendarEvent;
use App\Models\CalendarEventType;
use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Models\User;
use App\Services\LoanService;
use App\Services\PayrollService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

class DemoOpsSeeder extends Seeder
{
    private const OFFICE_LAT = 14.5547;
    private const OFFICE_LNG = 121.0244;

    public function run(): void
    {
        $today = SystemClock::today();
        [$priorStart, $priorEnd, $currentStart] = $this->cutoffWindows($today);
        $employees = Employee::query()->where('status', 'active')->with(['user', 'schedules.template'])->get();
        $byEmail = $employees->keyBy('email');
        $adminUser = User::query()->where('role', 'admin')->first();
        $hrUser = User::query()->where('email', 'hr@springboardph.com')->first() ?? $adminUser;
        $juan = $byEmail->get('juan@springboardph.com');

        $this->seedOfficeLocation();
        $this->seedCalendar($adminUser, $priorStart, $currentStart);
        $approvedLeaves = $this->seedLeaveRequests($byEmail, $hrUser, $today);
        $this->seedRequests($byEmail, $today);
        $this->seedLoans($byEmail, $hrUser, $priorStart);
        $this->seedAttendance($employees, $juan, $priorStart, $today, $approvedLeaves);
        $this->seedPriorPayrolls($employees, $priorStart, $priorEnd);
    }

    private function cutoffWindows(Carbon $today): array
    {
        $day = $today->day;
        if ($day >= 11 && $day <= 25) {
            $currentStart = $today->copy()->day(11);
            $priorStart = $today->copy()->subMonthNoOverflow()->day(26);
            $priorEnd = $today->copy()->day(10);
        } elseif ($day >= 26) {
            $currentStart = $today->copy()->day(26);
            $priorStart = $today->copy()->day(11);
            $priorEnd = $today->copy()->day(25);
        } else {
            $currentStart = $today->copy()->subMonthNoOverflow()->day(26);
            $priorStart = $today->copy()->subMonthNoOverflow()->day(11);
            $priorEnd = $today->copy()->subMonthNoOverflow()->day(25);
        }

        return [$priorStart->startOfDay(), $priorEnd->startOfDay(), $currentStart->startOfDay()];
    }

    private function seedOfficeLocation(): void
    {
        SystemSettings::set('office_locations', [
            ['name' => 'Springboard Makati', 'lat' => self::OFFICE_LAT, 'lng' => self::OFFICE_LNG, 'radius_m' => 250],
        ], 'Allowed clock-in zones: [{name, lat, lng, radius_m}]', 'json');
        SystemSettings::set('login_otp_required', false, 'Whether an email OTP is required to log in', 'boolean');
        SystemSettings::set('geo_capture_enabled', false, 'Master switch: whether clock-in captures employee location at all', 'boolean');
        SystemSettings::set('geofence_enabled', false, 'Whether clock-in is restricted to configured office locations', 'boolean');
    }

    private function seedCalendar(?User $admin, Carbon $priorStart, Carbon $currentStart): void
    {
        $regular = CalendarEventType::query()->where('name', 'Regular Holiday')->first();
        $special = CalendarEventType::query()->where('name', 'Special Non-Working Day')->first();
        $company = CalendarEventType::query()->where('name', 'Company Event')->first();
        $training = CalendarEventType::query()->where('name', 'Training / Seminar')->first();
        $createdBy = $admin?->id;

        $events = [
            ['type' => $regular, 'event_date' => $priorStart->copy()->day(21)->toDateString(), 'title' => 'Ninoy Aquino Day', 'counts_as_absence' => false],
            ['type' => $special, 'event_date' => $currentStart->copy()->addDays(12)->toDateString(), 'title' => 'Special Non-Working Holiday', 'counts_as_absence' => false],
            ['type' => $company, 'event_date' => $priorStart->copy()->addDays(5)->toDateString(), 'title' => 'Town Hall', 'counts_as_absence' => true],
            ['type' => $training, 'event_date' => $currentStart->copy()->addDays(4)->toDateString(), 'title' => 'Payroll Workshop', 'counts_as_absence' => true],
        ];

        foreach ($events as $event) {
            if (!$event['type']) {
                continue;
            }
            CalendarEvent::updateOrCreate(
                ['title' => $event['title'], 'event_date' => $event['event_date']],
                [
                    'calendar_event_type_id' => $event['type']->id,
                    'end_date' => $event['event_date'],
                    'description' => $event['title'],
                    'counts_as_absence' => $event['counts_as_absence'],
                    'created_by' => $createdBy,
                ]
            );
        }
    }

    private function seedLeaveRequests($byEmail, ?User $hrUser, Carbon $today): array
    {
        $approved = [];
        $rows = [
            ['email' => 'ana@springboardph.com', 'leave_type' => 'vacation', 'start' => $today->copy()->subWeek()->startOfWeek()->addDays(1), 'end' => $today->copy()->subWeek()->startOfWeek()->addDays(2), 'status' => 'approved', 'reason' => 'Family trip to Tagaytay'],
            ['email' => 'liza.morales@springboardph.com', 'leave_type' => 'sick', 'start' => $today->copy()->subDays(12), 'end' => $today->copy()->subDays(11), 'status' => 'approved', 'reason' => 'Flu recovery'],
            ['email' => 'sofia.mendoza@springboardph.com', 'leave_type' => 'vacation', 'start' => $today->copy()->next('Monday'), 'end' => $today->copy()->next('Monday')->addDay(), 'status' => 'pending', 'reason' => 'Long weekend in La Union'],
            ['email' => 'gabriel.tan@springboardph.com', 'leave_type' => 'unpaid', 'start' => $today->copy()->next('Wednesday'), 'end' => $today->copy()->next('Wednesday'), 'status' => 'pending', 'reason' => 'Personal errand'],
        ];

        foreach ($rows as $row) {
            $employee = $byEmail->get($row['email']);
            if (!$employee) {
                continue;
            }
            $request = LeaveRequest::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'start_date' => $row['start']->toDateString(),
                    'leave_type' => $row['leave_type'],
                ],
                [
                    'end_date' => $row['end']->toDateString(),
                    'reason' => $row['reason'],
                    'status' => $row['status'],
                    'approver_id' => $row['status'] === 'approved' ? $hrUser?->id : null,
                ]
            );
            if ($row['status'] !== 'approved') {
                continue;
            }
            foreach (CarbonPeriod::create($row['start']->startOfDay(), $row['end']->startOfDay()) as $date) {
                if ($date->isWeekday()) {
                    $approved[$employee->id.'|'.$date->toDateString()] = $request->id;
                }
            }
        }

        return $approved;
    }

    private function seedRequests($byEmail, Carbon $today): void
    {
        $pedro = $byEmail->get('pedro@springboardph.com');
        $rosa = $byEmail->get('rosa@springboardph.com');
        if ($pedro) {
            EmployeeRequest::updateOrCreate(
                ['employee_id' => $pedro->id, 'request_type' => 'overtime', 'subject' => 'Client demo overtime'],
                [
                    'details' => 'Stayed after 6 PM to close a client walkthrough.',
                    'meta' => ['date' => $today->copy()->subDays(2)->toDateString(), 'hours' => 2],
                    'status' => 'pending',
                ]
            );
        }
        if ($rosa) {
            EmployeeRequest::updateOrCreate(
                ['employee_id' => $rosa->id, 'request_type' => 'coe', 'subject' => 'COE for bank loan'],
                [
                    'details' => 'Need a certificate of employment for a housing loan.',
                    'status' => 'pending',
                ]
            );
        }
    }

    private function seedLoans($byEmail, ?User $hrUser, Carbon $priorStart): void
    {
        $specs = [
            ['email' => 'hannah.villanueva@springboardph.com', 'loan_type' => 'sss_salary', 'principal' => 12000, 'interest_rate' => 0.00, 'term_count' => 12],
            ['email' => 'enzo.gutierrez@springboardph.com', 'loan_type' => 'pagibig_mpl', 'principal' => 18000, 'interest_rate' => 0.00, 'term_count' => 18],
            ['email' => 'marco.delacruz@springboardph.com', 'loan_type' => 'sss_calamity', 'principal' => 8000, 'interest_rate' => 0.00, 'term_count' => 8],
        ];

        foreach ($specs as $spec) {
            $employee = $byEmail->get($spec['email']);
            if (!$employee) {
                continue;
            }
            [$totalPayable, $installment] = LoanService::computeSchedule($spec['principal'], $spec['interest_rate'], $spec['term_count']);
            Loan::updateOrCreate(
                ['employee_id' => $employee->id, 'loan_type' => $spec['loan_type']],
                [
                    'principal' => $spec['principal'],
                    'interest_rate' => $spec['interest_rate'],
                    'total_payable' => $totalPayable,
                    'installment_amount' => $installment,
                    'term_count' => $spec['term_count'],
                    'balance' => $totalPayable,
                    'status' => 'active',
                    'start_cutoff' => $priorStart->toDateString(),
                    'approver_id' => $hrUser?->id,
                    'notes' => 'Demo loan',
                ]
            );
        }
    }

    private function seedAttendance($employees, ?Employee $juan, Carbon $from, Carbon $today, array $approvedLeaves): void
    {
        $todayStr = $today->toDateString();
        $holidayDates = CalendarEvent::query()
            ->whereHas('type', fn ($q) => $q->where('counts_as_absence', false))
            ->get()
            ->flatMap(function ($event) {
                $start = Carbon::parse($event->event_date);
                $end = Carbon::parse($event->end_date ?? $event->event_date);
                return collect(CarbonPeriod::create($start, $end))->map->toDateString();
            })
            ->unique()
            ->all();

        $now = now();
        $rows = [];
        foreach ($employees as $employee) {
            $schedule = $employee->schedules->first();
            $template = $schedule?->template;
            $workDays = $template?->work_days ?? [1, 2, 3, 4, 5];
            $seq = (int) preg_replace('/\D+/', '', $employee->employee_id);
            $isNight = ($template?->type ?? '') === 'night';

            foreach (CarbonPeriod::create($from, $today) as $date) {
                $dateStr = $date->toDateString();
                if (!in_array((int) $date->dayOfWeek, $workDays, true)) {
                    continue;
                }
                if ($juan && $employee->id === $juan->id && $dateStr === $todayStr) {
                    continue;
                }

                $leaveKey = $employee->id.'|'.$dateStr;
                $coords = $this->officeCoords($seq + $date->day);
                if (isset($approvedLeaves[$leaveKey])) {
                    $payload = ['clock_in_time' => null, 'clock_out_time' => null, 'status' => 'on_leave', 'clock_in_lat' => null, 'clock_in_lng' => null];
                } elseif (in_array($dateStr, $holidayDates, true)) {
                    $payload = ['clock_in_time' => null, 'clock_out_time' => null, 'status' => 'holiday', 'clock_in_lat' => null, 'clock_in_lng' => null];
                } elseif ($dateStr === $todayStr) {
                    if ($isNight) {
                        continue;
                    }
                    if ($seq % 4 === 0) {
                        $payload = ['clock_in_time' => null, 'clock_out_time' => null, 'status' => 'absent', 'clock_in_lat' => null, 'clock_in_lng' => null];
                    } else {
                        $payload = array_merge([
                            'clock_in_time' => '09:0'.($seq % 5).':00',
                            'clock_out_time' => null,
                            'status' => 'working',
                        ], $coords);
                    }
                } elseif ($seq % 11 === $date->day % 11 && $date->day % 2 === 0) {
                    $payload = ['clock_in_time' => null, 'clock_out_time' => null, 'status' => 'absent', 'clock_in_lat' => null, 'clock_in_lng' => null];
                } else {
                    $late = $seq % 9 === $date->day % 9;
                    if ($isNight) {
                        $payload = array_merge([
                            'clock_in_time' => $late ? '22:08:00' : '22:02:00',
                            'clock_out_time' => $late ? '06:00:00' : '06:04:00',
                            'status' => $late ? 'late' : 'completed',
                        ], $coords);
                    } else {
                        $payload = array_merge([
                            'clock_in_time' => $late ? '09:08:00' : '09:00:00',
                            'clock_out_time' => $late ? '18:00:00' : '18:03:00',
                            'status' => $late ? 'late' : 'completed',
                        ], $coords);
                    }
                }

                $rows[] = array_merge($payload, [
                    'employee_id' => $employee->id,
                    'date' => $dateStr,
                    'schedule_template_id' => $template?->id,
                    'schedule_template_name' => $template?->name,
                    'schedule_type' => $template?->type,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (array_chunk($rows, 400) as $chunk) {
            AttendanceLog::insert($chunk);
        }
    }

    private function seedPriorPayrolls($employees, Carbon $start, Carbon $end): void
    {
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();
        $now = SystemClock::now();

        foreach ($employees as $employee) {
            if ($employee->user?->role === 'admin') {
                continue;
            }
            $template = $employee->schedules->first()?->template;
            $workDays = $template?->work_days ?? [1, 2, 3, 4, 5];
            $daysInWeek = count($workDays);
            $divisor = $daysInWeek <= 5 ? 261 : 313;
            $isDaily = ($employee->rate_type ?? 'monthly') === 'daily';
            $dailyRate = $isDaily ? (float) $employee->salary : ((float) $employee->salary * 12) / $divisor;
            $daysWorked = 0;
            foreach (CarbonPeriod::create($start, $end) as $date) {
                if (in_array((int) $date->dayOfWeek, $workDays, true)) {
                    $daysWorked++;
                }
            }
            $gross = round($dailyRate * $daysWorked, 2);
            $basis = $isDaily ? (float) ($employee->undeclared_salary ?? 0) : (float) $employee->salary;
            $sss = PayrollService::calculateSSS($basis, 2);
            $philhealth = PayrollService::calculatePhilHealth($basis, 2);
            $pagibig = PayrollService::calculatePagIBIG($basis, 2);
            $taxable = max(0, $gross - $sss - $philhealth - $pagibig);
            $tax = PayrollService::calculateWithholdingTax($taxable, 'semi_monthly');
            $deductions = array_filter([
                'SSS EE Contribution' => round($sss, 2),
                'PhilHealth EE Contribution' => round($philhealth, 2),
                'Pag-IBIG EE Contribution' => round($pagibig, 2),
                'Withholding Tax' => round($tax, 2),
            ]);
            $totalDeductions = array_sum($deductions);

            Payroll::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'cutoff_start' => $startStr,
                    'cutoff_end' => $endStr,
                ],
                [
                    'base_salary' => $employee->salary,
                    'undeclared_salary' => $employee->undeclared_salary,
                    'daily_rate' => round($dailyRate, 2),
                    'total_hours' => $daysWorked * (float) ($template->expected_hours_per_day ?? 9),
                    'days_worked' => $daysWorked,
                    'overtime_hours' => 0,
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                    'gross_pay' => $gross,
                    'deductions' => $deductions,
                    'allowances' => [],
                    'net_pay' => round($gross - $totalDeductions, 2),
                    'status' => 'finalized',
                    'use_undeclared' => false,
                    'processed_at' => $now,
                ]
            );
        }
    }

    private function officeCoords(int $n): array
    {
        return [
            'clock_in_lat' => round(self::OFFICE_LAT + ($n % 7) * 0.00012, 6),
            'clock_in_lng' => round(self::OFFICE_LNG + ($n % 5) * 0.00012, 6),
        ];
    }
}
