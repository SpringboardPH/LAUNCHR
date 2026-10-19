<?php

namespace Database\Seeders;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Models\User;
use App\Services\BIR\BirAggregationService;
use App\Services\PayrollService;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo data for the BIR Form Assistant: company settings, eight made-up employees who between
 * them cover every 1601-C and 2316 case, their payroll from January 2025 to September 2026,
 * and forms in every status. The forms are made through the app's own API (create, answer,
 * submit, return, approve, finalize, revise), so they are built, checked and stored exactly as
 * the screens would do it.
 *
 * Every name, TIN and address here is invented. Safe to run again: it first removes what a
 * previous run made (employees BIR-001 to BIR-008, their payroll and forms, and the 1601-C
 * forms for 12/2025 to 09/2026).
 *
 *   php artisan db:seed --class=BirDemoSeeder
 */
class BirDemoSeeder extends Seeder
{
    private const MONTHS_1601C = ['2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09'];

    /**
     * code => who they are and which case they cover. monthly: basic pay a month (the 13th
     * month pay in 12/2025 is one month's pay); holiday/overtime/night: the months with that
     * extra pay (see EXTRAS); late: months with late and absent deductions; left: last day.
     */
    private const EMPLOYEES = [
        // Taxable, all year. An undeclared 'Allowance' (left off the forms) and some overtime.
        'BIR-001' => ['first' => 'Andres', 'last' => 'Villanueva', 'position' => 'Accountant', 'hired' => '2023-02-01', 'monthly' => 30000,
            'tin' => '201-345-678-00000', 'phone' => '09171230001', 'undeclared' => 1000, 'overtime' => [3, 8, 11]],
        // High earner: 13th month over P90,000, so P30,000 of it is taxable (2316 item 48); night differential.
        'BIR-002' => ['first' => 'Bea', 'last' => 'Santiago', 'position' => 'Operations Manager', 'hired' => '2021-09-01', 'monthly' => 120000,
            'tin' => '202-456-789-00000', 'phone' => '09171230002', 'night' => [1, 2, 6, 7]],
        // Not a minimum wage earner, but P250,000 or less a year: no tax (1601-C item 23, 2316 items 29/37).
        // Holiday pay, and late/absent deductions that come off the compensation.
        'BIR-003' => ['first' => 'Carlo', 'last' => 'Mendoza', 'position' => 'Office Assistant', 'hired' => '2024-04-15', 'monthly' => 18000,
            'tin' => '203-567-890-00000', 'phone' => '09171230003', 'holiday' => [4, 6, 8, 11], 'late' => [2, 5, 9]],
        // Minimum wage earner (System Settings list): holiday, overtime and night pay are not taxed.
        'BIR-004' => ['first' => 'Dana', 'last' => 'Lopez', 'position' => 'Warehouse Staff', 'hired' => '2022-01-10', 'monthly' => 16823.75,
            'tin' => '204-678-901-00000', 'phone' => '09171230004', 'mwe' => true, 'holiday' => [4, 6, 8, 12], 'overtime' => [3, 10], 'night' => [5, 9]],
        // Hired 07/01/2026 after another employer: the 2026 2316 asks about the previous employer.
        'BIR-005' => ['first' => 'Elias', 'last' => 'Ramos', 'position' => 'Sales Associate', 'hired' => '2026-07-01', 'monthly' => 35000,
            'tin' => '205-789-012-00000', 'phone' => '09171230005'],
        // Left in May 2026: the 2026 2316 runs 01/01 to 05/31.
        'BIR-006' => ['first' => 'Fiona', 'last' => 'Cruz', 'position' => 'HR Assistant', 'hired' => '2022-06-01', 'monthly' => 28000,
            'tin' => '206-890-123-00000', 'phone' => '09171230006', 'left' => '2026-05-31'],
        // Taxable, all year; files under substituted filing with a valid ID instead of a CTC.
        'BIR-007' => ['first' => 'Gino', 'last' => 'Bautista', 'position' => 'Driver', 'hired' => '2024-01-15', 'monthly' => 25000,
            'tin' => '207-901-234-00000', 'phone' => '09171230007'],
        // Hired 03/16/2025 after another employer; TIN saved the old 12-digit way (printed with 14).
        'BIR-008' => ['first' => 'Hazel', 'last' => 'Navarro', 'position' => 'Developer', 'hired' => '2025-03-16', 'monthly' => 40000,
            'tin' => '987654321000', 'phone' => '09171230008'],
    ];

