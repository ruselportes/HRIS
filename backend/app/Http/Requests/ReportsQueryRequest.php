<?php

namespace App\Http\Requests;

use App\Models\AuditLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Query contract for the Reports & Analytics endpoints (Phase 9, UC-09).
 *
 * Every bound is validated so a report can never 500 on bad input and can
 * never blow the response up: the from/to pair travels together, spans at
 * most 366 days, per_page is capped at 100, the action filter only accepts
 * known audit action types, and site_id must be a real site.
 */
class ReportsQueryRequest extends FormRequest
{
    public const MAX_SPAN_DAYS = 366;

    public const MAX_PER_PAGE = 100;

    /** Every action_type the audit log can carry, for the action filter. */
    public const ACTION_TYPES = [
        AuditLog::LATE_OVERRIDE,
        AuditLog::MANUAL_TIME_OVERRIDE,
        AuditLog::MANUAL_TIME_OUT,
        AuditLog::ACTING_FOREMAN_ASSIGNED,
        AuditLog::ACTING_FOREMAN_ENDED,
        AuditLog::RETROACTIVE_RECOVERY,
        AuditLog::RECOVERY_SUBMITTED,
        AuditLog::RECOVERY_RETURNED,
        AuditLog::RECOVERY_SIGNED_OFF,
        AuditLog::PAYROLL_APPROVED,
        AuditLog::REQUEST_SUBMITTED,
        AuditLog::REQUEST_ENDORSED,
        AuditLog::REQUEST_APPROVED,
        AuditLog::REQUEST_REJECTED,
        AuditLog::REQUEST_CANCELLED,
        AuditLog::REQUEST_ENDORSER_REASSIGNED,
        AuditLog::ATTENDANCE_CLOCK_FLAGGED,
        AuditLog::ATTENDANCE_VERIFICATION_FAILED,
        AuditLog::ATTENDANCE_REFUSED,
    ];

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
            // comparisons whereBetween and the labour line-date filter.
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from'],
            'site_id' => ['nullable', 'integer', 'exists:sites,site_id'],
            'per_page' => ['nullable', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            'page' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', Rule::in(self::ACTION_TYPES)],
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

            if ($from === null || $to === null) {
                return;
            }

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
