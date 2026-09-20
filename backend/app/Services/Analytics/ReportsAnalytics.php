<?php

namespace App\Services\Analytics;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\Site;
use App\Support\CertificationStatus;
use App\Support\ResilientCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reports & Analytics dashboard (Phase 9 — UC-09, FR-09), matching the
 * Executive Dashboard prototype (`/docs/prototypes/HRIS Executive
 * Dashboard.dc.html`): one scorecard per site with company-wide headline
 * KPIs, a labour cost figure split regular/overtime, and a flagged audit
 * feed.
 *
 * Scores use the FR-09 formula 100 − (2×expired certifications) − (1×
 * overrides) − (5×integrity incidents), clamped to 0–100. Bands follow the
 * prototype's tag vocabulary: ≥85 good, 80–84 fair, <80 watch. Every site
 * is scored from its own three components. The company-wide headline score
 * is the headcount-weighted average of the per-site scores — not the formula
 * run on summed components, which a large headcount would drive to zero; a
 * single busy site can never sink every compliant site. FR-09 records this.
 *
 * Attribution basis is "where the work happened", one rule per dataset, and
 * the docblock states it because the row is otherwise treacherous:
 * headcount, certifications, attendance and labour cost are charged to the
 * employee's home site (employees.site_id); overrides are charged to the
 * crew the override row names (audit_logs.crew_id → crews.site_id), and an
 * override by a foreman running a crew at another site charges the crew's
 * site, not the foreman's; integrity incidents are charged to their crew's
 * site too. A site gets a row only if it is a work site — a crew is assigned
 * to it, or field staff (a worker, operator or foreman) are homed there — and
 * some dataset mentions it in the window. An office-only site (Head Office)
 * never does, not even when its staff take leave, and a vacant one does not
 * either, unless explicitly drilled into via site_id.
 *
 * Window: a ring of Manila days (default the last 30). `from`/`to` are
 * inclusive days; the audit timestamps they map to are stored in UTC, so the
 * day bounds are converted to UTC before comparing. Both endpoints are
 * inclusive in *dates* but exclusive at the day boundary: an event at
 * exactly 00:00 of the day after `to` is outside the window.
 *
 * Absences on a crew's rest days (config payroll.rest_day_iso) and on
 * proclaimed holidays are not counted — crews work those days (TC-06's
 * fixture rolls a Sunday and the 31 Aug holiday), so a rest-day absence is
 * not an absence from scheduled work. Present/late rolls on those days still
 * count, since the crew actually worked.
 *
 * Overtime is counted by the request's work date (ot_date), approved rows
 * only, so a request lands in the window of the day it covered. Labour cost
 * is the plan's basis: approved payroll rows only, split regular/overtime by
 * payslip line, counted by the line's date, and prefiltered to runs whose
 * pay period overlaps the window so the breakdown store is never read
 * wholesale. Draft rows are never counted.
 */
class ReportsAnalytics
{
    /**
     * Two minutes. The dashboard reads attendance, payroll and the audit log
     * for a whole window on every load, so it is cached; a write to any of
     * those bumps the `reports` namespace (AppServiceProvider::INVALIDATES),
     * and this ttl is the ceiling if a bump is ever missed. `generated_at` in
     * the payload is the moment the figures were computed, so the screen shows
     * when what it displays was true.
     */
    private const TTL = 120;

    public function __construct(private readonly ResilientCache $cache) {}

    /** Bands per the prototype's tags: 95/92/88 → good, 81 → fair, 67 → watch. */
    public const BAND_GOOD = 'good';

    public const BAND_FAIR = 'fair';

    public const BAND_WATCH = 'watch';

    public const GOOD_MIN = 85;

    public const FAIR_MIN = 80;

    /** Audit event families the dashboard's "flagged" feed shows. */
    public const FLAGGED_TYPES = [
        AuditLog::RETROACTIVE_RECOVERY,
        ...AuditLog::OVERRIDE_TYPES,
        ...AuditLog::INTEGRITY_TYPES,
        ...AuditLog::RECOVERY_STEPS,
    ];