    /** Extra pay in the listed months (second cutoff): kind => [payroll label, share of the monthly pay]. */
    private const EXTRAS = [
        'holiday' => ['Special Holiday', 0.10],
        'overtime' => ['Overtime Pay', 0.10],
        'night' => ['Night Differential', 0.05],
    ];

    /** 2316 amounts with nothing to report, entered as 0.00 so every amount box on the form is filled. */
    private const ZERO_2316 = [
        'taxable_income_previous_employer' => '0.00', 'taxes_withheld_previous' => '0.00', 'pera_tax_credit' => '0.00',
        'tax_others_44a_amount' => '0.00', 'tax_others_44b_amount' => '0.00', 'tax_others_51a_amount' => '0.00', 'tax_others_51b_amount' => '0.00',
    ];

    /** @var array<string, string> role => API token */
    private array $tokens = [];

    /** @var array<string, int> code => employees.id */
    private array $ids = [];

    public function run(): void
    {
        $hr = User::firstOrCreate(['email' => 'hr@springboardph.com'], ['name' => 'HR Officer', 'password' => bcrypt('password'), 'role' => 'hr']);
        $accounting = User::firstOrCreate(['email' => 'accounting@springboardph.com'], ['name' => 'Accounting Officer', 'password' => bcrypt('password'), 'role' => 'accounting']);

        DB::transaction(function () {
            $this->clearPreviousRun();
            $this->companySettings();
            $this->employeesAndPayroll();
        });

        $this->tokens = [
            'hr' => $hr->createToken('bir-demo-seeder')->plainTextToken,
            'accounting' => $accounting->createToken('bir-demo-seeder')->plainTextToken,
        ];

        try {
            $this->forms1601C();
            $this->forms2316();
        } finally {
            $hr->tokens()->where('name', 'bir-demo-seeder')->delete();
            $accounting->tokens()->where('name', 'bir-demo-seeder')->delete();
        }

        $this->command?->info('BIR demo data ready: 8 employees, payroll 01/2025 to 09/2026, '
            . BirFormDraft::count() . ' BIR forms. Log in as hr@ or accounting@springboardph.com.');
    }

    private function clearPreviousRun(): void
    {
        $old = Employee::withTrashed()->where('employee_id', 'like', 'BIR-%')->pluck('id');

        // Revisions point at their parent, so the highest versions go first. Removed for good, not
        // soft-deleted: a deleted correction still counts toward the amendment limit.
        BirFormDraft::withTrashed()
            ->where(fn ($q) => $q->whereIn('employee_id', $old)
                ->orWhere(fn ($q) => $q->where('form_type', '1601-C')->whereIn('period', self::MONTHS_1601C)))
            ->orderByDesc('version')
            ->get()
            ->each->forceDelete();

        Payroll::whereIn('employee_id', $old)->delete();
        Employee::withTrashed()->whereIn('id', $old)->forceDelete();
    }

    private function companySettings(): void
    {
        $settings = [
            'bir_forms_enabled' => ['true', 'boolean'],
            'company_tin' => ['010-234-567-00000', 'string'],
            'rdo_code' => ['047', 'string'],
            'atc_code' => ['WW010', 'string'],
            'company_name' => ['SPRINGBOARD PH INC.', 'string'],
            'company_address' => ['UNIT 1203 SAMPLE TOWER, 88 SAMPLE AVE., BRGY. SAN ANTONIO, PASIG CITY', 'string'],
            'company_zip' => ['1605', 'string'],
            'company_contact_number' => ['(02) 8123-4567', 'string'],
            'company_email' => ['payroll@springboardph.com', 'string'],
            'agent_category' => ['private', 'string'],
            'employer_type' => ['main', 'string'],
        ];
        foreach ($settings as $key => [$value, $type]) {
            $existing = SystemSettings::where('key', $key)->first();
            SystemSettings::set($key, $value, $existing?->description, $type);
        }
    }

