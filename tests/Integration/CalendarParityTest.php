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

namespace GlpiPlugin\Ticketflow\Tests\Integration;

use Calendar;
use CalendarSegment;
use Calendar_Holiday;
use GlpiPlugin\Ticketflow\Calendar\CalendarDefinition;
use GlpiPlugin\Ticketflow\Calendar\GlpiCalendarEngine;
use GlpiPlugin\Ticketflow\Calendar\InMemoryCalendarEngine;
use Holiday;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the one duplication TicketFlow deliberately accepts.
 *
 * Production deadlines come from core's Calendar::computeEndDate(); the unit suite uses a
 * port of that algorithm so weekend and holiday behaviour is testable without a database.
 * If core ever changes its semantics, this test fails — which is the whole point of
 * writing it down rather than hoping the two stay in step.
 */
final class CalendarParityTest extends TestCase
{
    private int $calendars_id = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $calendar = new Calendar();
        $this->calendars_id = (int) $calendar->add([
            'name'        => 'TicketFlow parity calendar',
            'entities_id' => 0,
            'is_recursive' => 1,
        ]);
        self::assertGreaterThan(0, $this->calendars_id);

        // Monday to Friday, 08:00-18:00 — the same shape as the reference installation.
        for ($day = 1; $day <= 5; $day++) {
            (new CalendarSegment())->add([
                'calendars_id' => $this->calendars_id,
                'entities_id'  => 0,
                'day'          => $day,
                'begin'        => '08:00:00',
                'end'          => '18:00:00',
            ]);
        }

        $holiday = new Holiday();
        $holidays_id = (int) $holiday->add([
            'name'         => 'TicketFlow parity holiday',
            'entities_id'  => 0,
            'is_recursive' => 1,
            'begin_date'   => '2026-08-05',
            'end_date'     => '2026-08-05',
            'is_perpetual' => 0,
        ]);
        (new Calendar_Holiday())->add([
            'calendars_id' => $this->calendars_id,
            'holidays_id'  => $holidays_id,
        ]);

        (new Calendar())->updateDurationCache($this->calendars_id);
    }

    /**
     * @return list<array{0: string, 1: int, 2: bool}>
     */
    public static function scenarios(): array
    {
        return [
            'five business days from a Monday'   => ['2026-08-03 10:00:00', 5 * DAY_TIMESTAMP, true],
            'two business days from a Thursday'  => ['2026-08-06 14:30:00', 2 * DAY_TIMESTAMP, true],
            'one business day from a Saturday'   => ['2026-08-08 15:00:00', DAY_TIMESTAMP, true],
            'one business day after closing'     => ['2026-08-03 19:00:00', DAY_TIMESTAMP, true],
            'spanning the holiday'               => ['2026-08-04 09:00:00', 3 * DAY_TIMESTAMP, true],
            'four business hours over a weekend' => ['2026-08-14 17:00:00', 4 * HOUR_TIMESTAMP, false],
            'two business hours inside a day'    => ['2026-08-03 09:00:00', 2 * HOUR_TIMESTAMP, false],
        ];
    }

    #[DataProvider('scenarios')]
    public function testPortAgreesWithCore(string $start, int $delay, bool $work_in_days): void
    {
        $core = new GlpiCalendarEngine();
        $port = new InMemoryCalendarEngine([
            1 => new CalendarDefinition(
                'parity',
                [
                    1 => [['08:00:00', '18:00:00']],
                    2 => [['08:00:00', '18:00:00']],
                    3 => [['08:00:00', '18:00:00']],
                    4 => [['08:00:00', '18:00:00']],
                    5 => [['08:00:00', '18:00:00']],
                ],
                [['begin' => '2026-08-05', 'end' => '2026-08-05']],
            ),
        ]);

        self::assertSame(
            $core->computeEndDate($this->calendars_id, $start, $delay, $work_in_days),
            $port->computeEndDate(1, $start, $delay, $work_in_days),
            sprintf('port and core disagree for start=%s delay=%d days=%s', $start, $delay, var_export($work_in_days, true)),
        );
    }
}
