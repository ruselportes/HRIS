<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ERD: tbl_audit_log (audit_id, actor_id → employee, action_type,
 * description, timestamp). actor_id is required: system-side events that have
 * no human actor are written to application logs, not this trail.
 *
 * Phase 7 additions (all nullable, unused by ordinary entries): an override
 * event also names its crew and day, and carries HR's review decision, since
 * TC-04 makes the audit entry itself the record of the override. A recovery
 * case (UC-07) works the same way, with its cause in reason_code.
 */
class AuditLog extends Model
{
    public const LATE_OVERRIDE = 'FOREMAN_LATE_OVERRIDE';

    public const MANUAL_TIME_OVERRIDE = 'MANUAL_TIME_OVERRIDE';

    /** A time-out the foreman stated rather than tapped (as in the Late Override Audit prototype). */
    public const MANUAL_TIME_OUT = 'MANUAL_TIME_OUT';

    /** A Site Engineer handed a crew to an acting foreman (UC-06, TC-05). */
    public const ACTING_FOREMAN_ASSIGNED = 'ACTING_FOREMAN_ASSIGNED';

    /** The cover ended — early by the engineer, or on its own at expiry. */
    public const ACTING_FOREMAN_ENDED = 'ACTING_FOREMAN_ENDED';

    /**
     * A reconstructed crew-day (UC-07): one per crew and day, signed by a Site
     * Engineer and then by HR. Its current state lives on this row.
     */
    public const RETROACTIVE_RECOVERY = 'RETROACTIVE_RECOVERY';

    /** Each step of a recovery, kept as its own entry so the history survives. */
    public const RECOVERY_SUBMITTED = 'RECOVERY_SUBMITTED';

    public const RECOVERY_RETURNED = 'RECOVERY_RETURNED';

    public const RECOVERY_SIGNED_OFF = 'RECOVERY_SIGNED_OFF';

    public const RECOVERY_STEPS = [self::RECOVERY_SUBMITTED, self::RECOVERY_RETURNED, self::RECOVERY_SIGNED_OFF];

    /** HR approved a payroll run's ready rows (Phase 8). */
    public const PAYROLL_APPROVED = 'PAYROLL_APPROVED';

    /** A worker activated their own portal account (Add-on B, FR-11). */
    public const PORTAL_ACTIVATED = 'PORTAL_ACTIVATED';

    /** HR reset a portal account with a temporary password (Add-on B, FR-11). */
    public const PORTAL_ACCESS_RESET = 'PORTAL_ACCESS_RESET';

    /** A user changed their own password (Add-on B, FR-11, W3). */
    public const PASSWORD_CHANGED = 'PASSWORD_CHANGED';

    /** Project site administration (C2, UC-03): created, renamed, closed, reopened. */
    public const SITE_CREATED = 'SITE_CREATED';

    public const SITE_UPDATED = 'SITE_UPDATED';

    public const SITE_CLOSED = 'SITE_CLOSED';

    public const SITE_REOPENED = 'SITE_REOPENED';

    /** An admin signed a person out of every session (C5, UC-01). */
    public const SESSIONS_REVOKED = 'SESSIONS_REVOKED';

    /** An admin changed someone's role (C5, UC-01): who, from what, to what. */
    public const ROLE_CHANGED = 'ROLE_CHANGED';

    /**
     * Leave & Overtime workflow steps (Phase 9, UC-10). One family for both
     * request kinds: the description carries the type and id, so the list is
     * uniform and the intent (endorse/approve/reject/cancel/reassign) is
     * searchable. These entries never carry crew_id or subject_date, so they
     * stay out of the override and integrity feeds without any extra filter.
     */
    public const REQUEST_SUBMITTED = 'REQUEST_SUBMITTED';

    public const REQUEST_ENDORSED = 'REQUEST_ENDORSED';

    public const REQUEST_APPROVED = 'REQUEST_APPROVED';

