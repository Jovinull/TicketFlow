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

namespace GlpiPlugin\Ticketflow\Enum;

/**
 * How a rule delay is expressed.
 *
 * `business_*` units are resolved through a GLPI Calendar; the `calendar_*` units
 * ignore working hours entirely and are plain elapsed time.
 */
enum DelayUnit: string
{
    case BusinessDays = 'business_days';
    case BusinessHours = 'business_hours';
    case CalendarDays = 'calendar_days';
    case Hours = 'hours';

    public function label(): string
    {
        return match ($this) {
            self::BusinessDays  => __('Business days', 'ticketflow'),
            self::BusinessHours => __('Business hours', 'ticketflow'),
            self::CalendarDays  => __('Calendar days', 'ticketflow'),
            self::Hours         => __('Hours', 'ticketflow'),
        };
    }

    /** Whether this unit needs a calendar to be meaningful. */
    public function usesCalendar(): bool
    {
        return $this === self::BusinessDays || $this === self::BusinessHours;
    }

    /**
     * Delay expressed in seconds, as GLPI's Calendar::computeEndDate() expects it.
     *
     * Note that for `business_days` core interprets the seconds as whole days
     * (see Calendar::computeEndDate() with $work_in_days = true), which is why a
     * day count is multiplied by DAY_TIMESTAMP here.
     */
    public function toSeconds(int $value): int
    {
        return match ($this) {
            self::BusinessDays, self::CalendarDays => $value * 86400,
            self::BusinessHours, self::Hours       => $value * 3600,
        };
    }

    /** True when core must be called with $work_in_days = true. */
    public function isDayBased(): bool
    {
        return $this === self::BusinessDays || $this === self::CalendarDays;
    }

    /**
     * @return array<string, string> value => translated label
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
