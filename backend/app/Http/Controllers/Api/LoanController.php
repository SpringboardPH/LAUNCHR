<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use Illuminate\Http\Request;

class LoanController extends Controller
{
    /**
     * List loans. HR/Admin/Accounting see all (optionally filtered by employee);
     * everyone else sees only their own.
     */
    public function index(Request $request)
    {
        $query = Loan::query();

        if (!$request->user()->isAdminOrHr()) {
            $employee = $request->user()->employee;
            $query->where('employee_id', $employee?->id ?? 0);
        } elseif ($employeeId = $request->query('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($loanType = $request->query('loan_type')) {
            $query->where('loan_type', $loanType);
        }

        $loans = $query->with('employee', 'approver')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $loans->items(),
            'pagination' => [
                'total' => $loans->total(),
                'count' => $loans->count(),
                'per_page' => $loans->perPage(),
                'current_page' => $loans->currentPage(),
                'last_page' => $loans->lastPage(),
            ],
            'message' => 'Loans retrieved',
        ]);
    }

    public function show(int $id)
    {
        $loan = Loan::with('employee', 'approver', 'payments')->findOrFail($id);

        if (!request()->user()->isAdminOrHr()) {
            $employee = request()->user()->employee;
            if (!$employee || $loan->employee_id !== $employee->id) {
                return response()->json(['success' => false, 'data' => null, 'message' => 'Unauthorized'], 403);
            }
        }

        return response()->json(['success' => true, 'data' => $loan, 'message' => 'Loan retrieved']);
    }

    /**
     * HR-entered government loan (SSS/Pag-IBIG). Created active immediately —
     * statutorily authorized, no request/consent step needed.
     */
    public function store(Request $request)
    {
        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'loan_type' => 'required|in:sss_salary,sss_calamity,pagibig_mpl',
            'principal' => 'required|numeric|min:1',
            'installment_amount' => 'required|numeric|min:1',
            'term_count' => 'required|integer|min:1',
            'start_cutoff' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $totalPayable = round($request->installment_amount * $request->term_count, 2);

        $loan = Loan::create([
            'employee_id' => $request->employee_id,
            'loan_type' => $request->loan_type,
            'principal' => $request->principal,
            'interest_rate' => 0,
            'total_payable' => $totalPayable,
            'installment_amount' => $request->installment_amount,
            'term_count' => $request->term_count,
            'balance' => $totalPayable,
            'status' => 'active',
            'start_cutoff' => $request->start_cutoff,
            'approver_id' => $request->user()->id,
            'notes' => $request->notes,
        ]);

        return response()->json(['success' => true, 'data' => $loan, 'message' => 'Loan created'], 201);
    }
}
