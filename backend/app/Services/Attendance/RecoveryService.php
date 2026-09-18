<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\DeviceKey;
use App\Models\Employee;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Retroactive crew recovery (Phase 7 — UC-07): "review and sign off on crew
 * attendance for days where no logging occurred" (SPMP glossary).
 *
 * A gap is a deployed crew's working day, already over, with no attendance
 * recorded for anyone on it. A Site Engineer reconstructs the day from the
 * roster and says why it was missing (first signature); HR checks it and
 * signs it off or sends it back (second signature). Nothing a phone did not
 * sign is paid until both have signed.
 *
 * Deliberately scoped: there are no gate biometrics to corroborate against, so
 * reconstructed records are marked as such rather than passed off as
 * captured. The one corroboration available is the foreman's own phone — see
 * phoneCheck().
 */
class RecoveryService
{
    public const CAUSES = ['foreman_absent', 'phone_problem', 'records_rejected', 'no_work', 'other'];

    public const STAGE_AWAITING_ENGINEER = 'awaiting_engineer';

    public const STAGE_AWAITING_HR = 'awaiting_hr';

    public const STAGE_RETURNED = 'returned';

    public const STAGE_CLOSED = 'closed';

    public function __construct(private readonly CrewLeadership $leadership) {}

    /**
     * Every crew-day in the lookback window that needs recovering or has been
     * recovered: open gaps first, then cases by stage.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function queue(?int $siteId = null): Collection
    {
        [$from, $to] = $this->window();

        $cases = $this->cases()
            ->whereBetween('subject_date', [$from, $to])
            ->when($siteId, fn ($q) => $q->whereHas('crew', fn ($c) => $c->where('site_id', $siteId)))
            ->with('crew.site', 'crew.activeMembers.employee', 'overriddenAttendances.employee')
            ->get();

        $items = $cases->map(fn (AuditLog $case) => $this->caseItem($case));

        return $this->gaps($from, $to, $siteId, $cases)
            ->concat($items)
            ->sortBy([
                fn ($a, $b) => $this->stageOrder($a['stage']) <=> $this->stageOrder($b['stage']),
                fn ($a, $b) => strcmp($b['date'], $a['date']),
            ])
            ->values();
    }

    /** One crew-day, with its roster and whatever has been proposed for it. */
    public function detail(Crew $crew, string $date): array
    {
        $crew->loadMissing('site', 'activeMembers.employee');
        $case = $this->caseFor($crew, $date);
        $proposed = $case?->overriddenAttendances()->with('employee')->get()->keyBy('employee_id') ?? collect();

        // The roster as it stands, plus anyone the case already covers who has
        // since left the crew — a reconstructed record must stay visible.
        $employees = $crew->activeMembers->pluck('employee')
            ->concat($proposed->pluck('employee'))
            ->filter()
            ->unique('employee_id')
            ->sortBy('last_name')
            ->values();

        return array_merge(
            $case === null ? $this->gapItem($crew, $date) : $this->caseItem($case),
            [
                'shift_start' => config('attendance.shift_start', '07:00'),
                'engineer_note' => $case?->description,
                'hr_note' => $case?->review_note,
                'engineer' => $this->person($case?->actor),
                'engineer_signed_at' => $case?->timestamp?->toIso8601String(),
                'hr' => $this->person($case?->reviewer),
                'hr_signed_at' => $case?->reviewed_at?->toIso8601String(),
                'roster' => $employees->map(fn (Employee $employee) => [
                    'employee' => $this->worker($employee),
                    'proposed' => $this->proposal($proposed->get($employee->employee_id), $case),
                ])->values(),
                'history' => $this->history($crew, $date),
            ],
        );
    }

