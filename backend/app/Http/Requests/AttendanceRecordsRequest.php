<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Query contract for the Attendance & DTR listing (the web portal's Fig 20.0
 * "attendance monitoring interface").
 *
 * Every bound is validated so the endpoint can never 500 on bad input and can
 * never blow the response up: the from/to pair is required and travels
 * together, the window is capped at 62 days (two semi-monthly payroll periods
 * — the same bound the CSV export relies on), ids must be real rows, and
 * per_page is capped.
 */
class AttendanceRecordsRequest extends FormRequest
{
    public const MAX_SPAN_DAYS = 62;

    public const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // date_format:Y-m-d over date: `date` also accepts formats like
            // m/d/Y, and the raw strings then leak into SQL string
            // comparisons in the whereBetween.
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'site_id' => ['nullable', 'integer', 'exists:sites,site_id'],
            'crew_id' => ['nullable', 'integer', 'exists:crews,crew_id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,employee_id'],
            'per_page' => ['nullable', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // The field rules have already run by now; a malformed date that
            // already failed must not be parsed again — Carbon::parse would
            // throw on it and turn a 422 into a 500.
            if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }

            $from = $this->query('from');
            $to = $this->query('to');

            $start = Carbon::parse($from, config('attendance.timezone'))->startOfDay();
            $end = Carbon::parse($to, config('attendance.timezone'))->startOfDay();

            if ($end->lt($start)) {
                $validator->errors()->add('to', 'The to date must be on or after the from date.');
            } elseif ($start->diffInDays($end) > self::MAX_SPAN_DAYS) {
                $validator->errors()->add('to', 'The window may be at most '.self::MAX_SPAN_DAYS.' days long.');
            }
        });
    }
}
