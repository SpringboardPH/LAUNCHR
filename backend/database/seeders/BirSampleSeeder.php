<?php

namespace Database\Seeders;

use App\Models\BirFormDraft;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalance;
use App\Models\EmployeeSchedule;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\ScheduleTemplate;
use App\Models\SystemSettings;
use App\Models\User;
use App\Services\BIR\BirAggregationService;
use App\Services\BIR\BirSettingsService;
use App\Services\PayrollService;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A small test set for the BIR Form Assistant: three made-up employees, one of each kind, each
 * with a full profile (login, government numbers, bank account, schedule, leave balances), a
 * full year of paid payroll (01/2025 to 12/2025) and a 2025 2316 draft with every answer filled
 * in. No 1601-C is made, so the 1601-Cs for 01-12/2025 can be created and checked by hand.
 *
 *   TST-001  taxable: over P250,000 a year (1601-C item 22, 2316 items 39/50)
 *   TST-002  not taxable: not a minimum wage earner, but P250,000 or less a year (1601-C item 23, 2316 item 29)
 *   TST-003  minimum wage earner (1601-C items 15/16, 2316 items 29-32)
 *
 * Every name, number and address here is invented. Company settings are only filled in where
 * they are still empty or SystemSettingsSeeder's placeholder. Safe to run again: it first
 * removes TST-001 to TST-003, their logins, payroll and 2316s. The 1601-C adds up every
 * employee's payroll, so for these three alone use a fresh database. Logins: hr@ and
 * accounting@springboardph.com and each employee's email, password "password".
 *
 *   php artisan db:seed --class=BirSampleSeeder
 */
class BirSampleSeeder extends Seeder
{
    private const YEAR = 2025;

    private const EMAIL_DOMAIN = '@bir-sample.test';

    /**
     * monthly: basic pay a month (13th month pay in December is one month's pay); holiday/overtime/night:
     * months with that extra pay. middle, rdo, address, zip, born: the 2316 answers about the employee.
     */
    private const EMPLOYEES = [
        'TST-001' => ['first' => 'Teresa', 'middle' => 'DIAZ', 'last' => 'Go', 'position' => 'Accountant', 'department' => 'Finance', 'monthly' => 35000,
            'tin' => '301-111-222-00000', 'sss' => '34-1110001-1', 'philhealth' => '12-111000001-1', 'pagibig' => '1210-1001-2001', 'bank' => '200000000001',
            'phone' => '09171239001', 'rdo' => '047', 'address' => '12 SAMPLE ST., BRGY. SANTOLAN, PASIG CITY', 'zip' => '1610', 'born' => '1990-04-12',
            'overtime' => [3, 10]],
        'TST-002' => ['first' => 'Noel', 'middle' => 'CRUZ', 'last' => 'Tan', 'position' => 'Office Assistant', 'department' => 'Administration', 'monthly' => 18000,
            'tin' => '302-222-333-00000', 'sss' => '34-1110002-2', 'philhealth' => '12-111000002-2', 'pagibig' => '1210-1002-2002', 'bank' => '200000000002',
            'phone' => '09171239002', 'rdo' => '044', 'address' => '8 SAMPLE AVE., BRGY. 123, CALOOCAN CITY', 'zip' => '1400', 'born' => '2001-07-30',
            'holiday' => [6]],
        'TST-003' => ['first' => 'Mina', 'middle' => 'REYES', 'last' => 'Lim', 'position' => 'Warehouse Staff', 'department' => 'Administration', 'monthly' => 16823.75,
            'tin' => '303-333-444-00000', 'sss' => '34-1110003-3', 'philhealth' => '12-111000003-3', 'pagibig' => '1210-1003-2003', 'bank' => '200000000003',
            'phone' => '09171239003', 'rdo' => '043', 'address' => '27 SAMPLE RD., BRGY. PINYAHAN, QUEZON CITY', 'zip' => '1100', 'born' => '1998-09-03',
            'mwe' => ['daily' => '645.00'], 'holiday' => [6], 'overtime' => [4], 'night' => [9]],
    ];

    /** Extra pay in the listed months (second cutoff): kind => [payroll label, share of the monthly pay]. */
    private const EXTRAS = [
        'holiday' => ['Special Holiday', 0.10],
        'overtime' => ['Overtime Pay', 0.10],
        'night' => ['Night Differential', 0.05],
    ];