    /**
     * The engineer's reconstruction — the first signature. Also the
     * resubmission after HR returns a case.
     *
     * @param  array<int, array{employee_id:int, status:string, time_in:?string}>  $records
     */
    public function submit(Crew $crew, string $date, string $cause, string $note, array $records, Employee $engineer): AuditLog
    {
        return DB::transaction(function () use ($crew, $date, $cause, $note, $records, $engineer) {
            $crew->loadMissing('activeMembers.employee');
            $case = $this->caseFor($crew, $date, lock: true);

            $this->guardRecoverable($crew, $date, $case);

            $records = $cause === 'no_work' ? [] : $this->validatedRecords($crew, $date, $records);

            $case ??= new AuditLog([
                'action_type' => AuditLog::RETROACTIVE_RECOVERY,
                'crew_id' => $crew->crew_id,
                'subject_date' => $date,
            ]);

            $case->fill([
                'actor_id' => $engineer->employee_id,
                'timestamp' => Carbon::now(),
                'description' => $note,
                'reason_code' => $cause,
                'review_status' => AuditLog::REVIEW_PENDING,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_note' => null,
            ])->save();

            // A resubmission replaces the previous draft outright. Those rows
            // were never paid on and never signed off; the history entry below
            // keeps what changed.
            $case->overriddenAttendances()->where('sync_status', Attendance::RECONSTRUCTED)->delete();

            foreach ($records as $record) {
                Attendance::query()->create([
                    'employee_id' => $record['employee_id'],
                    'crew_id' => $crew->crew_id,
                    'date' => $date,
                    'status' => $record['status'],
                    'time_in' => $record['time_in'],
                    'captured_at' => null,
                    'monotonic_timestamp' => null,
                    'sync_status' => Attendance::RECONSTRUCTED,
                    'override_flag' => Attendance::RECONSTRUCTED,
                    'override_audit_id' => $case->audit_id,
                ]);
            }

            $this->step($case, AuditLog::RECOVERY_SUBMITTED, $engineer, sprintf(
                'Reconstructed %s for %s: %s. Cause: %s. %s',
                $date,
                $crew->crew_name,
                $this->tally($records),
                $cause,
                $note,
            ));

            return $case->fresh();
        });
    }

    /** HR's second signature: the reconstruction is accepted and payroll may use it. */
    public function signOff(AuditLog $case, Employee $hr, ?string $note): AuditLog
    {
        return $this->review($case, $hr, AuditLog::REVIEW_APPROVED, $note, AuditLog::RECOVERY_SIGNED_OFF);
    }

    /** HR sends it back to the engineer, saying what is wrong. Nothing is paid meanwhile. */
    public function returnToEngineer(AuditLog $case, Employee $hr, string $note): AuditLog
    {
        return $this->review($case, $hr, AuditLog::REVIEW_RETURNED, $note, AuditLog::RECOVERY_RETURNED);
    }

    /**
     * What the foreman's phone can say about the day.
     *
     * A phone's records form one chain in capture order and are accepted
     * strictly in that order. So once the server holds a tap from the foreman's
     * phone captured after this day, anything taken on this day has already
     * arrived — nothing is still waiting on the phone, and recovering the day
     * cannot collide with records about to sync. Until then, it might be.
     *
     * @return array{status:string, detail:string}
     */
    public function phoneCheck(Crew $crew, string $date): array
    {
        $noon = Carbon::parse("{$date} 12:00", $this->timezone());
        $leaderId = $this->leadership->leaderAt($crew, $noon);

        $device = $leaderId === null ? null : DeviceKey::query()
            ->where('employee_id', $leaderId)
            ->whereNull('revoked_at')
            ->orderByDesc('bound_at')
            ->first();

        if ($device === null) {
            return [
                'status' => 'no_phone',
                'detail' => 'The foreman who led the crew that day has no phone set up for roll call.',
            ];
        }

        $dayEndMs = Carbon::parse($date, $this->timezone())->endOfDay()->getTimestampMs();

        if ($device->last_captured_at !== null && (int) $device->last_captured_at > $dayEndMs) {
            return [
                'status' => 'nothing_waiting',
                'detail' => "The foreman's phone has sent roll call taken after this day, so nothing from this day is still waiting on it.",
            ];
        }

        return [
            'status' => 'may_be_on_phone',
            'detail' => "The foreman's phone has not sent anything taken after this day. Roll call may still be on it: have the foreman sync before recovering the day.",
        ];
    }

