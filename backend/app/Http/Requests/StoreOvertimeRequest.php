<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,employee_id'],
            'ot_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hours_requested' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'reason' => ['nullable', 'string', 'max:500'],
            'batch_key' => ['nullable', 'string', 'max:64'],
        ];
    }
}
