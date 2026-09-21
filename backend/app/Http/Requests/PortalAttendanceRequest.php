<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Query contract for the worker portal's own-attendance read (Add-on B, FR-11).
 *
 * The portal shows one worker their own records, so the only query input is
 * the window. The same strict pair as the staff DTR: required together,
 * strict Y-m-d (a raw string leaking into the SQL comparison is a bug), to on
 * or after from, and capped at two semi-monthly payroll periods so a months-
 * spanning query cannot balloon the response.
 */
class PortalAttendanceRequest extends FormRequest
{
    public const MAX_SPAN_DAYS = 62;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Field rules have run by now; a malformed date that already
            // failed must not be parsed again, or a 422 turns into a 500.
            if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }

            $from = Carbon::parse($this->query('from'), config('attendance.timezone'))->startOfDay();
            $to = Carbon::parse($this->query('to'), config('attendance.timezone'))->startOfDay();

            if ($to->lt($from)) {
                $validator->errors()->add('to', 'The to date must be on or after the from date.');
            } elseif ($from->diffInDays($to) > self::MAX_SPAN_DAYS) {
                $validator->errors()->add('to', 'The window may be at most '.self::MAX_SPAN_DAYS.' days long.');
            }
        });
    }
}