    public function overview(?string $from = null, ?string $to = null, ?int $siteId = null): array
    {
        return $this->cache->remember(
            'reports',
            'overview:'.($from ?? 'default').':'.($to ?? 'default').':'.($siteId ?? 'all'),
            self::TTL,
            fn (): array => $this->computeOverview($from, $to, $siteId),
        );
    }

    /** @return array<string, mixed> */
    private function computeOverview(?string $from, ?string $to, ?int $siteId): array
    {
        $timezone = config('attendance.timezone', 'Asia/Manila');
        $from = $from ?? Carbon::now($timezone)->subDays(29)->toDateString();
        $to = $to ?? Carbon::now($timezone)->toDateString();

        // The score's "today" is the window's own last day, in Manila, not the
        // calendar date of the request and not UTC (which lags Manila by 8h
        // each morning): a historical window must score the way the company
        // would have seen it on that day, and never drift as days pass.
        $asOf = Carbon::parse($to, $timezone)->startOfDay();

        [$fromUtc, $toExclusiveUtc] = $this->boundsUtc($from, $to);
        $fromDay = Carbon::parse($from, $timezone)->startOfDay();
        $toDay = Carbon::parse($to, $timezone)->startOfDay();

        $employees = $this->eligibleEmployees($siteId);
        $expiredByEmployee = $employees->mapWithKeys(
            fn (Employee $e) => [$e->employee_id => CertificationStatus::for($e->certification, $asOf)['expired']],
        );

        $overrides = $this->overridesBySite($fromUtc, $toExclusiveUtc, $siteId);
        $incidents = AuditLog::integrityIncidents($fromDay, $toDay);
        $incidentsBySite = $this->incidentsBySite($incidents);
        $incidentsTotal = count($incidents);

        if ($siteId !== null) {
            // A site filter scopes the incident totals to that site too;
            // incidents are crew-attributed, so this is the only dataset where
            // the crew's site decides, not the actor's (an integrity flag's
            // actor is the device owner, whose home site may be elsewhere).
            $incidentsBySite = $incidentsBySite->filter(fn ($n, $site) => (int) $site === (int) $siteId);
            $incidentsTotal = (int) $incidentsBySite->sum();
        }

        $attendance = $this->attendanceBySite($from, $to, $siteId);
        $overtime = $this->approvedOvertime($from, $to, $siteId);
        $leaves = $this->approvedLeaves($from, $to, $siteId);
        $labour = $this->labourCost($from, $to, $siteId);

        $siteIds = $this->reportSiteIds($employees, $attendance, $overrides, $incidentsBySite, $labour, $leaves, $siteId);
        $sitesById = Site::query()->whereIn('site_id', $siteIds)->get()->keyBy('site_id');

        $siteRows = $siteIds->map(fn (int $id): array => $this->siteRow(
            $id,
            $sitesById->get($id)?->site_name,
            $employees->where('site_id', $id),
            $expiredByEmployee,
            $attendance->get($id),
            (int) ($overrides->get($id) ?? 0),
            (int) ($incidentsBySite->get($id) ?? 0),
            $labour['by_site'][$id] ?? ['total' => 0.0, 'regular' => 0.0, 'overtime' => 0.0],
            $leaves['by_site'][$id] ?? ['requests' => 0, 'days' => 0],
        ))->values()->all();

        $attendanceTotals = $this->sumTotals($attendance);
        $score = $this->aggregateScore($siteRows);

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'window' => ['from' => $from, 'to' => $to],
            'site' => $siteId !== null ? [
                'site_id' => $siteId,
                'site_name' => $sitesById->get($siteId)?->site_name,
            ] : null,
            'kpis' => [
                'headcount' => $employees->count(),
                'attendance_rate' => $this->rate($attendanceTotals['worked'], $attendanceTotals['worked'] + $attendanceTotals['absent'], 1),
                'absence_rate' => $this->rate($attendanceTotals['absent'], $attendanceTotals['worked'] + $attendanceTotals['absent'], 1),
                'late_rate' => $this->rate($attendanceTotals['late'], $attendanceTotals['worked'], 1),
                'overtime_share' => $labour['total'] > 0 ? round($labour['overtime'] / $labour['total'] * 100, 1) : null,
                'sites_active' => $siteRows === [] ? 0 : count($siteRows),
            ],
            'score' => $score,
            'attendance' => [
                'present' => $attendanceTotals['present'],
                'late' => $attendanceTotals['late'],
                'absent' => $attendanceTotals['absent'],
                'worked' => $attendanceTotals['worked'],
            ],
            'overtime' => [
                'requests' => $overtime['requests'],
                'hours' => $overtime['hours'],
            ],
            'leaves' => [
                'requests' => $leaves['requests'],
                'days' => $leaves['days'],
            ],
            'labour_cost' => [
                'total' => $labour['total'],
                'regular' => $labour['regular'],
                'overtime' => $labour['overtime'],
                'basis' => 'approved payroll rows, by payslip line date',
            ],
            'audit' => [
                'overrides' => $overrides->sum(),
                'integrity_incidents' => $incidentsTotal,
            ],
            'sites' => $siteRows,
            'flagged_audits' => $this->flaggedAudits(8, $fromUtc, $toExclusiveUtc, $siteId),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function auditFeed(array $filters, int $perPage = 25, int $page = 1): array
    {
        $query = AuditLog::query()
            ->with('actor:employee_id,first_name,last_name')
            ->orderByDesc('audit_id');

        if (($filters['from'] ?? null) !== null && ($filters['to'] ?? null) !== null) {
            [$fromUtc, $toExclusiveUtc] = $this->boundsUtc($filters['from'], $filters['to']);
            $query->where('timestamp', '>=', $fromUtc)->where('timestamp', '<', $toExclusiveUtc);
        }

        if (($filters['action'] ?? null) !== null) {
            $query->where('action_type', $filters['action']);
        }

        if (($filters['site_id'] ?? null) !== null) {
            // Crew site, not actor home site: an override or incident is
            // charged where it happened, matching the per-site scorecard.
            $query->whereHas('crew', fn ($q) => $q->where('site_id', $filters['site_id']));
        }

        $total = (clone $query)->count();

        $rows = $query->forPage($page, $perPage)->get()->map(fn (AuditLog $a) => $this->auditRow($a))->all();

        return [
            'data' => $rows,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => max(1, (int) ceil($total / max(1, $perPage))),
            ],
        ];
    }

