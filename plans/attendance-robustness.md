# Plan to Fix Attendance Logic

## Objective
1. Prevent employees from clocking in on days they are on leave.
2. Ensure reliable automatic clock-out for all employees.

## Analysis
- **Leave Restriction**: Need to check `LeaveRequest` table for the employee on the clock-in date before creating an `AttendanceLog`.
- **Auto Clock-out**: The current `performAutoClockOut` is only triggered on-demand. I need to move this to a scheduled job that runs automatically.

## Implementation Steps
1. **Leave Check**: Update `AttendanceController@clockIn` to verify if the employee has an approved `LeaveRequest` for that date.
2. **Scheduled Task**: 
    - Create a new Artisan command `attendance:auto-clock-out` that calls `performAutoClockOut` for all employees with open logs.
    - Register this command in `routes/console.php` as a scheduled task.
3. **Verify Logic**: Ensure `performAutoClockOut` correctly handles different grace periods and templates.

## Verification
- Attempt to clock in while on approved leave (should fail).
- Check if `php artisan schedule:run` processes the auto clock-out.
