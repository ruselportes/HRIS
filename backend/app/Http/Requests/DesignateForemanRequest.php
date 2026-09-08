<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DesignateForemanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'foreman_id' => ['required', 'integer', 'exists:employees,employee_id'],
        ];
    }
}