    /** @return Collection<int, Employee> */
    private function eligibleEmployees(?int $siteId): Collection
    {
        return Employee::query()
            ->where(fn ($q) => $q->whereNull('employment_status')->orWhere('employment_status', '!=', 'separated'))
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->get();
    }

    private function overridesBySite(Carbon $fromUtc, Carbon $toExclusiveUtc, ?int $siteId): Collection
    {
        // Charged to the crew the override row names — the site the work
        // happened at — not the foreman's home site, per the attribution rule.
        return AuditLog::query()
            ->join('crews as c', 'c.crew_id', '=', 'audit_logs.crew_id')
            ->selectRaw('c.site_id, count(*) as n')
            ->whereIn('audit_logs.action_type', AuditLog::OVERRIDE_TYPES)
            ->where('audit_logs.timestamp', '>=', $fromUtc)
            ->where('audit_logs.timestamp', '<', $toExclusiveUtc)
            ->when($siteId !== null, fn ($q) => $q->where('c.site_id', $siteId))
            ->groupBy('c.site_id')
            ->pluck('n', 'site_id')
            ->mapWithKeys(fn ($n, $site) => [(int) $site => (int) $n]);
    }

    /**
     * One read of the incident log, grouped per site afterwards. The log is
     * read once — not once per reportable site — and each foreman-day is
     * charged to the crew of its earliest row, as integrityIncidents does.
     * Incidents whose crew is unresolvable stay unattributed and are only in
     * the aggregate counts.
     */
    private function incidentsBySite(array $incidents): Collection
    {
        $crewIds = collect($incidents)->pluck('crew_id')->filter()->all();
        $siteByCrew = Crew::query()
            ->whereIn('crew_id', $crewIds)
            ->pluck('site_id', 'crew_id');

        return collect($incidents)
            ->filter(fn (array $row): bool => $row['crew_id'] !== null)
            ->groupBy(fn (array $row): int => (int) ($siteByCrew[$row['crew_id']] ?? 0))
            ->map->count()
            ->filter(fn ($n, $site) => (int) $site > 0)
            ->map(fn ($n) => (int) $n);
    }

