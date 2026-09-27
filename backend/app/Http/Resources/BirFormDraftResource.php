<?php

namespace App\Http\Resources;

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
        return [
            'id' => $this->id,
            'form_type' => $this->form_type,
            'period' => $this->period,
            'employee_id' => $this->employee_id,
            'employee_name' => $this->whenLoaded('employee', fn ($employee) => "{$employee->last_name}, {$employee->first_name}"),
            'status' => $this->status,
            'version' => $this->version,
            'parent_id' => $this->parent_id,
            'prepared_by' => $this->whenLoaded('preparer', fn ($user) => ['id' => $user->id, 'name' => $user->name]),
            'approved_by' => $this->whenLoaded('approver', fn ($user) => ['id' => $user->id, 'name' => $user->name]),
            'rejection_reason' => $this->rejection_reason,
            // Empty fields is an object keyed by field name, so it must encode as {} not [].
            'fields' => $this->fields ?: new \stdClass(),
            'validation_errors' => $this->validation_errors ?? [],
        ];
    }
}
