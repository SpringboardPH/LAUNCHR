<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\ThirteenthMonth;
use App\Models\ThirteenthMonthSetting;
use App\Helpers\SystemClock;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ThirteenthMonthController extends Controller
{
    public function index(Request $request)
    {
        $year    = $request->integer('year', now()->year);
        $group   = $request->get('group');
        $perPage = min((int) $request->query('per_page', 15), 100);

        $empQuery = Employee::where('status', 'active')->orderBy('last_name')->orderBy('first_name');
        if ($group) $empQuery->where('group', $group);

        $paginated   = $empQuery->paginate($perPage);
        $employees   = collect($paginated->items());
        $employeeIds = $employees->pluck('id');

        $payrolls = Payroll::whereIn('employee_id', $employeeIds)
            ->whereYear('cutoff_start', $year)
            ->whereDate('cutoff_end', '<=', SystemClock::today())
            ->get(['id', 'employee_id', 'cutoff_start', 'cutoff_end', 'gross_pay', 'deductions', 'allowances']);
        // Note: removeContainedPeriods is applied per-employee inside buildEmployeeRow

        $saved = ThirteenthMonth::where('year', $year)
            ->whereIn('employee_id', $employeeIds)
            ->get()->groupBy('employee_id');

        $modes = ThirteenthMonthSetting::where('year', $year)
            ->whereIn('employee_id', $employeeIds)
            ->pluck('mode', 'employee_id');

        $data = $employees->map(function ($emp) use ($payrolls, $saved, $modes, $year) {
            $mode = $modes->get($emp->id, 'declared');
            return $this->buildEmployeeRow($emp, $payrolls, $saved, $year, $mode);
        });

        return response()->json([
            'success'    => true,
            'data'       => $data,
            'year'       => $year,
            'pagination' => [
                'total'        => $paginated->total(),
                'count'        => $paginated->count(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function save(Request $request)
    {
        $validated = $request->validate([
            'year'                  => 'required|integer|min:2000|max:2100',
            'records'               => 'required|array',
            'records.*.employee_id' => 'required|integer|exists:employees,id',
            'records.*.month'       => 'required|integer|min:1|max:12',
            'records.*.basic_pay'   => 'nullable|numeric|min:0',
            'records.*.is_override' => 'required|boolean',
        ]);

        foreach ($validated['records'] as $record) {
            if ($record['basic_pay'] === null) {
                ThirteenthMonth::where([
                    'employee_id' => $record['employee_id'],
                    'year'        => $validated['year'],
                    'month'       => $record['month'],
                ])->delete();
                continue;
            }
            ThirteenthMonth::updateOrCreate(
                ['employee_id' => $record['employee_id'], 'year' => $validated['year'], 'month' => $record['month']],
                ['basic_pay' => $record['basic_pay'], 'is_override' => $record['is_override']]
            );
        }

        return response()->json(['success' => true, 'message' => 'Records saved.']);
    }

    public function setMode(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'year'        => 'required|integer|min:2000|max:2100',
            'mode'        => 'required|in:declared,undeclared',
        ]);

        ThirteenthMonthSetting::updateOrCreate(
            ['employee_id' => $validated['employee_id'], 'year' => $validated['year']],
            ['mode' => $validated['mode']]
        );

        return response()->json(['success' => true, 'mode' => $validated['mode']]);
    }

    public function payrollPeriods(Request $request)
    {
        $year = $request->integer('year', now()->year);

        // Use DATE() to return plain YYYY-MM-DD strings, avoiding Carbon UTC serialization.
        $periods = \DB::table('payrolls')
            ->where('status', 'draft')
            ->whereYear('cutoff_start', $year)
            ->selectRaw('DATE(cutoff_start) as cutoff_start, DATE(cutoff_end) as cutoff_end')
            ->groupBy('cutoff_start', 'cutoff_end')
            ->orderBy('cutoff_start')
            ->get();

        return response()->json(['success' => true, 'data' => $periods]);
    }

    public function pushToPayroll(Request $request)
    {
        $validated = $request->validate([
            'year'           => 'required|integer|min:2000|max:2100',
            'cutoff_start'   => 'required|date',
            'cutoff_end'     => 'required|date',
            'employee_ids'   => 'nullable|array',
            'employee_ids.*' => 'integer|exists:employees,id',
        ]);

        $empQuery = Employee::where('status', 'active');
        if (!empty($validated['employee_ids'])) {
            $empQuery->whereIn('id', $validated['employee_ids']);
        }
        $employees = $empQuery->get();

        $pushed  = 0;
        $skipped = [];

        foreach ($employees as $emp) {
            $amount = $this->computeThirteenth($emp->id, $validated['year']);
            if ($amount <= 0) continue;

            $payroll = Payroll::where('employee_id', $emp->id)
                ->whereDate('cutoff_start', $validated['cutoff_start'])
                ->whereDate('cutoff_end', $validated['cutoff_end'])
                ->where('status', 'draft')
                ->latest('id')
                ->first();

            if (!$payroll) {
                $skipped[] = "{$emp->first_name} {$emp->last_name}";
                continue;
            }

            $allowances = collect($payroll->allowances ?? []);
            $oldAmount  = (float) ($allowances->firstWhere('label', '13th Month Pay')['amount'] ?? 0);
            $allowances = $allowances->filter(fn($a) => ($a['label'] ?? '') !== '13th Month Pay')->values()->all();
            $allowances[] = ['label' => '13th Month Pay', 'amount' => round($amount, 2)];

            $payroll->update([
                'allowances' => $allowances,
                'gross_pay'  => round((float) $payroll->gross_pay - $oldAmount + $amount, 2),
                'net_pay'    => max(0, round((float) $payroll->net_pay - $oldAmount + $amount, 2)),
            ]);

            $pushed++;
        }

        $msg = "{$pushed} payroll(s) updated.";
        if ($skipped) {
            $msg .= ' ' . count($skipped) . ' had no matching draft payroll for this period.';
        }

        return response()->json(['success' => true, 'pushed' => $pushed, 'skipped' => $skipped, 'message' => $msg]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Sum all deductions except statutory contributions (SSS, PhilHealth, Pag-IBIG).
     * Late, Absent, Half Day, Undertime, Withholding Tax, etc. all reduce the base.
     */
    private function nonContribDeductions(Payroll $p): float
    {
        $total = 0;
        foreach ($p->deductions ?? [] as $label => $amount) {
            if (!str_contains(strtolower((string) $label), 'contribution')) {
                $total += (float) $amount;
            }
        }
        return $total;
    }

    /**
     * Declared  = gross − all allowances − non-contribution deductions.
     * Undeclared = gross − overtime allowances only − non-contribution deductions.
     */
    private function periodBase(Payroll $p, string $mode): float
    {
        $deductions = $this->nonContribDeductions($p);
        $allowances = collect($p->allowances ?? []);

        if ($mode === 'declared') {
            return max(0, (float) $p->gross_pay - $allowances->sum('amount') - $deductions);
        }

        // Strip OT and any previously pushed 13th Month Pay (not part of basic salary).
        $otPay = $allowances
            ->filter(fn($a) => str_contains(strtolower($a['label'] ?? ''), 'overtime')
                             || ($a['label'] ?? '') === '13th Month Pay')
            ->sum('amount');

        return max(0, (float) $p->gross_pay - $otPay - $deductions);
    }

    /**
     * Remove overlapping/duplicate payrolls:
     * - Identical date range: keep only the newest (highest id).
     * - Strictly wider range: drop the wider one (monthly contains semi-monthly → drop monthly).
     */
    private function removeContainedPeriods($payrolls)
    {
        return $payrolls->filter(function ($p) use ($payrolls) {
            return !$payrolls->some(function ($other) use ($p) {
                if ($other->id === $p->id) return false;

                $sameRange = $p->cutoff_start->eq($other->cutoff_start)
                          && $p->cutoff_end->eq($other->cutoff_end);

                if ($sameRange) {
                    // Duplicate period: drop the older record, keep the newest.
                    return $other->id > $p->id;
                }

                // Drop if this period strictly contains another (wider = monthly duplicate).
                return $p->cutoff_start->lte($other->cutoff_start)
                    && $p->cutoff_end->gte($other->cutoff_end);
            });
        });
    }

    private function buildEmployeeRow($emp, $payrolls, $saved, $year, string $mode): array
    {
        $empPayrolls = $this->removeContainedPeriods($payrolls->where('employee_id', $emp->id));
        $empSaved    = $saved->get($emp->id, collect());
        $months      = [];

        for ($m = 1; $m <= 12; $m++) {
            $override = $empSaved->firstWhere('month', $m);

            if ($override && $override->is_override) {
                $months[$m] = ['amount' => (float) $override->basic_pay, 'is_override' => true, 'has_payroll' => false];
                continue;
            }

            $periodPayrolls = $empPayrolls->filter(fn($p) => $p->cutoff_start->month === $m);

            if ($periodPayrolls->isEmpty()) {
                $months[$m] = ['amount' => null, 'is_override' => false, 'has_payroll' => false];
                continue;
            }

            $basicPay  = $periodPayrolls->sum(fn($p) => $this->periodBase($p, $mode));
            $breakdown = $periodPayrolls->map(fn($p) => [
                'cutoff_start' => $p->cutoff_start->toDateString(),
                'cutoff_end'   => $p->cutoff_end->toDateString(),
                'gross_pay'    => round((float) $p->gross_pay, 2),
                'deductions'   => collect($p->deductions ?? [])
                    ->filter(fn($amt, $label) => !str_contains(strtolower((string) $label), 'contribution'))
                    ->map(fn($amt, $label) => ['label' => $label, 'amount' => round((float) $amt, 2)])
                    ->values()->all(),
                'allowances'   => collect($p->allowances ?? [])
                    ->filter(fn($a) => ($a['label'] ?? '') !== '13th Month Pay')
                    ->map(fn($a) => ['label' => $a['label'], 'amount' => round((float) $a['amount'], 2)])
                    ->values()->all(),
                'base'         => round($this->periodBase($p, $mode), 2),
            ])->values()->all();

            $months[$m] = ['amount' => round($basicPay, 2), 'is_override' => false, 'has_payroll' => true, 'breakdown' => $breakdown];
        }

        return [
            'id'          => $emp->id,
            'employee_id' => $emp->employee_id,
            'name'        => "{$emp->first_name} {$emp->last_name}",
            'group'       => $emp->group,
            'mode'        => $mode,
            'months'      => $months,
        ];
    }

    private function computeThirteenth(int $employeeId, int $year): float
    {
        $mode = ThirteenthMonthSetting::where('employee_id', $employeeId)
            ->where('year', $year)
            ->value('mode') ?? 'declared';

        $payrolls = $this->removeContainedPeriods(
            Payroll::where('employee_id', $employeeId)
                ->whereYear('cutoff_start', $year)
                ->whereDate('cutoff_end', '<=', SystemClock::today())
                ->get(['id', 'employee_id', 'cutoff_start', 'cutoff_end', 'gross_pay', 'deductions', 'allowances'])
        );

        $saved = ThirteenthMonth::where('year', $year)
            ->where('employee_id', $employeeId)
            ->get()->keyBy('month');

        $total = 0;
        for ($m = 1; $m <= 12; $m++) {
            $override = $saved->get($m);
            if ($override && $override->is_override) {
                $total += (float) $override->basic_pay;
                continue;
            }
            foreach ($payrolls->filter(fn($p) => $p->cutoff_start->month === $m) as $p) {
                $total += $this->periodBase($p, $mode);
            }
        }
        return $total / 12;
    }
}