    private function attendanceBySite(string $from, string $to, ?int $siteId): Collection
    {
        $rows = Attendance::query()
            ->join('employees as e', 'e.employee_id', '=', 'attendances.employee_id')
            ->selectRaw("e.site_id,
                sum(case when attendances.status = 'present' then 1 else 0 end) as present,
                sum(case when attendances.status = 'late' then 1 else 0 end) as late,
                sum(case when attendances.status = 'absent' then 1 else 0 end) as absent")
            ->whereBetween('attendances.date', [$from, $to])
            ->when($siteId !== null, fn ($q) => $q->where('e.site_id', $siteId))
            ->groupBy('e.site_id')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->site_id] = [
                'present' => (int) $row->present,
                'late' => (int) $row->late,
                'absent' => (int) $row->absent,
                'worked' => (int) $row->present + (int) $row->late,
            ];
        }

        // Crews work their rest days and proclaimed holidays (TC-06 rolls both),
        // so an Absent mark on those days isn't an absence from scheduled work.
        $excludedDates = $this->nonWorkDayDates($from, $to);
        if ($excludedDates !== []) {
            $excludedAbsents = Attendance::query()
                ->join('employees as e', 'e.employee_id', '=', 'attendances.employee_id')
                ->selectRaw('e.site_id, count(*) as n')
                ->where('attendances.status', 'absent')
                ->whereIn('attendances.date', $excludedDates)
                ->when($siteId !== null, fn ($q) => $q->where('e.site_id', $siteId))
                ->groupBy('e.site_id')
                ->pluck('n', 'site_id');

            foreach ($excludedAbsents as $site => $n) {
                $site = (int) $site;
                if (isset($totals[$site])) {
                    $totals[$site]['absent'] = max(0, $totals[$site]['absent'] - (int) $n);
                }
            }
        }

