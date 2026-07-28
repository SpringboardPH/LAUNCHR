<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class EmployeeRequest extends Model
{
    use Auditable;

    protected $fillable = [
        'employee_id', 'request_type', 'subject', 'details',
        'meta', 'status', 'approver_id', 'response_notes',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /**
     * Auto-file a pending request for an attendance deviation detected at clock-out.
     *
     * The log keeps its factual baseline status ('late' or 'completed'); this request
     * is what HR actions to decide whether the deviation changes pay. Idempotent per
     * (log, type) — and deliberately counts already-rejected requests as filed, so a
     * re-run of clock-out can't resurrect a deviation HR has already ruled on.
     */
    public static function autoFile(AttendanceLog $log, string $type, string $subject, string $details, array $meta = []): void
    {
        $alreadyFiled = static::where('employee_id', $log->employee_id)
            ->where('request_type', $type)
            ->where('meta->attendance_log_id', $log->id)
            ->exists();

        if ($alreadyFiled) {
            return;
        }

        $date = $log->date instanceof \Carbon\Carbon ? $log->date->toDateString() : (string) $log->date;

        static::create([
            'employee_id'  => $log->employee_id,
            'request_type' => $type,
            'subject'      => $subject,
            'details'      => $details,
            'meta'         => [
                ...$meta,
                'attendance_log_id' => $log->id,
                'date'              => $date,
                // Stashed so a decision can restore the pre-deviation status verbatim.
                'original_status'   => $log->status,
                'auto_filed'        => true,
            ],
            'status' => 'pending',
        ]);
    }
}
