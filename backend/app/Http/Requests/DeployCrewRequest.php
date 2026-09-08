<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeployCrewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_date' => ['nullable', 'date'],
        ];
    }
}