    private const COMPANY = [
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

    /** 2316 amounts with nothing to report, entered as 0.00 so every amount box on the form is filled. */
    private const ZERO_2316 = [
        'taxable_income_previous_employer' => '0.00', 'taxes_withheld_previous' => '0.00', 'pera_tax_credit' => '0.00',
        'tax_others_44a_amount' => '0.00', 'tax_others_44b_amount' => '0.00', 'tax_others_51a_amount' => '0.00', 'tax_others_51b_amount' => '0.00',
    ];

    /** @var array<string, int> code => employees.id */
    private array $ids = [];

    private string $token = '';

    public function run(): void
    {
        $hr = User::firstOrCreate(['email' => 'hr@springboardph.com'], ['name' => 'HR Officer', 'password' => 'password', 'role' => 'hr']);
        User::firstOrCreate(['email' => 'accounting@springboardph.com'], ['name' => 'Accounting Officer', 'password' => 'password', 'role' => 'accounting']);

        DB::transaction(function () {
            $old = $this->clearPreviousRun();

            $switch = SystemSettings::where('key', 'bir_forms_enabled')->first();
            SystemSettings::set('bir_forms_enabled', 'true', $switch?->description, 'boolean');
            // The settings that are really set (not empty, not SystemSettingsSeeder's placeholder), by name.
            $set = BirSettingsService::values('1601-C') + BirSettingsService::values('2316');
            foreach (self::COMPANY as $key => [$value, $type]) {
                if (! isset($set[$key])) {
                    $existing = SystemSettings::where('key', $key)->first();
                    SystemSettings::set($key, $value, $existing?->description, $type);
                }
            }

            $mwe = [];
            foreach (self::EMPLOYEES as $code => $e) {
                $employee = $this->employee($code, $e);
                $this->ids[$code] = $employee->id;
                $this->payrollFor($employee, $e);
                if (isset($e['mwe'])) {
                    $mwe[] = $employee->id;
                }
            }

            // Keep anyone else on the minimum wage list; drop the previous run's ids.
            $list = array_map('intval', (array) SystemSettings::get('bir_mwe_employee_ids', []));
            $existing = SystemSettings::where('key', 'bir_mwe_employee_ids')->first();
            SystemSettings::set('bir_mwe_employee_ids', array_values(array_merge(array_diff($list, $old), $mwe)), $existing?->description, 'json');
        });

        $this->token = $hr->createToken('bir-sample-seeder')->plainTextToken;
        try {
            foreach (self::EMPLOYEES as $code => $e) {
                $this->draft2316($code, $e);
            }
        } finally {
            $hr->tokens()->where('name', 'bir-sample-seeder')->delete();
        }

        $this->command?->info('BIR sample data ready: TST-001 (taxable), TST-002 (not taxable), TST-003 (minimum wage), '
            . 'payroll 01/2025 to 12/2025 and a 2025 2316 draft each.');
    }

    /** @return array<int, int> the removed employees' ids */
    private function clearPreviousRun(): array
    {
        $old = Employee::withTrashed()->where('employee_id', 'like', 'TST-%')->pluck('id')->all();

        // Revisions point at their parent, so the highest versions go first. Removed for good, not
        // soft-deleted: a deleted correction still counts toward the amendment limit.
                $forms = BirFormDraft::everything()->whereIn('employee_id', $old)->with('signatures')->orderByDesc('version')->get();
        // A signature's image is a file on the private disk: its row goes with the form or login, the file doesn't.
        Storage::disk('local')->delete($forms->flatMap->signatures->pluck('image_path')->all());
        $forms->each->forceDelete();
        Payroll::whereIn('employee_id', $old)->delete();
        Employee::withTrashed()->whereIn('id', $old)->forceDelete(); // schedules and leave balances go with it
        User::withTrashed()->where('email', 'like', '%' . self::EMAIL_DOMAIN)->get()->each(function (User $user) {
            Storage::disk('local')->delete($user->signature()->pluck('path')->all());
            $user->tokens()->delete();
            $user->forceDelete();
        });

        return $old;
    }

    /** The employee with a login, every profile field, the standard schedule and leave balances. */
    private function employee(string $code, array $e): Employee
    {
        $email = strtolower($e['first'] . '.' . $e['last']) . self::EMAIL_DOMAIN;
        $user = User::create(['name' => $e['first'] . ' ' . $e['last'], 'email' => $email, 'password' => 'password', 'role' => 'employee']);

        $employee = Employee::create([
            'user_id' => $user->id,
            'employee_id' => $code,
            'first_name' => $e['first'],
            'last_name' => $e['last'],
            'email' => $email,
            'phone' => $e['phone'],
            'position' => $e['position'],
            'department' => $e['department'],
            'hire_date' => '2024-01-15',
            'salary' => $e['monthly'],
            'undeclared_salary' => 0,
            'rate_type' => 'monthly',
            'status' => 'active',
            'notes' => 'BIR Form Assistant sample employee (made-up data).',
            'bank_account_number' => $e['bank'],
            'sss_number' => $e['sss'],
            'philhealth_number' => $e['philhealth'],
            'pagibig_number' => $e['pagibig'],
            'tin_number' => $e['tin'],
        ]);

        // Schedule templates and leave types come from DatabaseSeeder; skipped if it hasn't run.
        $template = ScheduleTemplate::where('name', 'Standard 9-6 (Mon-Fri)')->first();
        if ($template) {
            EmployeeSchedule::create(['employee_id' => $employee->id, 'schedule_template_id' => $template->id,
                'start_date' => '2024-01-15', 'end_date' => '2026-12-31', 'status' => 'active']);
        }
        foreach (LeaveType::where('requires_balance', true)->get() as $type) {
            EmployeeLeaveBalance::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id,
                'allocated_days' => $type->default_days, 'carryover_days' => 0, 'is_active' => true]);
        }

