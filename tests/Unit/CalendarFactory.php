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

namespace GlpiPlugin\Ticketclock\Tests\Unit;

use GlpiPlugin\Ticketclock\Calendar\BusinessTimeCalculator;
use GlpiPlugin\Ticketclock\Calendar\CalendarDefinition;
use GlpiPlugin\Ticketclock\Calendar\InMemoryCalendarEngine;

/**
 * Calendars the tests share.
 *
 * The "office" calendar mirrors what the reference production instance actually uses:
 * Monday to Friday, no weekend segments.
 */
final class CalendarFactory
{
    public const OFFICE = 1;
    public const WITH_HOLIDAY = 2;
    public const NEVER_OPEN = 3;

    public static function engine(): InMemoryCalendarEngine
    {
        $weekdays = [];
        for ($day = 1; $day <= 5; $day++) {
            $weekdays[$day] = [['08:00:00', '18:00:00']];
        }

        $engine = new InMemoryCalendarEngine();
        $engine->add(self::OFFICE, new CalendarDefinition('Office', $weekdays));
        $engine->add(self::WITH_HOLIDAY, new CalendarDefinition('Office + holiday', $weekdays, [
            // A single mid-week holiday, the classic case that must push a deadline.
            ['begin' => '2026-08-05', 'end' => '2026-08-05'],
        ]));
        $engine->add(self::NEVER_OPEN, new CalendarDefinition('Closed', []));

        return $engine;
    }

    public static function calculator(): BusinessTimeCalculator
    {
        return new BusinessTimeCalculator(self::engine());
    }
}
