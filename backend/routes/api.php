<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\ScheduleTemplateController;
use App\Http\Controllers\Api\EmployeeScheduleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\LeaveTypeController;
use App\Http\Controllers\Api\EmployeeLeaveBalanceController;
use App\Http\Controllers\Api\CalendarEventController;
use App\Http\Controllers\Api\CalendarEventTypeController;
use App\Http\Controllers\Api\AuditLogController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/request-otp', [AuthController::class, 'requestOtp']);
Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/register', [AuthController::class, 'register']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    
    // Employees
    Route::put('/profile', [EmployeeController::class, 'updateProfile']);
    Route::apiResource('employees', EmployeeController::class);
    Route::patch('/employees/{id}/deactivate', [EmployeeController::class, 'deactivate']);
    
    // Attendance
    Route::prefix('attendance')->group(function () {
        Route::post('/clock-in', [AttendanceController::class, 'clockIn']);
        Route::post('/clock-out', [AttendanceController::class, 'clockOut']);
        Route::get('/today', [AttendanceController::class, 'today']);
        Route::get('/{employeeId}/monthly', [AttendanceController::class, 'monthly']);
        Route::get('/', [AttendanceController::class, 'index']);
        Route::get('/{id}', [AttendanceController::class, 'show']);
        Route::put('/{id}', [AttendanceController::class, 'update'])->middleware('role:admin,hr');
        Route::post('/bulk-mark-absent', [AttendanceController::class, 'bulkMarkAbsent'])->middleware('role:admin,hr');
    });
    
    // Leave
    Route::prefix('leaves')->group(function () {
        Route::post('/', [LeaveController::class, 'store']);
        Route::get('/balance', [LeaveController::class, 'balance']);
        Route::get('/', [LeaveController::class, 'index']);
        Route::get('/{id}', [LeaveController::class, 'show']);
        Route::patch('/{id}/approve', [LeaveController::class, 'approve']);
        Route::patch('/{id}/reject', [LeaveController::class, 'reject']);
    });

    Route::get('/leave-types', [LeaveTypeController::class, 'index']);
    Route::get('/calendar-event-types', [CalendarEventTypeController::class, 'index']);

    // Calendar
    Route::get('/calendar-events', [CalendarEventController::class, 'index']);
    Route::get('/calendar-events/{calendarEvent}', [CalendarEventController::class, 'show']);
    
    // Payroll
    Route::prefix('payroll')->group(function () {
        Route::get('/', [PayrollController::class, 'index']);
        Route::post('/generate', [PayrollController::class, 'generate'])->middleware('role:admin,hr');
        Route::post('/send-paystubs', [PayrollController::class, 'sendPaystubs'])->middleware('role:admin,hr');
        Route::get('/{id}', [PayrollController::class, 'show']);
        Route::put('/{id}', [PayrollController::class, 'update'])->middleware('role:admin,hr');
        Route::get('/{id}/export', [PayrollController::class, 'export']);
    });
    
    // Dashboard
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    // Shared lookup data for admin + HR forms
    Route::middleware('role:admin,hr')->group(function () {
        Route::get('/departments', [DepartmentController::class, 'index']);
    });

    // System clock (virtual time used by attendance) - available to all authenticated users
    Route::get('/system-clock', [AdminSettingsController::class, 'systemClock']);
    
    // Employee Schedules (Self-service)
    Route::get('/my-schedules', [EmployeeScheduleController::class, 'mySchedules']);
    Route::post('/my-schedules', [EmployeeScheduleController::class, 'setMySchedule']);
    Route::get('/schedule-templates', [ScheduleTemplateController::class, 'index']);

    // Admin Routes (Admin Only)
    Route::middleware('role:admin')->group(function () {
        // Settings
        Route::prefix('admin/settings')->group(function () {
            Route::get('/', [AdminSettingsController::class, 'index']);
            Route::get('/defaults', [AdminSettingsController::class, 'getDefaults']);
            Route::post('/initialize', [AdminSettingsController::class, 'initializeDefaults']);
            Route::get('/{key}', [AdminSettingsController::class, 'show']);
            Route::put('/{key}', [AdminSettingsController::class, 'update']);
        });

        Route::prefix('admin/leave-types')->group(function () {
            Route::get('/', [LeaveTypeController::class, 'index']);
            Route::post('/', [LeaveTypeController::class, 'store']);
            Route::get('/{leaveType}', [LeaveTypeController::class, 'show']);
            Route::put('/{leaveType}', [LeaveTypeController::class, 'update']);
            Route::delete('/{leaveType}', [LeaveTypeController::class, 'destroy']);
        });

        Route::prefix('admin/employee-leave-balances')->group(function () {
            Route::get('/{employee}', [EmployeeLeaveBalanceController::class, 'show']);
            Route::put('/{employee}/{leaveType}', [EmployeeLeaveBalanceController::class, 'upsert']);
            Route::delete('/{employee}/{leaveType}', [EmployeeLeaveBalanceController::class, 'destroy']);
        });

        Route::prefix('admin/calendar-event-types')->group(function () {
            Route::get('/', [CalendarEventTypeController::class, 'index']);
            Route::post('/', [CalendarEventTypeController::class, 'store']);
            Route::get('/{calendarEventType}', [CalendarEventTypeController::class, 'show']);
            Route::put('/{calendarEventType}', [CalendarEventTypeController::class, 'update']);
            Route::delete('/{calendarEventType}', [CalendarEventTypeController::class, 'destroy']);
        });
        
        // Departments
        Route::apiResource('admin/departments', DepartmentController::class);
        Route::patch('/admin/departments/{id}/restore', [DepartmentController::class, 'restore']);
        Route::delete('/admin/departments/{id}/hard-delete', [DepartmentController::class, 'hardDelete']);
        
        // Employee Management (Admin)
        Route::delete('/admin/employees/{id}/hard-delete', [EmployeeController::class, 'hardDelete']);
        Route::patch('/admin/employees/{id}/restore', [EmployeeController::class, 'restore']);
        
        // User Management (Admin)
        Route::delete('/admin/users/{id}/hard-delete', [UserController::class, 'hardDelete']);
        Route::get('/admin/users/trashed', [UserController::class, 'trashed']);
        Route::patch('/admin/users/{id}/restore', [UserController::class, 'restore']);
        Route::apiResource('admin/users', UserController::class);
        Route::get('/admin/audit-logs', [AuditLogController::class, 'index']);
    });
    
    // Employee Schedules & Templates (Admin + HR)
    Route::middleware('role:admin,hr')->group(function () {
        Route::apiResource('admin/employee-schedules', EmployeeScheduleController::class);
        Route::apiResource('admin/schedule-templates', ScheduleTemplateController::class);
        Route::apiResource('admin/calendar-events', CalendarEventController::class)->except(['index', 'show']);
        Route::post('/admin/calendar-events/import', [CalendarEventController::class, 'import']);
        Route::get('/admin/calendar-events/export', [CalendarEventController::class, 'export']);
    });
    Route::get('/admin/employee-schedules/employee/{employeeId}/current', [EmployeeScheduleController::class, 'getCurrentForEmployee']);
});
