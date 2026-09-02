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
 * What starts the clock.
 *
 * `pending_start` times the state itself: the ticket entered a pending status and the
 * countdown runs from that moment.
 *
 * `ticket_date_field` times a date somebody put on the ticket -- an SLA target, the date it
 * was taken into account -- chosen per rule from a closed list, {@see ReferenceField}. The
 * arithmetic, the calendar and the actions are unchanged; only where the clock starts moves.
 *
 * `last_target_group_message` times the *conversation*: the countdown runs from the last
 * message written by a member of the rule's target group, and only while that message is
 * still the last one on the ticket. The moment anybody else replies, the ball is back with
 * the team and the rule stops matching. That is the difference between "this ticket has
 * been pending a while" and "we answered, and nobody came back to us".
 */
enum StartEvent: string
{
    case PendingStart = 'pending_start';
    case LastTargetGroupMessage = 'last_target_group_message';
    case TicketDateField = 'ticket_date_field';

    public function label(): string
    {
        return match ($this) {
            self::PendingStart => __('When the ticket entered the status', 'ticketclock'),
            self::LastTargetGroupMessage => __('Last message, when written by the target group', 'ticketclock'),
            self::TicketDateField => __('A date stored on the ticket', 'ticketclock'),
        };
    }

    public function helper(): string
    {
        return match ($this) {
            self::PendingStart => __('The countdown runs from the moment the ticket entered the selected status.', 'ticketclock'),
            self::LastTargetGroupMessage => __('The rule only applies while the most recent message on the ticket was written by a member of one of the target groups. The countdown runs from that message, and stops as soon as anybody else replies.', 'ticketclock'),
            self::TicketDateField => __('The countdown runs from a date field of the ticket, chosen below. The rule\'s delay is added to that date, so a rule counting from an SLA target acts after the target rather than on it. A ticket whose field is empty is reported as "no_reference_date" and left alone.', 'ticketclock'),
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
        return $value === null ? null : self::tryFrom($value);
    }
}