    public const REQUEST_REJECTED = 'REQUEST_REJECTED';

    public const REQUEST_CANCELLED = 'REQUEST_CANCELLED';

    public const REQUEST_ENDORSER_REASSIGNED = 'REQUEST_ENDORSER_REASSIGNED';

    /**
     * Integrity failure entries (layers 1–3 of the integrity engine). A flagged
     * event passed signature and chain but failed the monotonic-clock check;
     * a verification failure failed the chain or the signature wholesale.
     */
    public const ATTENDANCE_CLOCK_FLAGGED = 'ATTENDANCE_CLOCK_FLAGGED';

    public const ATTENDANCE_VERIFICATION_FAILED = 'ATTENDANCE_VERIFICATION_FAILED';

    /** Authentic but not permitted — a stale roster, not an integrity failure. */
    public const ATTENDANCE_REFUSED = 'ATTENDANCE_REFUSED';

    /** What counts as an integrity failure for the compliance score. */
    public const INTEGRITY_TYPES = [self::ATTENDANCE_CLOCK_FLAGGED, self::ATTENDANCE_VERIFICATION_FAILED];

    /** Action types that group override records and go through HR review. */
    public const OVERRIDE_TYPES = [self::LATE_OVERRIDE, self::MANUAL_TIME_OVERRIDE, self::MANUAL_TIME_OUT];

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    /** Recovery only: HR sent it back to the engineer to correct. */
    public const REVIEW_RETURNED = 'returned';

    protected $primaryKey = 'audit_id';

    protected $fillable = [
        'actor_id',
        'crew_id',
        'subject_date',
        'action_type',
        'description',
        'timestamp',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'reason_code',
    ];

