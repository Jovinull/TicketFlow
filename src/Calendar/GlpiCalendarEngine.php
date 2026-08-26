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

use Calendar;

/**
 * Production calendar engine: a thin, caching wrapper around GLPI's own Calendar.
 *
 * Deliberately does no arithmetic of its own. Deadlines therefore have exactly the same
 * semantics as SLA/OLA due dates (LevelAgreement::computeDate()) and as core's pending
 * auto-solve date (PendingReason_Item::getAutoResolvedate()), both of which call
 * Calendar::computeEndDate() the same way.
 */
final class GlpiCalendarEngine implements CalendarEngineInterface
{
    /** @var array<int, Calendar|false> */
    private array $cache = [];

    private function get(int $calendars_id): Calendar|false
    {
        if ($calendars_id <= 0) {
            return false;
        }

        if (!array_key_exists($calendars_id, $this->cache)) {
            $calendar = new Calendar();
            $this->cache[$calendars_id] = $calendar->getFromDB($calendars_id) ? $calendar : false;
        }

        return $this->cache[$calendars_id];
    }

    public function exists(int $calendars_id): bool
    {
        return $this->get($calendars_id) !== false;
    }

    public function hasWorkingDay(int $calendars_id): bool
    {
        $calendar = $this->get($calendars_id);

        return $calendar !== false && $calendar->hasAWorkingDay();
    }

    public function getName(int $calendars_id): string
    {
        $calendar = $this->get($calendars_id);

        return $calendar === false ? '' : (string) $calendar->fields['name'];
    }

    public function computeEndDate(int $calendars_id, string $start, int $delay, bool $work_in_days): ?string
    {
        $calendar = $this->get($calendars_id);
        if ($calendar === false || !$calendar->hasAWorkingDay()) {
            return null;
        }

        $end = $calendar->computeEndDate($start, $delay, 0, $work_in_days);

        return is_string($end) && $end !== '' ? $end : null;
    }

    public function isWorkingDay(int $calendars_id, string $date): bool
    {
        $calendar = $this->get($calendars_id);
        if ($calendar === false) {
            return false;
        }

        // Native \strtotime on purpose: a malformed date means "not a working day".
        // @phpstan-ignore theCodingMachineSafe.function
        $time = \strtotime($date);

        return $time !== false && $calendar->isAWorkingDay($time);
    }
}
