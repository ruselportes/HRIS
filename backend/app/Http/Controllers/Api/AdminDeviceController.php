<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RevokeDeviceRequest;
use App\Models\AuditLog;
use App\Models\DeviceKey;
use App\Services\Crypto\DeviceBindingService;
use Illuminate\Http\JsonResponse;

/**
 * Device & Sync Health (Phase 10) — the web portal's registry of every bound
 * field device, backing the nav "synchealth" item (HR view, Admin full).
 *
 * This is the answer to the review's lost-or-stolen phone gap: until now the
 * only revoke on the system was DELETE /api/me/devices/{deviceId}, reachable
 * only by the foreman, from the phone they are revoking. A device that ended
 * up somewhere it should not be could not be cut off from the portal at all.
 *
 * Listing is deliberately plain-data: owner, security level, bound time, last
 * sync, chain-tip presence and the owner's integrity incidents. It never
 * returns the HMAC secret (hidden on the model), the public key, or the raw
 * last_chain_hash digest — those are verification material, not UI data.
 * Permission scopes follow the nav matrix: HR and Admin read, Admin alone
 * revokes (a write that cuts a device out of the system).
 */
class AdminDeviceController extends Controller
{
    public function __construct(private DeviceBindingService $binding) {}

    public function index(): JsonResponse
    {
        // Incidents are counted per OWNER, not per device: the audit log names
        // the device only in description text (never parsed), and attributes
        // every integrity entry to the device owner via actor_id. A foreman
        // with several devices carries one incident total, labelled as theirs.
        $incidentsByOwner = AuditLog::integrityIncidentsByOwner();

        $devices = DeviceKey::query()
            ->with(['employee.role', 'employee.site'])
            // Active devices first — an offline or revoked handset is a
            // health problem, not a directory entry.
            ->orderByRaw('revoked_at is not null')
            ->orderByDesc('bound_at')
            ->get()
            ->map(fn (DeviceKey $device) => [
                'device_key_id' => $device->device_key_id,
                'device_id' => $device->device_id,
                'owner' => [
                    'employee_id' => $device->employee->employee_id,
                    'employee_code' => $device->employee->employee_code,
                    'full_name' => $device->employee->full_name,
                    'role' => $device->employee->role?->slug,
                    'site' => $device->employee->site?->site_name,
                ],
                'security_level' => $device->security_level,
                'hardware_backed' => in_array(
                    $device->security_level,
                    config('crypto.accepted_security_levels', []),
                    true,
                ),
                'bound_at' => $device->bound_at,
                'last_synced_at' => $device->last_synced_at,
                // Presence, not the digest — the chain hash is verification
                // material, and "has it ever chained" is the UI question.
                'has_chain_history' => $device->last_chain_hash !== null,
                'revoked_at' => $device->revoked_at,
                'integrity_incidents' => $incidentsByOwner[$device->employee_id] ?? 0,
            ]);

        return response()->json(['devices' => $devices]);
    }

    public function revoke(RevokeDeviceRequest $request, DeviceKey $device): JsonResponse
    {
        if ($device->isRevoked()) {
            return response()->json(['message' => 'This device is already revoked.'], 422);
        }

        $this->binding->revoke(
            $device,
            $request->user(),
            $request->string('reason')->toString(),
        );

        return response()->json([
            'device_id' => $device->device_id,
            'revoked_at' => $device->fresh()->revoked_at,
        ]);
    }
}
