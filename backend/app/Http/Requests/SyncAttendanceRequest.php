<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is role:foreman gated; ownership of the device is checked in
        // the controller against the authenticated employee.
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:100'],

            // Chain order is significant: the array order IS the chain order,
            // so the client must not reorder and the server must not sort.
            'events' => ['required', 'array', 'min:1', 'max:500'],

            'events.*.employee_id' => ['required', 'integer'],
            'events.*.crew_id' => ['required', 'integer'],
            'events.*.date' => ['required', 'date_format:Y-m-d'],
            'events.*.status' => ['required', Rule::in(['pending', 'present', 'late', 'absent'])],

            /*
             | Epoch milliseconds, nullable because an Absent worker never
             | clocked in. Nullable is not the same as absent — the key must be
             | present so a missing field is a validation error rather than
             | silently becoming null and changing what was signed.
             */
            'events.*.time_in' => ['present', 'nullable', 'integer'],

            'events.*.monotonic_timestamp' => ['required', 'integer', 'min:0'],
            'events.*.boot_id' => ['required', 'string', 'max:64'],
            'events.*.device_id' => ['required', 'string', 'max:100'],

            // Null only for the very first event a device ever produces.
            'events.*.prev_hash' => ['present', 'nullable', 'string', 'size:64'],

            'events.*.hmac_hash' => ['required', 'string', 'size:64'],
            'events.*.ecdsa_signature' => ['required', 'string', 'max:1000'],
            'events.*.override_flag' => ['sometimes', 'boolean'],
            'events.*.captured_at' => ['sometimes', 'integer'],
        ];
    }
}
