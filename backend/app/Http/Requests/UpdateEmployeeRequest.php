<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $employeeId = $this->route('employee');

        return [
            'employee_id' => "nullable|string|unique:employees,employee_id,{$employeeId}|max:50",
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => "required|email|unique:employees,email,{$employeeId}|max:255",
            'phone' => 'nullable|string|max:20',
            'position' => 'required|string|max:100',
            'department' => 'required|string|max:100',
            'hire_date' => 'required|date',
            'salary' => 'required_without:basic_salary|numeric|min:0',
            'basic_salary' => 'required_without:salary|numeric|min:0',
            'status' => 'nullable|in:active,inactive,on_leave',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'employee_id.unique' => 'This employee ID is already in use.',
            'email.unique' => 'This email is already registered.',
            'hire_date.date' => 'Hire date must be a valid date.',
            'salary.numeric' => 'Salary must be a number.',
        ];
    }
}
