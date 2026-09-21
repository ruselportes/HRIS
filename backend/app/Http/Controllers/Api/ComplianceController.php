<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CertificationsRequest;
use App\Models\Crew;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Support\CertificationStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Compliance & Docs → Certifications (C1, UC-02 Worker Registry and Skill
 * Certification Management, UC-09 scorecard). Reads the certifications
 * already stored on employee records — no new table, no writes.
 *
 * One rule for both screens: each certificate is marked by
 * CertificationStatus::each(), the same helper the scorecard counts from, so
 * this page and the scorecard cannot disagree. Certificates count against
 * the employee's home site (SRS §3.2.3). Nothing sensitive leaves the server:
 * no rates, no government IDs, no emergency contacts.
 */
class ComplianceController extends Controller
{
    /**
     * GET /api/compliance/certifications?site_id=&status=&as_of=
     *
     * as_of defaults to today in the site timezone (Manila). The status
     * filter narrows the rows only; the summary always describes the whole
     * role+site scope, so the counts stay comparable across filter clicks.
     */
    public function certifications(CertificationsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $request->user();

        $timezone = config('attendance.timezone', 'Asia/Manila');
        $asOf = isset($filters['as_of'])
            ? Carbon::parse($filters['as_of'], $timezone)->startOfDay()
            : Carbon::now($timezone)->startOfDay();

        $employees = $this->scopedEmployees($request, $filters);

        $rows = [];
        $summary = ['valid' => 0, 'expiring_soon' => 0, 'expired' => 0, 'no_expiry' => 0];
        $workersWithoutCerts = 0;

        foreach ($employees as $employee) {
            $marked = CertificationStatus::each($employee->certification, $asOf->copy());

            if ($marked === []) {
                $workersWithoutCerts++;
            }

            foreach ($marked as $m) {
                $summary[$m['status']]++;

                if (isset($filters['status']) && $m['status'] !== $filters['status']) {
                    continue;
                }

                $cert = is_array($m['certificate']) ? $m['certificate'] : [];
                $rows[] = [
                    'employee_code' => $employee->employee_code,
                    'full_name' => $employee->full_name,
                    'trade_skill' => $employee->trade_skill,
                    'site' => $employee->site?->site_name,
                    'name' => $cert['name'] ?? null,
                    'issuer' => $cert['issuer'] ?? null,
                    'certificate_no' => $cert['certificate_no'] ?? null,
                    'issued_at' => $cert['issued_at'] ?? null,
                    'expires_at' => $cert['expires_at'] ?? null,
                    'status' => $m['status'],
                ];
            }
        }

        return response()->json([
            'data' => $rows,
            'summary' => [
                ...$summary,
                'workers_without_certificates' => $workersWithoutCerts,
            ],
            'as_of' => $asOf->toDateString(),
        ]);
    }

    /**
     * Who this viewer may see. Mirrors the report scope: everyone
     * non-separated; engineers clamped to their own home site (a sent site_id
     * is ignored, and no home site is a 403, never the company-wide view);
     * foremen limited to the members of the deployed crews they lead now —
     * the same crews the phone's crew list shows, all of them rather than
     * just the latest, since a foreman may hold several.
     *
     * @return Collection<int, Employee>
     */
    private function scopedEmployees(CertificationsRequest $request, array $filters): Collection
    {
        $user = $request->user();
        $slug = $user->role?->slug;

        $base = Employee::query()
            ->where(fn (Builder $q) => $q->whereNull('employment_status')->orWhere('employment_status', '!=', 'separated'))
            ->with('site')
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($slug === 'engineer') {
            abort_unless($user->site_id, 403, 'No home site is set for this engineer, so there are no certifications to show.');
            $base->where('site_id', $user->site_id);

            return $base->get();
        }

        if ($slug === 'foreman') {
            $memberIds = CrewAssignment::query()
                ->whereIn('crew_id', Crew::query()
                    ->where('foreman_id', $user->employee_id)
                    ->where('status', 'deployed')
                    ->select('crew_id'))
                ->where('assignment_type', CrewAssignment::TYPE_MEMBER)
                ->where('status', 'active')
                ->distinct()
                ->pluck('employee_id');

            return $base->whereIn('employee_id', $memberIds)->get();
        }

        if (isset($filters['site_id'])) {
            $base->where('site_id', (int) $filters['site_id']);
        }

        return $base->get();
    }
}
