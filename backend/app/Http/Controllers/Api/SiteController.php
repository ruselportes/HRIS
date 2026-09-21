<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Crew;
use App\Models\Employee;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Project Sites (C2, UC-03) — the admin's site registry plus the read-only
 * overview every role with site visibility gets.
 *
 * The existing GET /api/sites (ReferenceController) stays exactly as it is:
 * every dropdown and filter reads it, and AppServiceProvider::INVALIDATES
 * already retires its cache entry on any Site save — so a site created here
 * appears in the dropdowns at once, with no cache code to write. Sites are
 * never deleted (employees, crews and report history reference them): a
 * finished site is closed instead, and closing is refused while deployed
 * crews remain. Nothing sensitive leaves the server: names and codes only,
 * no rates or government IDs.
 */
class SiteController extends Controller
{
    /**
     * GET /api/sites/overview — one row per visible site: headcount,
     * deployed crews and engineers. Engineers see their own site only; no
     * home site is a 403, never the company-wide view.
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Site::query()->orderBy('site_name');

        if ($user->role?->slug === 'engineer') {
            abort_unless($user->site_id, 403, 'No home site is set for this engineer, so there are no sites to show.');
            $query->where('site_id', $user->site_id);
        }

        $rows = $query->get()->map(fn (Site $site) => $this->row($site))->values();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/sites (admin): name (unique) and location. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:255', 'unique:sites,site_name'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $site = Site::create($data + ['status' => Site::STATUS_ACTIVE]);

        $this->audit($request->user(), AuditLog::SITE_CREATED, "Site {$site->site_name} created.");

        return response()->json(['data' => $this->row($site->fresh())], 201);
    }

    /** PUT /api/sites/{site} (admin): rename and relocate. */
    public function update(Request $request, Site $site): JsonResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:255', 'unique:sites,site_name,'.$site->site_id.',site_id'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $site->update($data);

        $this->audit($request->user(), AuditLog::SITE_UPDATED, "Site {$site->site_name} updated.");

        return response()->json(['data' => $this->row($site->fresh())]);
    }

    /** POST /api/sites/{site}/close (admin): refused while crews are deployed. */
    public function close(Request $request, Site $site): JsonResponse
    {
        if ($site->isClosed()) {
            return response()->json(['message' => 'This site is already closed.'], 422);
        }

        $deployed = Crew::query()->where('site_id', $site->site_id)->where('status', 'deployed')->count();

        if ($deployed > 0) {
            return response()->json([
                'message' => "This site still has {$deployed} deployed crew(s). Move or end them first.",
            ], 422);
        }

        $site->update(['status' => Site::STATUS_CLOSED]);

        $this->audit($request->user(), AuditLog::SITE_CLOSED, "Site {$site->site_name} closed.");

        return response()->json(['data' => $this->row($site->fresh())]);
    }

    /** POST /api/sites/{site}/reopen (admin). */
    public function reopen(Request $request, Site $site): JsonResponse
    {
        if (! $site->isClosed()) {
            return response()->json(['message' => 'This site is already open.'], 422);
        }

        $site->update(['status' => Site::STATUS_ACTIVE]);

        $this->audit($request->user(), AuditLog::SITE_REOPENED, "Site {$site->site_name} reopened.");

        return response()->json(['data' => $this->row($site->fresh())]);
    }

    /** @return array<string, mixed> */
    private function row(Site $site): array
    {
        $headcount = Employee::query()
            ->where('site_id', $site->site_id)
            ->where(fn (Builder $q) => $q->whereNull('employment_status')->orWhere('employment_status', '!=', 'separated'))
            ->count();

        $deployedCrews = Crew::query()
            ->with('foreman')
            ->where('site_id', $site->site_id)
            ->where('status', 'deployed')
            ->orderBy('crew_name')
            ->get()
            ->map(fn (Crew $crew) => [
                'crew_id' => $crew->crew_id,
                'crew_name' => $crew->crew_name,
                'foreman' => $crew->foreman?->full_name,
            ])
            ->values()
            ->all();

        $engineers = Employee::query()
            ->where('site_id', $site->site_id)
            ->where(fn (Builder $q) => $q->whereNull('employment_status')->orWhere('employment_status', '!=', 'separated'))
            ->whereHas('role', fn (Builder $q) => $q->where('slug', 'engineer'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Employee $e) => [
                'employee_code' => $e->employee_code,
                'full_name' => $e->full_name,
            ])
            ->values()
            ->all();

        return [
            'site_id' => $site->site_id,
            'site_name' => $site->site_name,
            'location' => $site->location,
            'status' => $site->status,
            'headcount' => $headcount,
            'deployed_crews' => $deployedCrews,
            'engineers' => $engineers,
        ];
    }

    private function audit(Employee $actor, string $action, string $description): void
    {
        AuditLog::create([
            'actor_id' => $actor->employee_id,
            'action_type' => $action,
            'description' => $description.' by '.$actor->employee_code.'.',
            'timestamp' => now(),
        ]);
    }
}
