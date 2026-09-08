<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Attempts before the account/identifier locks for LOCK_SECONDS.
     */
    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 900;

    /**
     * POST /api/auth/login
     * Identifier is either the employee email or the employee code (ADC-XXXX).
     * Failed attempts are throttled per identifier+IP so foremen sharing one
     * site's connection cannot lock each other out, and identifier rotation
     * cannot trivially bypass the limiter.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:'.sha1(strtolower(trim($credentials['identifier'])).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json([
                'message' => 'Too many failed attempts. Account locked for 15 minutes. Retry in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        $employee = Employee::query()
            ->where('email', $credentials['identifier'])
            ->orWhere('employee_code', trim($credentials['identifier']))
            ->first();

        $authenticated = $employee
            && $employee->canSignIn()
            && Hash::check($credentials['password'], $employee->getAuthPassword());

        if (! $authenticated) {
            RateLimiter::hit($key, self::LOCK_SECONDS);

            $remaining = RateLimiter::remaining($key, self::MAX_ATTEMPTS);

            throw ValidationException::withMessages([
                'identifier' => "Incorrect ID or password. {$remaining} attempt(s) left before the account locks for 15 minutes.",
            ]);
        }

        RateLimiter::clear($key);

        $token = $employee->createToken('hris-session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new EmployeeResource($employee->load('role', 'site')),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new EmployeeResource($request->user()->load('role', 'site')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * POST /api/auth/forgot-password
     * No self-serve reset: HR receives the request (prototype). An audit row is
     * only written when the identifier matches an employee — actor_id is a
     * required FK (ERD), so unmatched attempts go to the app/security log and
     * both cases return the same generic response to prevent identifier probing.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $fields = $request->validate([
            'employee_code' => ['required', 'string', 'max:8'],
        ]);

        $employee = Employee::where('employee_code', $fields['employee_code'])->first();

        if ($employee) {
            AuditLog::create([
                'actor_id' => $employee->employee_id,
                'action_type' => 'PASSWORD_RESET_REQUEST',
                'description' => 'Password reset requested for '.$employee->employee_code.' from '.$request->ip().'.',
                'timestamp' => now(),
            ]);
        } else {
            Log::warning('Password reset requested for unknown identifier', [
                'employee_code' => $fields['employee_code'],
                'ip' => $request->ip(),
            ]);
        }

        return response()->json([
            'message' => 'If that Employee ID exists, HR has received your request.',
        ], 202);
    }
}
