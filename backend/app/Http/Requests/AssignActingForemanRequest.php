<?php

namespace App\Http\Requests;

use App\Services\ActingForemanService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignActingForemanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'foreman_id' => ['required', 'integer', 'exists:employees,employee_id'],
            'duration' => ['required', Rule::in(ActingForemanService::DURATIONS)],
        ];
    }
}
