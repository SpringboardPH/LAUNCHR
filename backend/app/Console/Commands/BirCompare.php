<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\BIR\BirAggregationService;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Console\Command;

/**
 * Week 4 comparison: LAUNCHR's figures for a period, item by item, beside the
 * figures on a form that was actually filed.
 *
 *   php artisan bir:compare 1601-C 2025-08 --template          blank sheet to copy the filed figures into
 *   php artisan bir:compare 1601-C 2025-08 --filed=filed.csv   compare, and write a report with a cause column
 *   php artisan bir:compare 2316 2025 --employee=EMP003 ...    same for one employee's 2316
 *
 * Only payroll-derived money fields are compared — settings and user fields
 * aren't calculated, so there is nothing to check.
 */
class BirCompare extends Command
{
    protected $signature = 'bir:compare {form : 1601-C or 2316} {period : YYYY-MM for 1601-C, YYYY for 2316}
        {--employee= : 2316 only — employee code (EMP003) or id}
        {--template : write a blank sheet for the filed figures instead of comparing}
        {--filed= : CSV with the filed figures (the --template sheet, filled in)}';

    protected $description = 'Compare LAUNCHR BIR figures against a filed form, item by item';

    /** Differences under half a centavo are rounding, not a mismatch. */
    private const TOLERANCE = 0.005;

    public function handle(): int
    {
        $form = strtoupper((string) $this->argument('form'));
        $period = (string) $this->argument('period');

        if (!$this->validInput($form, $period)) {
            return self::FAILURE;
        }

        $employee = null;
        if ($form === Form2316Schema::FORM_TYPE) {
            $employee = $this->findEmployee((string) $this->option('employee'));
            if (!$employee) {
                return self::FAILURE;
            }
        }

        $generated = $form === Form1601CSchema::FORM_TYPE
            ? BirAggregationService::monthlyWithholding((int) substr($period, 0, 4), (int) substr($period, 5, 2))
            : BirAggregationService::annualCompensation($employee->id, (int) $period);

        $fields = $this->comparedFields($form);
        $name = $this->baseName($form, $period, $employee);

        if ($this->option('template')) {
            $path = $this->writeCsv("{$name}-filed.csv", ['item', 'key', 'label', 'filed'], array_map(
                fn (array $f) => [$f['item'] ?? '', $f['key'], $f['label'], ''],
                $fields,
            ));
            $this->info("Template written to {$path}");
            $this->line('Fill in the "filed" column from the filed form, then run again with --filed=<that file>.');
            return self::SUCCESS;
        }

        if (!$this->option('filed')) {
            $this->error('Pass --template to get a blank sheet, or --filed=<csv> to compare.');
            return self::FAILURE;
        }

        $filed = $this->readFiled((string) $this->option('filed'));
        if ($filed === null) {
            return self::FAILURE;
        }

        $rows = [];
        $counts = ['match' => 0, 'DIFFERS' => 0, 'not filed' => 0];
        foreach ($fields as $f) {
            $ours = (float) $generated[$f['key']];
            $theirs = $filed[$f['key']] ?? null;
            $difference = $theirs === null ? null : round($ours - $theirs, 2);
            $status = match (true) {
                $theirs === null => 'not filed',
                abs($difference) < self::TOLERANCE => 'match',
                default => 'DIFFERS',
            };
            $counts[$status]++;

            $rows[] = [
                $f['item'] ?? '',
                $f['label'],
                $this->money($ours),
                $theirs === null ? '' : $this->money($theirs),
                $difference === null || $status === 'match' ? '' : $this->money($difference),
                $status,
            ];
        }

        $this->table(['Item', 'Label', 'LAUNCHR', 'Filed', 'Difference', 'Status'], array_map(
            fn (array $r) => [$r[0], mb_strimwidth($r[1], 0, 48, '…'), ...array_slice($r, 2)],
            $rows,
        ));
        $this->line(sprintf('%d match, %d differ, %d not filed.', $counts['match'], $counts['DIFFERS'], $counts['not filed']));

        foreach ($generated['_meta']['warnings'] ?? [] as $warning) {
            $this->warn($warning);
        }

        $path = $this->writeCsv("{$name}-comparison.csv",
            ['item', 'label', 'launchr', 'filed', 'difference', 'status', 'cause', 'explanation'],
            array_map(fn (array $r) => [...$r, '', ''], $rows),
        );
        $this->info("Report written to {$path}");
        if ($counts['DIFFERS'] > 0) {
            $this->line('For each DIFFERS row, fill in cause (logic error / missed rule / payroll data) and a one-line explanation.');
        }

        return self::SUCCESS;
    }

    private function validInput(string $form, string $period): bool
    {
        $ok = match ($form) {
            Form1601CSchema::FORM_TYPE => (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period),
            Form2316Schema::FORM_TYPE => (bool) preg_match('/^\d{4}$/', $period),
            default => null,
        };

        if ($ok === null) {
            $this->error("Unknown form '{$form}'. Use 1601-C or 2316.");
        } elseif (!$ok) {
            $this->error($form === Form1601CSchema::FORM_TYPE ? 'A 1601-C period is YYYY-MM.' : 'A 2316 period is YYYY.');
        }

        return (bool) $ok;
    }

    private function findEmployee(string $ref): ?Employee
    {
        if ($ref === '') {
            $this->error('A 2316 needs --employee=<code or id>.');
            return null;
        }

        $employee = Employee::where('employee_id', $ref)->first()
            ?? (ctype_digit($ref) ? Employee::find((int) $ref) : null);

        if (!$employee) {
            $this->error("No employee with code or id '{$ref}'.");
        }

        return $employee;
    }

    /** Payroll-derived money fields with a box on the printed form, in form order. */
    private function comparedFields(string $form): array
    {
        $schema = $form === Form1601CSchema::FORM_TYPE ? Form1601CSchema::fields() : Form2316Schema::fields();

        return array_values(array_filter($schema, fn (array $f) => $f['source'] === 'payroll'
            && $f['type'] === 'decimal'
            && $f['item'] !== null));
    }

    /** @return array<string, float>|null filed amounts by schema key; blank cells are left out */
    private function readFiled(string $path): ?array
    {
        if (!is_readable($path)) {
            $this->error("Can't read {$path}.");
            return null;
        }

        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h, "\xEF\xBB\xBF \t")), fgetcsv($handle) ?: []);
        $keyCol = array_search('key', $header, true);
        $filedCol = array_search('filed', $header, true);

        if ($keyCol === false || $filedCol === false) {
            fclose($handle);
            $this->error('The CSV needs "key" and "filed" columns — start from the --template sheet.');
            return null;
        }

        $filed = [];
        while (($row = fgetcsv($handle)) !== false) {
            $key = trim((string) ($row[$keyCol] ?? ''));
            $value = preg_replace('/[^\d.\-]/', '', (string) ($row[$filedCol] ?? ''));
            if ($key !== '' && $value !== '' && is_numeric($value)) {
                $filed[$key] = (float) $value;
            }
        }
        fclose($handle);

        return $filed;
    }

    private function baseName(string $form, string $period, ?Employee $employee): string
    {
        return strtolower(str_replace('-', '', $form)) . "-{$period}" . ($employee ? '-' . ($employee->employee_id ?: $employee->id) : '');
    }

    private function writeCsv(string $file, array $header, array $rows): string
    {
        $dir = storage_path('app/bir-compare');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = "{$dir}/{$file}";
        $handle = fopen($path, 'w');
        fputcsv($handle, $header);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2);
    }
}
