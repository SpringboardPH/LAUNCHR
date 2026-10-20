<?php

namespace App\Http\Controllers\Api;

use App\Helpers\SystemClock;
use App\Http\Controllers\Controller;
use App\Http\Resources\BirFormDraftResource;
use App\Models\AuditLog;
use App\Models\BirFormDraft;
use App\Services\BIR\Schemas\Form1601CSchema;
use App\Services\BIR\Schemas\Form2316Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A form's record: voiding it (forms are never deleted) and its history, read back from the
 * audit_logs rows the Auditable trait writes on every create and change.
 */
class BirFormRecordController extends Controller
{
    // What each status move means, as the history shows it. Keys are "from>to".
    private const MOVES = [
        'draft>pending' => ['submitted', 'Submitted for review'],
        'pending>approved' => ['approved', 'Approved'],
        'pending>draft' => ['returned', 'Returned to the preparer'],
        'approved>draft' => ['reopened', 'A box was changed after approval, so it went back to draft'],
        'approved>finalized' => ['filed', 'Marked as filed'],
    ];

    /**
     * Voids a form in draft status, a returned one too, with a reason. The form is kept: it
     * stays in the list as void, read-only, and normal queries skip it (notVoid scope), so its
     * period can be created again. A voided correction still counts toward the amendments.
     * Locked, as in BirFormController::transition(), so a submit at the same moment can't race it.
     */
    public function void(Request $request, int $id)
    {
        $request->validate(['reason' => 'required|string|max:1000']);

        return DB::transaction(function () use ($request, $id) {
            $draft = BirFormDraft::withVoid()->lockForUpdate()->find($id);
            if (!$draft) {
                return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
            }

            $label = $draft->label();
            if ($draft->status !== 'draft') {
                return response()->json([
                    'success' => false,
                    'message' => $draft->status === BirFormDraft::VOID
                        ? "Form {$label} is already void"
                        : "Only a draft can be voided; form {$label} is in {$draft->status} status",
                ], 400);
            }

            $draft->fill([
                'status' => BirFormDraft::VOID,
                'void_reason' => trim($request->reason),
                'voided_by' => $request->user()->id,
                'voided_at' => SystemClock::now(),
            ])->save();
            $draft->load('preparer', 'approver', 'employee', 'voider');

            return response()->json(['success' => true, 'data' => new BirFormDraftResource($draft), 'message' => "Form {$label} voided"]);
        });
    }

    /**
     * Everything that happened to a form, newest first: created, submitted, returned, approved,
     * filed, voided, and each change to its boxes (old and new value). Saves that only re-ran
     * the checks (validation_errors) are left out.
     */
    public function history(int $id)
    {
        $draft = BirFormDraft::withVoid()->find($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        $boxes = collect($draft->form_type === Form2316Schema::FORM_TYPE ? Form2316Schema::fields() : Form1601CSchema::fields())->keyBy('key');
        $entries = AuditLog::with('user')
            ->where('auditable_type', BirFormDraft::class)
            ->where('auditable_id', $draft->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditLog $log) => $this->entry($log, $boxes))
            ->filter()
            ->values();

        return response()->json(['success' => true, 'data' => $entries, 'message' => "History of form {$draft->label()}"]);
    }

    /** One history entry, or null for a save that changed nothing a person would look for. */
    private function entry(AuditLog $log, $boxes): ?array
    {
        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];
        $changes = $this->boxChanges(self::decoded($old['fields'] ?? null), self::decoded($new['fields'] ?? null), $boxes);
        $reason = null;

        if (str_starts_with($log->event, 'created')) {
            $version = (int) ($new['version'] ?? 1);
            [$event, $text] = $version > 1 ? ['created', "Correction started (version {$version})"] : ['created', 'Form created from payroll data'];
            $changes = [];
        } elseif (str_starts_with($log->event, 'deleted')) {
            [$event, $text] = ['deleted', 'Deleted (before forms were voided instead)'];
        } elseif (isset($new['status'])) {
            $to = $new['status'];
            $from = $old['status'] ?? null;
            if ($to === BirFormDraft::VOID) {
                [$event, $text, $reason] = ['voided', 'Voided', $new['void_reason'] ?? null];
            } else {
                [$event, $text] = self::MOVES["{$from}>{$to}"] ?? ['status', "Status changed from {$from} to {$to}"];
                $reason = $event === 'returned' ? ($new['rejection_reason'] ?? null) : null;
            }
        } elseif ($changes !== []) {
            $n = count($changes);
            [$event, $text] = ['edited', $n === 1 ? 'Changed 1 box' : "Changed {$n} boxes"];
        } else {
            return null;
        }

        return [
            'id' => $log->id,
            'at' => $log->created_at?->toIso8601String(),
            'by' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
            'event' => $event,
            'text' => $text,
            'reason' => $reason,
            'changes' => $changes,
        ];
    }

    /** Boxes whose value differs between two versions of fields, in the form's printed order. */
    private function boxChanges(?array $before, ?array $after, $boxes): array
    {
        if ($before === null || $after === null) {
            return [];
        }

        $changes = [];
        foreach ($boxes as $key => $box) {
            $from = $before[$key]['value'] ?? null;
            $to = $after[$key]['value'] ?? null;
            if ($from !== $to) {
                $changes[] = ['key' => $key, 'item' => $box['item'], 'label' => $box['label'], 'type' => $box['type'], 'from' => $from, 'to' => $to];
            }
        }

        return $changes;
    }

    /** Auditable keeps old fields decoded but new ones as a JSON string (see BirFormDraft). */
    private static function decoded(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : null;
    }
}