    private function employeesAndPayroll(): void
    {
        foreach (self::EMPLOYEES as $code => $e) {
            $employee = Employee::create([
                'employee_id' => $code,
                'first_name' => $e['first'],
                'last_name' => $e['last'],
                'email' => strtolower($e['first'] . '.' . $e['last']) . '@bir-demo.test',
                'phone' => $e['phone'],
                'position' => $e['position'],
                'department' => 'BIR Demo',
                'hire_date' => $e['hired'],
                'salary' => $e['monthly'],
                'rate_type' => 'monthly',
                'status' => isset($e['left']) ? 'inactive' : 'active',
                'tin_number' => $e['tin'],
                'notes' => 'BIR Form Assistant demo employee (made-up data).',
            ]);
            $this->ids[$code] = $employee->id;

            $this->payrollFor($employee, $e);
        }

        $mwe = collect(self::EMPLOYEES)->filter(fn ($e) => $e['mwe'] ?? false)->keys()->map(fn ($code) => $this->ids[$code])->values()->all();
        $existing = SystemSettings::where('key', 'bir_mwe_employee_ids')->first();
        SystemSettings::set('bir_mwe_employee_ids', $mwe, $existing?->description, 'json');
    }

    /**
     * Two cutoffs a month (1st-15th, 16th-end), 01/2025 to 09/2026, from the hire date to the
     * leaving date. All paid, except the last September cutoff (finalized) and one extra
     * September payroll left as a draft, which the 1601-C must leave out.
     */
    private function payrollFor(Employee $employee, array $e): void
    {
        $isMwe = $e['mwe'] ?? false;
        $ee = $this->employeeShare($e['monthly']);
        $withheldInYear = [];

        foreach ($this->cutoffs() as [$start, $end]) {
            if ($end < $e['hired'] || (isset($e['left']) && $start > $e['left'])) {
                continue;
            }

            $year = (int) substr($end, 0, 4);
            $month = (int) substr($end, 5, 2);
            $second = (int) substr($end, 8, 2) > 15;
            $basic = round($e['monthly'] / 2, 2);

            $allowances = [];
            foreach (self::EXTRAS as $kind => [$label, $share]) {
                if (in_array($month, $e[$kind] ?? [], true) && $second) {
                    $allowances[] = ['label' => $label, 'amount' => round($e['monthly'] * $share, 2)];
                }
            }
            if (isset($e['undeclared'])) {
                $allowances[] = ['label' => 'Allowance', 'amount' => (float) $e['undeclared']];
            }
            if ($year === 2025 && $month === 12 && $second) {
                $allowances[] = ['label' => '13th Month Pay', 'amount' => (float) $e['monthly']];
            }

            $deductions = [
                'SSS EE Contribution' => $ee['sss'],
                'PhilHealth EE Contribution' => $ee['philhealth'],
                'Pag-IBIG EE Contribution' => $ee['pagibig'],
            ];
            $attendance = 0.0;
            if (in_array($month, $e['late'] ?? [], true) && ! $second) {
                $deductions['Late'] = 250.00;
                $deductions['Absent'] = round($e['monthly'] / 26, 2);
                $attendance = $deductions['Late'] + $deductions['Absent'];
            }

            $gross = round($basic + array_sum(array_column($allowances, 'amount')), 2);
            $taxableThisCutoff = $this->taxablePart($basic, $allowances, $attendance, $ee, $isMwe);
            $tax = $this->withholding($e, $year, $month, $second, $taxableThisCutoff, $withheldInYear[$year] ?? 0.0);
            $withheldInYear[$year] = ($withheldInYear[$year] ?? 0.0) + $tax;
            if ($tax > 0) {
                $deductions['Withholding Tax'] = $tax;
            }

            $lastCutoff = $end === '2026-09-30';
            Payroll::create([
                'employee_id' => $employee->id,
                'cutoff_start' => $start,
                'cutoff_end' => $end,
                'base_salary' => $basic,
                'gross_pay' => $gross,
                'deductions' => $deductions,
                'allowances' => $allowances,
                'net_pay' => round($gross - array_sum($deductions), 2),
                'status' => $lastCutoff ? 'finalized' : 'paid',
                'processed_at' => $end . ' 18:00:00',
                'paid_at' => $lastCutoff ? null : $end . ' 18:00:00',
            ]);
        }

        // A payroll still being prepared: the 09/2026 1601-C must not count it.
        if ($employee->employee_id === 'BIR-001') {
            Payroll::create([
                'employee_id' => $employee->id, 'cutoff_start' => '2026-09-16', 'cutoff_end' => '2026-09-30',
                'base_salary' => 2500, 'gross_pay' => 2500, 'deductions' => [], 'allowances' => [['label' => 'Overtime Pay', 'amount' => 2500]],
                'net_pay' => 2500, 'status' => 'draft',
            ]);
        }
    }

