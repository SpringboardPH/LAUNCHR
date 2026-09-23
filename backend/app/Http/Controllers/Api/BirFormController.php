<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $drafts = collect(BirFixtureSeeder::fixtures());

        if ($status = $request->query('status')) {
            $drafts = $drafts->where('status', $status);
        }
        if ($formType = $request->query('form_type')) {
            $drafts = $drafts->where('form_type', $formType);
        }

        return response()->json([
            'success' => true,
            'data' => $drafts->values(),
            'message' => 'BIR drafts retrieved',
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'form_type' => 'required|in:1601-C,2316',
            'period' => 'required|string',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => 999,
                'form_type' => $request->form_type,
                'period' => $request->period,
                'status' => 'draft',
                'version' => 1,
                'prepared_by' => ['id' => $request->user()->id, 'name' => $request->user()->name],
                'approved_by' => null,
                'rejection_reason' => null,
                'fields' => [],
                'validation_errors' => [],
            ],
            'message' => 'Draft generated (stub — real aggregation lands Week 2)',
        ], 201);
    }

    public function show(int $id)
    {
        $draft = $this->findFixture($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $draft, 'message' => 'Draft retrieved']);
    }

    public function update(Request $request, int $id)
    {
        $draft = $this->findFixture($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        foreach ($request->input('fields', []) as $key => $value) {
            $draft['fields'][$key] = ['value' => $value, 'source' => 'user', 'edited' => true];
        }

        return response()->json(['success' => true, 'data' => $draft, 'message' => 'Draft updated (stub — not persisted)']);
    }

    private function transition(int $id, string $to, ?string $reason = null)
    {
        $draft = $this->findFixture($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        $allowed = self::STATUS_FLOW[$draft['status']] ?? [];
        if (!in_array($to, $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot move a form in {$draft['status']} status to {$to}",
            ], 400);
        }

        $draft['status'] = $to;
        $draft['rejection_reason'] = $reason;

        return response()->json(['success' => true, 'data' => $draft, 'message' => "Draft moved to {$to} (stub — not persisted)"]);
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
        $draft = $this->findFixture($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        if ($draft['status'] !== 'finalized') {
            return response()->json([
                'success' => false,
                'message' => "Only finalized forms can be revised; this one is in {$draft['status']} status",
            ], 400);
        }

        $revision = $draft;
        $revision['id'] = 999;
        $revision['status'] = 'draft';
        $revision['version'] = $draft['version'] + 1;
        $revision['parent_id'] = $draft['id'];
        $revision['rejection_reason'] = null;
        $revision['approved_by'] = null;
        $revision['prepared_by'] = ['id' => $request->user()->id, 'name' => $request->user()->name];

        return response()->json([
            'success' => true,
            'data' => $revision,
            'message' => 'Revision created (stub — not persisted)',
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
