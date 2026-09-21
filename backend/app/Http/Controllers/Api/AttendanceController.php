<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceRecordsRequest;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Attendance records for the web portal's DTR (Fig 20.0).
 *
 * Sync (Phase 5) writes attendance; nothing yet lets a browser read it back.
 * This endpoint is that read side: per-site/per-crew/per-employee listing with
 * the credited and real tap times, sync/recovery state, and flagged overrides
 * and stated time-outs — the "attendance monitoring interface" SDD §4.4.4
 * promises, on top of the DTR view §5.1.5 describes.
 *
 * Visibility per role (web nav matrix `attendance`):
 *  - HR, Admin, Executive: everything the filters allow.
 *  - Site Engineer: clamped to their own home site. A site_id they send is
 *    silently ignored (the clamp is applied, never widened by their filter),
 *    and an engineer with no home site gets a 403 rather than an empty or a
 *    world-visible answer. This mirrors ReportsController.
 *  - Site Foreman: exactly the records they could have taken — a record is
 *    visible only if they led its crew at the instant the tap was captured,
 *    falling back to crew.foreman_id when there is no leadership history for
 *    that crew. This is CrewLeadership::leads() reproduced per record, so
 *    "who could have recorded it" equals "who can see it" (one-day cover sees
 *    exactly that one day, not the crew's whole history).
 *
 * Filters are applied after the role scope, so they can only narrow it.
 */
class AttendanceController extends Controller
{
    public function records(AttendanceRecordsRequest $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validated();

        $base = $this->baseScope($user)
            ->whereBetween('date', [$filters['from'], $filters['to']]);

        if ($user->role?->slug === 'engineer') {
            abort_unless($user->site_id, 403, 'No home site is set for this engineer, so there is no attendance to show.');
            $base->whereHas('crew', fn (Builder $c) => $c->where('site_id', $user->site_id));
        } elseif ($request->filled('site_id')) {
            $base->whereHas('crew', fn (Builder $c) => $c->where('site_id', $filters['site_id']));
        }

        // Filters narrow the scope; the role scope above is already applied.
        $filtered = (clone $base)
            ->when($request->filled('crew_id'), fn (Builder $q) => $q->where('crew_id', $filters['crew_id']))
            ->when($request->filled('employee_id'), fn (Builder $q) => $q->where('employee_id', $filters['employee_id']));

        $page = (clone $filtered)
            ->with(['employee', 'crew.site', 'cryptoSignature', 'overrideEvent', 'timeOutEvent'])
            ->orderBy('date')
            ->orderBy('employee_id')
            ->paginate($filters['per_page'] ?? 50, ['*'], 'page');

        $records = collect($page->items())->map(
            fn (Attendance $attendance) => $this->map($attendance),
        );

        // The crew picker lists the crews actually present in the site + date
        // scope, so it stays useful even while an employee filter is active.
        // An engineer sees only their own site's crews, because $base already
        // carries the clamp.
        $crewIds = (clone $base)->whereNotNull('crew_id')->distinct()->pluck('crew_id');
        $crewOptions = Crew::query()->whereIn('crew_id', $crewIds)
            ->orderBy('crew_name')->get(['crew_id', 'crew_name']);

        return response()->json([
            'data' => $records,
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'pages' => $page->lastPage(),
            ],
            'summary' => $this->summary(clone $filtered),
            'crew_options' => $crewOptions,
        ]);
    }

    /**
     * The role scope, applied before any filter so a filter can only narrow.
     *
     * Foreman: CrewLeadership::leads() per record. A crew without leadership
     * history is led only by its current foreman, so any record whose capture
     * happened with no period on file resolves to crew.foreman_id — as does a
     * record with no capture instant at all (a reconstructed recovery record).
     * Otherwise the record is visible only if a leadership period by this
     * foreman covered the capture instant (started_at <= captured_at exclusive
     * of ended_at). This is the same instant rule the sync acceptance path
     * uses, so the two surfaces cannot disagree.
     */
    private function baseScope(Employee $user): Builder
    {
        return Attendance::query()->when(
            $user->role?->slug === 'foreman',
            fn (Builder $q) => $q->where(function (Builder $where) use ($user) {
                $where->where(
                    fn (Builder $fallback) => $fallback
                        ->whereHas('crew', fn (Builder $c) => $c->where('foreman_id', $user->employee_id))
                        ->where(
                            fn (Builder $needsFallback) => $needsFallback
                                ->whereNull('captured_at')
                                ->orWhereDoesntHave('crew.leadershipHistory'),
                        ),
                )->orWhere(function ($period) use ($user) {
                    $period->whereExists(function ($exists) use ($user): void {
                        $exists->selectRaw('1')
                            ->from('crew_assignments as ca')
                            ->whereColumn('ca.crew_id', 'attendances.crew_id')
                            ->where('ca.employee_id', $user->employee_id)
                            ->whereIn('ca.assignment_type', CrewAssignment::LEADERSHIP_TYPES)
                            ->whereColumn('ca.started_at', '<=', 'attendances.captured_at')
                            ->where(function ($bounds) {
                                $bounds->whereNull('ca.ended_at')
                                    ->orWhereColumn('ca.ended_at', '>', 'attendances.captured_at');
                            });
                    });
                });
            }),
        );
    }

    /**
     * Counts over the whole filtered set, independent of pagination, so the
     * stat cards always add up to what the report will contain.
     */
    private function summary(Builder $query): array
    {
        $statuses = (clone $query)
            ->select('status')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $flagged = (clone $query)
            ->where(
                fn (Builder $q) => $q
                    ->orWhereHas('overrideEvent', fn (Builder $a) => $a->where('review_status', AuditLog::REVIEW_PENDING))
                    ->orWhereHas('timeOutEvent', fn (Builder $a) => $a->where('review_status', AuditLog::REVIEW_PENDING)),
            )
            ->count();

        return [
            'present' => (int) ($statuses['present'] ?? 0),
            'late' => (int) ($statuses['late'] ?? 0),
            'absent' => (int) ($statuses['absent'] ?? 0),
            'pending' => (int) ($statuses['pending'] ?? 0),
            'flagged' => $flagged,
            'total' => (int) $statuses->sum(),
        ];
    }

    /** One DTR row. Nothing secret leaves the server: no hashes, no signatures. */
    private function map(Attendance $attendance): array
    {
        // Stored times are instants kept in app.timezone (UTC); the site clock
        // they describe is attendance.timezone (Asia/Manila, +08:00 — the same
        // conversion RecoveryService does on its reconstructed rows). Without
        // it a 7:00 AM tap reads as 23:00 on the DTR.
        $tz = config('attendance.timezone', 'Asia/Manila');

        return [
            'attendance_id' => $attendance->attendance_id,
            'date' => $attendance->date,
            'status' => $attendance->status,
            'time_in' => $attendance->time_in?->copy()->setTimezone($tz)->format('H:i'),
            'captured_at' => $attendance->captured_at?->copy()->setTimezone($tz)->format('H:i'),
            'time_out' => $attendance->time_out?->copy()->setTimezone($tz)->format('H:i'),
            'time_out_type' => $attendance->time_out_type,
            'sync_status' => $attendance->sync_status,
            'override_flag' => $attendance->override_flag,
            'reconstructed' => $attendance->isReconstructed(),
            'payroll_ready' => $attendance->isPayrollReady(),
            'has_pending_override_review' => $attendance->overrideEvent?->review_status === AuditLog::REVIEW_PENDING,
            'has_pending_time_out_review' => $attendance->time_out_type === Attendance::TIME_OUT_MANUAL
                && $attendance->timeOutEvent?->review_status === AuditLog::REVIEW_PENDING,
            'employee' => [
                'employee_id' => $attendance->employee->employee_id,
                'employee_code' => $attendance->employee->employee_code,
                'full_name' => $attendance->employee->full_name,
                'trade_skill' => $attendance->employee->trade_skill,
            ],
            'crew' => [
                'crew_id' => $attendance->crew?->crew_id,
                'crew_name' => $attendance->crew?->crew_name,
            ],
            'site' => [
                'site_id' => $attendance->crew?->site?->site_id,
                'site_name' => $attendance->crew?->site?->site_name,
            ],
        ];
    }
}
