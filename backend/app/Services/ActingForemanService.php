<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Services\Attendance\CrewLeadership;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Acting foreman cover (Phase 7 — UC-06, STD TC-05).
 *
 * When a crew's foreman is absent, a Site Engineer hands the crew to another
 * Site Foreman in one action. The cover is temporary by design: it ends on its
 * own at the end of the day (or week), or earlier when the engineer ends it,
 * and the crew goes back to its regular foreman without anyone remembering to
 * do it.
 *
 * Only a foreman who is free may cover. The phone holds one roster at a time,
 * so a foreman already leading another deployed crew would have to abandon
 * that crew's roll call to take this one.
 */
class ActingForemanService
{
    public const DURATIONS = ['today', 'week'];

    public function __construct(private readonly CrewLeadership $leadership) {}

    /**
     * Every Site Foreman who is not already this crew's, with whether they can
     * cover and, if not, why. Available first, then same site, then by name.
     */
    public function candidates(Crew $crew): Collection
    {
        $foremen = Employee::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'foreman'))
            ->where('employment_status', '!=', 'separated')
            ->whereNotIn('employee_id', array_filter([$crew->foreman_id, $crew->regular_foreman_id]))
            ->with('site')
            ->get();

        $leading = $this->deployedCrewsLedBy($foremen->pluck('employee_id'));

        $actedBefore = CrewAssignment::query()
            ->where('assignment_type', CrewAssignment::TYPE_ACTING_FOREMAN)
            ->whereIn('employee_id', $foremen->pluck('employee_id'))
            ->selectRaw('employee_id, count(*) as stints')
            ->groupBy('employee_id')
            ->pluck('stints', 'employee_id');

        return $foremen->map(function (Employee $foreman) use ($crew, $leading, $actedBefore) {
            $unavailable = $this->unavailableReason($foreman, $leading->get($foreman->employee_id));

            return [
                'employee_id' => $foreman->employee_id,
                'employee_code' => $foreman->employee_code,
                'full_name' => $foreman->full_name,
                'trade_skill' => $foreman->trade_skill,
                'date_hired' => $foreman->date_hired?->format('Y-m-d'),
                'site' => $foreman->site === null ? null : [
                    'site_id' => $foreman->site->site_id,
                    'site_name' => $foreman->site->site_name,
                ],
                'same_site' => (int) $foreman->site_id === (int) $crew->site_id,
                'foreman_certificate' => $this->foremanCertificate($foreman),
                'acted_before' => (int) ($actedBefore[$foreman->employee_id] ?? 0),
                'available' => $unavailable === null,
                'unavailable_reason' => $unavailable,
            ];
        })->sortBy([
            ['available', 'desc'],
            ['same_site', 'desc'],
            ['full_name', 'asc'],
        ])->values();
    }

    /** Hand the crew to an acting foreman until the end of today or this week. */
    public function assign(Crew $crew, Employee $acting, string $duration, Employee $engineer): Crew
    {
        return DB::transaction(function () use ($crew, $acting, $duration, $engineer) {
            $crew = Crew::query()->with('foreman', 'site')->lockForUpdate()->findOrFail($crew->crew_id);

            $this->guardAssignable($crew, $acting);

            $now = Carbon::now();
            $until = $this->coverEnds($duration, $now);
            $regular = $crew->foreman;

            $this->leadership->handOver($crew, $acting->employee_id, CrewAssignment::TYPE_ACTING_FOREMAN, $now);

            $crew->update([
                'regular_foreman_id' => $regular->employee_id,
                'acting_until' => $until,
            ]);

            AuditLog::query()->create([
                'actor_id' => $engineer->employee_id,
                'action_type' => AuditLog::ACTING_FOREMAN_ASSIGNED,
                'crew_id' => $crew->crew_id,
                'subject_date' => $this->siteDate($now),
                'timestamp' => $now,
                'description' => sprintf(
                    '%s (%s) is acting foreman of %s, %s, until %s, covering for %s (%s).',
                    $acting->full_name,
                    $acting->employee_code,
                    $crew->crew_name,
                    $crew->site?->site_name ?? 'no site',
                    $this->siteDateTime($until),
                    $regular->full_name,
                    $regular->employee_code,
                ),
            ]);

            return $crew->fresh();
        });
    }

    /**
     * Give the crew back to its regular foreman — early, by the engineer, or
     * on its own once the cover has run out ($expired).
     */
    public function end(Crew $crew, Employee $actor, ?CarbonInterface $at = null, bool $expired = false): Crew
    {
        return DB::transaction(function () use ($crew, $actor, $at, $expired) {
            $crew = Crew::query()->with('foreman', 'regularForeman')->lockForUpdate()->findOrFail($crew->crew_id);

            if (! $crew->hasActingForeman()) {
                throw $this->unprocessable("{$crew->crew_name} has no acting foreman to end.");
            }

            $at ??= Carbon::now();
            $acting = $crew->foreman;
            $regular = $crew->regularForeman;

            $this->leadership->handOver($crew, $regular->employee_id, CrewAssignment::TYPE_FOREMAN, $at);

            $crew->update(['regular_foreman_id' => null, 'acting_until' => null]);

            AuditLog::query()->create([
                'actor_id' => $actor->employee_id,
                'action_type' => AuditLog::ACTING_FOREMAN_ENDED,
                'crew_id' => $crew->crew_id,
                'subject_date' => $this->siteDate($at),
                'timestamp' => $at,
                'description' => sprintf(
                    '%s %s. %s (%s) leads %s again.',
                    $expired ? 'Cover by' : "{$actor->full_name} ended the cover by",
                    $expired ? "{$acting?->full_name} expired at {$this->siteDateTime($at)}" : ($acting?->full_name ?? 'the acting foreman'),
                    $regular->full_name,
                    $regular->employee_code,
                    $crew->crew_name,
                ),
            ]);

            return $crew->fresh();
        });
    }

    /**
     * End every cover whose time is up. Run before anything reads crew
     * leadership, so a cover never outlives its expiry just because nobody
     * looked; also scheduled, so the history is written close to the moment.
     *
     * The expiry is attributed to the engineer who set it: they chose when it
     * would end, and the audit trail needs an employee on every entry.
     */
    public function endExpired(?CarbonInterface $now = null): int
    {
        $now ??= Carbon::now();

        $expired = Crew::query()
            ->whereNotNull('acting_until')
            ->where('acting_until', '<=', $now)
            ->get();

        foreach ($expired as $crew) {
            $assignedBy = AuditLog::query()
                ->where('action_type', AuditLog::ACTING_FOREMAN_ASSIGNED)
                ->where('crew_id', $crew->crew_id)
                ->latest('timestamp')
                ->value('actor_id');

            $actor = Employee::query()->find($assignedBy ?? $crew->regular_foreman_id);

            $this->end($crew, $actor, $crew->acting_until, expired: true);
        }

        return $expired->count();
    }

    private function guardAssignable(Crew $crew, Employee $acting): void
    {
        if ($crew->status !== 'deployed') {
            throw $this->unprocessable('Only a deployed crew can be handed to an acting foreman.');
        }

        if ($crew->foreman_id === null) {
            throw $this->unprocessable("{$crew->crew_name} has no foreman to cover for. Designate one instead.");
        }

        if ($crew->hasActingForeman()) {
            throw $this->unprocessable(sprintf(
                '%s already has an acting foreman until %s. End that cover first.',
                $crew->crew_name,
                $this->siteDateTime($crew->acting_until),
            ));
        }

        if ($acting->role?->slug !== 'foreman') {
            throw $this->unprocessable("{$acting->full_name} does not hold the Site Foreman role.");
        }

        if ($acting->employment_status === 'separated') {
            throw $this->unprocessable("{$acting->full_name} is separated.");
        }

        if ((int) $acting->employee_id === (int) $crew->foreman_id) {
            throw $this->unprocessable("{$acting->full_name} already leads {$crew->crew_name}.");
        }

        $unavailable = $this->unavailableReason(
            $acting,
            $this->deployedCrewsLedBy(collect([$acting->employee_id]))->get($acting->employee_id),
        );

        if ($unavailable !== null) {
            throw $this->unprocessable("{$acting->full_name} cannot cover: {$unavailable}. Choose a foreman who is free.");
        }
    }

    /**
     * Why a foreman cannot cover, or null if they can. They must be free —
     * the phone holds one roster — and able to sign in, or nobody could open
     * the crew's roll call at all.
     */
    private function unavailableReason(Employee $foreman, ?Crew $busyWith): ?string
    {
        if ($busyWith !== null) {
            return $this->busyReason($busyWith);
        }

        if ($foreman->password === null) {
            return 'No HRIS sign-in account';
        }

        return null;
    }

    /** @return Collection<int, Crew> keyed by foreman_id */
    private function deployedCrewsLedBy(Collection $employeeIds): Collection
    {
        return Crew::query()
            ->with('site')
            ->where('status', 'deployed')
            ->whereIn('foreman_id', $employeeIds)
            ->get()
            ->keyBy('foreman_id');
    }

    private function busyReason(Crew $crew): string
    {
        return sprintf(
            '%s %s · %s',
            $crew->hasActingForeman() ? 'Acting for' : 'Leads',
            $crew->crew_name,
            $crew->site?->site_name ?? 'no site',
        );
    }

    /** The foreman qualification on file, for the picker — not a gate. */
    private function foremanCertificate(Employee $employee): ?string
    {
        foreach ($employee->certification ?? [] as $cert) {
            $name = is_array($cert) ? ($cert['name'] ?? '') : '';

            if (stripos($name, 'foreman') !== false) {
                return $name;
            }
        }

        return null;
    }

    /** End of the site's day, or of its week (Sunday), in UTC. */
    private function coverEnds(string $duration, CarbonInterface $now): Carbon
    {
        $site = Carbon::instance($now)->setTimezone($this->timezone());

        $end = $duration === 'week' ? $site->endOfWeek(Carbon::SUNDAY) : $site->endOfDay();

        return $end->startOfSecond()->utc();
    }

    private function siteDate(CarbonInterface $at): string
    {
        return Carbon::instance($at)->setTimezone($this->timezone())->toDateString();
    }

    private function siteDateTime(CarbonInterface $at): string
    {
        return Carbon::instance($at)->setTimezone($this->timezone())->format('D d M Y, H:i');
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
