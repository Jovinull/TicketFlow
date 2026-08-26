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

use function Safe\strtotime;

/**
 * Turns "5 business days after this date" into an actual timestamp.
 *
 * Business units are delegated to the calendar engine (core's Calendar in production);
 * calendar units are plain elapsed time. When a business unit is requested but no usable
 * calendar is available, the calculator falls back to elapsed time and says so on the
 * result — the fallback is recorded on every execution row and shown in the rule preview,
 * never applied silently.
 */
final readonly class BusinessTimeCalculator
{
    public function __construct(private CalendarEngineInterface $engine) {}

    /**
     * @param string $reference 'Y-m-d H:i:s'
     * @param int    $calendars_id 0 when no calendar could be resolved
     */
    public function computeDeadline(string $reference, int $value, DelayUnit $unit, int $calendars_id): Deadline
    {
        $seconds = $unit->toSeconds($value);

        if ($unit->usesCalendar() && $calendars_id > 0 && $this->engine->hasWorkingDay($calendars_id)) {
            $deadline = $this->engine->computeEndDate($calendars_id, $reference, $seconds, $unit->isDayBased());
            if ($deadline !== null) {
                return new Deadline(
                    $reference,
                    $deadline,
                    $value,
                    $unit,
                    $calendars_id,
                    $this->engine->getName($calendars_id),
                    false,
                );
            }
        }

        $fallback_used = $unit->usesCalendar();

        return new Deadline(
            $reference,
            date('Y-m-d H:i:s', strtotime($reference) + $seconds),
            $value,
            $unit,
            $fallback_used ? 0 : $calendars_id,
            $fallback_used ? '' : $this->engine->getName($calendars_id),
            $fallback_used,
        );
    }

    public function engine(): CalendarEngineInterface
    {
        return $this->engine;
    }
}
