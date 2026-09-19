<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportsQueryRequest;
use App\Models\Employee;
use App\Services\Analytics\ReportsAnalytics;
use Illuminate\Http\JsonResponse;

/**
 * Reports & Analytics (Phase 9 — UC-09, FR-09): the executive dashboard's
 * scorecard, labour cost and flagged audit feed, plus the full audit log
 * behind the "Open full audit log" button. Read-only, per the nav `reports`
 * matrix: HR full, Site Engineer view (their own site only), Executive full.
 *
 * Engineers get no query surface wider than their site: a site_id they
 * supply is silently clamped to their own home site, so "view" can never
 * become "browse the whole company." FR-09 records this rule.
 */
class ReportsController extends Controller
{
    public function __construct(private readonly ReportsAnalytics $analytics) {}

    public function overview(ReportsQueryRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->analytics->overview(
            $data['from'] ?? null,
            $data['to'] ?? null,
            $this->resolvedSiteId($request, $data),
        )]);
    }

    public function audit(ReportsQueryRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json($this->analytics->auditFeed([
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'action' => $data['action'] ?? null,
            'site_id' => $this->resolvedSiteId($request, $data),
        ], (int) ($data['per_page'] ?? 25), (int) ($data['page'] ?? 1)));
    }

    /**
     * Which site this viewer may see. An engineer's whole role is their site,
     * so any site_id parameter is overridden by their own home site; everyone
     * else may drill into any site the validation admits.
     *
     * The clamp fails closed: an engineer with no home site is refused, never
     * given the unfiltered (company-wide) view that a null site would mean.
     */
    private function resolvedSiteId(ReportsQueryRequest $request, array $data): ?int
    {
        $user = $request->user();

        if ($user instanceof Employee && $user->role?->slug === 'engineer') {
            abort_if($user->site_id === null, 403, 'No home site is set for this engineer, so there is no site to report on.');

            return (int) $user->site_id;
        }

        return isset($data['site_id']) ? (int) $data['site_id'] : null;
    }
}
