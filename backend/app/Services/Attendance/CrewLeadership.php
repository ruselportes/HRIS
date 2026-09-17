<?php

namespace App\Services\Attendance;

use App\Models\Crew;

/**
 * Who may record roll call for a crew (Phase 7 — UC-06, STD TC-05).
 *
 * Sync previously verified that an event came from an authentic, enrolled
 * device, but never that the device's foreman actually leads the crew named
 * in it. So after a crew was handed to another foreman, the original foreman's
 * phone could still submit accepted roll call for it — the opposite of what
 * TC-05 requires.
 *
 * The single place this is decided, so the roster endpoint and sync ingestion
 * cannot disagree.
 */
class CrewLeadership
{
    public function leads(int $employeeId, int $crewId): bool
    {
        return Crew::query()
            ->whereKey($crewId)
            ->where('foreman_id', $employeeId)
            ->exists();
    }
}
