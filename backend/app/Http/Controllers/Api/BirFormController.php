<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBirDraftRequest;
use App\Http\Resources\BirFormDraftResource;
use App\Models\BirFormDraft;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Services\BIR\BirAggregationService;
use App\Services\BIR\BirSettingsService;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Http\Request;

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

        $aggregation = $validated['form_type'] === '1601-C'
            ? BirAggregationService::monthlyWithholding((int) substr($period, 0, 4), (int) substr($period, 5, 2))
            : BirAggregationService::annualCompensation($validated['employee_id'], (int) $period);

        // Disjoint key sets: the aggregation returns only source: payroll keys, settings only
        // source: settings keys. values() omits blanks, seed placeholders and out-of-options
        // values, so those stay pending and get prompted for.
        $values = $aggregation + BirSettingsService::values($validated['form_type']);

        $draft = BirFormDraft::create([
            'form_type' => $validated['form_type'],
            'period' => $period,
            'employee_id' => $validated['employee_id'] ?? null,
            'status' => 'draft',
            'version' => 1,
            'parent_id' => null,
            'prepared_by' => $request->user()->id,
            'fields' => $this->buildFields($validated['form_type'], $values),
            'source_snapshot' => $this->buildSourceSnapshot(
                $validated['form_type'],
                $period,
                $validated['employee_id'] ?? null,
                $aggregation['_meta'],
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

    /**
     * Turns the aggregation result merged with the settings values into the draft's fields
     * object: every schema key, each a full six-key entry. A filled value's origin is its schema
     * source (payroll or settings). Missing and empty-string values become pending (contract §3,
     * invariant 1), so Dev C's prompting loop sees them; _meta and other non-schema keys are dropped.
     */
    private function buildFields(string $formType, array $values): array
    {
        $schema = $formType === '1601-C' ? Form1601CSchema::fields() : Form2316Schema::fields();

        $fields = [];
        foreach ($schema as $field) {
            $value = $values[$field['key']] ?? null;
            $filled = $value !== null && $value !== '';

            $fields[$field['key']] = [
                'value' => $filled ? $value : null,
                'origin' => $filled ? $field['source'] : 'pending',
                'edited' => false,
                'system_value' => null,
                'edited_by' => null,
                'edited_at' => null,
            ];
        }

        return $fields;
    }

    public function show(int $id)
    {
        $draft = BirFormDraft::with('preparer', 'approver', 'employee')->find($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        return response()->json(['success' => true, 'data' => new BirFormDraftResource($draft), 'message' => 'Draft retrieved']);
    }

    public function update(Request $request, int $id)
    {
        $draft = BirFormDraft::find($id);
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
        foreach ((array) $request->input('fields', []) as $key => $value) {
            // Clearing a field is not an edit: a null value is always pending (contract §3, invariant 1).
            $fields[$key] = array_merge($fields[$key] ?? [], $value === null
                ? ['value' => null, 'origin' => 'pending', 'edited' => false, 'system_value' => null, 'edited_by' => null, 'edited_at' => null]
                : ['value' => $value, 'origin' => 'user', 'edited' => true]);
        }
        $draft->fields = $fields;
        $draft->save();

        $draft->load('preparer', 'approver', 'employee');

        return response()->json(['success' => true, 'data' => new BirFormDraftResource($draft), 'message' => 'Draft updated']);
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

    public function submit(int $id)
    {
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

        $revision = BirFormDraft::create([
            'form_type' => $draft->form_type,
            'period' => $draft->period,
            'employee_id' => $draft->employee_id,
            'status' => 'draft',
            'version' => $draft->version + 1,
            'parent_id' => $draft->id,
            'prepared_by' => $request->user()->id,
            'approved_by' => null,
            'rejection_reason' => null,
            // stdClass so an empty set stores {} rather than [].
            'fields' => $draft->fields ?: new \stdClass(),
            'validation_errors' => [],
        ]);

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
