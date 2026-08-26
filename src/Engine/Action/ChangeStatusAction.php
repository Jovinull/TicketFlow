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

use Ticket;
use GlpiPlugin\Ticketclock\Engine\ActionContext;
use GlpiPlugin\Ticketclock\Engine\ActionDefinition;
use GlpiPlugin\Ticketclock\Engine\ActionResult;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use Throwable;

/**
 * Moves the ticket to another status through Ticket::update(), so history, notifications
 * and the status lifecycle checks all still apply.
 *
 * Solving and closing have dedicated actions; this one exists for the non-terminal moves
 * (back to ASSIGNED, to APPROVAL, …) that a workflow rule may need.
 */
final class ChangeStatusAction implements ActionInterface
{
    public function supports(ActionType $type): bool
    {
        return $type === ActionType::ChangeStatus;
    }

    public function describe(ActionDefinition $definition): string
    {
        $status = $definition->intParam('status');
        $labels = class_exists(Ticket::class) ? Ticket::getAllStatusArray() : [];

        return sprintf(
            __('change the status to %s', 'ticketclock'),
            $labels[$status] ?? (string) $status,
        );
    }

    public function execute(ActionDefinition $definition, ActionContext $context): ActionResult
    {
        $status = $definition->intParam('status');
        if ($status <= 0) {
            return ActionResult::failure(ActionType::ChangeStatus, __('No target status is configured.', 'ticketclock'));
        }

        if ($status === $context->ticket->status) {
            return ActionResult::success(
                ActionType::ChangeStatus,
                __('The ticket already has the target status.', 'ticketclock'),
                ['status' => $status, 'changed' => false],
            );
        }

        if ($context->dry_run) {
            return ActionResult::simulated(
                ActionType::ChangeStatus,
                sprintf(__('The status would change from %1$s to %2$s.', 'ticketclock'), $context->ticket->status, $status),
                ['from' => $context->ticket->status, 'to' => $status],
            );
        }

        try {
            $ticket = new Ticket();
            $ok = $ticket->update([
                'id'     => $context->ticket->tickets_id,
                'status' => $status,
            ]);
        } catch (Throwable $e) {
            return ActionResult::failure(ActionType::ChangeStatus, $e->getMessage());
        }

        return $ok
            ? ActionResult::success(
                ActionType::ChangeStatus,
                __('Status updated.', 'ticketclock'),
                ['from' => $context->ticket->status, 'to' => $status, 'changed' => true],
            )
            : ActionResult::failure(ActionType::ChangeStatus, __('The status could not be updated.', 'ticketclock'));
    }
}
