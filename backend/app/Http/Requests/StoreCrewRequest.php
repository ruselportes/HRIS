<?php

namespace App\Http\Requests;

use App\Models\Site;
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
            'site_id' => [
                'required',
                'integer',
                'exists:sites,site_id',
                // A closed site takes no new crews; the row stays for history.
                function (string $attribute, mixed $value, callable $fail): void {
                    $site = Site::query()->find($value);

                    if ($site !== null && $site->isClosed()) {
                        $fail('Crews cannot be created at a closed site.');
                    }
                },
            ],
            'crew_name' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['draft', 'deployed'])],
        ];
    }
}
