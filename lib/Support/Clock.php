<?php

declare(strict_types=1);

namespace Meridian\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A source of the current time.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * Every timestamp in LoanApp is produced by a bare `date('c')` call, which
 * uses the server's local timezone (America/New_York on the origination box)
 * and writes the offset into a column that the Perl batch layer then parses as
 * if it were UTC. That mismatch is the root cause of **LOAN-2811** (timezone
 * off-by-one in disclosure dates), open since 2023 and marked low priority.
 *
 * Because `date('c')` is called inline in roughly forty places, the timezone
 * cannot be fixed in one edit; it has to be fixed by making time injectable,
 * which is what this interface is for.
 *
 * ---------------------------------------------------------------------------
 * MIGRATION WARNING
 * ---------------------------------------------------------------------------
 * Adopting this clock is not a no-op. Normalising to UTC moves the calendar
 * date forward by one day for anything submitted after 20:00 Eastern (19:00
 * during standard time). Disclosure dates, the three-day rescission window and
 * the funding-batch cutoff all key off that calendar date, so evening
 * submissions would shift by a day the moment this is adopted — in the
 * direction that makes the currently-wrong dates right, but shifting all the
 * same. Historical rows are not rewritten, so reports spanning the cutover
 * would contain both conventions.
 *
 * @package   Meridian\Support
 * @author    Bluewater Consulting <engineering@bluewater-consulting.example>
 * @copyright 2024 Meridian Trust Financial
 * @since     2024-08-14
 *
 * @internal Not yet wired up — LOAN-3002.
 */
interface ClockInterface
{
    /**
     * The current instant, always in UTC.
     */
    public function now(): DateTimeImmutable;

    /**
     * The current instant truncated to midnight UTC.
     *
     * Provided separately because the date-sensitive parts of origination
     * (disclosures, rescission, batch cutoff) care about the calendar day and
     * should not be doing their own truncation.
     */
    public function today(): DateTimeImmutable;
}

/**
 * The real clock. UTC, always.
 *
 * @author   Bluewater Consulting <engineering@bluewater-consulting.example>
 * @internal Not yet wired up — LOAN-3002.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function today(): DateTimeImmutable
    {
        return $this->now()->setTime(0, 0, 0);
    }
}

/**
 * A clock stopped at a fixed instant, for tests.
 *
 * Any instant handed in is converted to UTC, so a test written against
 * Eastern-local input still exercises UTC behavior.
 *
 * @author   Bluewater Consulting <engineering@bluewater-consulting.example>
 * @internal Not yet wired up — LOAN-3002.
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $frozenAt;

    public function __construct(DateTimeImmutable $frozenAt)
    {
        $this->frozenAt = $frozenAt->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Freeze at an instant expressed as a string, e.g. "2024-11-01 20:30 EST".
     */
    public static function at(string $instant): self
    {
        return new self(new DateTimeImmutable($instant, new DateTimeZone('UTC')));
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozenAt;
    }

    public function today(): DateTimeImmutable
    {
        return $this->frozenAt->setTime(0, 0, 0);
    }

    /**
     * A new frozen clock advanced by a relative interval, e.g. "+3 days".
     */
    public function advancedBy(string $modifier): self
    {
        return new self($this->frozenAt->modify($modifier) ?: $this->frozenAt);
    }
}
