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
 * What a rule does once its deadline is crossed.
 */
enum ActionType: string
{
    case AssignGroup = 'assign_group';
    case AddFollowup = 'add_followup';
    case AddSolution = 'add_solution';
    case ChangeStatus = 'change_status';
    case CloseTicket = 'close_ticket';
    case SendNotification = 'send_notification';

    public function label(): string
    {
        return match ($this) {
            self::AssignGroup      => __('Assign the ticket to a group', 'ticketclock'),
            self::AddFollowup      => __('Add a followup', 'ticketclock'),
            self::AddSolution      => __('Solve the ticket (add a solution)', 'ticketclock'),
            self::ChangeStatus     => __('Change the status', 'ticketclock'),
            self::CloseTicket      => __('Close the ticket', 'ticketclock'),
            self::SendNotification => __('Send a notification', 'ticketclock'),
        };
    }

    /**
     * Actions that modify the ticket in a way users will notice and that cannot be
     * silently undone. Used to flag rules in the UI and to gate the safety switches.
     */
    public function isDestructive(): bool
    {
        return match ($this) {
            self::AddSolution, self::ChangeStatus, self::CloseTicket => true,
            // Reassigning is not destructive in the sense this flag means -- it does not
            // solve, close or move a ticket out of anybody's sight -- but it does take the
            // ticket away from the group that had it.
            self::AddFollowup, self::SendNotification, self::AssignGroup => false,
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
