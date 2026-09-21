<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class RollCallRequest extends FormRequest
{
    public const MAX_LOOKBACK_DAYS = 62;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // date_format:Y-m-d over date: `date` also accepts formats like
            // m/d/Y, and the raw strings then leak into Carbon parsing below.
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('date') || $this->query('date') === null) {
                return;
            }

            $today = Carbon::now(config('attendance.timezone', 'Asia/Manila'))->startOfDay();
            $date = Carbon::parse($this->query('date'), config('attendance.timezone', 'Asia/Manila'))->startOfDay();

            if ($date->gt($today)) {
                $validator->errors()->add('date', 'The date cannot be in the future.');
            } elseif (abs($today->diffInDays($date)) > self::MAX_LOOKBACK_DAYS) {
                // diffInDays is signed (past dates come back negative).
                $validator->errors()->add('date', 'The date may go back at most '.self::MAX_LOOKBACK_DAYS.' days.');
            }
        });
    }
}