    /** @return array{0:string, 1:string} from and to, inclusive, as site dates */
    public function window(): array
    {
        $today = Carbon::now($this->timezone())->startOfDay();

        return [
            $today->copy()->subDays((int) config('attendance.recovery_lookback_days', 14))->toDateString(),
            $today->copy()->subDay()->toDateString(),
        ];
    }

    public function caseFor(Crew $crew, string $date, bool $lock = false): ?AuditLog
    {
        return $this->cases()
            ->where('crew_id', $crew->crew_id)
            ->where('subject_date', $date)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->with('actor', 'reviewer', 'crew.site', 'crew.activeMembers.employee', 'overriddenAttendances.employee')
            ->first();
    }

    public function findCase(int $caseId): AuditLog
    {
        return $this->cases()->with('crew.site', 'crew.activeMembers.employee', 'overriddenAttendances.employee')->findOrFail($caseId);
    }

    private function cases()
    {
        return AuditLog::query()->where('action_type', AuditLog::RETROACTIVE_RECOVERY);
    }

    /** Open gaps: working days with no attendance and no case. */
    private function gaps(string $from, string $to, ?int $siteId, Collection $cases): Collection
    {
        $crews = Crew::query()
            ->with('site', 'activeMembers.employee')
            ->where('status', 'deployed')
            ->whereNotNull('deployed_at')
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->get();

        $recorded = Attendance::query()
            ->whereBetween('date', [$from, $to])
            ->whereIn('crew_id', $crews->pluck('crew_id'))
            ->get(['crew_id', 'date'])
            ->mapWithKeys(fn ($row) => [$row->crew_id.'|'.substr((string) $row->date, 0, 10) => true]);

        $covered = $cases->mapWithKeys(fn (AuditLog $case) => [$case->crew_id.'|'.$case->subject_date => true]);
        $workDays = config('attendance.work_days', [1, 2, 3, 4, 5, 6]);

        return $crews->flatMap(function (Crew $crew) use ($from, $to, $recorded, $covered, $workDays) {
            $deployed = $crew->deployed_at->copy()->setTimezone($this->timezone())->toDateString();
            $day = Carbon::parse(max($from, $deployed), $this->timezone());
            $last = Carbon::parse($to, $this->timezone());
            $gaps = [];

            for (; $day->lte($last); $day->addDay()) {
                $key = $crew->crew_id.'|'.$day->toDateString();

                if (in_array($day->dayOfWeekIso, $workDays, true) && ! isset($recorded[$key]) && ! isset($covered[$key])) {
                    $gaps[] = $this->gapItem($crew, $day->toDateString());
                }
            }

            return $gaps;
        });
    }

    private function gapItem(Crew $crew, string $date): array
    {
        $members = $crew->activeMembers->pluck('employee')->filter();

        return [
            'crew_id' => $crew->crew_id,
            'crew_name' => $crew->crew_name,
            'site' => $crew->site === null ? null : ['site_id' => $crew->site->site_id, 'site_name' => $crew->site->site_name],
            'date' => $date,
            'stage' => self::STAGE_AWAITING_ENGINEER,
            'case_id' => null,
            'code' => null,
            'cause' => null,
            'records' => $members->count(),
            'hours' => round($members->count() * $this->hoursPerDay(), 2),
            'amount' => round($members->sum(fn (Employee $e) => (float) $e->daily_rate), 2),
            'phone' => $this->phoneCheck($crew, $date),
        ];
    }

