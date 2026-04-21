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

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    
    // Employees
    Route::apiResource('employees', EmployeeController::class);
    Route::patch('/employees/{id}/deactivate', [EmployeeController::class, 'deactivate']);
    
    // Attendance
    Route::prefix('attendance')->group(function () {
        Route::post('/clock-in', [AttendanceController::class, 'clockIn']);
        Route::post('/clock-out', [AttendanceController::class, 'clockOut']);
        Route::get('/today', [AttendanceController::class, 'today']);
        Route::get('/', [AttendanceController::class, 'index']);
        Route::get('/{id}', [AttendanceController::class, 'show']);
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
    
    // Payroll
    Route::prefix('payroll')->group(function () {
        Route::post('/compute', [PayrollController::class, 'compute']);
        Route::get('/', [PayrollController::class, 'index']);
        Route::get('/{id}', [PayrollController::class, 'show']);
        Route::get('/{id}/export', [PayrollController::class, 'export']);
    });
    
    // Dashboard
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    
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
        
        // Departments
        Route::apiResource('admin/departments', DepartmentController::class);
        Route::patch('/admin/departments/{id}/restore', [DepartmentController::class, 'restore']);
        Route::delete('/admin/departments/{id}/hard-delete', [DepartmentController::class, 'hardDelete']);
        
        // Schedule Templates (Admin)
        Route::apiResource('admin/schedule-templates', ScheduleTemplateController::class);
        
        // Employee Management (Admin)
        Route::delete('/admin/employees/{id}/hard-delete', [EmployeeController::class, 'hardDelete']);
        Route::patch('/admin/employees/{id}/restore', [EmployeeController::class, 'restore']);
        
        // User Management (Admin)
        Route::apiResource('admin/users', UserController::class);
    });
    
    // Employee Schedules (Admin + HR)
    Route::middleware('role:admin,hr')->group(function () {
        Route::apiResource('admin/employee-schedules', EmployeeScheduleController::class);
        Route::get('/admin/employee-schedules/employee/{employeeId}/current', [EmployeeScheduleController::class, 'getCurrentForEmployee']);
    });
});
