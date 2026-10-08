<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBirDraftRequest;
use App\Http\Requests\UpdateBirDraftFieldsRequest;
use App\Http\Resources\BirFormDraftResource;
use App\Models\BirFormDraft;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Services\BIR\BirAggregationService;
use App\Services\BIR\BirDraftValidator;
use App\Services\BIR\Mappers\BirFormMapper;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BIR Form Assistant drafts: CRUD, the draft -> pending -> approved -> finalized
 * lifecycle, and revisions, all backed by the BirFormDraft model.
 */
class BirFormController extends Controller
{
    private const STATUS_FLOW = [
        'draft' => ['pending'],
        'pending' => ['approved', 'draft'], // approve, or reject back to draft
        'approved' => ['finalized'],
        'finalized' => [],
    ];

    public function config()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => (bool) SystemSettings::get('bir_forms_enabled', false),
                'form_types' => ['1601-C', '2316'],
                'status_flow' => self::STATUS_FLOW,
                'schemas' => ['1601-C' => Form1601CSchema::fields(), '2316' => Form2316Schema::fields()],
            ],
            'message' => 'BIR Form Assistant config retrieved',
        ]);
    }

    public function index(Request $request)
    {
        $query = BirFormDraft::with('preparer', 'approver', 'employee');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($formType = $request->query('form_type')) {
            $query->where('form_type', $formType);
        }

        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $drafts = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => BirFormDraftResource::collection($drafts->items()),
            'pagination' => [
                'total' => $drafts->total(),
                'count' => $drafts->count(),
                'per_page' => $drafts->perPage(),
                'current_page' => $drafts->currentPage(),
                'last_page' => $drafts->lastPage(),
            ],
            'message' => 'BIR drafts retrieved',
        ]);
    }

    public function store(StoreBirDraftRequest $request)
    {
        $validated = $request->validated();
        $period = $validated['period'];

        // Dev A's mapper: payroll totals plus company settings as full six-key entries, totals
        // worked out, anything it can't supply left pending. meta is the aggregation's _meta.
        $built = BirFormMapper::build($validated['form_type'], $period, $validated['employee_id'] ?? null);

        $draft = BirFormDraft::create([
            'form_type' => $validated['form_type'],
            'period' => $period,
            'employee_id' => $validated['employee_id'] ?? null,
            'status' => 'draft',
            'version' => 1,
            'parent_id' => null,
            'prepared_by' => $request->user()->id,
            'fields' => $built['fields'],
            'source_snapshot' => $this->buildSourceSnapshot(
                $validated['form_type'],
                $period,
                $validated['employee_id'] ?? null,
                $built['meta'],
            ),
            'validation_errors' => [],
        ]);

        $draft->load('preparer', 'approver', 'employee');

        return response()->json([
            'success' => true,
            'data' => new BirFormDraftResource($draft),
            'message' => 'Draft created',
        ], 201);
    }

    /**
     * What a draft was built from, and under what conditions, frozen onto source_snapshot:
     * 'rows' are the payroll rows its totals came from (same period and status filters as
     * BirAggregationService; empty when the period has no payroll), and 'meta' is the
     * aggregation's _meta unchanged — excluded payrolls per status, warnings, and on a 2316
     * the category and payroll range.
     */
    private function buildSourceSnapshot(string $formType, string $period, ?int $employeeId, array $meta): array
    {
        // The cutoff rule (a payroll belongs to the month/year of BirAggregationService::PERIOD_DATE)
        // comes from the service's helpers. Confirmed: it matches how ThirteenthMonthController
        // groups payrolls and the accountant's reference sheet showing two cutoffs for August.
        // The helpers return every status, so the COUNTED_STATUSES filter is applied here.
        $query = ($formType === '1601-C'
                ? BirAggregationService::monthQuery((int) substr($period, 0, 4), (int) substr($period, 5, 2))
                : BirAggregationService::yearQuery((int) $period)->where('employee_id', $employeeId))
            ->whereIn('status', BirAggregationService::COUNTED_STATUSES);

        $rows = $query->orderBy('cutoff_end')->orderBy('id')
            ->get(['id', 'employee_id', 'cutoff_start', 'cutoff_end', 'status', 'gross_pay', 'deductions', 'allowances'])
            ->map(fn (Payroll $p) => [
                'id' => $p->id,
                'employee_id' => $p->employee_id,
                'cutoff_start' => $p->cutoff_start->toDateString(),
                'cutoff_end' => $p->cutoff_end->toDateString(),
                'status' => $p->status,
                'gross_pay' => $p->gross_pay,
                'deductions' => $p->deductions ?? [],
                'allowances' => $p->allowances ?? [],
            ])
            ->all();

        return ['rows' => $rows, 'meta' => $meta];
    }

    public function show(int $id)
    {
        $draft = BirFormDraft::with('preparer', 'approver', 'employee')->find($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        return response()->json(['success' => true, 'data' => new BirFormDraftResource($draft), 'message' => 'Draft retrieved']);
    }

    /**
     * Saves answers to a draft. UpdateBirDraftFieldsRequest has already refused unknown keys
     * and values that don't suit their field (422); it skips those checks for a missing or
     * non-draft form, so the 404 and 400 below still apply.
     */
    public function update(UpdateBirDraftFieldsRequest $request, int $id)
    {
        $draft = $request->draft();
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        if ($draft->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => "Only drafts can be edited; this form is in {$draft->status} status",
            ], 400);
        }

        // Merge per key: other keys, and system_value/edited_by/edited_at on edited keys, are kept.
        $fields = $draft->fields ?? [];
        foreach ($request->answers() as $key => $value) {
            // Clearing a field is not an edit: a null value is always pending (contract §3, invariant 1).
            $fields[$key] = array_merge($fields[$key] ?? [], $value === null
                ? ['value' => null, 'origin' => 'pending', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null]
                : ['value' => $value, 'origin' => 'user', 'edited' => true]);
        }
        // Totals follow their parts. A total someone typed (edited) is never overwritten, so a
        // hand-typed total that doesn't add up is still left for the validator to flag.
        $draft->fields = BirFormMapper::recalculate($draft->form_type, $fields);
        $draft->save();

        $draft->load('preparer', 'approver', 'employee');

        return response()->json(['success' => true, 'data' => new BirFormDraftResource($draft), 'message' => 'Draft updated']);
    }

    /**
     * Runs BirDraftValidator and stores the result on validation_errors, so it holds the
     * last known result rather than live truth. Named validateDraft so it isn't mistaken
     * for $request->validate(). Nothing is written, and so no audit_logs entry, when the
     * result hasn't changed, so the chatbot can call this after every answer.
     */
    public function validateDraft(BirDraftValidator $validator, int $id)
    {
        // employee: BirConversationService::context() needs the hire date for a 2316.
        $draft = BirFormDraft::with('employee')->find($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        if ($draft->status === 'finalized') {
            return response()->json([
                'success' => false,
                'message' => "Finalized forms are locked and can't be revalidated",
            ], 400);
        }

        $this->storeValidation($draft, $validator);

        $draft->load('preparer', 'approver', 'employee');

        $severities = array_count_values(array_column($draft->validation_errors, 'severity'));
        $count = function (string $severity) use ($severities) {
            $n = $severities[$severity] ?? 0;

            return "{$n} {$severity}" . ($n === 1 ? '' : 's');
        };

        return response()->json([
            'success' => true,
            'data' => new BirFormDraftResource($draft),
            'message' => 'Draft validated: ' . $count('error') . ', ' . $count('warning'),
        ]);
    }

    /** Runs the validator and stores its result on validation_errors, saving only when it changed. */
    private function storeValidation(BirFormDraft $draft, BirDraftValidator $validator): void
    {
        $errors = $validator->validate($draft);
        // MySQL's JSON column re-sorts object keys, so Eloquent's strict compare always sees a change.
        // == ignores key order; only save, and so audit, when the result really differs.
        $changed = $errors != $draft->validation_errors;
        $draft->validation_errors = $errors;
        if ($changed) {
            $draft->save();
        }
    }

    private function transition(int $id, string $to, ?string $reason = null)
    {
        $draft = BirFormDraft::find($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        $allowed = self::STATUS_FLOW[$draft->status] ?? [];
        if (!in_array($to, $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot move a form in {$draft->status} status to {$to}",
            ], 400);
        }

        $draft->status = $to;
        if ($to === 'pending') {
            $draft->rejection_reason = null;
        } elseif ($to === 'approved') {
            $draft->approved_by = request()->user()->id;
        } elseif ($to === 'draft') {
            $draft->rejection_reason = $reason;
        }
        $draft->save();

        $draft->load('preparer', 'approver', 'employee');

        return response()->json(['success' => true, 'data' => new BirFormDraftResource($draft), 'message' => "Draft moved to {$to}"]);
    }

    /**
     * draft -> pending, but only once the draft passes validation. The status check comes
     * first, so a form that can't be submitted is never validated or changed. The result is
     * stored either way: a refused draft shows why, and warnings, which never block, stay
     * visible to the reviewer. Refused with 422, so the screen can tell "fix the form" from
     * the 400 for an illegal move.
     */
    public function submit(BirDraftValidator $validator, int $id)
    {
        // employee: BirConversationService::context() needs the hire date for a 2316.
        $draft = BirFormDraft::with('employee')->find($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        if (!in_array('pending', self::STATUS_FLOW[$draft->status] ?? [], true)) {
            return $this->transition($id, 'pending'); // returns the 400 naming the current status
        }

        $this->storeValidation($draft, $validator);

        $errors = count(array_filter($draft->validation_errors, fn (array $entry) => $entry['severity'] === 'error'));
        if ($errors > 0) {
            $draft->load('preparer', 'approver', 'employee');

            return response()->json([
                'success' => false,
                'data' => new BirFormDraftResource($draft),
                'message' => $errors === 1
                    ? 'Draft has 1 error; fix it before submitting'
                    : "Draft has {$errors} errors; fix them before submitting",
            ], 422);
        }

        return $this->transition($id, 'pending');
    }

    public function approve(int $id)
    {
        return $this->transition($id, 'approved');
    }

    public function reject(Request $request, int $id)
    {
        $request->validate(['reason' => 'required|string|max:1000']);
        return $this->transition($id, 'draft', $request->reason);
    }

    public function finalize(int $id)
    {
        return $this->transition($id, 'finalized');
    }

    public function revise(Request $request, int $id)
    {
        $draft = BirFormDraft::find($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        if ($draft->status !== 'finalized') {
            return response()->json([
                'success' => false,
                'message' => "Only finalized forms can be revised; this one is in {$draft->status} status",
            ], 400);
        }

        // Numbered after the highest version of this form (type, period, employee), not the
        // parent's: revising the same filed v1 twice gives v2 then v3, never two v2s. Whether
        // only the latest version may be revised is still open with the accountant. The lock
        // keeps two revisions created at the same moment from reading the same highest version.
        $revision = DB::transaction(function () use ($draft, $request) {
            $highest = BirFormDraft::where('form_type', $draft->form_type)
                ->where('period', $draft->period)
                ->where('employee_id', $draft->employee_id)
                ->lockForUpdate()
                ->max('version');

            return BirFormDraft::create([
                'form_type' => $draft->form_type,
                'period' => $draft->period,
                'employee_id' => $draft->employee_id,
                'status' => 'draft',
                'version' => $highest + 1,
                'parent_id' => $draft->id,
                'prepared_by' => $request->user()->id,
                'approved_by' => null,
                'rejection_reason' => null,
                // stdClass so an empty set stores {} rather than [].
                'fields' => $draft->fields ?: new \stdClass(),
                'validation_errors' => [],
            ]);
        });

        $revision->load('preparer', 'approver', 'employee');

        return response()->json([
            'success' => true,
            'data' => new BirFormDraftResource($revision),
            'message' => 'Revision created',
        ], 201);
    }

    public function export(int $id)
    {
        $draft = BirFormDraft::find($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        return response()->json([
            'success' => false,
            'message' => 'PDF export is not implemented yet (Dev C, Week 6-7)',
        ], 501);
    }
}
