<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Query contract for the worker portal's own-attendance read (Add-on B, FR-11).
 *
 * The portal shows one worker their own records, so the only query input is
 * the window — and since W3 it is optional: with no from/to the read defaults
 * to the current pay period, and the response carries the period block the
 * page steps through, so no cutoff rule ever lives in JavaScript (a copy
 * there would silently drift when payroll.cutoff_start_days changes). An
 * explicit window still travels as a strict Y-m-d pair (a raw string leaking
 * into the SQL comparison is a bug), required together, to on or after from,
 * and capped at 62 days — four semi-monthly payroll periods, about two
 * months — so a years-spanning query cannot balloon the response (the staff
 * DTR carries the same cap for its CSV export).
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
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Field rules have run by now; a malformed date that already
            // failed must not be parsed again, or a 422 turns into a 500.
            // A missing pair is the default-period read, not an error.
            if ($validator->errors()->has('from') || $validator->errors()->has('to')) {
                return;
            }

            if ($this->query('from') === null || $this->query('to') === null) {
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
