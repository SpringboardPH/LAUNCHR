<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBirDraftRequest extends FormRequest
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
        return [
            'form_type' => 'required|in:1601-C,2316',
            'period' => 'required|string|max:10',
            'employee_id' => 'nullable|integer|exists:employees,id',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $messages = $this->messages();
                $errors = $validator->errors();
                $period = (string) $this->input('period');

                if ($this->input('form_type') === '1601-C') {
                    if (! $errors->has('period') && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
                        $validator->errors()->add('period', $messages['period.monthly']);
                    }
                    if (! $errors->has('employee_id') && $this->filled('employee_id')) {
                        $validator->errors()->add('employee_id', $messages['employee_id.prohibited']);
                    }
                }

                if ($this->input('form_type') === '2316') {
                    if (! $errors->has('period') && ! preg_match('/^\d{4}$/', $period)) {
                        $validator->errors()->add('period', $messages['period.annual']);
                    }
                    if (! $errors->has('employee_id') && ! $this->filled('employee_id')) {
                        $validator->errors()->add('employee_id', $messages['employee_id.required']);
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'form_type.in' => 'Form type must be either 1601-C (monthly remittance) or 2316 (annual employee certificate).',
            'employee_id.exists' => 'The selected employee does not exist.',
            'period.monthly' => 'A 1601-C covers one month — use YYYY-MM.',
            'period.annual' => 'A 2316 covers one calendar year — use YYYY.',
            'employee_id.prohibited' => 'A 1601-C covers all employees for the month — leave the employee blank.',
            'employee_id.required' => 'A 2316 is per employee — an employee is required.',
        ];
    }
}
