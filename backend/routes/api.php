<?php

use App\Http\Controllers\Api\ActingForemanController;
use App\Http\Controllers\Api\AdminDeviceController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceSyncController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CrewController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ForemanController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\OverrideController;
use App\Http\Controllers\Api\OvertimeRequestController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\RecoveryController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\ReportsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth (public)
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    // auth.activate is NOT registered yet: it opens in W3 together with the
    // /portal sign-in surface, so activation and login work at the same
    // moment (review, 2026-09-21) and no dead endpoint sits on the tunnel.
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
});

/*
|--------------------------------------------------------------------------
| Authenticated API
|--------------------------------------------------------------------------
*/

// portal.scope is the deny-by-default gate for the worker portal (Add-on B,
// FR-11): listed between auth and acting.expire, and registered before
// SubstituteBindings in bootstrap/app.php's priority list, so a denied worker
// gets an exact 403 that beats model binding (no fake-id 404 shadow) and runs
// before acting.expire's database write. Route names are security-relevant —
// the allowlist lives in EnsurePortalScope::ALLOWED_PORTAL_ROUTES.
// acting.expire ends acting foreman covers that have run out before anything
// reads crew leadership (Phase 7, UC-06).
Route::middleware(['auth:sanctum', 'portal.scope', 'acting.expire'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
    });

    Route::get('roles', [ReferenceController::class, 'roles']);
    Route::get('sites', [ReferenceController::class, 'sites']);

    // Crew builder (UC-03). Writes are engineer-only via CrewPolicy; the role
    // middleware is defense-in-depth. {crew} binds Crew for show/update.
    Route::prefix('crews')->middleware('role:hr,engineer,executive')->group(function () {
        Route::get('/', [CrewController::class, 'index']);

        // Must precede apiResource-style {crew} routes so 'pool' isn't bound.
        Route::get('pool', [CrewController::class, 'pool']);

        Route::post('/', [CrewController::class, 'store'])->middleware('role:engineer');
        Route::get('{crew}', [CrewController::class, 'show']);
        Route::put('{crew}', [CrewController::class, 'update'])->middleware('role:engineer');
        Route::put('{crew}/foreman', [CrewController::class, 'foreman'])->middleware('role:engineer');
        Route::post('{crew}/members', [CrewController::class, 'assignMembers'])->middleware('role:engineer');
        Route::post('{crew}/deploy', [CrewController::class, 'deploy'])->middleware('role:engineer');
        Route::delete('{crew}/members/{employee}', [CrewController::class, 'removeMember'])->middleware('role:engineer');

        // Acting foreman cover (Phase 7, UC-06 / TC-05).
        Route::get('{crew}/acting-candidates', [ActingForemanController::class, 'candidates'])->middleware('role:engineer');
        Route::post('{crew}/acting-foreman', [ActingForemanController::class, 'store'])->middleware('role:engineer');
        Route::delete('{crew}/acting-foreman', [ActingForemanController::class, 'destroy'])->middleware('role:engineer');
    });

    Route::get('deployment', [CrewController::class, 'deployment'])->middleware('role:hr,engineer,executive');

    // Mobile roster fetch (UC-04) — foreman's own deployed crew only.
    Route::get('me/crew', [ForemanController::class, 'myCrew'])->middleware('role:foreman');

    // Add-on B (FR-11, UC-11) — the worker portal's own-data reads. The
    // controllers derive the scope from the signed-in employee; there is no
    // employee id parameter, and me/payslips only ever shows APPROVED runs
    // (a draft row, a guessed id, or someone else's run reads as 404). {run}
    // is the payroll row id, bound by a digit run no longer than a 32-bit id
    // — whereNumber alone would let a 20-digit value through to the int
    // parameter as a TypeError 500 — never a route model, so the
    // middleware-403 ordering stays out of the picture. Staff roles may call
    // these too and get their own data (deliberate: harmless self-service,
    // and portal roles are still confined to exactly these by portal.scope).
    Route::get('me/attendance', [PortalController::class, 'attendance'])->name('me.attendance');
    Route::get('me/payslips', [PortalController::class, 'payslips'])->name('me.payslips');
    Route::get('me/payslips/{run}', [PortalController::class, 'payslip'])
        ->where('run', '[0-9]{1,10}')
        ->name('me.payslips.show');

    // Device binding (Phase 5) — the trust anchor for the integrity engine.
    // Foreman-only, and every handler scopes to the authenticated employee so
    // a device id from the request can never reach someone else's device.
    Route::prefix('me/devices')->middleware('role:foreman')->group(function () {
        Route::get('/', [DeviceController::class, 'index']);
        Route::post('/', [DeviceController::class, 'bind']);
        Route::delete('{deviceId}', [DeviceController::class, 'revoke']);
    });

    // Attendance sync ingestion (Phase 5 layer 4). Returns 207 when any event
    // was flagged or rejected, so a partial result is not indistinguishable
    // from a clean one.
    Route::post('attendance/sync', [AttendanceSyncController::class, 'store'])
        ->middleware('role:foreman');

    // Server chain tip, for the sync engine to reconcile after a lost response.
    Route::get('attendance/sync/status', [AttendanceSyncController::class, 'status'])
        ->middleware('role:foreman');

    // Attendance & DTR (the web portal's Fig 20.0 read side — Phase 10). Same
    // roles as the nav `attendance` matrix: HR full, Site Foreman full (but
    // scoped to the crews they led per the AttendanceController), Site
    // Engineer full (clamped to their home site), Admin/Executive view.
    Route::get('attendance/records', [AttendanceController::class, 'records'])
        ->middleware('role:hr,foreman,engineer,admin,executive');

    // Device & Sync Health (Phase 10) — the nav `synchealth` matrix: HR view,
    // Admin full. Listing and revocation both hit the same registry; HR may
    // read the field-device fleet, only Admin may cut a device out of it. The
    // revoke route is bound to the DeviceKey by its primary key, so a body id
    // can never reach a device the URL did not name.
    Route::prefix('devices')->group(function () {
        Route::get('/', [AdminDeviceController::class, 'index'])
            ->middleware('role:hr,admin')
            ->name('devices.index');

        Route::delete('{device}', [AdminDeviceController::class, 'revoke'])
            ->whereNumber('device')
            ->middleware('role:admin')
            ->name('devices.revoke');
    });

    // Overrides & Audit (Phase 7, UC-05). Viewing per the nav matrix, foremen
    // scoped to their own in the controller; only HR decides, since approval
    // changes pay.
    Route::prefix('overrides')->middleware('role:hr,engineer,admin,foreman')->group(function () {
        Route::get('/', [OverrideController::class, 'index'])->name('overrides.index');
        Route::get('{override}', [OverrideController::class, 'show'])->whereNumber('override')->name('overrides.show');
        Route::post('{override}/approve', [OverrideController::class, 'approve'])->whereNumber('override')->middleware('role:hr');
        Route::post('{override}/reject', [OverrideController::class, 'reject'])->whereNumber('override')->middleware('role:hr');
    });

    // Attendance Recovery (Phase 7, UC-07). The engineer reconstructs a
    // crew-day and HR signs it off — each signature held to its own role.
    Route::prefix('recovery')->middleware('role:hr,engineer,admin')->group(function () {
        Route::get('/', [RecoveryController::class, 'index']);
        Route::get('{crew}/{date}', [RecoveryController::class, 'show'])->where('date', '\d{4}-\d{2}-\d{2}');
        Route::post('{crew}/{date}', [RecoveryController::class, 'submit'])->where('date', '\d{4}-\d{2}-\d{2}')->middleware('role:engineer');
        Route::post('cases/{case}/sign-off', [RecoveryController::class, 'signOff'])->whereNumber('case')->middleware('role:hr');
        Route::post('cases/{case}/return', [RecoveryController::class, 'returnToEngineer'])->whereNumber('case')->middleware('role:hr');
    });

    // Payroll (Phase 8, UC-08). HR computes and approves; executives read.
    Route::prefix('payroll/runs')->middleware('role:hr,executive')->group(function () {
        $code = '\d{4}-\d{2}-[AB]';
        Route::get('/', [PayrollController::class, 'index']);
        Route::get('{code}', [PayrollController::class, 'show'])->where('code', $code);
        Route::get('{code}/employees/{employee}', [PayrollController::class, 'payslip'])->where('code', $code);
        Route::post('{code}/compute', [PayrollController::class, 'compute'])->where('code', $code)->middleware('role:hr');
        Route::post('{code}/approve', [PayrollController::class, 'approve'])->where('code', $code)->middleware('role:hr');
    });

    // Holiday calendar (Phase 8). HR keeps it; others who deal with pay or
    // attendance may read it.
    Route::prefix('holidays')->middleware('role:hr,executive,admin,engineer')->group(function () {
        Route::get('/', [HolidayController::class, 'index']);
        Route::post('/', [HolidayController::class, 'store'])->middleware('role:hr');
        Route::delete('{holiday}', [HolidayController::class, 'destroy'])->middleware('role:hr');
    });

    // Leave & Overtime Filing and Approval (Phase 9, UC-10). Executive is
    // read-only; Admin has no row in the navigation matrix for leave at all,
    // so it is excluded here. Visibility inside the lists is decided by
    // RequestReadScope. Endorse is the assigned endorser's step (any login
    // role could be assigned), approve/reject/reassign are HR's, cancel is
    // the filer's.
    Route::prefix('leaves')->middleware('role:hr,engineer,foreman,executive')->group(function () {
        Route::get('/', [LeaveRequestController::class, 'index']);
        Route::post('/', [LeaveRequestController::class, 'store'])->middleware('role:hr,engineer,foreman');
        Route::get('{leave}', [LeaveRequestController::class, 'show'])->whereNumber('leave');
        Route::post('{leave}/endorse', [LeaveRequestController::class, 'endorse'])->whereNumber('leave')->middleware('role:hr,engineer,foreman');
        Route::post('{leave}/approve', [LeaveRequestController::class, 'approve'])->whereNumber('leave')->middleware('role:hr');
        Route::post('{leave}/reject', [LeaveRequestController::class, 'reject'])->whereNumber('leave')->middleware('role:hr');
        Route::post('{leave}/cancel', [LeaveRequestController::class, 'cancel'])->whereNumber('leave')->middleware('role:hr,engineer,foreman');
        Route::post('{leave}/reassign-endorser', [LeaveRequestController::class, 'reassignEndorser'])->whereNumber('leave')->middleware('role:hr');
    });

    Route::prefix('overtimes')->middleware('role:hr,engineer,foreman,executive')->group(function () {
        Route::get('/', [OvertimeRequestController::class, 'index']);
        Route::post('/', [OvertimeRequestController::class, 'store'])->middleware('role:hr,engineer,foreman');
        Route::post('batch-approve', [OvertimeRequestController::class, 'batchApprove'])->middleware('role:hr');
        Route::get('{overtime}', [OvertimeRequestController::class, 'show'])->whereNumber('overtime');
        Route::post('{overtime}/endorse', [OvertimeRequestController::class, 'endorse'])->whereNumber('overtime')->middleware('role:hr,engineer,foreman');
        Route::post('{overtime}/approve', [OvertimeRequestController::class, 'approve'])->whereNumber('overtime')->middleware('role:hr');
        Route::post('{overtime}/reject', [OvertimeRequestController::class, 'reject'])->whereNumber('overtime')->middleware('role:hr');
        Route::post('{overtime}/cancel', [OvertimeRequestController::class, 'cancel'])->whereNumber('overtime')->middleware('role:hr,engineer,foreman');
        Route::post('{overtime}/reassign-endorser', [OvertimeRequestController::class, 'reassignEndorser'])->whereNumber('overtime')->middleware('role:hr');
    });

    // Reports & Analytics (Phase 9, UC-09/FR-09), matching the nav `reports`
    // matrix: HR full, Site Engineer view, Executive full. Both endpoints are
    // read-only. Every filter (from/to pair, site_id, per_page, page, action)
    // is validated by ReportsQueryRequest.
    Route::prefix('reports')->middleware('role:hr,engineer,executive')->group(function () {
        Route::get('overview', [ReportsController::class, 'overview']);
        Route::get('audit', [ReportsController::class, 'audit']);
    });

    // Must precede apiResource so 'next-code' isn't captured as {employee}.
    Route::middleware('role:hr,admin')->get('employees/next-code', [EmployeeController::class, 'nextCode']);

    // Add-on B (FR-11): HR recovers a hijacked or withdrawn portal account.
    // portal.scope is in the group above the apiResource, so a worker probing
    // another worker's id gets an exact 403 from the middleware before the
    // route model even binds.
    Route::middleware('role:hr,admin')->post('employees/{employee}/reset-portal-access', [EmployeeController::class, 'resetPortalAccess']);

    Route::apiResource('employees', EmployeeController::class)->except('destroy');
});
