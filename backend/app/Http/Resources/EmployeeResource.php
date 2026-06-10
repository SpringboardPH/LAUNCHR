<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'position' => $this->position,
            'department' => $this->department,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'salary' => (float) $this->salary,
            'undeclared_salary' => (float) $this->undeclared_salary,
            'rate_type' => $this->rate_type,
            'status' => $this->status,
            'notes' => $this->notes,
            'bank_account_number' => $this->bank_account_number,
            'sss_number' => $this->sss_number,
            'philhealth_number' => $this->philhealth_number,
            'pagibig_number' => $this->pagibig_number,
            'tin_number' => $this->tin_number,
            'user' => [
                'id' => $this->user?->id,
                'role' => $this->user?->role,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