        return $employee;
    }

    /**
     * Two paid cutoffs a month (1st-15th, 16th-end). Tax is withheld from payroll's
     * semi-monthly table; the last cutoff squares the year with the annual tax table, the way
     * payroll does its year-end adjustment, so the 2316 tax withheld equals its tax due.
     */
    private function payrollFor(Employee $employee, array $e): void
    {
        $isMwe = isset($e['mwe']);
        $ee = [
            'SSS EE Contribution' => round(min($e['monthly'], 35000) * 0.05 / 2, 2),
            'PhilHealth EE Contribution' => round(min(max($e['monthly'], 10000), 100000) * 0.025 / 2, 2),
            'Pag-IBIG EE Contribution' => 100.00,
        ];
        $taxableYear = 0.0;
        $withheld = 0.0;

        for ($month = 1; $month <= 12; $month++) {
            $first = sprintf('%d-%02d-01', self::YEAR, $month);
            $cutoffs = [[$first, sprintf('%d-%02d-15', self::YEAR, $month)], [sprintf('%d-%02d-16', self::YEAR, $month), date('Y-m-t', strtotime($first))]];

            foreach ($cutoffs as $i => [$start, $end]) {
                $second = $i === 1;
                $basic = round($e['monthly'] / 2, 2);
                $allowances = [];
                foreach (self::EXTRAS as $kind => [$label, $share]) {
                    if ($second && in_array($month, $e[$kind] ?? [], true)) {
                        $allowances[] = ['label' => $label, 'amount' => round($e['monthly'] * $share, 2)];
                    }
                }
                $thirteenth = $second && $month === 12 ? (float) $e['monthly'] : 0.0; // under P90,000, so none of it is taxed
                if ($thirteenth > 0) {
                    $allowances[] = ['label' => '13th Month Pay', 'amount' => $thirteenth];
                }

                $gross = round($basic + array_sum(array_column($allowances, 'amount')), 2);
                // Before the SSS/PhilHealth/Pag-IBIG share, as 2316 item 39 counts it.
                $taxableYear += $gross - $thirteenth;
                $tax = round(match (true) {
                    $isMwe => 0.0,
                    $second && $month === 12 => max(0.0, BirAggregationService::annualTaxDue($taxableYear) - $withheld),
                    default => PayrollService::calculateWithholdingTax($gross - array_sum($ee), 'semi_monthly'),
                }, 2);
                $withheld += $tax;

                $deductions = $ee + ($tax > 0 ? ['Withholding Tax' => $tax] : []);
                Payroll::create([
                    'employee_id' => $employee->id,
                    'cutoff_start' => $start,
                    'cutoff_end' => $end,
                    'base_salary' => $basic,
                    'gross_pay' => $gross,
                    'deductions' => $deductions,
                    'allowances' => $allowances,
                    'net_pay' => round($gross - array_sum($deductions), 2),
                    'status' => 'paid',
                    'processed_at' => $end . ' 18:00:00',
                    'paid_at' => $end . ' 18:00:00',
                ]);
            }
        }
    }

    /** The 2025 2316 as a draft, every answer filled in (substituted filing with a CTC), then validated. */
    private function draft2316(string $code, array $e): void
    {
        $answers = [
            'employee_middle_name' => $e['middle'], 'employee_rdo_code' => $e['rdo'], 'employee_registered_address' => $e['address'],
            'employee_registered_zip' => $e['zip'], 'employee_birthdate' => $e['born'],
            'employer_signature_date' => '2026-01-30', 'employee_signature_date' => '2026-01-30',
            'is_substituted_filing' => true, 'employee_ctc_or_id' => 'CTC 2026-00' . substr($code, -3) . '01',
            'ctc_place_of_issue' => 'PASIG CITY', 'ctc_date_issued' => '2026-01-08', 'ctc_amount_paid' => '50.00',
        ] + (isset($e['mwe']) ? ['mwe_daily_rate' => $e['mwe']['daily'], 'mwe_monthly_rate' => number_format($e['monthly'], 2, '.', '')] : []);

        $draft = $this->api('POST', '/bir/drafts', ['form_type' => '2316', 'period' => (string) self::YEAR, 'employee_id' => $this->ids[$code]]);
        $this->api('PUT', "/bir/drafts/{$draft['id']}", ['fields' => $answers + self::ZERO_2316]);
        $this->api('POST', "/bir/drafts/{$draft['id']}/validate");
    }

    /** One request through the app's own routes, as HR. Stops the seeder on a refusal. */
    private function api(string $method, string $uri, array $data = []): array
    {
        $request = Request::create('/api' . $uri, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($data));

        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        app('auth')->forgetGuards();

        $body = json_decode($response->getContent(), true) ?? [];
        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException("{$method} {$uri}: {$response->getStatusCode()} " . ($body['message'] ?? '') . ' ' . json_encode($body['errors'] ?? []));
        }

        return $body['data'] ?? [];
    }
}
