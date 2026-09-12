<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BindDeviceRequest;
use App\Models\DeviceKey;
use App\Services\Crypto\DeviceBindingService;
use App\Services\Crypto\SignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Foreman device binding (Phase 5) — backs the Foreman Device Binding screen
 * (docs/prototypes/HRIS Foreman Device Binding.dc.html).
 *
 * Routes are role:foreman gated; a foreman only ever acts on their own
 * devices, which is enforced by scoping every query to the authenticated
 * employee rather than trusting an id from the request.
 */
class DeviceController extends Controller
{
    public function __construct(
        private DeviceBindingService $binding,
        private SignatureVerifier $signatureVerifier,
    ) {}

    public function bind(BindDeviceRequest $request): JsonResponse
    {
        $publicKeyPem = $request->string('public_key')->toString();

        $inspection = $this->binding->validatePublicKey($publicKeyPem);

        if (! $inspection['valid']) {
            // 422 rather than 500: a malformed or wrong-curve key is bad input,
            // and the reason is safe to return — it tells an honest client what
            // to fix and tells an attacker nothing they did not already know.
            return response()->json([
                'message' => 'The submitted device public key was rejected.',
                'reason' => $inspection['reason'],
                'expected_curve' => config('crypto.signature.curve'),
            ], 422);
        }

        $securityLevel = $request->input('security_level');

        if (! $this->signatureVerifier->isAcceptableSecurityLevel($securityLevel)) {
            return response()->json([
                'message' => 'This device cannot be bound: its key is not hardware-backed.',
                'reason' => 'security_level_not_accepted',
                'reported_security_level' => $securityLevel,
                'accepted' => config('crypto.accepted_security_levels'),
            ], 422);
        }

        $result = $this->binding->bind(
            $request->user(),
            $request->string('device_id')->toString(),
            $publicKeyPem,
            $securityLevel,
        );

        $deviceKey = $result['device_key'];

        return response()->json([
            'device_id' => $deviceKey->device_id,
            'bound_at' => $deviceKey->bound_at,
            'security_level' => $deviceKey->security_level,
            'hardware_backed' => in_array(
                $deviceKey->security_level,
                config('crypto.accepted_security_levels', []),
                true,
            ),
            'rebound' => $result['rebound'],

            /*
             | Returned exactly once. There is no endpoint that will hand this
             | back later — losing it means rebinding, which is the correct
             | trade: a retrievable shared secret is one HTTP bug away from
             | being an extractable one.
             */
            'hmac_key' => $result['hmac_key_base64'],
        ], $result['rebound'] ? 200 : 201);
    }

    /** The foreman's own bound devices. Never exposes hmac_key (model $hidden). */
    public function index(Request $request): JsonResponse
    {
        $devices = DeviceKey::where('employee_id', $request->user()->employee_id)
            ->orderByDesc('bound_at')
            ->get()
            ->map(fn (DeviceKey $device) => [
                'device_id' => $device->device_id,
                'security_level' => $device->security_level,
                'hardware_backed' => in_array(
                    $device->security_level,
                    config('crypto.accepted_security_levels', []),
                    true,
                ),
                'bound_at' => $device->bound_at,
                'revoked_at' => $device->revoked_at,
                'has_chain_history' => $device->last_chain_hash !== null,
            ]);

        return response()->json(['devices' => $devices]);
    }

    public function revoke(Request $request, string $deviceId): JsonResponse
    {
        // Scoped to the authenticated foreman: a foreman cannot revoke someone
        // else's device by guessing its id.
        $deviceKey = DeviceKey::where('employee_id', $request->user()->employee_id)
            ->where('device_id', $deviceId)
            ->firstOrFail();

        if ($deviceKey->isRevoked()) {
            return response()->json(['message' => 'This device is already revoked.'], 422);
        }

        $this->binding->revoke(
            $deviceKey,
            $request->user(),
            $request->input('reason', 'revoked by foreman'),
        );

        return response()->json(['device_id' => $deviceId, 'revoked_at' => $deviceKey->fresh()->revoked_at]);
    }
}
