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
 * @link      https://github.com/Jovinull/ticketclock
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Ticketclock\Calendar;

use function Safe\strtotime;

/**
 * A dependency-free port of \Calendar::computeEndDate() (GLPI 11.0.4, src/Calendar.php:412).
 *
 * Why a port exists at all: the production path delegates to core (see GlpiCalendarEngine),
 * but core's Calendar cannot be instantiated without a database, and the weekend/holiday
 * behaviour is exactly the part that must be covered by tests. This class reproduces the
 * core algorithm step for step so those tests are meaningful.
 *
 * The integration suite asserts that this port and core agree on the same inputs
 * (tests/Integration/CalendarParityTest.php); if core ever changes its semantics, that test
 * fails rather than the two silently drifting apart.
 */
final class InMemoryCalendarEngine implements CalendarEngineInterface
{
    /** @var array<int, CalendarDefinition> */
    private array $calendars = [];

    /**
     * @param array<int, CalendarDefinition> $calendars
     */
    public function __construct(array $calendars = [])
    {
        foreach ($calendars as $id => $calendar) {
            $this->calendars[(int) $id] = $calendar;
        }
    }

    public function add(int $calendars_id, CalendarDefinition $definition): void
    {
        $this->calendars[$calendars_id] = $definition;
    }

    public function exists(int $calendars_id): bool
    {
        return isset($this->calendars[$calendars_id]);
    }

    public function hasWorkingDay(int $calendars_id): bool
    {
        $calendar = $this->calendars[$calendars_id] ?? null;

        return $calendar !== null && $calendar->hasWorkingDay();
    }

    public function getName(int $calendars_id): string
    {
        return $this->calendars[$calendars_id]->name ?? '';
    }

    public function isWorkingDay(int $calendars_id, string $date): bool
    {
        $calendar = $this->calendars[$calendars_id] ?? null;
        if ($calendar === null) {
            return false;
        }

        // Native \strtotime on purpose: a malformed date here means "not a working day",
        // not an exception.
        // @phpstan-ignore theCodingMachineSafe.function
        $time = \strtotime($date);
        if ($time === false) {
            return false;
        }

        return $calendar->isWorkingDay($time);
    }

    public function computeEndDate(int $calendars_id, string $start, int $delay, bool $work_in_days): ?string
    {
        $calendar = $this->calendars[$calendars_id] ?? null;
        if ($calendar === null || !$calendar->hasWorkingDay()) {
            return null;
        }

        // Native \strtotime on purpose: an unparseable start date yields "no deadline",
        // which the calculator turns into its documented fallback rather than a crash.
        // @phpstan-ignore theCodingMachineSafe.function
        $actualtime = \strtotime($start);
        if ($actualtime === false) {
            return null;
        }

        $negative = false;
        if ($delay < 0) {
            $delay    = -$delay;
            $negative = true;
        }

        if ($work_in_days) {
            return $this->computeInDays($calendar, $actualtime, $delay, $negative);
        }

        return $this->computeInHours($calendar, $actualtime, $delay, $negative);
    }

    /**
     * Port of the `$work_in_days` branch of \Calendar::computeEndDate().
     */
    private function computeInDays(CalendarDefinition $calendar, int $actualtime, int $delay, bool $negative): string
    {
        // If the starting day is not a working day, start at the beginning of the next one.
        if (!$calendar->isWorkingDay($actualtime)) {
            while (!$calendar->isWorkingDay($actualtime)) {
                $actualtime = $this->shift($actualtime, 86400, $negative);
            }
            $first = $calendar->firstWorkingHour((int) date('w', $actualtime));
            if ($first !== null) {
                $actualtime = strtotime(date('Y-m-d', $actualtime) . ' ' . $first);
            }
        }

        // The starting day itself is never counted: core steps forward first, then decrements.
        while ($delay > 0) {
            $actualtime = $this->shift($actualtime, 86400, $negative);
            if ($calendar->isWorkingDay($actualtime)) {
                $delay -= 86400;
            }
            if ($delay < 0) {
                $actualtime = $this->shift($actualtime, $delay, $negative);
            }
        }

        // Never land after the last working hour of the day we ended on.
        $last = $calendar->lastWorkingHour((int) date('w', $actualtime));
        if ($last !== null && $last < date('H:i:s', $actualtime)) {
            $actualtime = strtotime(date('Y-m-d', $actualtime) . ' ' . $last);
        }

        return date('Y-m-d H:i:s', $actualtime);
    }

    /**
     * Port of the working-hours branch of \Calendar::computeEndDate().
     *
     * Core walks day by day, consuming the active seconds of each day until the delay is
     * exhausted, then converts the remainder into a time inside that day's segments.
     */
    private function computeInHours(CalendarDefinition $calendar, int $actualtime, int $delay, bool $negative): string
    {
        $timestart = $actualtime;
        $datestart = date('Y-m-d', $timestart);

        $guard = 0;
        while ($delay >= 0) {
            if (++$guard > 20000) {
                // Defensive: a calendar with working days always terminates, but never spin forever.
                break;
            }

            $dayofweek = (int) date('w', $actualtime);
            if ($calendar->isWorkingDay($actualtime)) {
                $beginhour = '00:00:00';
                if (date('Y-m-d', $actualtime) === $datestart) {
                    $beginhour = date('H:i:s', $timestart);
                }

                $available = $negative
                    ? $calendar->activeTimeBetween($dayofweek, '00:00:00', $beginhour)
                    : $calendar->activeTimeBetween($dayofweek, $beginhour, '24:00:00');

                if ($available >= $delay && $available > 0) {
                    $target = $negative
                        ? $calendar->timeAfter($dayofweek, '00:00:00', $available - $delay)
                        : $calendar->timeAfter($dayofweek, $beginhour, $delay);
                    if ($target !== null) {
                        return date('Y-m-d', $actualtime) . ' ' . $target;
                    }
                }

                $delay -= $available;
            }

            $actualtime = $this->shift($actualtime, 86400, $negative);
            $actualtime = strtotime(date('Y-m-d', $actualtime) . ($negative ? ' 23:59:59' : ' 00:00:00'));
        }

        return date('Y-m-d H:i:s', $actualtime);
    }

    private function shift(int $time, int $amount, bool $negative): int
    {
        return $negative ? $time - $amount : $time + $amount;
    }
}
