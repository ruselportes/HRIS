<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Holiday calendar (Phase 8). Holidays are proclaimed yearly by Executive
 * Order and some dates move, so HR keeps the calendar. A change affects draft
 * payroll on its next recompute; approved payroll is final.
 */
class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? now()->year);

        return response()->json([
            'year' => $year,
            'data' => Holiday::query()
                ->whereBetween('date', ["{$year}-01-01", "{$year}-12-31"])
                ->orderBy('date')
                ->get(['holiday_id', 'date', 'name', 'type'])
                ->map(fn (Holiday $h) => [
                    'holiday_id' => $h->holiday_id,
                    'date' => substr((string) $h->date, 0, 10),
                    'name' => $h->name,
                    'type' => $h->type,
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('holidays')->where(fn ($q) => $q->where('date', $request->input('date'))),
            ],
            'type' => ['required', Rule::in([Holiday::REGULAR, Holiday::SPECIAL])],
        ], [
            'name.unique' => 'That holiday is already on the calendar for this date.',
        ]);

        $holiday = Holiday::query()->create($data);

        return response()->json(['data' => [
            'holiday_id' => $holiday->holiday_id,
            'date' => $data['date'],
            'name' => $holiday->name,
            'type' => $holiday->type,
        ]], 201);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        return response()->json(null, 204);
    }
}
