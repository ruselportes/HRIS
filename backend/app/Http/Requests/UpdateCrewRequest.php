<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCrewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'integer', 'exists:sites,site_id'],
            'crew_name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