    /** @return array<int, array{string, string}> */
    private function cutoffs(): array
    {
        $cutoffs = [];
        foreach ([2025 => 12, 2026 => 9] as $year => $last) {
            for ($m = 1; $m <= $last; $m++) {
                $first = sprintf('%d-%02d-01', $year, $m);
                $cutoffs[] = [$first, sprintf('%d-%02d-15', $year, $m)];
                $cutoffs[] = [sprintf('%d-%02d-16', $year, $m), date('Y-m-t', strtotime($first))];
            }
        }

        return $cutoffs;
    }

    /** The employee's SSS/PhilHealth/Pag-IBIG share per cutoff (simplified 2025 rates). */
    private function employeeShare(float $monthly): array
    {
        return [
            'sss' => round(min($monthly, 35000) * 0.05 / 2, 2),
            'philhealth' => round(min(max($monthly, 10000), 100000) * 0.025 / 2, 2),
            'pagibig' => 100.00,
        ];
    }

    /** What the tax is worked out on: pay less the employee's share; nothing for a minimum wage earner. */
    private function taxablePart(float $basic, array $allowances, float $attendance, array $ee, bool $isMwe): float
    {
        if ($isMwe) {
            return 0.0;
        }
        $taxed = $basic - $attendance;
        foreach ($allowances as $a) {
            $taxed += match ($a['label']) {
                'Allowance' => 0,                                            // undeclared, off the forms
                '13th Month Pay' => max(0, $a['amount'] - 90000),            // only the part over P90,000
                default => $a['amount'],
            };
        }

        return $taxed - array_sum($ee);
    }

    /**
     * Withholding for one cutoff from payroll's semi-monthly table, which already leaves out
     * anyone earning P250,000 a year or less. The last 2025 cutoff squares the year with the
     * annual tax table, the way payroll does its year-end adjustment, so a taxable employee's
     * 2316 tax withheld equals its tax due (anything over is refunded on the 2316 itself).
     */
    private function withholding(array $e, int $year, int $month, bool $second, float $taxable, float $withheldSoFar): float
    {
        if ($e['mwe'] ?? false) {
            return 0.0;
        }
        if ($year === 2025 && $month === 12 && $second) {
            return round(max(0, BirAggregationService::annualTaxDue($this->annualTaxable($e, $year)) - $withheldSoFar), 2);
        }

        return round(PayrollService::calculateWithholdingTax($taxable, 'semi_monthly'), 2);
    }

    /**
     * The year's taxable pay at this employer as the 2316 counts it, for the year-end
     * adjustment: basic pay before the SSS/PhilHealth/Pag-IBIG share (item 39).
     */
    private function annualTaxable(array $e, int $year): float
    {
        $taxable = 0.0;
        foreach ($this->cutoffs() as [$start, $end]) {
            if ((int) substr($end, 0, 4) !== $year || $end < $e['hired'] || (isset($e['left']) && $start > $e['left'])) {
                continue;
            }
            $month = (int) substr($end, 5, 2);
            $second = (int) substr($end, 8, 2) > 15;
            $taxable += $e['monthly'] / 2;
            foreach (self::EXTRAS as $kind => [, $share]) {
                if (in_array($month, $e[$kind] ?? [], true) && $second) {
                    $taxable += $e['monthly'] * $share;
                }
            }
            if (in_array($month, $e['late'] ?? [], true) && ! $second) {
                $taxable -= 250 + round($e['monthly'] / 26, 2);
            }
            if ($year === 2025 && $month === 12 && $second) {
                $taxable += max(0, $e['monthly'] - 90000);
            }
        }

        return round($taxable, 2);
    }

    // ── Forms, through the API ─────────────────────────────────────────────────────────

