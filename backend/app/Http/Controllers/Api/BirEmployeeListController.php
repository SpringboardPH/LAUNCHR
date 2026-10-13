<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BirFormDraft;
use App\Services\BIR\BirEmployeeList;
use App\Services\BIR\Schemas\Form1601CSchema;

/**
 * GET /bir/drafts/{id}/employees (contract §6): each employee's part of a 1601-C, as frozen when
 * the draft was made and inherited by its revisions. Only the list leaves source_snapshot; the
 * payroll rows stay hidden.
 */
class BirEmployeeListController extends Controller
{
    public function show(int $id)
    {
        $draft = BirFormDraft::find($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        if ($draft->form_type !== Form1601CSchema::FORM_TYPE) {
            return response()->json(['success' => false, 'message' => 'The employee list is only for a 1601-C'], 422);
        }

        $list = BirEmployeeList::forDraft($draft);

        return response()->json([
            'success' => true,
            'data' => [
                'draft_id' => $draft->id,
                'form_type' => $draft->form_type,
                'period' => $draft->period,
                'version' => $draft->version,
                'employees' => $list['employees'] ?? null,
                'totals' => $list['totals'] ?? null,
                'differences' => $list['differences'] ?? null,
            ],
            'message' => $list === null
                ? 'This form was made before the employee list existed. Make a new draft to see it.'
                : 'Employee list retrieved',
        ]);
    }
}
