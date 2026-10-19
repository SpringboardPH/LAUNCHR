<?php

namespace App\Http\Controllers\Api;

use App\Helpers\SystemClock;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBirDraftRequest;
use App\Http\Requests\UpdateBirDraftFieldsRequest;
use App\Http\Resources\BirFormDraftResource;
use App\Models\BirFormDraft;
use App\Models\Payroll;
use App\Models\SystemSettings;
use App\Services\BIR\BirAggregationService;
use App\Services\BIR\BirDraftValidator;
use App\Services\BIR\BirEmployeeList;
use App\Services\BIR\BirPdfService;
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
                'switch_password_set' => !empty(SystemSettings::get(BirSwitchController::PASSWORD_KEY)),
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
        $formType = $validated['form_type'];
        $period = $validated['period'];
        $employeeId = $validated['employee_id'] ?? null;

        // One return per period: a second original for the same form type, period and employee
        // (null on a 1601-C) is refused in any status and any version; corrections go through
        // revise. A deleted form (soft delete) doesn't count, so the period can be created again. The lock, as in revise, keeps two creates at the same moment from both finding
        // nothing. On MySQL the loser of that race deadlocks and is retried (3 attempts), and the
        // retry finds the winner's form, so it gets the 409 rather than a 500.
        [$draft, $existing] = DB::transaction(function () use ($request, $formType, $period, $employeeId) {
            $existing = BirFormDraft::where('form_type', $formType)
                ->where('period', $period)
                ->where('employee_id', $employeeId)
                ->lockForUpdate()
                ->get()
                ->sortByDesc('version')
                ->first();
            if ($existing) {
                return [null, $existing];
            }

            // Dev A's mapper: payroll totals plus company settings as full six-key entries, totals
            // worked out, anything it can't supply left pending. meta is the aggregation's _meta.
            $built = BirFormMapper::build($formType, $period, $employeeId);

            return [BirFormDraft::create([
                'form_type' => $formType,
                'period' => $period,
                'employee_id' => $employeeId,
                'status' => 'draft',
                'version' => 1,
                'parent_id' => null,
                'prepared_by' => $request->user()->id,
                'fields' => $built['fields'],
                'source_snapshot' => $this->buildSourceSnapshot($formType, $period, $employeeId, $built['meta']),
                'validation_errors' => [],
            ]), null];
        }, 3);

        if ($existing) {
            return $this->alreadyExists($existing);
        }

        $draft->load('preparer', 'approver', 'employee');

        return response()->json([
            'success' => true,
            'data' => new BirFormDraftResource($draft),
            'message' => 'Draft created',
        ], 201);
    }

    /** 409 for a second original, naming the latest version of the form already there. */
    private function alreadyExists(BirFormDraft $existing)
    {
        if ($existing->form_type === '2316') {
            $employee = $existing->employee()->withTrashed()->first();
            $what = '2316 for ' . trim(($employee?->first_name ?? '') . ' ' . ($employee?->last_name ?? '')) . " for {$existing->period}";
        } else {
            $what = '1601-C for ' . substr($existing->period, 5, 2) . '/' . substr($existing->period, 0, 4);
        }
        $label = $existing->label();

        return response()->json([
            'success' => false,
            'message' => $existing->status === 'finalized'
                ? "A {$what} was already filed (form {$label}). Start a correction instead."
                : "A {$what} already exists (form {$label}, {$existing->status}). Open it instead.",
            'data' => ['existing' => [
                'id' => $existing->id,
                'label' => $label,
                'status' => $existing->status,
                'version' => $existing->version,
            ]],
        ], 409);
    }

    /**
     * What a draft was built from, and under what conditions, frozen onto source_snapshot:
     * 'rows' are the payroll rows its totals came from (same period and status filters as
     * BirAggregationService; empty when the period has no payroll), and 'meta' is the
     * aggregation's _meta unchanged — excluded payrolls per status, warnings, and on a 2316
     * the category and payroll range. A 1601-C adds 'people', each listed employee's name and TIN.
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

        return ['rows' => $rows, 'meta' => $meta]
            + ($formType === '1601-C' && isset($meta['employees']) ? ['people' => BirEmployeeList::people($meta)] : []);
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
     * Saves answers to a draft, pending or approved form (BirFormDraft::EDITABLE_STATUSES); pending
     * lets accounting correct a submitted form; an approved one goes back to draft (reopenIfApproved).
     * UpdateBirDraftFieldsRequest has already refused unknown keys and values that don't suit
     * their field (422); it skips those checks for a missing or locked form, or a pending one
     * the caller's role may not edit, so the 404, 400 and 403 below still come first.
     */
    public function update(UpdateBirDraftFieldsRequest $request, int $id)
    {
        $draft = $request->draft();
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        if (!in_array($draft->status, BirFormDraft::EDITABLE_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => "Only draft, pending and approved forms can be edited; this form is in {$draft->status} status",
            ], 400);
        }

        if ($request->refusedByRole()) {
            return response()->json([
                'success' => false,
                'message' => 'Only accounting can edit a form that is under review or approved',
            ], 403);
        }

        $schema = $draft->form_type === Form1601CSchema::FORM_TYPE ? Form1601CSchema::byKey() : Form2316Schema::byKey();
        $editor = ['id' => $request->user()->id, 'name' => $request->user()->name];
        $now = SystemClock::now()->toIso8601String();

        $before = $draft->fields ?? [];
        $fields = $before;
        foreach ($request->answers() as $key => $value) {
            $fields[$key] = $this->answeredEntry($fields[$key] ?? [], $value, $schema[$key]['source'], $editor, $now);
        }
        // Totals follow their parts. A total someone typed (edited) is never overwritten, so a
        // hand-typed total that doesn't add up is still left for the validator to flag.
        $fields = BirFormMapper::recalculate($draft->form_type, $fields);

        // A save that changes nothing writes nothing, so a stray Save can't cancel an approval.
        $changed = !self::sameAnswers($before, $fields);
        if ($changed) {
            $draft->fields = $fields;
            $draft->reopenIfApproved();
            $draft->save();
        }

        $draft->load('preparer', 'approver', 'employee');

        return response()->json([
            'success' => true,
            'data' => new BirFormDraftResource($draft),
            'message' => $changed ? 'Draft updated' : 'No changes to save',
        ]);
    }

    /**
     * True when every field has the same value, origin and edited flag in both sets. Who and
     * when (edited_by, edited_at) are left out: they are new on every save. Compared exactly,
     * so "1000" and "1000.00", or blank and No, count as different.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private static function sameAnswers(array $before, array $after): bool
    {
        foreach (array_keys($before + $after) as $key) {
            $old = (array) ($before[$key] ?? []);
            $new = (array) ($after[$key] ?? []);
            if (($old['value'] ?? null) !== ($new['value'] ?? null)
                || ($old['origin'] ?? null) !== ($new['origin'] ?? null)
                || !empty($old['edited']) !== !empty($new['edited'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * A field entry after a person's answer (contract §3).
     *
     * - null on a field the system supplies (schema source payroll or settings) never throws
     *   its figure away, since recalculate() only rebuilds totals: an override is undone (the
     *   calculated figure comes back, not edited, no history), and an untouched calculated
     *   figure is left as it is. Only a field that never had a figure goes back to pending.
     * - null on a field only a person answers clears it: back to pending, with no history.
     * - On a field the system supplies (schema source payroll or settings) the answer is an
     *   override: edited, with who and when, and system_value keeping the figure it replaced.
     *   Edited again, system_value keeps the original calculated figure, not the earlier answer.
     * - On a field only a person answers, nothing was replaced: origin user, not edited.
     *
     * @param  array<string, mixed>  $entry  the field's current entry
     * @param  array{id: int, name: string}  $editor
     * @return array<string, mixed>
     */
    private function answeredEntry(array $entry, mixed $value, string $source, array $editor, string $now): array
    {
        $none = ['system_value' => null, 'edited_by' => null, 'edited_at' => null];

        $systemSupplied = in_array($source, ['payroll', 'settings'], true);

        if ($value === null) {
            if ($systemSupplied && !empty($entry['edited']) && ($entry['system_value'] ?? null) !== null) {
                return ['value' => $entry['system_value'], 'origin' => $source, 'edited' => false] + $none;
            }
            if ($systemSupplied && empty($entry['edited']) && ($entry['value'] ?? null) !== null
                && in_array($entry['origin'] ?? null, ['payroll', 'settings'], true)) {
                return $entry;
            }

            return ['value' => null, 'origin' => 'pending', 'edited' => false] + $none;
        }

        if (!$systemSupplied) {
            return ['value' => $value, 'origin' => 'user', 'edited' => false] + $none;
        }

        return [
            'value' => $value,
            'origin' => 'user',
            'edited' => true,
            'system_value' => !empty($entry['edited']) ? ($entry['system_value'] ?? null) : ($entry['value'] ?? null),
            'edited_by' => $editor,
            'edited_at' => $now,
        ];
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

    /** draft -> pending, but only once the draft passes validation (see validatedTransition()). */
    public function submit(BirDraftValidator $validator, int $id)
    {
        return $this->validatedTransition($validator, $id, 'pending', 'submitting');
    }

    /**
     * pending -> approved, but only if the form still passes validation. The approver may have
     * edited it since it was submitted (EDITABLE_STATUSES), so submit's result can be stale.
     * The preparer may approve their own form: there is one accountant (contract §5).
     */
    public function approve(BirDraftValidator $validator, int $id)
    {
        return $this->validatedTransition($validator, $id, 'approved', 'approving');
    }

    /**
     * A status move that first requires the form to pass validation: submit and approve. The
     * status check comes first, so a form that can't make the move is never validated or
     * changed. The result is stored either way: a refused form shows why, and warnings, which
     * never block, stay visible to the reviewer. Refused with 422, so the screen can tell
     * "fix the form" from the 400 for an illegal move.
     */
    private function validatedTransition(BirDraftValidator $validator, int $id, string $to, string $doing)
    {
        // employee: BirConversationService::context() needs the hire date for a 2316.
        $draft = BirFormDraft::with('employee')->find($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        if (!in_array($to, self::STATUS_FLOW[$draft->status] ?? [], true)) {
            return $this->transition($id, $to); // returns the 400 naming the current status
        }

        $this->storeValidation($draft, $validator);

        $errors = count(array_filter($draft->validation_errors, fn (array $entry) => $entry['severity'] === 'error'));
        if ($errors > 0) {
            $draft->load('preparer', 'approver', 'employee');

            return response()->json([
                'success' => false,
                'data' => new BirFormDraftResource($draft),
                'message' => $errors === 1
                    ? "Draft has 1 error; fix it before {$doing}"
                    : "Draft has {$errors} errors; fix them before {$doing}",
            ], 422);
        }

        return $this->transition($id, $to);
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
        // keeps two revisions created at the same moment from reading the same highest version,
        // and so from both getting past the amendment limit (null: the limit is reached). Deleted
        // corrections count (withTrashed), as in amendmentsMade(): a number is never reused.
        $limit = BirFormDraft::AMENDMENT_LIMITS[$draft->form_type] ?? null;
        $revision = DB::transaction(function () use ($draft, $request, $limit) {
            $highest = BirFormDraft::withTrashed()->where('form_type', $draft->form_type)
                ->where('period', $draft->period)
                ->where('employee_id', $draft->employee_id)
                ->lockForUpdate()
                ->max('version');

            if ($limit !== null && $highest - 1 >= $limit) {
                return null;
            }

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
                'fields' => BirFormDraft::fieldsForRevision($draft) ?: new \stdClass(),
                'validation_errors' => [],
            ]);
        });

        if ($revision === null) {
            $message = "This {$draft->form_type} has already been amended {$limit} times for {$draft->period}, the most allowed for one month";
            return response()->json(['success' => false, 'message' => $message], 400);
        }

        $revision->load('preparer', 'approver', 'employee');

        return response()->json([
            'success' => true,
            'data' => new BirFormDraftResource($revision),
            'message' => 'Revision created',
        ], 201);
    }

    /**
     * The official BIR PDF with the draft's values (Dev C, BirPdfService). Any status can be
     * exported so a draft can be printed and checked; until it is approved, every page says
     * DRAFT — NOT FOR FILING.
     */
    public function export(BirPdfService $pdf, int $id)
    {
        $draft = BirFormDraft::with('employee')->find($id);
        if (!$draft) {
            return response()->json(['success' => false, 'message' => 'Draft not found'], 404);
        }

        $name = sprintf(
            'BIR-%s-%s%s-v%d%s.pdf',
            $draft->form_type,
            $draft->period,
            $draft->employee ? '-' . $draft->employee->employee_id : '',
            $draft->version,
            in_array($draft->status, BirPdfService::FILEABLE_STATUSES, true) ? '' : '-DRAFT',
        );

        return response($pdf->render($draft), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }
}