    private function forms1601C(): void
    {
        // Every question answered, so each box on the printed form has a value.
        $answers = [
            'sheets_attached' => 0, 'other_nontaxable_compensation' => '0.00', 'prior_month_adjustment' => '0.00',
            'other_remittances' => '0.00', 'surcharge' => '0.00', 'interest' => '0.00', 'compromise' => '0.00',
        ];
        // Months that also fill the less common boxes (items 4, 20, 26, 32 to 34).
        $extra = [
            '2026-01' => ['prior_month_adjustment' => '-150.00'],                                   // 26: a refund of tax withheld in 2025
            '2026-02' => ['surcharge' => '1250.00', 'interest' => '310.50', 'compromise' => '1000.00'], // 32-34: filed late
            '2026-04' => ['sheets_attached' => 1,                                                   // 4, 20
                'other_nontaxable_compensation' => '1500.00', 'other_nontaxable_compensation_desc' => 'RICE SUBSIDY'],
        ];

        // Filed: 12/2025 (13th month pay) and 01/2026 to 05/2026.
        $filed = [];
        foreach (['2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05'] as $period) {
            $filed[$period] = $this->filed1601C($period, ($extra[$period] ?? []) + $answers);
        }

        // 03/2026 amended: a revision of the filed form (version 2), still a draft. Revise
        // sets item 2 "Amended Return?" to Yes.
        $revision = $this->api('hr', 'POST', "/bir/drafts/{$filed['2026-03']['id']}/revise");
        $this->api('hr', 'PUT', "/bir/drafts/{$revision['id']}", ['fields' => [
            'previously_remitted_tax' => $filed['2026-03']['fields']['taxes_withheld_for_remittance']['value'],
            'prior_month_adjustment' => '150.00',
        ]]);

        // 06/2026 approved, waiting to be marked filed.
        $june = $this->create1601C('2026-06', $answers);
        $this->api('hr', 'POST', "/bir/drafts/{$june['id']}/submit");
        $this->api('accounting', 'POST', "/bir/drafts/{$june['id']}/approve");

        // 07/2026 submitted, waiting for accounting.
        $july = $this->create1601C('2026-07', ['other_remittances' => '500.00', 'other_remittances_desc' => 'ADVANCE PAYMENT 07/20/2026'] + $answers);
        $this->api('hr', 'POST', "/bir/drafts/{$july['id']}/submit");

        // 08/2026 returned by accounting with a reason.
        $august = $this->create1601C('2026-08', $answers);
        $this->api('hr', 'POST', "/bir/drafts/{$august['id']}/submit");
        $this->api('accounting', 'POST', "/bir/drafts/{$august['id']}/reject", [
            'reason' => 'Please confirm item 26: was there an adjustment from July? Resubmit once checked.',
        ]);

        // 09/2026 a fresh draft: a draft payroll is left out, and item 25 is changed by hand
        // (shows the replaced payroll figure and the payroll-mismatch warning).
        $september = $this->create1601C('2026-09', $answers);
        $withheld = (float) $september['fields']['total_taxes_withheld']['value'];
        $this->api('hr', 'PUT', "/bir/drafts/{$september['id']}", ['fields' => ['total_taxes_withheld' => number_format($withheld + 100, 2, '.', '')]]);
        $this->api('hr', 'POST', "/bir/drafts/{$september['id']}/validate");
    }

    private function create1601C(string $period, array $answers): array
    {
        $draft = $this->api('hr', 'POST', '/bir/drafts', ['form_type' => '1601-C', 'period' => $period]);

        return $answers ? $this->api('hr', 'PUT', "/bir/drafts/{$draft['id']}", ['fields' => $answers]) : $draft;
    }

    private function filed1601C(string $period, array $answers): array
    {
        $draft = $this->create1601C($period, $answers);
        $this->api('hr', 'POST', "/bir/drafts/{$draft['id']}/submit");
        $this->api('accounting', 'POST', "/bir/drafts/{$draft['id']}/approve");

        return $this->api('accounting', 'POST', "/bir/drafts/{$draft['id']}/finalize");
    }

