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
use RuntimeException;
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
     * The pending reason this action must attach, or null when it attaches none.
     *
     * Looked up before the ticket is touched. A reason configured once and deleted since
     * would otherwise leave the ticket in exactly the state this feature exists to prevent:
     * pending, with nothing registered, so core's bump followups and its auto-solve never
     * see it. Nobody would be told, because the status change itself succeeded.
     *
     * @throws RuntimeException when a reason is configured but no longer exists
     */
    private function pendingReasonToAttach(ActionDefinition $definition, int $status): ?PendingReason
    {
        $pendingreasons_id = $definition->intParam('pendingreasons_id');
        if ($status !== Ticket::WAITING || $pendingreasons_id <= 0) {
            return null;
        }

        $reason = new PendingReason();
        if (!$reason->getFromDB($pendingreasons_id)) {
            throw new RuntimeException(sprintf(
                __('Pending reason %d no longer exists. The ticket was left as it was: moving it to pending without a reason would leave nothing to chase it.', 'ticketclock'),
                $pendingreasons_id,
            ));
        }

        return $reason;
    }

    /**
     * Registers the reason against the ticket, after the status change it belongs to.
     *
     * Registered directly rather than by writing a followup that carries `pending`, which is
     * how the interface does it. The interface has a person typing a message; a rule does
     * not, and a followup with no content exists only to carry a flag.
     *
     * `previous_status` is what core restores when the ticket leaves pending, so it has to be
     * the status the ticket really had, captured before the update.
     */
    private function attachPendingReason(PendingReason $reason, ActionContext $context): bool
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($context->ticket->tickets_id)) {
            return false;
        }

        return PendingReason_Item::createForItem($ticket, [
            'pendingreasons_id'           => (int) $reason->fields['id'],
            // Taken from the reason itself: those two fields are what an administrator
            // configured on it, and overriding them per rule would quietly diverge from what
            // the reason says it does everywhere else.
            'followup_frequency'          => (int) $reason->fields['followup_frequency'],
            'followups_before_resolution' => (int) $reason->fields['followups_before_resolution'],
            'previous_status'             => $context->ticket->status,
        ]);
    }

    /**
     * A ticket that is already where the action wants to put it.
     *
     * Nothing to change, and until now that meant nothing to say either -- which quietly lost
     * the pending reason. A rule selecting tickets that are already pending, acting to make
     * them pending, with a reason attached, reported success and registered nothing: the
     * tickets stayed pending with no reason, which is the exact state the reason exists to
     * prevent, arrived at by a configuration the form accepts.
     *
     * There is no safe way to attach one here. `previous_status` is what core restores when
     * the ticket leaves pending, and the only status this ticket has is pending itself.
     * Recording that would send the ticket back to pending on the way out of pending. Core
     * never does it either: it writes `previous_status` only on the branch where the ticket
     * was not pending yet.
     *
     * So: already handed to core, fine. Not handed over and unable to be, refused with the
     * reason rather than a success nobody can act on.
     */
    private function alreadyInTargetStatus(ActionDefinition $definition, ActionContext $context, int $status): ActionResult
    {
        $unchanged = ActionResult::success(
            ActionType::ChangeStatus,
            __('The ticket already has the target status.', 'ticketclock'),
            ['status' => $status, 'changed' => false],
        );

        if ($status !== Ticket::WAITING || $definition->intParam('pendingreasons_id') <= 0) {
            return $unchanged;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($context->ticket->tickets_id)) {
            return $unchanged;
        }

        if (PendingReason_Item::getForItem($ticket) !== false) {
            return $unchanged;
        }

        return ActionResult::failure(
            ActionType::ChangeStatus,
            __('The ticket is already pending with no reason recorded, and a reason cannot be attached now: there is no earlier status for GLPI to restore when it leaves pending. Point this rule at the status the ticket is in before it goes pending.', 'ticketclock'),
        );
    }

    public function execute(ActionDefinition $definition, ActionContext $context): ActionResult
    {
        $status = $definition->intParam('status');
        if ($status <= 0) {
            return ActionResult::failure(ActionType::ChangeStatus, __('No target status is configured.', 'ticketclock'));
        }

        if ($status === $context->ticket->status) {
            return $this->alreadyInTargetStatus($definition, $context, $status);
        }

        if ($context->dry_run) {
            return ActionResult::simulated(
                ActionType::ChangeStatus,
                sprintf(__('The status would change from %1$s to %2$s.', 'ticketclock'), $context->ticket->status, $status),
                ['from' => $context->ticket->status, 'to' => $status],
            );
        }

        try {
            // Resolved first: if the configured reason is gone, the ticket must not move at
            // all. Moving it and reporting success would produce the one outcome this action
            // is supposed to rule out.
            $reason = $this->pendingReasonToAttach($definition, $status);

            $ticket = new Ticket();
            $ok = $ticket->update([
                'id'     => $context->ticket->tickets_id,
                'status' => $status,
            ]);

            if ($ok && $reason !== null && !$this->attachPendingReason($reason, $context)) {
                // The ticket is pending and nothing is registered against it. The status
                // change cannot be taken back from here, so the run says so instead of
                // reporting a success that left the ticket unattended.
                return ActionResult::failure(
                    ActionType::ChangeStatus,
                    __('The ticket is now pending, but its reason could not be registered, so nothing will chase it.', 'ticketclock'),
                );
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
