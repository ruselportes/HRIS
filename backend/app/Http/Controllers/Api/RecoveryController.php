<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitRecoveryRequest;
use App\Models\Crew;
use App\Services\Attendance\RecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Attendance Recovery (Phase 7 — UC-07), backing
 * docs/prototypes/HRIS Attendance Recovery Signoff.dc.html.
 *
 * Two signatures by two roles: a Site Engineer reconstructs the day, HR signs
 * it off. The route middleware keeps each signature with its role, so the
 * same person can never give both.
 */
class RecoveryController extends Controller
{
    public function __construct(private readonly RecoveryService $recovery) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'site_id' => ['nullable', 'integer'],
            'stage' => ['nullable', Rule::in([
                RecoveryService::STAGE_AWAITING_ENGINEER,
                RecoveryService::STAGE_RETURNED,
                RecoveryService::STAGE_AWAITING_HR,
                RecoveryService::STAGE_CLOSED,
            ])],
            'cause' => ['nullable', Rule::in(RecoveryService::CAUSES)],
        ]);

        $all = $this->recovery->queue($filters['site_id'] ?? null);

        $items = $all
            ->when($filters['stage'] ?? null, fn ($c, $stage) => $c->where('stage', $stage))
            ->when($filters['cause'] ?? null, fn ($c, $cause) => $c->where('cause', $cause))
            ->values();

        $open = $all->whereIn('stage', [RecoveryService::STAGE_AWAITING_ENGINEER, RecoveryService::STAGE_RETURNED]);
        $awaitingHr = $all->where('stage', RecoveryService::STAGE_AWAITING_HR);
        $unpaid = $open->concat($awaitingHr);
        [$from, $to] = $this->recovery->window();

        return response()->json([
            'data' => $items,
            'summary' => [
                'to_recover' => $open->count(),
                'to_recover_records' => $open->sum('records'),
                'awaiting_hr' => $awaitingHr->count(),
                // Unpaid until recovered and signed off.
                'hours_at_risk' => round($unpaid->sum('hours'), 2),
                'amount_at_risk' => round($unpaid->sum('amount'), 2),
                'recovered' => $all->where('stage', RecoveryService::STAGE_CLOSED)->count(),
                'window' => ['from' => $from, 'to' => $to],
            ],
        ]);
    }

    public function show(Crew $crew, string $date): JsonResponse
    {
        return response()->json(['data' => $this->recovery->detail($crew, $date)]);
    }

    /** The engineer's signature: reconstruct the day, or resubmit it after a return. */
    public function submit(SubmitRecoveryRequest $request, Crew $crew, string $date): JsonResponse
    {
        $this->recovery->submit(
            $crew,
            $date,
            $request->validated('cause'),
            $request->validated('note'),
            $request->validated('records') ?? [],
            $request->user(),
        );

        return response()->json(['data' => $this->recovery->detail($crew, $date)]);
    }

    /** HR's signature: the day is recovered, and payroll may use it. */
    public function signOff(Request $request, int $case): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;
        $record = $this->recovery->signOff($this->recovery->findCase($case), $request->user(), $note);

        return response()->json(['data' => $this->recovery->detail($record->crew, $record->subject_date)]);
    }

    /** HR sends it back; the reason is required, since the engineer has to act on it. */
    public function returnToEngineer(Request $request, int $case): JsonResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:2000']])['note'];
        $record = $this->recovery->returnToEngineer($this->recovery->findCase($case), $request->user(), $note);

        return response()->json(['data' => $this->recovery->detail($record->crew, $record->subject_date)]);
    }
}