    private function forms2316(): void
    {
        $person = fn (string $middle, string $rdo, string $address, string $zip, string $born) => [
            'employee_middle_name' => $middle, 'employee_rdo_code' => $rdo, 'employee_registered_address' => $address,
            'employee_registered_zip' => $zip, 'employee_birthdate' => $born,
        ];
        $signed = fn (string $date) => ['employer_signature_date' => $date, 'employee_signature_date' => $date];
        // Substituted filing (no box on the form): with a CTC (amount paid) or a valid ID (no amount).
        $substituted = fn (string $id, string $place, string $issued, ?string $paid = null) => [
            'is_substituted_filing' => true, 'employee_ctc_or_id' => $id, 'ctc_place_of_issue' => $place, 'ctc_date_issued' => $issued,
        ] + ($paid === null ? [] : ['ctc_amount_paid' => $paid]);
        $notSubstituted = ['is_substituted_filing' => false];

        // 2025, filed, under substituted filing (tax withheld equals tax due), with a CTC.
        $this->filed2316('BIR-001', '2025', $person('REYES', '047', 'BLK 5 LOT 12 SAMPAGUITA ST., BRGY. SANTOLAN, PASIG CITY', '1610', '1990-04-12')
            + $signed('2026-01-30') + $substituted('CTC 2026-0012345', 'PASIG CITY', '2026-01-08', '55.00'));

        // 2025, filed: minimum wage earner (rates per day and month; items 30-32), valid ID.
        $this->filed2316('BIR-004', '2025', $person('GARCIA', '043', '27 KAMIAS RD., BRGY. PINYAHAN, QUEZON CITY', '1100', '1998-09-03')
            + $signed('2026-01-30') + ['mwe_daily_rate' => '645.00', 'mwe_monthly_rate' => '16823.75']
            + $substituted('UMID 0111-2233445-6', 'QUEZON CITY', '2024-06-17'));

        // 2025, filed: the employee who later left (full year in 2025); lives away from the registered address.
        $this->filed2316('BIR-006', '2025', $person('DELA PAZ', '048', '14 MABINI ST., BRGY. POBLACION, MAKATI CITY', '1210', '1992-11-21')
            + ['employee_home_address' => '22 SAMPLE ST., BRGY. MALAMIG, MANDALUYONG CITY', 'employee_home_zip' => '1550']
            + $signed('2026-01-30') + $substituted('CTC 2026-0012346', 'MAKATI CITY', '2026-01-09', '120.00'));

        // 2025, approved, not filed yet: 13th month over P90,000 (item 48), a PERA tax credit (item 27),
        // a home address and a foreign address; files her own return, so not substituted.
        $bea = $this->create2316('BIR-002', '2025', $person('LIM', '050', '3 ACACIA LANE, BRGY. FORBES PARK, MAKATI CITY', '1219', '1985-02-14')
            + ['employee_home_address' => 'UNIT 9B THE GROVE, BRGY. UGONG, PASIG CITY', 'employee_home_zip' => '1604',
                'employee_foreign_address' => '10 SAMPLE ROAD #08-01, SINGAPORE 123456', 'pera_tax_credit' => '250.00']
            + $signed('2026-02-02') + $notSubstituted);
        $this->api('hr', 'POST', "/bir/drafts/{$bea['id']}/submit");
        $this->api('accounting', 'POST', "/bir/drafts/{$bea['id']}/approve");

        // 2025, submitted: P250,000 and below, no tax (items 29, 37).
        $carlo = $this->create2316('BIR-003', '2025', $person('AQUINO', '044', '8 RIZAL AVE., BRGY. 123, CALOOCAN CITY', '1400', '2001-07-30')
            + $signed('2026-01-30') + $substituted('CTC 2026-0012347', 'CALOOCAN CITY', '2026-01-12', '15.00'));
        $this->api('hr', 'POST', "/bir/drafts/{$carlo['id']}/submit");

        // 2025, draft: hired 03/16/2025 after another employer (items 16-18A, 22, 25B), and a foreign address.
        $this->create2316('BIR-008', '2025', $person('TAN', '039', '45 P. GOMEZ ST., BRGY. PLAINVIEW, MANDALUYONG CITY', '1550', '1995-05-05') + [
            'employee_foreign_address' => '200 SAMPLE AVE., UNIT 4, TORONTO, ON M5V 2T6, CANADA',
            'has_previous_employer' => true, 'previous_employer_tin' => '301-222-333-00000', 'previous_employer_name' => 'NORTHWIND TRADING CORP.',
            'previous_employer_address' => '100 SAMPLE BLVD., BRGY. BEL-AIR, MAKATI CITY', 'previous_employer_zip' => '1209',
            'taxable_income_previous_employer' => '95000.00', 'taxes_withheld_previous' => '0.00',
        ] + $signed('2026-02-05') + $notSubstituted);

        // 2025, draft: substituted filing with a driver's licence as the valid ID.
        $gino = $this->create2316('BIR-007', '2025', $person('SORIANO', '045', '9 IBA ST., BRGY. TATALON, QUEZON CITY', '1113', '1988-03-25')
            + $signed('2026-01-30') + $substituted("DRIVER'S LICENSE N01-23-456789", 'QUEZON CITY', '2023-08-01'));
        $this->api('hr', 'POST', "/bir/drafts/{$gino['id']}/validate");

        // 2026 so far: the leaver (period to 05/31), with taxable pay outside payroll in the
        // "Others" rows (items 44A/44B, 51A/51B).
        $this->create2316('BIR-006', '2026', $person('DELA PAZ', '048', '14 MABINI ST., BRGY. POBLACION, MAKATI CITY', '1210', '1992-11-21') + [
            'tax_others_44a_desc' => 'LEAVE CONVERSION', 'tax_others_44a_amount' => '9333.33',
            'tax_others_44b_desc' => 'RICE SUBSIDY (EXCESS)', 'tax_others_44b_amount' => '600.00',
            'tax_others_51a_desc' => 'PERFORMANCE INCENTIVE', 'tax_others_51a_amount' => '5000.00',
            'tax_others_51b_desc' => 'GIFT CERTIFICATE (EXCESS)', 'tax_others_51b_amount' => '1500.00',
        ] + $signed('2026-06-15') + $notSubstituted);

        // 2026 so far: the July hire, after another employer that withheld tax (items 16-18A, 22, 25B).
        $this->create2316('BIR-005', '2026', $person('CASTRO', '049', '31 SAMPLE ST., BRGY. KAPITOLYO, PASIG CITY', '1603', '1994-12-01') + [
            'has_previous_employer' => true, 'previous_employer_tin' => '302-333-444-00000', 'previous_employer_name' => 'BLUE HARBOR LOGISTICS INC.',
            'previous_employer_address' => '7 SAMPLE PIER RD., BRGY. 650, PORT AREA, MANILA', 'previous_employer_zip' => '1018',
            'taxable_income_previous_employer' => '180000.00', 'taxes_withheld_previous' => '4500.00',
        ] + $signed('2026-10-01') + $notSubstituted);
    }

