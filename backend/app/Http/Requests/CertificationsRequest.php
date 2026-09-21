<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CertificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // date_format:Y-m-d over date: `date` also accepts formats like
            // m/d/Y, and the raw strings then leak into Carbon parsing and
            // comparisons below.
            'site_id' => ['nullable', 'integer', 'exists:sites,site_id'],
            'status' => ['nullable', 'string', 'in:valid,expiring_soon,expired,no_expiry'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
