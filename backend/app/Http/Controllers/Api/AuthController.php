<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Attempts before the account/identifier locks for LOCK_SECONDS.
     */
    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 900;

    /**
     * Activation's anti-spray backstop per IP (Add-on B, FR-11). Deliberately
     * loose: office and site staff share connections. The tight per-code limit
     * above is what stops guessing a date of birth, since an attacker controls
     * their own IP.
     */
    private const ACTIVATION_IP_MAX_ATTEMPTS = 30;

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
     * POST /api/auth/activate — Add-on B (FR-11) worker portal activation.
     *
     * The activation secret is employee code + date of birth + a new password.
     * It is deliberately weak — codes are sequential and coworkers know each
     * other's birthdays — so this endpoint DETECTS abuse and recovers from it
     * via HR's "reset portal access"; it does not prevent it. Every failure
     * returns the same generic message, so the endpoint cannot probe which
     * codes exist or which birthday is right; only a successful activation
     * differs. A successful activation is audit-logged with the IP, which is
     * meaningful because the API trusts X-Forwarded-For only from the trusted
     * proxy range.
     */
    public function activate(Request $request): JsonResponse
    {
        $rawCode = trim((string) $request->input('employee_code'));
        $codeKey = $rawCode !== '' ? 'activate:code:'.sha1(strtolower($rawCode)) : null;
        $ipKey = 'activate:ip:'.sha1((string) $request->ip());

        // Both counters are bumped on every failure — unknown code included —
        // so a runaway attempt on a misspelled code is still throttled by IP.
        // On success only the per-code key is cleared; the per-IP key decays on
        // its own, since clearing it would let one worker spam a shared IP.
        $bump = function () use ($codeKey, $ipKey): void {
            if ($codeKey !== null) {
                RateLimiter::hit($codeKey, self::LOCK_SECONDS);
            }
            RateLimiter::hit($ipKey, self::LOCK_SECONDS);
        };

        $validator = Validator::make($request->all(), [
            'employee_code' => ['required', 'string', 'max:8'],
            'date_of_birth' => ['required', 'date'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            $bump();
            $this->activationFailure();
        }

        $data = $validator->validated();

        if (RateLimiter::tooManyAttempts((string) $codeKey, self::MAX_ATTEMPTS)
            || RateLimiter::tooManyAttempts($ipKey, self::ACTIVATION_IP_MAX_ATTEMPTS)) {
            $this->activationFailure();
        }

        if (strtolower(trim($data['password'])) === strtolower($rawCode)) {
            $bump();
            $this->activationFailure();
        }

        $employee = Employee::query()->where('employee_code', $rawCode)->first();

        if ($employee === null) {
            $bump();
            $this->activationFailure();
        }

        if ($employee->password !== null
            || $employee->employment_status === 'separated'
            || ! $employee->role?->isPortalRole()
            || Carbon::parse($data['date_of_birth'])->toDateString() !== $employee->date_of_birth?->toDateString()) {
            $bump();
            $this->activationFailure();
        }

        // The password's digit string must not spell the date of birth in any
        // common ordering — full and two-digit years, day-month-year variants,
        // and the bare day-month / month-day pair. Keeping birthdays out of
        // passwords is the one the worker can silently satisfy.
        $passwordDigits = preg_replace('/\D+/', '', $data['password']);
        $dob = Carbon::parse($data['date_of_birth']);
        $dobRenderings = [
            $dob->format('Ymd'), $dob->format('dmY'), $dob->format('mdY'),
            $dob->format('ymd'), $dob->format('dmy'), $dob->format('mdy'),
            $dob->format('dm'), $dob->format('md'),
        ];

        foreach ($dobRenderings as $rendering) {
            if (str_contains((string) $passwordDigits, $rendering)) {
                $bump();
                $this->activationFailure();
            }
        }

        // Atomic: only an employee with no password can be activated, so a
        // worker and an attacker racing to the same code have exactly one
        // winner. The hashed cast does not apply to query-builder updates, so
        // the hash is made here explicitly.
        $affected = Employee::query()
            ->where('employee_code', $rawCode)
            ->whereNull('password')
            ->where('employment_status', '!=', 'separated')
            ->whereHas('role', fn ($query) => $query->whereIn('slug', Role::PORTAL_SLUGS))
            ->update(['password' => Hash::make($data['password'])]);

        if ($affected !== 1) {
            $this->activationFailure();
        }

        $employee->refresh();

        AuditLog::create([
            'actor_id' => $employee->employee_id,
            'action_type' => AuditLog::PORTAL_ACTIVATED,
            'description' => 'Worker portal activated for '.$employee->employee_code.' from '.$request->ip().'.',
            'timestamp' => now(),
        ]);

        RateLimiter::clear((string) $codeKey);

        // No token is issued: the worker signs in through the same login
        // endpoint as staff (which the portal opens in W3).
        return response()->json([
            'message' => 'Portal activated — you can now sign in.',
        ], 201);
    }

    private function activationFailure(): never
    {
        throw ValidationException::withMessages([
            'employee_code' => "Can't activate — contact HR.",
        ]);
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
