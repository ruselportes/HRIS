<?php

namespace App\Services\Payroll;

/**
 * Hours worked on one day (Phase 8), from what the system actually records.
 *
 * Roll call records arrival only, so the day's regular hours come from the
 * shift frame (07:00-16:00, meal hour unpaid — Art. 85): a Present worker
 * works the full 8, a Late one from their arrival. Overtime comes only from an
 * approved request's window, and its night hours are the part of that window
 * between 22:00 and 06:00 (Art. 86). Regular shift hours never fall at night.
 *
 * All times are minutes from midnight of the day, in site time. Pure.
 */
class TimeWorked
{
    private const DAY = 1440;

    /**
     * Paid regular hours for a day's attendance.
     *
     * @param  int|null  $arrival  minutes from midnight; only read for 'late'
     */
    public function regularHours(string $status, ?int $arrival, array $shift): float
    {
        if (! in_array($status, ['present', 'late'], true)) {
            return 0.0;
        }

        $start = $this->minutes($shift['start']);
        $end = $this->minutes($shift['end']);

        if ($status === 'late' && $arrival !== null) {
            $start = min(max($arrival, $start), $end);
        }

        $minutes = ($end - $start) - $this->overlap($start, $end, $this->minutes($shift['meal_start']), $this->minutes($shift['meal_end']));

        // Exact, not rounded: 7 h 20 m must pay 7.3333… hours, not 7.3333.
        // Rounding happens once, on the peso amount.
        return max(0, $minutes) / 60;
    }

    /**
     * An overtime window's paid hours, and how many of them are night hours.
     * An end at or before the start runs past midnight. Any part of the window
     * inside the regular shift is not overtime — those hours are already paid.
     *
     * @return array{hours:float, night_hours:float}
     */
    public function overtime(string $startTime, string $endTime, array $shift, array $night): array
    {
        $start = $this->minutes($startTime);
        $end = $this->minutes($endTime);

        if ($end <= $start) {
            $end += self::DAY;
        }

        // The regular shift of this day and, for a window past midnight, the
        // next one.
        $shiftStart = $this->minutes($shift['start']);
        $shiftEnd = $this->minutes($shift['end']);
        $inShift = $this->overlap($start, $end, $shiftStart, $shiftEnd)
            + $this->overlap($start, $end, $shiftStart + self::DAY, $shiftEnd + self::DAY);

        // Night runs 22:00 to 06:00: the early hours of this day, the night
        // after it, and the early hours of the next day.
        $nightStart = $this->minutes($night['start']);
        $nightEnd = $this->minutes($night['end']);
        $atNight = $this->overlap($start, $end, 0, $nightEnd)
            + $this->overlap($start, $end, $nightStart, $nightEnd + self::DAY);

        return [
            'hours' => max(0, ($end - $start) - $inShift) / 60,
            'night_hours' => $atNight / 60,
        ];
    }

    /** "HH:MM" or "HH:MM:SS" to minutes from midnight. */
    public function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private function overlap(int $aStart, int $aEnd, int $bStart, int $bEnd): int
    {
        return max(0, min($aEnd, $bEnd) - max($aStart, $bStart));
    }
}
