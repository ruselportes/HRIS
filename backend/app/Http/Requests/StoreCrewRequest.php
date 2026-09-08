<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_id' => ['required', 'integer', 'exists:sites,site_id'],
            'crew_name' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['draft', 'deployed'])],
        ];
    }
}
