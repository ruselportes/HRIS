<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncAttendanceRequest;
use App\Models\DeviceKey;
use App\Services\Crypto\AttendanceSyncService;
use Illuminate\Http\JsonResponse;

/**
 * Attendance sync ingestion (Phase 5, layer 4). Phase 6 builds the mobile
 * engine that drains the local queue into this endpoint; the verification
 * gate itself lives here.
 */
class AttendanceSyncController extends Controller
{
    public function __construct(private AttendanceSyncService $sync) {}

    public function store(SyncAttendanceRequest $request): JsonResponse
    {
        $deviceKey = DeviceKey::query()
            ->where('device_id', $request->string('device_id')->toString())
            // Scoped to the authenticated foreman: a device id from the
            // request can never reach someone else's device.
            ->where('employee_id', $request->user()->employee_id)
            ->first();

        if ($deviceKey === null) {
            return response()->json([
                'message' => 'This device is not bound to your account.',
                'reason' => 'device_not_bound',
            ], 403);
        }

        if ($deviceKey->isRevoked()) {
            // A revoked device keeps its keypair and could still produce
            // perfectly valid signatures, so revocation has to be enforced
            // here rather than relying on the crypto to fail.
            return response()->json([
                'message' => 'This device has been revoked. Re-bind it before syncing.',
                'reason' => 'device_revoked',
            ], 403);
        }

        $result = $this->sync->ingest($deviceKey, $request->validated()['events']);

        return response()->json([
            'accepted' => $result['accepted'],
            'rejected' => $result['rejected'],
            'results' => $result['results'],
        ], $result['rejected'] > 0 ? 207 : 200);
    }
}
