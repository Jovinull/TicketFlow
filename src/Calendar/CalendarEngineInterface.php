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

namespace GlpiPlugin\Ticketflow\Calendar;

/**
 * The seam between TicketFlow's clock and GLPI's Calendar.
 *
 * Production uses {@see GlpiCalendarEngine}, which forwards to
 * \Calendar::computeEndDate() so the deadline semantics are literally core's own.
 * Tests use {@see InMemoryCalendarEngine}, a faithful port of the same algorithm over an
 * in-memory calendar definition, which is what makes weekend/holiday behaviour coverable
 * without a running GLPI.
 */
interface CalendarEngineInterface
{
    public function exists(int $calendars_id): bool;

    /** False for a calendar with no working day at all; such a calendar cannot yield a deadline. */
    public function hasWorkingDay(int $calendars_id): bool;

    public function getName(int $calendars_id): string;

    /**
     * @param string $start        'Y-m-d H:i:s'
     * @param int    $delay        delay in seconds (interpreted as whole days when $work_in_days)
     * @param bool   $work_in_days mirrors \Calendar::computeEndDate()'s $work_in_days
     *
     * @return string|null 'Y-m-d H:i:s', or null when the calendar cannot produce a date
     */
    public function computeEndDate(int $calendars_id, string $start, int $delay, bool $work_in_days): ?string;

    /** @param string $date 'Y-m-d' or 'Y-m-d H:i:s' */
    public function isWorkingDay(int $calendars_id, string $date): bool;
}
