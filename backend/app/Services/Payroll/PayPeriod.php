<?php

namespace App\Services\Payroll;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A semi-monthly cut-off (Phase 8), named as the Payroll Run prototype does:
 * "2026-09-A" runs from 21 Aug to 05 Sep, "2026-09-B" from 06 to 20 Sep
 * (config payroll.cutoff_start_days = [21, 6]).
 */
final class PayPeriod
{
    private function __construct(
        public readonly string $code,
        public readonly string $start,
        public readonly string $end,
    ) {}

    public static function fromCode(string $code): self
    {
        if (! preg_match('/^(\d{4})-(\d{2})-([AB])$/', $code, $m)) {
            throw new InvalidArgumentException("[{$code}] is not a pay period. Expected YYYY-MM-A or YYYY-MM-B.");
        }

        [$aStart, $bStart] = config('payroll.cutoff_start_days', [21, 6]);
        $month = Carbon::create((int) $m[1], (int) $m[2], 1);

        if ($m[3] === 'A') {
            $start = $month->copy()->subMonthNoOverflow()->day($aStart);
            $end = $month->copy()->day($bStart - 1);
        } else {
            $start = $month->copy()->day($bStart);
            $end = $month->copy()->day($aStart - 1);
        }

        return new self($code, $start->toDateString(), $end->toDateString());
    }

    /** The cut-off a site date falls in. */
    public static function containing(string $date): self
    {
        [$aStart, $bStart] = config('payroll.cutoff_start_days', [21, 6]);
        $day = Carbon::parse($date);

        if ($day->day >= $aStart) {
            return self::fromCode($day->copy()->addMonthNoOverflow()->format('Y-m').'-A');
        }

        return self::fromCode($day->format('Y-m').($day->day < $bStart ? '-A' : '-B'));
    }

    /** The cut-off before this one. */
    public function previous(): self
    {
        return self::containing(Carbon::parse($this->start)->subDay()->toDateString());
    }

    /** The cut-off after this one. */
    public function next(): self
    {
        return self::containing(Carbon::parse($this->end)->addDay()->toDateString());
    }

    /** "21 Aug – 05 Sep 2026" */
    public function label(): string
    {
        $start = Carbon::parse($this->start);
        $end = Carbon::parse($this->end);

        return $start->format($start->year === $end->year ? 'd M' : 'd M Y').' – '.$end->format('d M Y');
    }

    /** @return list<string> every date in the period, Y-m-d */
    public function dates(): array
    {
        $dates = [];

        for ($day = Carbon::parse($this->start); $day->toDateString() <= $this->end; $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }
}
