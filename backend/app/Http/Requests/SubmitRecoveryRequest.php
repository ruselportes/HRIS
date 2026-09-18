<?php

namespace App\Http\Requests;

use App\Services\Attendance\RecoveryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Site Engineer's reconstruction of a crew-day (Phase 7 — UC-07). The note
 * is required: it is the only account of what happened that day, and HR signs
 * off on the strength of it.
 */
class SubmitRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cause' => ['required', Rule::in(RecoveryService::CAUSES)],
            'note' => ['required', 'string', 'min:10', 'max:2000'],
            'records' => ['array', Rule::requiredIf(fn () => $this->input('cause') !== 'no_work')],
            'records.*.employee_id' => ['required', 'integer'],
            'records.*.status' => ['required', Rule::in(['present', 'late', 'absent', 'not_on_crew'])],
            'records.*.time_in' => ['nullable', 'date_format:H:i', 'required_if:records.*.status,present,late'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.min' => 'Say what happened that day and what the reconstruction is based on.',
            'records.*.time_in.required_if' => 'Give an arrival time for everyone who worked.',
        ];
    }
}
