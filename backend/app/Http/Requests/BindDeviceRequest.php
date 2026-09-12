<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BindDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is role:foreman gated; a foreman may only bind for themselves,
        // which is implicit in binding against the authenticated employee.
        return true;
    }

    public function rules(): array
    {
        return [
            /*
             | device_id ends up inside the signed canonical payload, so a line
             | break in it could forge a field boundary. AttendancePayload
             | rejects that too, but refusing it at the boundary means a device
             | can never be bound with an id that later breaks signing.
             */
            'device_id' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],

            'public_key' => ['required', 'string', 'max:4000'],

            /*
             | Android KeyInfo security level. Nullable because iOS reports
             | differently and an older device may not report at all — the
             | absence is recorded as unreported rather than assumed secure.
             */
            'security_level' => [
                'nullable',
                'string',
                Rule::in(['STRONGBOX', 'TRUSTED_ENVIRONMENT', 'SOFTWARE']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'device_id.not_regex' => 'The device id must not contain line breaks.',
        ];
    }
}
