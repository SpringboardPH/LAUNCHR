<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBirDraftRequest;
use App\Http\Resources\BirFormDraftResource;
use App\Models\BirFormDraft;
use App\Models\SystemSettings;
use Database\Seeders\BirFixtureSeeder;
use Illuminate\Http\Request;

/**
 * Week 1 stub: every method reads/mutates an in-memory fixture array so
 * C and D have a stable contract to build against. Week 2 swaps this for
 * the real BirFormDraft model and BirAggregationService.
 */
class BirFormController extends Controller
{
    private const STATUS_FLOW = [
        'draft' => ['pending'],
        'pending' => ['approved', 'draft'], // approve, or reject back to draft
        'approved' => ['finalized'],
        'finalized' => [],
    ];

    private function findFixture(int $id): ?array
    {
        foreach (BirFixtureSeeder::fixtures() as $draft) {
            if ($draft['id'] === $id) {
                return $draft;
            }
        }
        return null;
    }

    public function config()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => (bool) SystemSettings::get('bir_forms_enabled', false),
                'form_types' => ['1601-C', '2316'],
                'status_flow' => self::STATUS_FLOW,
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

        $draft = BirFormDraft::create([
            'form_type' => $validated['form_type'],
            'period' => $validated['period'],
            'employee_id' => $validated['employee_id'] ?? null,
            'status' => 'draft',
            'version' => 1,
            'parent_id' => null,
            'prepared_by' => $request->user()->id,
            // stdClass so the column stores {} rather than [].
            'fields' => new \stdClass(),
            'validation_errors' => [],
        ]);

        $draft->load('preparer', 'approver', 'employee');

        return response()->json([
            'success' => true,
            'data' => new BirFormDraftResource($draft),
            'message' => 'Draft created',
        ], 201);
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
            $fields[$key] = array_merge($fields[$key] ?? [], ['value' => $value, 'origin' => 'user', 'edited' => true]);
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
        $draft = $this->findFixture($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        return response()->json([
            'success' => false,
            'message' => 'PDF export is not implemented yet (Dev C, Week 6-7)',
        ], 501);
    }
}