    protected function casts(): array
    {
        // subject_date is deliberately NOT cast: a date cast stores
        // "Y-m-d 00:00:00" on SQLite, and the per-crew-day lookup compares
        // against a plain "Y-m-d", so every sync would create a duplicate event.
        return [
            'timestamp' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actor_id', 'employee_id');
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(Crew::class, 'crew_id', 'crew_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by', 'employee_id');
    }

    /** The attendance records an override event credited. */
    public function overriddenAttendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'override_audit_id', 'audit_id');
    }

    /** Records whose manual time-out this event reviews. */
    public function timeOutAttendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'time_out_audit_id', 'audit_id');
    }

    /** The records under review: time-outs for a manual time-out event, credited time ins otherwise. */
    public function reviewedAttendances()
    {
        return $this->action_type === self::MANUAL_TIME_OUT ? $this->timeOutAttendances : $this->overriddenAttendances;
    }

    public function scopeOverrideEvents(Builder $query): Builder
    {
        return $query->whereIn('action_type', self::OVERRIDE_TYPES);
    }

    /**
     * Integrity incidents — one per distinct foreman-day, not per audit row.
     * A single tamper orphans every later event in the batch
     * (chain_broken_upstream), so a plain count would charge one attack
     * twenty times over, and a clock rollback flags a whole batch the same
     * way. The compliance score must count the attack, not its fallout.
     *
     * The "day" is the foreman's day in attendance.timezone (the app runs in
     * UTC, so the two disagree by up to eight hours): each row's day is worked
     * out in PHP, not date(timestamp) in SQL, and the period bounds are
     * converted to UTC. A rejected event's claimed date is untrusted, so its
     * day comes from the server's receive time — the audit timestamp — which
     * is also the day the incident becomes known to HR.
     *
     * When several rows of one foreman-day name different crews, the earliest
     * row (lowest audit_id) decides which crew is charged, keeping the
     * attribution deterministic. That decision happens before any site filter,
     * so a foreman-day is charged to exactly one site and the per-site figures
     * sum to the global total.
     *
     * @return array<int, array{day: ?string, crew_id: int|null}>
     */
    public static function integrityIncidents(
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $siteId = null,
    ): array {
        $timezone = config('attendance.timezone', 'Asia/Manila');

        // The site day runs Manila 00:00→24:00, i.e. UTC 16:00→16:00, so the
        // bounds are shifted to UTC before they ever meet the column — and the
        // per-row day is computed in Manila after the rows are read.
        $fromUtc = Carbon::instance($from)->setTimezone($timezone)->startOfDay()->setTimezone('UTC');
        $toExclusiveUtc = Carbon::instance($to)->setTimezone($timezone)->copy()->addDay()->startOfDay()->setTimezone('UTC');

        $rows = self::query()
            ->select(['audit_id', 'actor_id', 'crew_id', 'timestamp'])
            ->whereIn('action_type', self::INTEGRITY_TYPES)
            ->where('timestamp', '>=', $fromUtc)
            ->where('timestamp', '<', $toExclusiveUtc)
            ->orderBy('audit_id')
            ->get();

        // A foreman (the actor_id is the device owner) who flags or fails on
        // one day is one incident, even if a whole batch of rows arrived
        // together. The lowest audit_id row is kept, so the crew attribution
        // above is stable.
        $rows = $rows->unique(fn (AuditLog $row): string => (string) $row->actor_id.'|'.$row->dayIn($timezone));

        $incidents = $rows->map(
            fn (AuditLog $row): array => ['day' => $row->dayIn($timezone), 'crew_id' => $row->crew_id],
        )->values()->all();

        if ($siteId === null) {
            return $incidents;
        }

        $siteByCrew = Crew::query()
            ->whereIn('crew_id', collect($incidents)->pluck('crew_id')->filter()->all())
            ->pluck('site_id', 'crew_id');

        // Dedupe happened above, so this filter can only drop, never double
        // count: each foreman-day is charged to exactly one site.
        return collect($incidents)->filter(
            fn (array $row): bool => $row['crew_id'] !== null
                && (int) ($siteByCrew[$row['crew_id']] ?? 0) === $siteId,
        )->values()->all();
    }

    /** The Manila day this row was received on, per attendance.timezone. */
    private function dayIn(string $timezone): ?string
    {
        return $this->timestamp?->copy()->setTimezone($timezone)->toDateString();
    }

    /**
     * Integrity incidents grouped per OWNER — the figure the Device & Sync
     * Health page shows beside each device (review: incidents are counted per
     * owner and labelled as such, because the audit log carries device_id only
     * in description text, which must never be parsed).
     *
     * Same rule as integrityIncidents(): one incident per distinct foreman-day
     * per owner, so a single attack that orphans a whole batch does not count
     * twenty times over. The flood is the foreman's, not the batch's.
     *
     * @return array<int, int> owner employee_id => distinct incident days
     */
    public static function integrityIncidentsByOwner(): array
    {
        $timezone = config('attendance.timezone', 'Asia/Manila');

        $rows = self::query()
            ->select(['audit_id', 'actor_id', 'timestamp'])
            ->whereIn('action_type', self::INTEGRITY_TYPES)
            ->whereNotNull('actor_id')
            ->orderBy('audit_id')
            ->get();

        return $rows
            ->unique(fn (AuditLog $row): string => (string) $row->actor_id.'|'.$row->dayIn($timezone))
            ->groupBy('actor_id')
            ->map->count()
            ->all();
    }

    public function isOverrideEvent(): bool
    {
        return in_array($this->action_type, self::OVERRIDE_TYPES, true);
    }

    /** Human-facing reference, e.g. OVR-2026-0014, as shown in the prototype. */
    public function overrideCode(): string
    {
        return sprintf('OVR-%s-%04d', $this->timestamp?->format('Y') ?? now()->format('Y'), $this->audit_id);
    }

    /** "REC-2026-0012" — how a recovery case is referred to on screen. */
    public function recoveryCode(): string
    {
        return sprintf('REC-%s-%04d', substr((string) $this->subject_date, 0, 4) ?: now()->format('Y'), $this->audit_id);
    }
}
