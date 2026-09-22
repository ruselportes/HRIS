<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * System Settings (C6, FR-01) — read-only. Changing any of these is a config
 * edit and a redeploy, and the page says so; this endpoint only reports the
 * values in force, built from config() alone.
 *
 * No secret is ever returned: no APP_KEY, no database credentials, no keys.
 * A test pins the forbidden list, so a future addition cannot leak one by
 * accident. The honest headline stays visible: hardware-backed keys are not
 * enforced yet.
 */
class AdminSettingsController extends Controller
{
    /** GET /api/admin/settings — admin only. */
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                'attendance' => [
                    'timezone' => config('attendance.timezone'),
                    'shift_start' => config('attendance.shift_start'),
                    'shift_end' => config('attendance.shift_end'),
                    'late_override_grace_minutes' => config('attendance.late_override_grace_minutes'),
                    'time_out_tracked_from' => config('attendance.time_out_tracked_from'),
                ],
                'payroll' => [
                    'cutoff_start_days' => config('payroll.cutoff_start_days'),
                    'rest_day_iso' => config('payroll.rest_day_iso'),
                    'night_differential' => config('payroll.night'),
                    'regional_minimum_wage' => config('payroll.regional_minimum_wage'),
                    'statutory_in_effect_from' => $this->statutoryInEffectFrom(),
                ],
                'integrity' => [
                    'hardware_keys_required' => config('crypto.require_hardware_backed_keys'),
                    'accepted_security_levels' => config('crypto.accepted_security_levels'),
                    'accepted_payload_versions' => config('crypto.accepted_payload_versions'),
                ],
                'sign_in' => [
                    'web_minutes' => config('sanctum.session_lifetimes.web_minutes'),
                    'portal_minutes' => config('sanctum.session_lifetimes.portal_minutes'),
                    'mobile_days' => config('sanctum.session_lifetimes.mobile_days'),
                ],
                'cache' => [
                    'store' => config('cache.default'),
                ],
            ],
        ]);
    }

    /** Latest effective `from` per statutory set — the table in force. */
    private function statutoryInEffectFrom(): array
    {
        $inEffect = [];

        foreach (['sss', 'philhealth', 'pagibig', 'withholding'] as $agency) {
            $sets = config("payroll.statutory.{$agency}", []);
            $inEffect[$agency] = collect($sets)->max('from');
        }

        return $inEffect;
    }
}
