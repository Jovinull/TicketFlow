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

namespace GlpiPlugin\Ticketclock\Engine\Action;

use NotificationEvent;
use Ticket;
use GlpiPlugin\Ticketclock\Engine\ActionContext;
use GlpiPlugin\Ticketclock\Engine\ActionDefinition;
use GlpiPlugin\Ticketclock\Engine\ActionResult;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use Throwable;

/**
 * Raises a GLPI notification event on the ticket.
 *
 * TicketFlow does not ship notification templates of its own in 0.1: it raises an existing
 * ticket event so administrators can reuse the notifications they already maintain.
 * The event name is part of the action's configuration and defaults to `update`.
 */
final class SendNotificationAction implements ActionInterface
{
    public function supports(ActionType $type): bool
    {
        return $type === ActionType::SendNotification;
    }

    public function describe(ActionDefinition $definition): string
    {
        return sprintf(
            __('send the "%s" notification', 'ticketclock'),
            $definition->stringParam('event', 'update'),
        );
    }

    public function execute(ActionDefinition $definition, ActionContext $context): ActionResult
    {
        $event = $definition->stringParam('event', 'update');

        if ($context->dry_run) {
            return ActionResult::simulated(
                ActionType::SendNotification,
                sprintf(__('The "%s" notification would be raised.', 'ticketclock'), $event),
                ['event' => $event],
            );
        }

        try {
            $ticket = new Ticket();
            if (!$ticket->getFromDB($context->ticket->tickets_id)) {
                return ActionResult::failure(ActionType::SendNotification, __('Ticket not found.', 'ticketclock'));
            }

            NotificationEvent::raiseEvent($event, $ticket);
        } catch (Throwable $e) {
            return ActionResult::failure(ActionType::SendNotification, $e->getMessage());
        }

        return ActionResult::success(
            ActionType::SendNotification,
            __('Notification raised.', 'ticketclock'),
            ['event' => $event],
        );
    }
}
