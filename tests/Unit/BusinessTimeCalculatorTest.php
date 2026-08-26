<?php

/**
 * -------------------------------------------------------------------------
 * TicketFlow plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 Felipe Jovino.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/Jovinull/ticketflow
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Ticketflow\Tests\Unit;

use GlpiPlugin\Ticketflow\Enum\DelayUnit;
use PHPUnit\Framework\TestCase;

/**
 * The deadline arithmetic, which is the part of TicketFlow that is easiest to get subtly
 * wrong and hardest to notice in production.
 */
final class BusinessTimeCalculatorTest extends TestCase
{
    public function testFiveBusinessDaysFromMondaySkipsTheWeekend(): void
    {
        $deadline = CalendarFactory::calculator()->computeDeadline(
            '2026-08-03 10:00:00', // Monday
            5,
            DelayUnit::BusinessDays,
            CalendarFactory::OFFICE,
        );

        // Mon -> Tue, Wed, Thu, Fri, (Sat/Sun skipped), Mon. The start day is never counted,
        // which is GLPI's own semantics in Calendar::computeEndDate().
        self::assertSame('2026-08-10 10:00:00', $deadline->deadline_date);
        self::assertFalse($deadline->used_elapsed_time_fallback);
        self::assertSame('Office', $deadline->calendar_name);
    }

    public function testHolidayInTheMiddlePushesTheDeadline(): void
    {
        $deadline = CalendarFactory::calculator()->computeDeadline(
            '2026-08-03 10:00:00', // Monday
            5,
            DelayUnit::BusinessDays,
            CalendarFactory::WITH_HOLIDAY,
        );

        // Wednesday 2026-08-05 is a holiday, so one more day is needed: Tuesday instead
        // of Monday.
        self::assertSame('2026-08-11 10:00:00', $deadline->deadline_date);
    }

    public function testStartingOnASaturdayJumpsToTheNextWorkingDayOpening(): void
    {
        $deadline = CalendarFactory::calculator()->computeDeadline(
            '2026-08-08 15:00:00', // Saturday
            1,
            DelayUnit::BusinessDays,
            CalendarFactory::OFFICE,
        );

        // The clock cannot start outside the calendar: it starts Monday at opening time,
        // then one working day later is Tuesday.
        self::assertSame('2026-08-11 08:00:00', $deadline->deadline_date);
    }

    public function testResultIsClampedToTheLastWorkingHour(): void
    {
        $deadline = CalendarFactory::calculator()->computeDeadline(
            '2026-08-03 19:00:00', // Monday, after closing time
            1,
            DelayUnit::BusinessDays,
            CalendarFactory::OFFICE,
        );

        self::assertSame('2026-08-04 18:00:00', $deadline->deadline_date);
    }

    public function testExactlyAtTheDeadlineCountsAsExpired(): void
    {
        $deadline = CalendarFactory::calculator()->computeDeadline(
            '2026-08-03 10:00:00',
            5,
            DelayUnit::BusinessDays,
            CalendarFactory::OFFICE,
        );

        self::assertFalse($deadline->isExpiredAt('2026-08-10 09:59:59'));
        self::assertTrue($deadline->isExpiredAt('2026-08-10 10:00:00'));
        self::assertTrue($deadline->isExpiredAt('2026-08-10 10:00:01'));
    }

    public function testOverdueSecondsIsNegativeInsideTheWindow(): void
    {
        $deadline = CalendarFactory::calculator()->computeDeadline(
            '2026-08-03 10:00:00',
            5,
            DelayUnit::BusinessDays,
            CalendarFactory::OFFICE,
        );

        self::assertSame(-3600, $deadline->overdueSeconds('2026-08-10 09:00:00'));
        self::assertSame(7200, $deadline->overdueSeconds('2026-08-10 12:00:00'));
    }

    public function testBusinessHoursConsumeOnlyOpeningTime(): void
    {
        // Friday 17:00 + 4 business hours: one hour left on Friday, the remaining three
        // land on Monday morning.
        $deadline = CalendarFactory::calculator()->computeDeadline(
            '2026-08-14 17:00:00', // Friday
            4,
            DelayUnit::BusinessHours,
            CalendarFactory::OFFICE,
        );

        self::assertSame('2026-08-17 11:00:00', $deadline->deadline_date);
    }

    public function testCalendarDaysIgnoreTheCalendarEntirely(): void
    {
        $deadline = CalendarFactory::calculator()->computeDeadline(
            '2026-08-07 10:00:00', // Friday
            2,
            DelayUnit::CalendarDays,
            CalendarFactory::OFFICE,
        );

        self::assertSame('2026-08-09 10:00:00', $deadline->deadline_date);
        self::assertFalse($deadline->used_elapsed_time_fallback);
    }

    public function testMissingCalendarFallsBackToElapsedTimeAndSaysSo(): void
    {
        $deadline = CalendarFactory::calculator()->computeDeadline(
            '2026-08-03 10:00:00',
            5,
            DelayUnit::BusinessDays,
            0,
        );

        self::assertSame('2026-08-08 10:00:00', $deadline->deadline_date);
        self::assertTrue($deadline->used_elapsed_time_fallback);
        self::assertSame(0, $deadline->calendars_id);
    }

    public function testCalendarWithoutAnyWorkingDayFallsBackToElapsedTime(): void
    {
        $deadline = CalendarFactory::calculator()->computeDeadline(
            '2026-08-03 10:00:00',
            5,
            DelayUnit::BusinessDays,
            CalendarFactory::NEVER_OPEN,
        );

        // A calendar that is never open can never produce a date; silently looping forever
        // or returning the start date would both be worse than an explicit fallback.
        self::assertSame('2026-08-08 10:00:00', $deadline->deadline_date);
        self::assertTrue($deadline->used_elapsed_time_fallback);
    }

    public function testTwoBusinessDaysForApprovals(): void
    {
        $deadline = CalendarFactory::calculator()->computeDeadline(
            '2026-08-06 14:30:00', // Thursday
            2,
            DelayUnit::BusinessDays,
            CalendarFactory::OFFICE,
        );

        // Thu -> Fri -> Mon (the weekend does not count).
        self::assertSame('2026-08-10 14:30:00', $deadline->deadline_date);
    }
}
