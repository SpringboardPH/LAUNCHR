<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DtrUpload;
use App\Models\Employee;
use App\Models\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DtrController extends Controller
{
    public function config(Request $request)
    {
        $perRestriction = (bool) SystemSettings::get('dtr_per_employee_restriction', false);

        $uploadAllowed = true;
        if ($perRestriction) {
            $employee      = $request->user()?->employee;
            $uploadAllowed = $employee?->dtr_upload_enabled ?? false;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'enabled'                  => (bool) SystemSettings::get('dtr_page_enabled', false),
                'frequency'                => SystemSettings::get('dtr_upload_frequency', 'semi_monthly'),
                'per_employee_restriction' => $perRestriction,
                'cutoff1_day'              => (int) SystemSettings::get('dtr_cutoff1_day', 10),
                'cutoff2_day'              => (int) SystemSettings::get('dtr_cutoff2_day', 25),
                'upload_allowed'           => $uploadAllowed,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = DtrUpload::with('employee');

        $user = $request->user();
        if (!$user->isAdminOrHr()) {
            $employee = $user->employee;
            if (!$employee) {
                return response()->json(['success' => false, 'data' => [], 'message' => 'No employee record'], 404);
            }
            $query->where('employee_id', $employee->id);
        }

        if ($employeeId = $request->query('employee_id')) {
            if ($user->isAdminOrHr()) {
                $query->where('employee_id', $employeeId);
            }
        }

        $dtrs = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['success' => true, 'data' => $dtrs, 'message' => 'DTRs retrieved']);
    }

    public function store(Request $request)
    {
        $enabled = SystemSettings::get('dtr_page_enabled', false);
        if (!$enabled) {
            return response()->json(['success' => false, 'message' => 'DTR upload is not enabled'], 403);
        }

        $request->validate([
            'cutoff_type'  => 'required|in:cutoff1,cutoff2,monthly',
            'period_label' => 'required|string|max:20',
            'file'         => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $user     = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Employee record not found'], 404);
        }

        $perRestriction = SystemSettings::get('dtr_per_employee_restriction', false);
        if ($perRestriction && !$employee->dtr_upload_enabled) {
            return response()->json(['success' => false, 'message' => 'DTR upload is not available for your account'], 403);
        }

        $file = $request->file('file');
        $path = $file->store("dtrs/{$employee->id}", 'local');

        // Replace existing DTR for same period + cutoff
        $existing = DtrUpload::where([
            'employee_id'  => $employee->id,
            'cutoff_type'  => $request->cutoff_type,
            'period_label' => $request->period_label,
        ])->first();

        if ($existing) {
            Storage::disk('local')->delete($existing->file_path);
            $existing->delete();
        }

        $dtr = DtrUpload::create([
            'employee_id'       => $employee->id,
            'cutoff_type'       => $request->cutoff_type,
            'period_label'      => $request->period_label,
            'original_filename' => $file->getClientOriginalName(),
            'file_path'         => $path,
            'auto_delete_at'    => now()->addMonths(2),
        ]);

        return response()->json(['success' => true, 'data' => $dtr->load('employee'), 'message' => 'DTR uploaded'], 201);
    }

    public function download(int $id)
    {
        $dtr = DtrUpload::findOrFail($id);

        if (!Storage::disk('local')->exists($dtr->file_path)) {
            return response()->json(['success' => false, 'message' => 'File not found on disk'], 404);
        }

        return Storage::disk('local')->download($dtr->file_path, $dtr->original_filename);
    }

    public function destroy(int $id, Request $request)
    {
        $dtr  = DtrUpload::findOrFail($id);
        $user = $request->user();

        if (!$user->isAdminOrHr()) {
            $employee = $user->employee;
            if (!$employee || $dtr->employee_id !== $employee->id) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }
        }

        Storage::disk('local')->delete($dtr->file_path);
        $dtr->delete();

        return response()->json(['success' => true, 'data' => null, 'message' => 'DTR deleted']);
    }

    public function employeeAccess()
    {
        $employees = Employee::select('id', 'first_name', 'last_name', 'employee_id', 'dtr_upload_enabled')
            ->whereNull('deleted_at')
            ->orderBy('last_name')
            ->get()
            ->each(fn ($e) => $e->setAppends([]));

        return response()->json(['success' => true, 'data' => $employees]);
    }

    public function toggleEmployeeAccess(Request $request, int $employeeId)
    {
        $request->validate(['enabled' => 'required|boolean']);

        $employee = Employee::findOrFail($employeeId);
        $employee->update(['dtr_upload_enabled' => $request->boolean('enabled')]);

        return response()->json(['success' => true, 'data' => $employee, 'message' => 'Access updated']);
    }
}