    private function caseItem(AuditLog $case): array
    {
        $worked = $case->overriddenAttendances->whereIn('status', ['present', 'late']);

        return [
            'crew_id' => $case->crew_id,
            'crew_name' => $case->crew?->crew_name,
            'site' => $case->crew?->site === null ? null : ['site_id' => $case->crew->site->site_id, 'site_name' => $case->crew->site->site_name],
            'date' => $case->subject_date,
            'stage' => match ($case->review_status) {
                AuditLog::REVIEW_APPROVED => self::STAGE_CLOSED,
                AuditLog::REVIEW_RETURNED => self::STAGE_RETURNED,
                default => self::STAGE_AWAITING_HR,
            },
            'case_id' => $case->audit_id,
            'code' => $case->recoveryCode(),
            'cause' => $case->reason_code,
            'records' => $case->overriddenAttendances->count(),
            'hours' => round($worked->count() * $this->hoursPerDay(), 2),
            'amount' => round($worked->sum(fn (Attendance $a) => (float) $a->employee?->daily_rate), 2),
            'phone' => $case->crew === null ? null : $this->phoneCheck($case->crew, $case->subject_date),
        ];
    }

    private function guardRecoverable(Crew $crew, string $date, ?AuditLog $case): void
    {
        $today = Carbon::now($this->timezone())->toDateString();

        if ($date >= $today) {
            throw $this->unprocessable('Only a day that is over can be recovered. Today’s roll call is still the foreman’s.');
        }

        $deployed = $crew->deployed_at?->copy()->setTimezone($this->timezone())->toDateString();

        if ($deployed === null || $date < $deployed) {
            throw $this->unprocessable("{$crew->crew_name} was not deployed on {$date}.");
        }

        if ($case !== null && $case->review_status !== AuditLog::REVIEW_RETURNED) {
            throw $this->unprocessable($case->review_status === AuditLog::REVIEW_APPROVED
                ? 'This day has already been recovered and signed off.'
                : 'This day is already waiting for HR. It can be changed if HR returns it.');
        }

        $captured = Attendance::query()
            ->where('crew_id', $crew->crew_id)
            ->where('date', $date)
            ->where(fn ($q) => $q->whereNull('sync_status')->orWhere('sync_status', '!=', Attendance::RECONSTRUCTED))
            ->exists();

        if ($captured) {
            throw $this->unprocessable('Roll call from the foreman’s phone exists for this day, so there is nothing to recover.');
        }
    }

    /**
     * Every current crew member exactly once, each with a status, and a time
     * for anyone who worked. Returns the rows to write (not_on_crew dropped).
     */
    private function validatedRecords(Crew $crew, string $date, array $records): array
    {
        $members = $crew->activeMembers->pluck('employee')->filter()->keyBy('employee_id');
        $given = collect($records)->keyBy(fn ($r) => (int) $r['employee_id']);

        $missing = $members->keys()->diff($given->keys());
        $unknown = $given->keys()->diff($members->keys());

        if ($missing->isNotEmpty() || $unknown->isNotEmpty() || $given->count() !== count($records)) {
            throw $this->unprocessable('Give every worker on the crew exactly one status.');
        }

        $shiftStart = Carbon::parse("{$date} ".config('attendance.shift_start', '07:00'), $this->timezone());
        $rows = [];

        foreach ($given as $employeeId => $record) {
            if ($record['status'] === 'not_on_crew') {
                continue;
            }

            $timeIn = null;

            if (in_array($record['status'], ['present', 'late'], true)) {
                $timeIn = Carbon::parse("{$date} {$record['time_in']}", $this->timezone());

                if ($record['status'] === 'late' && $timeIn->lte($shiftStart)) {
                    throw $this->unprocessable("{$members[$employeeId]->full_name} is marked Late but arrives by shift start.");
                }
            }

            $elsewhere = Attendance::query()
                ->where('employee_id', $employeeId)
                ->where('date', $date)
                ->where('crew_id', '!=', $crew->crew_id)
                ->exists();

            if ($elsewhere) {
                throw $this->unprocessable("{$members[$employeeId]->full_name} already has attendance with another crew that day.");
            }

            $rows[] = ['employee_id' => $employeeId, 'status' => $record['status'], 'time_in' => $timeIn?->utc()];
        }

        return $rows;
    }

