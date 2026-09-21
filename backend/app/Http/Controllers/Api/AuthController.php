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
     * via HR's "reset portal access"; it does not prevent it. A failure that
     * could reveal whether a code or a DOB is valid is one generic message,
     * and only a successful activation differs. The password-policy failures
     * are the exception: they hinge on nothing but the submitted values, so
     * each answers with its own message and bumps only the per-IP counter
     * (review, 2026-09-21). A successful activation is audit-logged with the
     * IP, which is meaningful because the API trusts X-Forwarded-For only from
     * the trusted proxy range. The route sits with the /portal sign-in surface
     * (W3), so both doors open at once.
     */
    public function activate(Request $request): JsonResponse
    {
        // date_format:Y-m-d instead of 'date': the rule otherwise accepts full
        // datetimes, and a W3 date picker sending toISOString() would hand a
        // UTC+8 birthday back as the previous calendar day. It also refuses
        // arbitrary resolvable strings like 'now' or 'tomorrow'. The password's
        // length is decided below with the other policy checks, so each can
        // answer with its own message.
        $validator = Validator::make($request->all(), [
            'employee_code' => ['required', 'string', 'max:8'],
            'date_of_birth' => ['required', 'date_format:Y-m-d'],
            'password' => ['required', 'string'],
        ]);

        // Malformed input — an array for employee_code, a datetime that is not
        // strict Y-m-d — is refused by the same generic message as any other,
        // before any value is cast, so nothing can throw.
        if ($validator->fails()) {
            $this->ipOnlyBump($request);
            $this->activationFailure();
        }

        $data = $validator->validated();

        // Rate-limiter keys are derived only from validated (string) values.
        $rawCode = trim($data['employee_code']);
        $codeKey = 'activate:code:'.sha1(strtolower($rawCode));
        $ipKey = 'activate:ip:'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($codeKey, self::MAX_ATTEMPTS)
            || RateLimiter::tooManyAttempts($ipKey, self::ACTIVATION_IP_MAX_ATTEMPTS)) {
            $this->activationFailure();
        }

        // Password policy, decided BEFORE any account lookup: none of these
        // checks has exercised the code or the date of birth, so each answers
        // with its own message and bumps only the per-IP counter — a worker
        // fumbling their password must not burn the per-code attempts that
        // stop an attacker guessing a date of birth (review, 2026-09-21).
        $password = $data['password'];

        if (mb_strlen($password) < 8) {
            $this->ipOnlyBump($request);
            $this->passwordFailure('Password must be at least 8 characters.');
        }

        if (strtolower($password) === strtolower($rawCode)) {
            $this->ipOnlyBump($request);
            $this->passwordFailure('Password cannot be the same as your employee ID.');
        }

        if ($this->passwordIsUnreadable($password)) {
            $this->ipOnlyBump($request);
            $this->passwordFailure('Password contains characters we could not read.');
        }

        if ($this->passwordContainsDateOfBirth($password, $data['date_of_birth'])) {
            $this->ipOnlyBump($request);
            $this->passwordFailure('Password cannot contain your date of birth.');
        }

        // Both counters are bumped on every failure from here down — unknown
        // code included — so a runaway attempt on a misspelled code is still
        // throttled by IP. On success only the per-code key is cleared; the
        // per-IP key decays on its own, since clearing it would let one worker
        // spam a shared IP.
        $bump = function () use ($codeKey, $ipKey): void {
            RateLimiter::hit($codeKey, self::LOCK_SECONDS);
            RateLimiter::hit($ipKey, self::LOCK_SECONDS);
        };

        $employee = Employee::query()->where('employee_code', $rawCode)->first();

        if ($employee === null) {
            $bump();
            $this->activationFailure();
        }

        if ($employee->password !== null
            || $employee->employment_status === 'separated'
            || ! $employee->role?->isPortalRole()
            || $data['date_of_birth'] !== $employee->date_of_birth?->toDateString()) {
            $bump();
            $this->activationFailure();
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
            ->update(['password' => Hash::make($password)]);

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
        // endpoint as staff (which the portal opened in W3).
        return response()->json([
            'message' => 'Portal activated — you can now sign in.',
        ], 201);
    }

    /**
     * POST /api/auth/password — the own-account password change (Add-on B,
     * FR-11, W3). Any signed-in role may use it, portal or staff; a worker's
     * change lands in the same password column HR's reset touches.
     *
     * No forced rotation at first sign-in (team decision, 2026-09-21): HR's
     * temporary password IS the login credential until the worker changes it
     * here. Nothing in this flow revokes the current token or any other — the
     * worker stays signed in and their other sessions stay live.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $employee = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Guessing the current password is throttled per account+IP, exactly
        // like login. The new_password policy failures below never bump the
        // counter: they hinge on the new value alone, so no guess is being
        // exercised.
        $key = 'password:'.sha1($employee->employee_id.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json([
                'message' => 'Too many failed attempts. Account locked for 15 minutes. Retry in '
                    .ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        if (! Hash::check($validated['current_password'], $employee->getAuthPassword())) {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            throw ValidationException::withMessages([
                'current_password' => 'Your current password does not match.',
            ]);
        }

        if (Hash::check($validated['new_password'], $employee->getAuthPassword())) {
            throw ValidationException::withMessages([
                'new_password' => 'Your new password must differ from the current one.',
            ]);
        }

        if (strtolower($validated['new_password']) === strtolower((string) $employee->employee_code)) {
            throw ValidationException::withMessages([
                'new_password' => 'Password cannot be the same as your employee ID.',
            ]);
        }

        if ($this->passwordIsUnreadable($validated['new_password'])) {
            throw ValidationException::withMessages([
                'new_password' => 'Password contains characters we could not read.',
            ]);
        }

        if ($employee->date_of_birth !== null
            && $this->passwordContainsDateOfBirth($validated['new_password'], $employee->date_of_birth->toDateString())) {
            throw ValidationException::withMessages([
                'new_password' => 'Password cannot contain your date of birth.',
            ]);
        }

        RateLimiter::clear($key);

        $employee->password = $validated['new_password'];
        $employee->save();

        AuditLog::create([
            'actor_id' => $employee->employee_id,
            'action_type' => AuditLog::PASSWORD_CHANGED,
            'description' => $employee->employee_code.' changed the '.($employee->role?->slug ?? 'unknown')
                .' password from '.$request->ip().'.',
            'timestamp' => now(),
        ]);

        return response()->json(['message' => 'Password updated.']);
    }

    private function activationFailure(): never
    {
        throw ValidationException::withMessages([
            'employee_code' => "Can't activate — contact HR.",
        ]);
    }

    /** A password-policy failure: its own message, never the generic one. */
    private function passwordFailure(string $message): never
    {
        throw ValidationException::withMessages([
            'password' => $message,
        ]);
    }

    /** Activation's anti-spray backstop per IP. */
    private function ipOnlyBump(Request $request): void
    {
        RateLimiter::hit('activate:ip:'.sha1((string) $request->ip()), self::LOCK_SECONDS);
    }

    /**
     * Whether the password could not be parsed at all. A malformed UTF-8 byte
     * makes the separator-aware regex return null; refusing is the honest
     * outcome, because the fallback split would let a separator-written
     * birthday escape through a stray high byte (review, 2026-09-21).
     */
    private function passwordIsUnreadable(string $password): bool
    {
        return preg_replace('/(?<=\d)[^\p{L}\d]+(?=\d)/u', '', $password) === null;
    }

    /**
     * Whether the password's digit runs spell the given date of birth in any
     * common ordering — full and two-digit years, day-month-year variants, and
     * the bare day-month/month-day pair. A separator run of any non-letter,
     * non-digit characters (dash, slash, dot, space, underscore, doubled
     * forms) is removed only when it sits between two digits, so
     * "juan05_12_1990" reads as the single run 05121990 while
     * "Moon1-Kite4-Lion0-Star7" keeps runs 1, 4, 0, 7 — the dashes sit between
     * a digit and a letter, which is punctuation, not a date.
     */
    private function passwordContainsDateOfBirth(string $password, string $dateOfBirth): bool
    {
        $separatorAware = preg_replace('/(?<=\d)[^\p{L}\d]+(?=\d)/u', '', $password);

        if ($separatorAware === null) {
            return true;
        }

        $dob = Carbon::parse($dateOfBirth);
        $renderings = [
            $dob->format('Ymd'), $dob->format('dmY'), $dob->format('mdY'),
            $dob->format('ymd'), $dob->format('dmy'), $dob->format('mdy'),
            $dob->format('dm'), $dob->format('md'),
        ];

        preg_match_all('/\d+/', $separatorAware, $matches);

        foreach ($matches[0] as $digitRun) {
            foreach ($renderings as $rendering) {
                if (str_contains($digitRun, $rendering)) {
                    return true;
                }
            }
        }

        return false;
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
