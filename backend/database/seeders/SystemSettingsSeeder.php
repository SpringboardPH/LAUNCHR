<?php

namespace Database\Seeders;

use App\Models\SystemSettings;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key' => 'clock_in_start',
                'value' => '08:45:00',
                'description' => 'Time when employees can start clocking in',
                'type' => 'string',
            ],
            [
                'key' => 'clock_in_end',
                'value' => '18:15:00',
                'description' => 'Time when clock in window closes',
                'type' => 'string',
            ],
            [
                'key' => 'clock_out_start',
                'value' => '18:00:00',
                'description' => 'Time when employees can start clocking out',
                'type' => 'string',
            ],
            [
                'key' => 'clock_out_end',
                'value' => '18:15:00',
                'description' => 'Time when clock out window closes',
                'type' => 'string',
            ],
            [
                'key' => 'work_start_time',
                'value' => '09:00:00',
                'description' => 'Official work day start time (used for late calculation)',
                'type' => 'string',
            ],
            [
                'key' => 'work_end_time',
                'value' => '18:00:00',
                'description' => 'Official work day end time',
                'type' => 'string',
            ],
            [
                'key' => 'late_threshold_minutes',
                'value' => '0',
                'description' => 'Minutes after work start time that marks an arrival as late',
                'type' => 'integer',
            ],
            [
                'key' => 'required_hours_per_day',
                'value' => '9',
                'description' => 'Minimum hours required per day',
                'type' => 'integer',
            ],
            [
                'key' => 'work_days',
                'value' => json_encode([1, 2, 3, 4, 5]),
                'description' => 'Work days (1=Mon, 2=Tue, ..., 7=Sun)',
                'type' => 'json',
            ],
            [
                'key' => 'overtime_threshold_hours',
                'value' => '9',
                'description' => 'Hours worked that counts as overtime',
                'type' => 'integer',
            ],
            [
                'key' => 'absent_marking_time',
                'value' => '00:00',
                'description' => 'Time when the system automatically marks employees as absent (HH:MM)',
                'type' => 'string',
            ],
            [
                'key' => 'leave_include_weekends',
                'value' => 'false',
                'description' => 'Whether leave date ranges count Saturdays and Sundays',
                'type' => 'boolean',
            ],
            [
                'key' => 'theme_color',
                'value' => 'sienna',
                'description' => 'System theme color preset (green, blue, purple, sienna, rose)',
                'type' => 'string',
            ],
            [
                'key' => 'system_name',
                'value' => 'LAUNCHR',
                'description' => 'The name of the system displayed in the sidebar',
                'type' => 'string',
            ],
            [
                'key' => 'system_logo',
                'value' => 'launchr_black.svg',
                'description' => 'The logo used by the system',
                'type' => 'string',
            ],
            [
                'key' => 'payroll_template',
                'value' => 'payrolltemplate.xlsx',
                'description' => 'The Excel template used for payroll generation',
                'type' => 'string',
            ],
            [
                'key' => 'sss_contribution_table',
                'value' => json_encode([
                    ['min' => 0, 'max' => 5249.99, 'msc' => 5000, 'ee' => 250, 'er' => 510],
                    ['min' => 5250, 'max' => 5749.99, 'msc' => 5500, 'ee' => 275, 'er' => 560],
                    ['min' => 5750, 'max' => 6249.99, 'msc' => 6000, 'ee' => 300, 'er' => 610],
                    ['min' => 6250, 'max' => 6749.99, 'msc' => 6500, 'ee' => 325, 'er' => 660],
                    ['min' => 6750, 'max' => 7249.99, 'msc' => 7000, 'ee' => 350, 'er' => 710],
                    ['min' => 7250, 'max' => 7749.99, 'msc' => 7500, 'ee' => 375, 'er' => 760],
                    ['min' => 7750, 'max' => 8249.99, 'msc' => 8000, 'ee' => 400, 'er' => 810],
                    ['min' => 8250, 'max' => 8749.99, 'msc' => 8500, 'ee' => 425, 'er' => 860],
                    ['min' => 8750, 'max' => 9249.99, 'msc' => 9000, 'ee' => 450, 'er' => 910],
                    ['min' => 9250, 'max' => 9749.99, 'msc' => 9500, 'ee' => 475, 'er' => 960],
                    ['min' => 9750, 'max' => 10249.99, 'msc' => 10000, 'ee' => 500, 'er' => 1010],
                    ['min' => 10250, 'max' => 10749.99, 'msc' => 10500, 'ee' => 525, 'er' => 1060],
                    ['min' => 10750, 'max' => 11249.99, 'msc' => 11000, 'ee' => 550, 'er' => 1110],
                    ['min' => 11250, 'max' => 11749.99, 'msc' => 11500, 'ee' => 575, 'er' => 1160],
                    ['min' => 11750, 'max' => 12249.99, 'msc' => 12000, 'ee' => 600, 'er' => 1210],
                    ['min' => 12250, 'max' => 12749.99, 'msc' => 12500, 'ee' => 625, 'er' => 1260],
                    ['min' => 12750, 'max' => 13249.99, 'msc' => 13000, 'ee' => 650, 'er' => 1310],
                    ['min' => 13250, 'max' => 13749.99, 'msc' => 13500, 'ee' => 675, 'er' => 1360],
                    ['min' => 13750, 'max' => 14249.99, 'msc' => 14000, 'ee' => 700, 'er' => 1410],
                    ['min' => 14250, 'max' => 14749.99, 'msc' => 14500, 'ee' => 725, 'er' => 1460],
                    ['min' => 14750, 'max' => 15249.99, 'msc' => 15000, 'ee' => 750, 'er' => 1530],
                    ['min' => 15250, 'max' => 15749.99, 'msc' => 15500, 'ee' => 775, 'er' => 1580],
                    ['min' => 15750, 'max' => 16249.99, 'msc' => 16000, 'ee' => 800, 'er' => 1630],
                    ['min' => 16250, 'max' => 16749.99, 'msc' => 16500, 'ee' => 825, 'er' => 1680],
                    ['min' => 16750, 'max' => 17249.99, 'msc' => 17000, 'ee' => 850, 'er' => 1730],
                    ['min' => 17250, 'max' => 17749.99, 'msc' => 17500, 'ee' => 875, 'er' => 1780],
                    ['min' => 17750, 'max' => 18249.99, 'msc' => 18000, 'ee' => 900, 'er' => 1830],
                    ['min' => 18250, 'max' => 18749.99, 'msc' => 18500, 'ee' => 925, 'er' => 1880],
                    ['min' => 18750, 'max' => 19249.99, 'msc' => 19000, 'ee' => 950, 'er' => 1930],
                    ['min' => 19250, 'max' => 19749.99, 'msc' => 19500, 'ee' => 975, 'er' => 1980],
                    ['min' => 19750, 'max' => 20249.99, 'msc' => 20000, 'ee' => 1000, 'er' => 2030],
                    ['min' => 20250, 'max' => 20749.99, 'msc' => 20500, 'ee' => 1025, 'er' => 2080],
                    ['min' => 20750, 'max' => 21249.99, 'msc' => 21000, 'ee' => 1050, 'er' => 2130],
                    ['min' => 21250, 'max' => 21749.99, 'msc' => 21500, 'ee' => 1075, 'er' => 2180],
                    ['min' => 21750, 'max' => 22249.99, 'msc' => 22000, 'ee' => 1100, 'er' => 2230],
                    ['min' => 22250, 'max' => 22749.99, 'msc' => 22500, 'ee' => 1125, 'er' => 2280],
                    ['min' => 22750, 'max' => 23249.99, 'msc' => 23000, 'ee' => 1150, 'er' => 2330],
                    ['min' => 23250, 'max' => 23749.99, 'msc' => 23500, 'ee' => 1175, 'er' => 2380],
                    ['min' => 23750, 'max' => 24249.99, 'msc' => 24000, 'ee' => 1200, 'er' => 2430],
                    ['min' => 24250, 'max' => 24749.99, 'msc' => 24500, 'ee' => 1225, 'er' => 2480],
                    ['min' => 24750, 'max' => 25249.99, 'msc' => 25000, 'ee' => 1275, 'er' => 2530],
                    ['min' => 25250, 'max' => 25749.99, 'msc' => 25500, 'ee' => 1300, 'er' => 2580],
                    ['min' => 25750, 'max' => 26249.99, 'msc' => 26000, 'ee' => 1325, 'er' => 2630],
                    ['min' => 26250, 'max' => 26749.99, 'msc' => 26500, 'ee' => 1350, 'er' => 2680],
                    ['min' => 26750, 'max' => 27249.99, 'msc' => 27000, 'ee' => 1375, 'er' => 2730],
                    ['min' => 27250, 'max' => 27749.99, 'msc' => 27500, 'ee' => 1400, 'er' => 2780],
                    ['min' => 27750, 'max' => 28249.99, 'msc' => 28000, 'ee' => 1425, 'er' => 2830],
                    ['min' => 28250, 'max' => 28749.99, 'msc' => 28500, 'ee' => 1450, 'er' => 2880],
                    ['min' => 28750, 'max' => 29249.99, 'msc' => 29000, 'ee' => 1475, 'er' => 2930],
                    ['min' => 29250, 'max' => 29749.99, 'msc' => 29500, 'ee' => 1500, 'er' => 2980],
                    ['min' => 29750, 'max' => 30249.99, 'msc' => 30000, 'ee' => 1525, 'er' => 3030],
                    ['min' => 30250, 'max' => 30749.99, 'msc' => 30500, 'ee' => 1550, 'er' => 3080],
                    ['min' => 30750, 'max' => 31249.99, 'msc' => 31000, 'ee' => 1575, 'er' => 3130],
                    ['min' => 31250, 'max' => 31749.99, 'msc' => 31500, 'ee' => 1600, 'er' => 3180],
                    ['min' => 31750, 'max' => 32249.99, 'msc' => 32000, 'ee' => 1625, 'er' => 3230],
                    ['min' => 32250, 'max' => 32749.99, 'msc' => 32500, 'ee' => 1650, 'er' => 3280],
                    ['min' => 32750, 'max' => 33249.99, 'msc' => 33000, 'ee' => 1675, 'er' => 3330],
                    ['min' => 33250, 'max' => 33749.99, 'msc' => 33500, 'ee' => 1700, 'er' => 3380],
                    ['min' => 33750, 'max' => 34249.99, 'msc' => 34000, 'ee' => 1725, 'er' => 3430],
                    ['min' => 34250, 'max' => 34749.99, 'msc' => 34500, 'ee' => 1750, 'er' => 3480],
                    ['min' => 34750, 'max' => null, 'msc' => 35000, 'ee' => 1750, 'er' => 3530],
                ]),
                'description' => 'SSS Employee and Employer Contribution Table',
                'type' => 'json',
            ],
            [
                'key' => 'withholding_tax_table',
                'value' => json_encode([
                    'semi_monthly' => [
                        ['from' => 0,         'to' => 10417,  'fixed' => 0,        'rate' => 0,    'floor' => 0],
                        ['from' => 10417.01,  'to' => 16666,  'fixed' => 0,        'rate' => 0.15, 'floor' => 10417],
                        ['from' => 16667,     'to' => 33332,  'fixed' => 937.50,   'rate' => 0.20, 'floor' => 16667],
                        ['from' => 33333,     'to' => 83332,  'fixed' => 4270.70,  'rate' => 0.25, 'floor' => 33333],
                        ['from' => 83333,     'to' => 333332, 'fixed' => 16770.70, 'rate' => 0.30, 'floor' => 83333],
                        ['from' => 333333,    'to' => null,   'fixed' => 91770.70, 'rate' => 0.35, 'floor' => 333333],
                    ],
                    'monthly' => [
                        ['from' => 0,         'to' => 20833,  'fixed' => 0,         'rate' => 0,    'floor' => 0],
                        ['from' => 20833.01,  'to' => 33332,  'fixed' => 0,         'rate' => 0.15, 'floor' => 20833],
                        ['from' => 33333,     'to' => 66666,  'fixed' => 1875.00,   'rate' => 0.20, 'floor' => 33333],
                        ['from' => 66667,     'to' => 166666, 'fixed' => 8541.80,   'rate' => 0.25, 'floor' => 66667],
                        ['from' => 166667,    'to' => 666666, 'fixed' => 33541.80,  'rate' => 0.30, 'floor' => 166667],
                        ['from' => 666667,    'to' => null,   'fixed' => 183541.80, 'rate' => 0.35, 'floor' => 666667],
                    ],
                ]),
                'description' => 'BIR Withholding Tax Brackets — TRAIN Law RA 10963 RR 8-2018',
                'type' => 'json',
            ],
            [
                'key' => 'auto_clock_out_enabled',
                'value' => 'false',
                'description' => 'Whether automatic clock-out is enabled',
                'type' => 'boolean',
            ],
            [
                'key' => 'login_otp_required',
                'value' => 'false',
                'description' => 'Whether an email OTP is required to log in',
                'type' => 'boolean',
            ],
            ['key' => 'payroll_frequency',         'value' => 'semi_monthly', 'description' => 'Payroll cycle: semi_monthly or monthly',                                    'type' => 'string'],
            ['key' => 'payroll_period1_start_day', 'value' => '11',           'description' => 'Semi-monthly: start day of first period',                                   'type' => 'integer'],
            ['key' => 'payroll_period1_end_day',   'value' => '25',           'description' => 'Semi-monthly: end day of first period',                                     'type' => 'integer'],
            ['key' => 'payroll_period2_start_day', 'value' => '26',           'description' => 'Semi-monthly: start day of second period',                                  'type' => 'integer'],
            ['key' => 'payroll_period2_end_day',   'value' => '10',           'description' => 'Semi-monthly: end day of second period (cross-month: end < start)',         'type' => 'integer'],
            ['key' => 'payroll_monthly_start_day', 'value' => '1',            'description' => 'Monthly: start day of the payroll period',                                  'type' => 'integer'],
            ['key' => 'payroll_monthly_end_day',   'value' => '31',           'description' => 'Monthly: end day of the payroll period (31 = end of month)',                'type' => 'integer'],
        ];

        foreach ($settings as $setting) {
            SystemSettings::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => $setting['value'],
                    'description' => $setting['description'],
                    'type' => $setting['type'],
                ]
            );
        }
    }
}
