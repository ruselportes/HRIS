<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk overtime approval (Phase 9 — UC-10): HR approves a whole batch at once,
 * and the service reports which requests it had to skip and why.
 */
class BatchApproveOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ot_ids' => ['required', 'array', 'min:1'],
            'ot_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
