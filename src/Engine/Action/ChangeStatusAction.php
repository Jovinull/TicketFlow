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

use PendingReason;
use PendingReason_Item;
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

    /**
     * Registers the pending reason that goes with a move into "pending".
     *
     * A ticket parked in `WAITING` with no pending reason is parked and forgotten: core's own
     * automation -- the bump followups and the auto-solve in `PendingReasonCron` -- keys off
     * `PendingReason_Item`, so without a reason attached nothing ever chases it and nothing
     * ever closes it. The rule moved the ticket out of somebody's queue and gave nothing the
     * job of moving it on.
     *
     * Registered against the ticket directly rather than by writing a followup that carries
     * `pending`, which is how the interface does it. The interface has a person typing a
     * message; a rule does not, and a followup with no content exists only to carry a flag.
     * If the operator wants the requester to see something, the rule's followup action is
     * there for that and says what they chose to say.
     *
     * `previous_status` is what core restores when the ticket leaves pending, so it has to be
     * the status the ticket actually had, captured before the update above.
     */
    private function registerPendingReason(ActionDefinition $definition, ActionContext $context, int $status): void
    {
        $pendingreasons_id = $definition->intParam('pendingreasons_id');
        if ($status !== Ticket::WAITING || $pendingreasons_id <= 0) {
            return;
        }

        $reason = new PendingReason();
        if (!$reason->getFromDB($pendingreasons_id)) {
            // Configured once, deleted later. Not worth failing the action the rule already
            // performed; the ticket is pending, it just has nobody chasing it.
            return;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($context->ticket->tickets_id)) {
            return;
        }

        PendingReason_Item::createForItem($ticket, [
            'pendingreasons_id'           => $pendingreasons_id,
            // Taken from the reason itself: those two fields are what an administrator
            // configured on it, and overriding them per rule would quietly diverge from what
            // the reason says it does everywhere else.
            'followup_frequency'          => (int) $reason->fields['followup_frequency'],
            'followups_before_resolution' => (int) $reason->fields['followups_before_resolution'],
            'previous_status'             => $context->ticket->status,
        ]);
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

            if ($ok) {
                $this->registerPendingReason($definition, $context, $status);
            }
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
