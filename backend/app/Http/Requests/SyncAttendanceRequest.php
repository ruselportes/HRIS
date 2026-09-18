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

            // The version each event was signed under; absent means v2, what
            // phones built before v3 send. It is the first signed line, so it
            // cannot be relabelled without breaking the signature.
            'events.*.payload_version' => ['sometimes', Rule::in(config('crypto.accepted_payload_versions', ['v2', 'v3']))],

            // Signed as of v3. Present on every v3 event for the same reason
            // as time_in below: a missing key would change what was signed.
            'events.*.event_type' => ['required_if:events.*.payload_version,v3', 'nullable', Rule::in(['roll_call', 'time_out'])],
            'events.*.time_out' => ['present_if:events.*.payload_version,v3', 'nullable', 'integer', 'min:0'],
            'events.*.time_out_type' => ['present_if:events.*.payload_version,v3', 'nullable', Rule::in(['shift_end', 'manual_time'])],

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

            // Signed as of payload v2. The real wall-clock moment of the tap —
            // what the clock check runs against, and what time_in is judged
            // relative to. Required: an event without it cannot be verified.
            'events.*.captured_at' => ['required', 'integer', 'min:0'],

            // Signed as of payload v2. Null for an ordinary tap. Present (not
            // "sometimes") for the same reason as time_in: a missing key must
            // fail validation rather than silently become null and change what
            // was signed.
            'events.*.override_type' => ['present', 'nullable', Rule::in(['shift_credit', 'manual_time'])],

            'events.*.monotonic_timestamp' => ['required', 'integer', 'min:0'],
            'events.*.boot_id' => ['required', 'string', 'max:64'],
            'events.*.device_id' => ['required', 'string', 'max:100'],

            // Null only for the very first event a device ever produces.
            'events.*.prev_hash' => ['present', 'nullable', 'string', 'size:64'],

            'events.*.hmac_hash' => ['required', 'string', 'size:64'],
            'events.*.ecdsa_signature' => ['required', 'string', 'max:1000'],
        ];
    }
}
