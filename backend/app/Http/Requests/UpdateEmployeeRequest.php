<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $minimumWage = config('payroll.regional_minimum_wage');

        return [
            'employee_code' => ['nullable', 'string', 'max:8', 'regex:/^ADC-\d{4}$/', Rule::unique('employees', 'employee_code')->ignore($employee->employee_id, 'employee_id')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('employees', 'email')->ignore($employee->employee_id, 'employee_id')],
            'password' => ['nullable', 'string', 'min:8'],
            'role_id' => ['sometimes', 'integer', 'exists:roles,role_id'],
            'site_id' => ['sometimes', 'integer', 'exists:sites,site_id'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'trade_skill' => ['sometimes', 'nullable', 'string', 'max:255'],
            'daily_rate' => ['sometimes', 'numeric', 'min:'.$minimumWage],
            'cost_centre' => ['sometimes', 'string', 'max:255'],
            'employment_status' => ['sometimes', Rule::in(['probationary', 'regular', 'project_based', 'seasonal', 'separated'])],
            'date_of_birth' => ['sometimes', 'date', 'before:today'],
            'date_hired' => ['sometimes', 'date'],
            'mobile' => ['sometimes', 'string', 'max:255'],
            'civil_status' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dependents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'blood_type' => ['sometimes', 'nullable', 'string', 'max:5'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sss' => ['sometimes', 'nullable', 'string', 'max:255'],
            'philhealth' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pag_ibig' => ['sometimes', 'nullable', 'string', 'max:255'],
            'certification' => ['sometimes', 'nullable', 'array'],
            'certification.*.name' => ['required', 'string'],
            'certification.*.issuer' => ['nullable', 'string', 'max:255'],
            'certification.*.certificate_no' => ['nullable', 'string', 'max:255'],
            'certification.*.issued_at' => ['nullable', 'date'],
            'certification.*.expires_at' => ['nullable', 'date'],
            'emergency_contact' => ['sometimes', 'nullable', 'array'],
            'emergency_contact.name' => ['nullable', 'string', 'max:255'],
            'emergency_contact.mobile' => ['nullable', 'string', 'max:255'],
            'emergency_contact.alternate' => ['nullable', 'string', 'max:255'],
            'emergency_contact.hospital' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'daily_rate.min' => 'The daily rate must be at least ₱'.number_format((float) config('payroll.regional_minimum_wage'), 2)
                .' (current Region VII minimum wage).',
        ];
    }
}
