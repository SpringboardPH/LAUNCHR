<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\DashboardController;

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
    
    // Attendance
    Route::prefix('attendance')->group(function () {
        Route::post('/clock-in', [AttendanceController::class, 'clockIn']);
        Route::post('/clock-out', [AttendanceController::class, 'clockOut']);
        Route::get('/', [AttendanceController::class, 'index']);
        Route::get('/{id}', [AttendanceController::class, 'show']);
    });
    
    // Leave
    Route::prefix('leaves')->group(function () {
        Route::post('/', [LeaveController::class, 'store']);
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
});
