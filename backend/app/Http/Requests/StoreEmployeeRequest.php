<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $minimumWage = config('payroll.regional_minimum_wage');

        return [
            'employee_code' => ['nullable', 'string', 'max:8', 'regex:/^ADC-\d{4}$/', 'unique:employees,employee_code'],
            'email' => ['nullable', 'email', 'max:255', 'unique:employees,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'role_id' => ['required', 'integer', 'exists:roles,role_id'],
            'site_id' => ['required', 'integer', 'exists:sites,site_id'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'trade_skill' => ['nullable', 'string', 'max:255'],
            'daily_rate' => ['required', 'numeric', 'min:'.$minimumWage],
            'cost_centre' => ['required', 'string', 'max:255'],
            'employment_status' => ['required', Rule::in(['probationary', 'regular', 'project_based', 'seasonal', 'separated'])],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'date_hired' => ['required', 'date'],
            'mobile' => ['required', 'string', 'max:255'],
            'civil_status' => ['nullable', 'string', 'max:255'],
            'dependents' => ['nullable', 'integer', 'min:0', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'blood_type' => ['nullable', 'string', 'max:5'],
            'tin' => ['nullable', 'string', 'max:255'],
            'sss' => ['nullable', 'string', 'max:255'],
            'philhealth' => ['nullable', 'string', 'max:255'],
            'pag_ibig' => ['nullable', 'string', 'max:255'],
            'certification' => ['nullable', 'array'],
            'certification.*.name' => ['required', 'string'],
            'certification.*.issuer' => ['nullable', 'string', 'max:255'],
            'certification.*.certificate_no' => ['nullable', 'string', 'max:255'],
            'certification.*.issued_at' => ['nullable', 'date'],
            'certification.*.expires_at' => ['nullable', 'date', 'after:certification.*.issued_at'],
            'emergency_contact' => ['nullable', 'array'],
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
                .' (current Region VII minimum wage). Update '.config('payroll.regional_minimum_wage').' in config/payroll.php when a new wage order takes effect.',
        ];
    }
}