    private function create2316(string $code, string $year, array $answers): array
    {
        $draft = $this->api('hr', 'POST', '/bir/drafts', ['form_type' => '2316', 'period' => $year, 'employee_id' => $this->ids[$code]]);

        return $this->api('hr', 'PUT', "/bir/drafts/{$draft['id']}", ['fields' => $answers + self::ZERO_2316]);
    }

    private function filed2316(string $code, string $year, array $answers): array
    {
        $draft = $this->create2316($code, $year, $answers);
        $this->api('hr', 'POST', "/bir/drafts/{$draft['id']}/submit");
        $this->api('accounting', 'POST', "/bir/drafts/{$draft['id']}/approve");

        return $this->api('accounting', 'POST', "/bir/drafts/{$draft['id']}/finalize");
    }

    /** One request through the app's own routes, as the given user. Stops the seeder on a refusal. */
    private function api(string $as, string $method, string $uri, array $data = []): array
    {
        $request = Request::create('/api' . $uri, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokens[$as],
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($data));

        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        app('auth')->forgetGuards();

        $body = json_decode($response->getContent(), true) ?? [];
        if ($response->getStatusCode() >= 400) {
            $detail = $body['errors'] ?? array_map(fn ($e) => "{$e['field']}: {$e['message']}", array_filter(
                $body['data']['validation_errors'] ?? [], fn ($e) => $e['severity'] === 'error'));
            throw new RuntimeException("{$method} {$uri} as {$as}: {$response->getStatusCode()} {$body['message']} " . json_encode($detail));
        }

        return $body['data'] ?? [];
    }
}
