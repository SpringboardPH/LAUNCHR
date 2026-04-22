<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$checked = 0;
$updated = 0;

foreach (App\Models\AttendanceLog::cursor() as $log) {
    $checked++;

    $schedule = App\Models\EmployeeSchedule::getForEmployeeOnDate($log->employee_id, $log->date);
    $name = $schedule?->template?->name;
    $templateId = $schedule?->schedule_template_id;

    if (!$name && !$templateId) {
        continue;
    }

    $dirty = false;

    if ($log->schedule_template_name !== $name) {
        $log->schedule_template_name = $name;
        $dirty = true;
    }

    if ((int) ($log->schedule_template_id ?? 0) !== (int) ($templateId ?? 0)) {
        $log->schedule_template_id = $templateId;
        $dirty = true;
    }

    if ($dirty) {
        $log->save();
        $updated++;
    }
}

echo "checked={$checked};updated={$updated}" . PHP_EOL;
