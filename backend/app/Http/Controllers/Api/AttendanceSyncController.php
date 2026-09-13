<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncAttendanceRequest;
use App\Models\DeviceKey;
use App\Services\Crypto\AttendanceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Attendance sync ingestion (Phase 5 layer 4) and the reconciliation check the
 * Phase 6 sync engine relies on.
 */
class AttendanceSyncController extends Controller
{
    public function __construct(private AttendanceSyncService $sync) {}

    public function store(SyncAttendanceRequest $request): JsonResponse
    {
        $deviceKey = $this->resolveDevice($request, $request->string('device_id')->toString());

        if ($deviceKey instanceof JsonResponse) {
            return $deviceKey;
        }

        $result = $this->sync->ingest($deviceKey, $request->validated()['events']);

        $clean = $result['flagged'] === 0 && $result['rejected'] === 0;

        return response()->json([
            'accepted' => $result['accepted'],
            'flagged' => $result['flagged'],
            'rejected' => $result['rejected'],
            'last_chain_hash' => $result['last_chain_hash'],
            'results' => $result['results'],
        ], $clean ? 200 : 207);
    }

    /**
     * The server's current chain tip for a device.
     *
     * Exists for one failure the sync engine cannot otherwise survive: a batch
     * the server fully commits, whose response is then lost in transit. The
     * device sees a network error and retries the same batch — but the server's
     * tip has already moved past it, so every event fails as prev_hash_mismatch
     * and the device would mark genuinely accepted records as rejected.
     *
     * So before sending, the device asks where the server actually is, marks
     * everything up to that hash as already confirmed, and sends only what
     * follows. The hash is a digest, not a secret — knowing it does not help
     * anyone forge the next link, which needs the HMAC key.
     */
    public function status(Request $request): JsonResponse
    {
        $deviceKey = $this->resolveDevice($request, (string) $request->query('device_id', ''));

        if ($deviceKey instanceof JsonResponse) {
            return $deviceKey;
        }

        return response()->json([
            'device_id' => $deviceKey->device_id,
            'last_chain_hash' => $deviceKey->last_chain_hash,
        ]);
    }

    /** Scoped to the authenticated foreman, and refuses revoked devices. */
    private function resolveDevice(Request $request, string $deviceId): DeviceKey|JsonResponse
    {
        $deviceKey = DeviceKey::query()
            ->where('device_id', $deviceId)
            // A device id from the request can never reach someone else's device.
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
            // perfectly valid signatures, so revocation is enforced here rather
            // than left to the crypto to fail.
            return response()->json([
                'message' => 'This device has been revoked. Re-bind it before syncing.',
                'reason' => 'device_revoked',
            ], 403);
        }

        return $deviceKey;
    }
}
