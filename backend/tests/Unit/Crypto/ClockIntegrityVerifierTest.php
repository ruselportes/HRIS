<?php

namespace Tests\Unit\Crypto;

use App\Services\Crypto\ClockIntegrityVerifier;
use Tests\TestCase;

/**
 * Covers STD TC-01 (clock rollback): the monotonic counter is unaffected by a
 * Settings clock change, so a wall clock that disagrees with it is evidence of
 * tampering rather than something to trust.
 */
class ClockIntegrityVerifierTest extends TestCase
{
    private ClockIntegrityVerifier $verifier;

    private const MINUTE = 60_000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new ClockIntegrityVerifier;
    }

    private function record(int $wallMs, int $monotonicMs, string $bootId = 'boot-a'): array
    {
        return [
            'time_in' => $wallMs,
            'monotonic_timestamp' => $monotonicMs,
            'boot_id' => $bootId,
        ];
    }

    public function test_first_record_from_a_device_establishes_a_baseline(): void
    {
        $result = $this->verifier->verify($this->record(1789200000000, 86400000), null);

        $this->assertTrue($result['valid']);
        $this->assertSame('baseline', $result['reason']);
    }

    public function test_both_clocks_advancing_together_is_accepted(): void
    {
        $previous = $this->record(1789200000000, 86400000);
        $current = $this->record(1789200000000 + 30 * self::MINUTE, 86400000 + 30 * self::MINUTE);

        $result = $this->verifier->verify($current, $previous);

        $this->assertTrue($result['valid']);
        $this->assertSame(0, $result['drift_seconds']);
    }

    /** TC-01: clock set back two hours between Worker A and Worker B. */
    public function test_two_hour_rollback_is_detected(): void
    {
        $previous = $this->record(1789200000000, 86400000);

        // Five real minutes pass (monotonic advances), but the wall clock now
        // reads 1h55m earlier than before.
        $current = $this->record(
            1789200000000 - 120 * self::MINUTE + 5 * self::MINUTE,
            86400000 + 5 * self::MINUTE,
        );

        $result = $this->verifier->verify($current, $previous);

        $this->assertFalse($result['valid']);
        $this->assertSame('wall_clock_rolled_back', $result['reason']);
        $this->assertSame(7200, $result['drift_seconds']);
    }

    public function test_forward_jump_is_detected_but_distinguished_from_rollback(): void
    {
        $previous = $this->record(1789200000000, 86400000);
        $current = $this->record(
            1789200000000 + 120 * self::MINUTE,
            86400000 + 5 * self::MINUTE,
        );

        $result = $this->verifier->verify($current, $previous);

        $this->assertFalse($result['valid']);
        $this->assertSame(
            'wall_clock_jumped_forward',
            $result['reason'],
            'Forward jumps are likelier misconfiguration than attack — the audit trail should not conflate them.'
        );
    }

    public function test_small_ntp_style_correction_stays_within_tolerance(): void
    {
        config(['crypto.clock_skew_tolerance_seconds' => 120]);

        $previous = $this->record(1789200000000, 86400000);
        // 30 minutes elapse; wall clock corrects by 45s. Honest drift.
        $current = $this->record(
            1789200000000 + 30 * self::MINUTE + 45_000,
            86400000 + 30 * self::MINUTE,
        );

        $result = $this->verifier->verify($current, $previous);

        $this->assertTrue($result['valid']);
        $this->assertSame(45, $result['drift_seconds']);
    }

    public function test_drift_just_past_tolerance_is_rejected(): void
    {
        config(['crypto.clock_skew_tolerance_seconds' => 120]);

        $previous = $this->record(1789200000000, 86400000);
        $current = $this->record(
            1789200000000 + 30 * self::MINUTE + 121_000,
            86400000 + 30 * self::MINUTE,
        );

        $result = $this->verifier->verify($current, $previous);

        $this->assertFalse($result['valid']);
        $this->assertSame(121, $result['drift_seconds']);
    }

    public function test_reboot_is_not_treated_as_tampering(): void
    {
        // elapsedRealtime resets to ~0 on reboot. Without boot_id awareness
        // this would look like a massive monotonic regression and every
        // restart would be flagged as an attack.
        $previous = $this->record(1789200000000, 86400000, 'boot-a');
        $current = $this->record(1789200000000 + 5 * self::MINUTE, 12_000, 'boot-b');

        $result = $this->verifier->verify($current, $previous);

        $this->assertTrue($result['valid']);
        $this->assertSame('boot_session_changed', $result['reason']);
    }

    public function test_monotonic_going_backwards_within_one_boot_is_rejected(): void
    {
        // Same boot session, counter decreased — elapsedRealtime cannot do
        // this, so the value was fabricated.
        $previous = $this->record(1789200000000, 86400000, 'boot-a');
        $current = $this->record(1789200000000 + self::MINUTE, 86400000 - 10 * self::MINUTE, 'boot-a');

        $result = $this->verifier->verify($current, $previous);

        $this->assertFalse($result['valid']);
        $this->assertSame('monotonic_regressed', $result['reason']);
    }

    public function test_absent_worker_without_time_in_skips_the_wall_clock_check(): void
    {
        $previous = $this->record(1789200000000, 86400000);
        $current = ['time_in' => null, 'monotonic_timestamp' => 86400000 + self::MINUTE, 'boot_id' => 'boot-a'];

        $result = $this->verifier->verify($current, $previous);

        $this->assertTrue($result['valid']);
        $this->assertSame('no_wall_clock_to_compare', $result['reason']);
    }
}
