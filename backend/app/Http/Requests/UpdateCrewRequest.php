<?php

namespace App\Http\Requests;

use App\Models\Site;
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
            'site_id' => [
                'sometimes',
                'integer',
                'exists:sites,site_id',
                // Same rule as crew creation: a crew cannot move onto a
                // closed site either.
                function (string $attribute, mixed $value, callable $fail): void {
                    $site = Site::query()->find($value);

                    if ($site !== null && $site->isClosed()) {
                        $fail('Crews cannot be moved to a closed site.');
                    }
                },
            ],
            'crew_name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
