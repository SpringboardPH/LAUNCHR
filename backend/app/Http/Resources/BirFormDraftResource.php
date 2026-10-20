<?php

namespace App\Http\Resources;

use App\Models\BirFormDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BirFormDraftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // source_snapshot (the frozen payroll rows) must never appear in API output.
        $limit = BirFormDraft::AMENDMENT_LIMITS[$this->form_type] ?? null;

        return [
            'id' => $this->id,
            'form_type' => $this->form_type,
            'period' => $this->period,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn ($employee) => "{$employee->last_name}, {$employee->first_name}"),
            'status' => $this->status,
            'version' => $this->version,
            'parent_id' => $this->parent_id,
            // The original's id, and the label the screens show: "#64", then "#64v2" for version 2.
            'root_id' => $this->rootId(),
            'label' => $this->label(),
            // A form with an amendment limit (1601-C): how many of its month's amendments are made.
            'amendments' => $limit === null ? null : ['made' => $this->amendmentsMade(), 'limit' => $limit],
            'prepared_by' => $this->whenLoaded('preparer', fn ($user) => ['id' => $user->id, 'name' => $user->name]),
            'approved_by' => $this->whenLoaded('approver', fn ($user) => ['id' => $user->id, 'name' => $user->name]),
            'rejection_reason' => $this->rejection_reason,
            // Set on a voided form only: why, by whom and when (the form is kept, never deleted).
            'void' => $this->status === BirFormDraft::VOID ? [
                'reason' => $this->void_reason,
                'by' => $this->voider ? ['id' => $this->voider->id, 'name' => $this->voider->name] : null,
                'at' => $this->voided_at?->toIso8601String(),
            ] : null,
            // Empty fields is an object keyed by field name, so it must encode as {} not [].
            'fields' => $this->fields ?: new \stdClass(),
            'validation_errors' => $this->validation_errors ?? [],
        ];
    }
}
