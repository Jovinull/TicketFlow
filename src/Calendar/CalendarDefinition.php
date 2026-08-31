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

use GlpiPlugin\Ticketclock\Support\Time;

/**
 * A calendar described the way GLPI stores it: weekday segments plus holidays.
 *
 * Mirrors glpi_calendarsegments (day 0 = Sunday, matching date('w')) and glpi_holidays
 * (with the `is_perpetual` flag that compares month/day only).
 */
final class CalendarDefinition
{
    /** @var array<int, list<array{0: string, 1: string}>> */
    private array $segments = [];

    /** @var list<array{begin: string, end: string, perpetual: bool}> */
    private array $holidays = [];

    /**
     * @param array<int, list<array{0: string, 1: string}>>             $segments  weekday (0-6) => list of [begin, end]
     * @param list<array{begin: string, end: string, perpetual?: bool}> $holidays
     */
    public function __construct(
        public readonly string $name = '',
        array $segments = [],
        array $holidays = [],
    ) {
        foreach ($segments as $day => $ranges) {
            foreach ($ranges as $range) {
                $this->addSegment((int) $day, $range[0], $range[1]);
            }
        }
        foreach ($holidays as $holiday) {
            $this->addHoliday($holiday['begin'], $holiday['end'], (bool) ($holiday['perpetual'] ?? false));
        }
    }

    public function addSegment(int $dayofweek, string $begin, string $end): void
    {
        $this->segments[$dayofweek][] = [$this->normalize($begin), $this->normalize($end)];
        usort($this->segments[$dayofweek], static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    }

    public function addHoliday(string $begin, string $end, bool $perpetual = false): void
    {
        $this->holidays[] = ['begin' => $begin, 'end' => $end, 'perpetual' => $perpetual];
    }

    public function hasWorkingDay(): bool
    {
        for ($day = 0; $day < 7; $day++) {
            if ($this->dayDuration($day) > 0) {
                return true;
            }
        }

        return false;
    }

    /** Seconds of opening time on a given weekday, ignoring holidays. */
    public function dayDuration(int $dayofweek): int
    {
        $total = 0;
        foreach ($this->segments[$dayofweek] ?? [] as [$begin, $end]) {
            $total += max(0, $this->toSeconds($end) - $this->toSeconds($begin));
        }

        return $total;
    }

    public function isHoliday(string $date): bool
    {
        $ymd = date('Y-m-d', Time::stamp($date));
        foreach ($this->holidays as $holiday) {
            if ($holiday['perpetual']) {
                $needle = date('m-d', Time::stamp($ymd));
                $begin  = date('m-d', Time::stamp($holiday['begin']));
                $end    = date('m-d', Time::stamp($holiday['end']));
            } else {
                $needle = $ymd;
                $begin  = date('Y-m-d', Time::stamp($holiday['begin']));
                $end    = date('Y-m-d', Time::stamp($holiday['end']));
            }

            if ($begin <= $needle && $needle <= $end) {
                return true;
            }
        }

        return false;
    }

    public function isWorkingDay(int $time): bool
    {
        return $this->dayDuration((int) date('w', $time)) > 0
            && !$this->isHoliday(date('Y-m-d', $time));
    }

    public function firstWorkingHour(int $dayofweek): ?string
    {
        $ranges = $this->segments[$dayofweek] ?? [];

        return $ranges === [] ? null : $ranges[0][0];
    }

    public function lastWorkingHour(int $dayofweek): ?string
    {
        $ranges = $this->segments[$dayofweek] ?? [];
        if ($ranges === []) {
            return null;
        }

        $last = null;
        foreach ($ranges as [, $end]) {
            if ($last === null || $end > $last) {
                $last = $end;
            }
        }

        return $last;
    }

    /** Opening seconds between two times of the same weekday. */
    public function activeTimeBetween(int $dayofweek, string $begin, string $end): int
    {
        $from  = $this->toSeconds($begin);
        $to    = $this->toSeconds($end);
        $total = 0;

        foreach ($this->segments[$dayofweek] ?? [] as [$sbegin, $send]) {
            $overlap = min($to, $this->toSeconds($send)) - max($from, $this->toSeconds($sbegin));
            if ($overlap > 0) {
                $total += $overlap;
            }
        }

        return $total;
    }

    /**
     * The time of day reached after consuming $seconds of opening time from $begin.
     *
     * @return string|null 'H:i:s', or null when the day does not hold that much opening time
     */
    public function timeAfter(int $dayofweek, string $begin, int $seconds): ?string
    {
        $cursor    = $this->toSeconds($begin);
        $remaining = $seconds;

        foreach ($this->segments[$dayofweek] ?? [] as [$sbegin, $send]) {
            $start = max($cursor, $this->toSeconds($sbegin));
            $stop  = $this->toSeconds($send);
            if ($stop <= $start) {
                continue;
            }

            $available = $stop - $start;
            if ($available >= $remaining) {
                return $this->toTime($start + $remaining);
            }

            $remaining -= $available;
        }

        return null;
    }

    private function normalize(string $time): string
    {
        return $this->toTime($this->toSeconds($time));
    }

    private function toSeconds(string $time): int
    {
        $parts = array_map(intval(...), explode(':', $time) + [0, 0, 0]);

        return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
    }

    private function toTime(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
