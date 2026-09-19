<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The window is required: payroll pays overtime from it, and a request
     * without one has nothing to pay from. hours_requested is not accepted
     * from the client at all — the server derives it from the window, so the
     * hours a request shows can never differ from the hours payroll pays.
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,employee_id'],
            'ot_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'reason' => ['nullable', 'string', 'max:500'],
            'batch_key' => ['nullable', 'string', 'max:64'],
        ];
    }
}