        return collect($totals);
    }

    /**
     * Days a crew is not scheduled to work: the configured rest day (iso
     * weekday) plus every proclaimed holiday, across the window. Present/Late
     * rolls on these days still count as worked; Absent ones are discarded.
     *
     * @return list<string> Y-m-d dates
     */
    private function nonWorkDayDates(string $from, string $to): array
    {
        $timezone = config('attendance.timezone', 'Asia/Manila');
        $restDayIso = (int) config('payroll.rest_day_iso', 7);

        $dates = [];
        $cursor = Carbon::parse($from, $timezone)->startOfDay();
        $end = Carbon::parse($to, $timezone)->startOfDay();

        while ($cursor->lte($end)) {
            if ($cursor->isoWeekday() === $restDayIso) {
                $dates[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        foreach (Holiday::query()->whereBetween('date', [$from, $to])->get() as $holiday) {
            $dates[] = substr((string) $holiday->date, 0, 10);
        }

        return array_values(array_unique($dates));
    }

    /**
     * Approved overtime in the window, counted by the request's work date
     * (ot_date) so it lands where the hours were actually worked — the same
     * basis as a labour-cost line — and never by when it was approved.
     *
     * @return array{requests: int, hours: float}
     */
    private function approvedOvertime(string $from, string $to, ?int $siteId): array
    {
        $query = OvertimeRequest::query()
            ->where('status', OvertimeRequest::APPROVED)
            ->whereDate('ot_date', '>=', $from)
            ->whereDate('ot_date', '<=', $to);

        if ($siteId !== null) {
            $query->whereHas('employee', fn ($q) => $q->where('site_id', $siteId));
        }

        $rows = $query->get(['ot_id', 'hours_requested']);

        return [
            'requests' => $rows->count(),
            'hours' => round($rows->sum(fn (OvertimeRequest $o) => (float) ($o->hours_requested ?? 0)), 2),
        ];
    }

    /** @return array{requests: int, days: int, by_site: array<int, array{requests: int, days: int}>} */
    private function approvedLeaves(string $from, string $to, ?int $siteId): array
    {
        $query = LeaveRequest::query()
            ->where('status', LeaveRequest::APPROVED)
            // date_from/date_to are DATE columns that Laravel stores with a
            // time part on SQLite; whereDate keeps the overlap check free of
            // driver-specific string comparisons.
            ->whereDate('date_to', '>=', $from)
            ->whereDate('date_from', '<=', $to);

        if ($siteId !== null) {
            $query->whereHas('employee', fn ($q) => $q->where('site_id', $siteId));
        }

        $requests = $query->with('employee:employee_id,site_id')->get(['leave_id', 'employee_id', 'date_from', 'date_to']);

        $days = 0;
        $bySite = [];

        foreach ($requests as $leave) {
            $overlap = $this->overlapDays($leave->date_from, $leave->date_to, $from, $to);

            $days += $overlap;
            $site = (int) ($leave->employee?->site_id ?? 0);
            $bySite[$site]['requests'] = ($bySite[$site]['requests'] ?? 0) + 1;
            $bySite[$site]['days'] = ($bySite[$site]['days'] ?? 0) + $overlap;
        }

        return [
            'requests' => $requests->count(),
            'days' => $days,
            'by_site' => $bySite,
        ];
    }

    private function overlapDays($fromDate, $toDate, string $from, string $to): int
    {
        $start = max($fromDate->toDateString(), $from);
        $end = min($toDate->toDateString(), $to);

        if ($end < $start) {
            return 0;
        }

        return Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;
    }

    /**
     * Labour cost from approved payroll only, sliced by payslip line date.
     * Draft rows are never paid against, so they never count. Lines split
     * into the prototype's two bar segments: regular (ordinary days and
     * unworked-regular-holiday pay) and overtime (overtime and its night
     * differential). The query is first cut to runs whose pay period overlaps
     * the window, so the growing breakdown store is never read wholesale.
     *
     * @return array{total: float, regular: float, overtime: float, by_site: array<int, array<string, float>>}
     */
    private function labourCost(string $from, string $to, ?int $siteId): array
    {
        // employee primary key is employee_id, not id — a listing the wrong
        // key outright fails on MySQL (unknown column 'id'); SQLite only
        // survives because it treats the unknown name as a string literal.
        $query = Payroll::query()
            ->where('status', Payroll::APPROVED)
            ->whereDate('pay_period_end', '>=', $from)
            ->whereDate('pay_period_start', '<=', $to)
            ->with('employee:employee_id,site_id', 'detail');

        if ($siteId !== null) {
            $query->whereHas('employee', fn ($q) => $q->where('site_id', $siteId));
        }

        $total = $regular = $overtime = 0.0;
        $bySite = [];

        foreach ($query->get() as $payroll) {
            foreach ($payroll->detail?->breakdown['lines'] ?? [] as $line) {
                $date = $line['date'] ?? null;

                if ($date === null || $date < $from || $date > $to || ! isset($line['amount'])) {
                    continue;
                }

                $amount = (float) $line['amount'];
                $isOvertime = in_array($line['kind'] ?? null, ['overtime', 'overtime_night'], true);
                $total += $amount;
                $regular += $isOvertime ? 0 : $amount;
                $overtime += $isOvertime ? $amount : 0;

                $site = (int) ($payroll->employee?->site_id ?? 0);
                if ($site === 0) {
                    continue;
                }
                $bySite[$site] ??= ['total' => 0.0, 'regular' => 0.0, 'overtime' => 0.0];
                $bySite[$site]['total'] += $amount;
                $bySite[$site]['regular'] += $isOvertime ? 0 : $amount;
                $bySite[$site]['overtime'] += $isOvertime ? $amount : 0;
            }
        }

        return [
            'total' => round($total, 2),
            'regular' => round($regular, 2),
            'overtime' => round($overtime, 2),
            'by_site' => array_map(
                fn (array $row) => [
                    'total' => round($row['total'], 2),
                    'regular' => round($row['regular'], 2),
                    'overtime' => round($row['overtime'], 2),
                ],
                $bySite,
            ),
        ];
    }

    /**
     * Every site that earned a row. The candidates are the sites any dataset
     * mentions; of those, only work sites are reported — a site with a crew
     * assigned to it, or with field staff (worker, operator, foreman) homed
     * there. An office-only site (Head Office) is never a work site, whatever
     * its staff do: their leave, like everything charged by home site, would
     * otherwise make it one. An explicit site_id drill-in always gets its row.
     */
    private function reportSiteIds(
        Collection $employees,
        Collection $attendance,
        Collection $overrides,
        Collection $incidents,
        array $labour,
        array $leaves,
        ?int $siteId,
    ): Collection {
        $candidates = collect()
            ->merge($employees->pluck('site_id'))
            ->merge($attendance->keys())
            ->merge($overrides->keys())
            ->merge($incidents->keys())
            ->merge(array_keys($labour['by_site']))
            ->merge(array_keys($leaves['by_site']))
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($siteId !== null) {
            return $candidates->push((int) $siteId)->unique()->values();
        }

        // Work is organised in crews, so a site with a crew is a work site.
        // Crew-charged activity (overrides, incidents) always names such a
        // site; home-site data (attendance, leave, labour) never decides.
        $crewSites = Crew::query()
            ->whereIn('site_id', $candidates)
            ->pluck('site_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $fieldSites = Employee::query()
            ->whereIn('site_id', $candidates)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['worker', 'operator', 'foreman']))
            ->pluck('site_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $candidates->filter(
            fn (int $id): bool => in_array($id, $crewSites, true) || in_array($id, $fieldSites, true),
        )->values();
    }

    /** @return array<string, mixed> */
    private function siteRow(
        int $siteId,
        ?string $siteName,
        Collection $employees,
        Collection $expiredByEmployee,
        ?array $attendance,
        int $overrides,
        int $incidents,
        array $labour,
        array $leaves,
    ): array {
        $expiredCerts = $employees->sum(fn (Employee $e) => (int) ($expiredByEmployee->get($e->employee_id) ?? 0));
        $attendance = $attendance ?? ['present' => 0, 'late' => 0, 'absent' => 0, 'worked' => 0];

        return [
            'site_id' => $siteId,
            'site_name' => $siteName ?? "Site #{$siteId}",
            'workers' => $employees->count(),
            'attendance' => $attendance,
            'attendance_rate' => $this->rate($attendance['worked'], $attendance['worked'] + $attendance['absent'], 1),
            'absence_rate' => $this->rate($attendance['absent'], $attendance['worked'] + $attendance['absent'], 1),
            'late_rate' => $this->rate($attendance['late'], $attendance['worked'], 1),
            'leaves' => $leaves,
            'overrides' => $overrides,
            'integrity_incidents' => $incidents,
            'certifications_expired' => $expiredCerts,
            'labour_cost' => $labour,
            'score' => $this->score($expiredCerts, $overrides, $incidents),
        ];
    }

    /** FR-09 per-site score: 100 − 2×expired certs − overrides − 5×incidents, clamped, banded per prototype tags. */
    private function score(int $certs, int $overrides, int $incidents): array
    {
        $deductions = [
            'certifications' => 2 * $certs,
            'overrides' => $overrides,
            'integrity' => 5 * $incidents,
        ];

        $value = max(0, min(100, 100 - array_sum($deductions)));
        $band = $value >= self::GOOD_MIN ? self::BAND_GOOD : ($value >= self::FAIR_MIN ? self::BAND_FAIR : self::BAND_WATCH);

        return [
            'value' => (int) $value,
            'band' => $band,
            'deductions' => $deductions,
        ];
    }

    /**
     * Company-wide headline score: the headcount-weighted average of the
     * per-site scores. Running the formula on summed components would let a
     * big compliant headcount drive every score to zero; a weighted average
     * makes the headline the typical site's health. FR-09 records this basis.
     * Sites with no workers cannot weight the average.
     */
    private function aggregateScore(array $siteRows): array
    {
        $weighted = collect($siteRows)
            ->filter(fn (array $row): bool => ($row['workers'] ?? 0) > 0);

        if ($weighted->isEmpty()) {
            return ['value' => 100, 'band' => self::BAND_GOOD, 'basis' => 'no work sites in window'];
        }

        $numerator = $weighted->sum(fn (array $row): int => $row['score']['value'] * $row['workers']);
        $headcount = $weighted->sum(fn (array $row): int => $row['workers']);
        $value = (int) round($numerator / max(1, $headcount));
        $band = $value >= self::GOOD_MIN ? self::BAND_GOOD : ($value >= self::FAIR_MIN ? self::BAND_FAIR : self::BAND_WATCH);

        return [
            'value' => $value,
            'band' => $band,
            'basis' => 'headcount-weighted average of per-site scores',
        ];
    }

    private function rate(int $part, int $whole, int $decimals): ?float
    {
        if ($whole <= 0) {
            return null;
        }

        return round($part / $whole * 100, $decimals);
    }

    /** @param  Collection<int, array<string, int>>  $totals */
    private function sumTotals(Collection $totals): array
    {
        return [
            'present' => (int) $totals->sum('present'),
            'late' => (int) $totals->sum('late'),
            'absent' => (int) $totals->sum('absent'),
            'worked' => (int) $totals->sum('worked'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function flaggedAudits(int $limit, Carbon $fromUtc, Carbon $toExclusiveUtc, ?int $siteId): array
    {
        $query = AuditLog::query()
            ->with('actor:employee_id,first_name,last_name')
            ->whereIn('action_type', self::FLAGGED_TYPES)
            ->where('timestamp', '>=', $fromUtc)
            ->where('timestamp', '<', $toExclusiveUtc);

        if ($siteId !== null) {
            $query->whereHas('crew', fn ($q) => $q->where('site_id', $siteId));
        }

        return $query->orderByDesc('audit_id')->limit($limit)->get()->map(fn (AuditLog $a) => $this->auditRow($a))->all();
    }

    /** @return array<string, mixed> */
    private function auditRow(AuditLog $a): array
    {
        return [
            'audit_id' => $a->audit_id,
            'action' => $a->action_type,
            'actor' => $a->actor?->full_name,
            'crew_id' => $a->crew_id,
            'subject_date' => $a->subject_date,
            'review_status' => $a->review_status,
            'description' => $a->description,
            'timestamp' => $a->timestamp?->toIso8601String(),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} Manila-day bounds converted to UTC. */
    private function boundsUtc(string $from, string $to): array
    {
        $timezone = config('attendance.timezone', 'Asia/Manila');
        $fromUtc = Carbon::parse($from, $timezone)->startOfDay()->setTimezone('UTC');
        $toExclusiveUtc = Carbon::parse($to, $timezone)->copy()->addDay()->startOfDay()->setTimezone('UTC');

        return [$fromUtc, $toExclusiveUtc];
    }
}
