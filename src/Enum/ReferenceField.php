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

namespace GlpiPlugin\Ticketclock\Enum;

/**
 * The ticket columns a rule may count from, as a closed set.
 *
 * Deliberately a curated list and not whatever `information_schema` reports. `glpi_tickets`
 * carries thirteen date columns and most of them are machinery: offering them all would let
 * an administrator build a rule that looks configured and behaves at random.
 *
 * What is left out, and why, because the omissions are the point of the list:
 *
 *  - `date_mod` moves whenever anybody edits the ticket -- including when this plugin writes
 *    a followup. A rule timed from it would push its own deadline away every time it acted;
 *  - `closedate` and `solvedate` describe a ticket that is over, and these rules act on
 *    tickets that are not;
 *  - `begin_waiting_date` is already {@see StartEvent::PendingStart}, and a second way to
 *    spell the same rule is a way to get it wrong;
 *  - `date_creation` is GLPI's row-creation stamp and duplicates `date` for a ticket;
 *  - `ola_tto_begin_date` and `ola_ttr_begin_date` are internal to core's OLA arithmetic,
 *    not a date anybody chose.
 *
 * The enum's value is the column name, and the column name only ever reaches SQL from a case
 * of this enum -- never from a POST, and never from the stored row without passing through
 * `tryFrom()` first. That is what keeps an identifier out of a query it should not be in.
 */
enum ReferenceField: string
{
    case Opened = 'date';
    case TakenIntoAccount = 'takeintoaccountdate';
    case TimeToOwn = 'time_to_own';
    case TimeToResolve = 'time_to_resolve';
    case InternalTimeToOwn = 'internal_time_to_own';
    case InternalTimeToResolve = 'internal_time_to_resolve';

    /** The `glpi_tickets` column this reads. Same as the case value, named for the callers. */
    public function column(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Opened => __('Opening date', 'ticketclock'),
            self::TakenIntoAccount => __('Date taken into account', 'ticketclock'),
            self::TimeToOwn => __('Time to own (SLA)', 'ticketclock'),
            self::TimeToResolve => __('Time to resolve (SLA)', 'ticketclock'),
            self::InternalTimeToOwn => __('Internal time to own (OLA)', 'ticketclock'),
            self::InternalTimeToResolve => __('Internal time to resolve (OLA)', 'ticketclock'),
        };
    }

    /**
     * True when the field is a target rather than something that happened.
     *
     * Worth saying on screen: the rule's delay is added to whatever date is chosen, so a rule
     * counting from "time to resolve" acts *after* the deadline, not on it.
     */
    public function isDeadline(): bool
    {
        return match ($this) {
            self::TimeToOwn, self::TimeToResolve,
            self::InternalTimeToOwn, self::InternalTimeToResolve => true,
            self::Opened, self::TakenIntoAccount => false,
        };
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
        return $value === null || $value === '' ? null : self::tryFrom($value);
    }
}
