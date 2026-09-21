<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Admin device revocation (Phase 10). The reason is REQUIRED — a revocation
 * that cuts a field device out of the system is a security action, and the
 * audit trail (DEVICE_REVOKED) is only useful if it says why. The foreman's
 * own self-revoke endpoint still defaults its reason for compatibility; this
 * endpoint, which an admin uses on a device they may never have held, must
 * not.
 */
class RevokeDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is role:admin gated; the device itself comes from route model
        // binding, so no id in the body can reach a device the admin did not
        // name in the URL.
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}