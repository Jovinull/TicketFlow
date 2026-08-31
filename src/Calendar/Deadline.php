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

use GlpiPlugin\Ticketclock\Enum\DelayUnit;
use GlpiPlugin\Ticketclock\Support\Time;

/**
 * The outcome of a deadline computation, including *how* it was computed.
 *
 * The engine stores all of this on the execution row so an administrator can always
 * answer "why did this ticket fire on that date?".
 */
final readonly class Deadline
{
    public function __construct(
        public string $reference_date,
        public string $deadline_date,
        public int $delay_value,
        public DelayUnit $delay_unit,
        public int $calendars_id,
        public string $calendar_name,
        /** True when no usable calendar was found and plain elapsed time was used instead. */
        public bool $used_elapsed_time_fallback,
    ) {}

    public function isExpiredAt(string $now): bool
    {
        return Time::stamp($now) >= Time::stamp($this->deadline_date);
    }

    /** Seconds past the deadline; negative when still inside the window. */
    public function overdueSeconds(string $now): int
    {
        return Time::stamp($now) - Time::stamp($this->deadline_date);
    }
}
