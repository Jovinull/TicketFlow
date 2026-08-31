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

namespace GlpiPlugin\Ticketclock\Engine;

use GlpiPlugin\Ticketclock\Enum\ActionType;
use Ticket;

/**
 * What the logged-in operator is allowed to do to one ticket, action by action.
 *
 * The engine has two callers and they are not the same kind of thing. The scheduled run is
 * automation: it has no session, it acts as the configured user, and it was armed by an
 * administrator who enabled the task. The "Run for real" button is a person doing something
 * to a ticket right now, and it must obey exactly what that person could do by hand.
 *
 * The plugin's own right does not answer that question. `Profile::installRights()` grants it
 * to every profile holding `config`, and GLPI keeps these permissions apart: adding a
 * followup keys on `ITILFollowup::$rightname` with its own bits (ADDMY, ADDALLITEM,
 * ADD_AS_GROUP), which an operator can lack while holding `ticket` UPDATE. Checking the
 * ticket right alone would still have let such an operator write followups from this screen.
 *
 * So each action asks the question core would ask, through the ticket's own capability
 * methods. Those also apply the interface's status transition matrix, which is right here
 * and wrong under cron: `isAllowedStatus()` answers false when the session carries no
 * `ticket_status` key, and a cron run carries no profile at all. That is precisely why this
 * is a separate object handed only to the manual run, rather than checks buried inside the
 * actions where both callers would hit them.
 */
final readonly class OperatorAuthorization
{
    /**
     * @throws OperatorNotAllowed when the operator could not do this by hand
     */
    public function authorize(ActionType $type, int $tickets_id): void
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            throw new OperatorNotAllowed(__('The ticket no longer exists.', 'ticketclock'));
        }

        $allowed = match ($type) {
            ActionType::AddFollowup => $ticket->canAddFollowups(),
            ActionType::AddSolution => $ticket->canSolve(),
            // Changing status, closing, and raising a notification about somebody's ticket
            // are all edits of the ticket as far as core is concerned.
            ActionType::ChangeStatus,
            ActionType::CloseTicket,
            ActionType::SendNotification => $ticket->can($tickets_id, UPDATE),
        };

        if (!$allowed) {
            throw new OperatorNotAllowed(sprintf(
                __('Your profile may not run "%s" on ticket %d.', 'ticketclock'),
                $type->value,
                $tickets_id,
            ));
        }
    }
}