    private function review(AuditLog $case, Employee $hr, string $decision, ?string $note, string $step): AuditLog
    {
        return DB::transaction(function () use ($case, $hr, $decision, $note, $step) {
            $case = $this->cases()->lockForUpdate()->findOrFail($case->audit_id);

            if ($case->review_status !== AuditLog::REVIEW_PENDING) {
                throw $this->unprocessable('Only a recovery waiting for HR can be signed off or returned.');
            }

            $case->update([
                'review_status' => $decision,
                'reviewed_by' => $hr->employee_id,
                'reviewed_at' => Carbon::now(),
                'review_note' => $note,
            ]);

            $this->step($case, $step, $hr, $note ?? '');

            return $case->fresh();
        });
    }

    /** One history entry per step, so a return and resubmission leave a trail. */
    private function step(AuditLog $case, string $action, Employee $actor, string $description): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->employee_id,
            'action_type' => $action,
            'crew_id' => $case->crew_id,
            'subject_date' => $case->subject_date,
            'timestamp' => Carbon::now(),
            'description' => trim($description),
        ]);
    }

    private function history(Crew $crew, string $date): Collection
    {
        return AuditLog::query()
            ->whereIn('action_type', AuditLog::RECOVERY_STEPS)
            ->where('crew_id', $crew->crew_id)
            ->where('subject_date', $date)
            ->with('actor')
            ->orderBy('timestamp')
            ->orderBy('audit_id')
            ->get()
            ->map(fn (AuditLog $step) => [
                'action' => $step->action_type,
                'actor' => $this->person($step->actor),
                'at' => $step->timestamp?->toIso8601String(),
                'note' => $step->description,
            ]);
    }

    private function proposal(?Attendance $attendance, ?AuditLog $case): ?array
    {
        if ($case === null) {
            return null;
        }

        if ($attendance === null) {
            // Covered by the case but no row written: left off the crew that
            // day, or the whole day was marked as no work.
            return ['status' => $case->reason_code === 'no_work' ? 'no_work' : 'not_on_crew', 'time_in' => null];
        }

        return [
            'status' => $attendance->status,
            'time_in' => $attendance->time_in?->copy()->setTimezone($this->timezone())->format('H:i'),
        ];
    }

    private function tally(array $records): string
    {
        if ($records === []) {
            return 'no attendance records';
        }

        $counts = collect($records)->countBy('status');

        return collect(['present', 'late', 'absent'])
            ->filter(fn ($status) => $counts->has($status))
            ->map(fn ($status) => "{$counts[$status]} {$status}")
            ->join(', ');
    }

    private function stageOrder(string $stage): int
    {
        return array_search($stage, [
            self::STAGE_RETURNED,
            self::STAGE_AWAITING_ENGINEER,
            self::STAGE_AWAITING_HR,
            self::STAGE_CLOSED,
        ], true);
    }

    private function worker(Employee $employee): array
    {
        return [
            'employee_id' => $employee->employee_id,
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'trade_skill' => $employee->trade_skill,
        ];
    }

    private function person(?Employee $employee): ?array
    {
        return $employee === null ? null : [
            'employee_id' => $employee->employee_id,
            'full_name' => $employee->full_name,
        ];
    }

    private function hoursPerDay(): float
    {
        return (float) config('payroll.rates.regular_hours_per_day', 8);
    }

    private function timezone(): string
    {
        return config('attendance.timezone', 'Asia/Manila');
    }

    private function unprocessable(string $message): HttpResponseException
    {
        return new HttpResponseException(new JsonResponse(['message' => $message], 422));
    }
}
