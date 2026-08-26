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

namespace GlpiPlugin\Ticketflow\Engine\Action;

use Ticket;
use GlpiPlugin\Ticketflow\Engine\ActionContext;
use GlpiPlugin\Ticketflow\Engine\ActionDefinition;
use GlpiPlugin\Ticketflow\Engine\ActionResult;
use GlpiPlugin\Ticketflow\Enum\ActionType;
use Throwable;

/**
 * Hard close: sets the ticket straight to CLOSED.
 *
 * This bypasses the normal solve -> (approve) -> close flow, which means no solution is
 * recorded and the requester gets no chance to reject it. It is offered because some
 * workflows genuinely want it, but the rule form marks it as such and the recommended
 * action for "encerrar por falta de retorno" is {@see AddSolutionAction}, which leaves a
 * solution behind and lets the entity's autoclose configuration decide the rest.
 */
final class CloseTicketAction implements ActionInterface
{
    public function supports(ActionType $type): bool
    {
        return $type === ActionType::CloseTicket;
    }

    public function describe(ActionDefinition $definition): string
    {
        return __('close the ticket', 'ticketflow');
    }

    public function execute(ActionDefinition $definition, ActionContext $context): ActionResult
    {
        $closed = Ticket::CLOSED;

        if ($context->ticket->status === $closed) {
            return ActionResult::success(
                ActionType::CloseTicket,
                __('The ticket is already closed.', 'ticketflow'),
                ['changed' => false],
            );
        }

        if ($context->dry_run) {
            return ActionResult::simulated(ActionType::CloseTicket, __('The ticket would be closed.', 'ticketflow'));
        }

        try {
            $ticket = new Ticket();
            $ok = $ticket->update([
                'id'        => $context->ticket->tickets_id,
                'status'    => $closed,
                'closedate' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            return ActionResult::failure(ActionType::CloseTicket, $e->getMessage());
        }

        return $ok
            ? ActionResult::success(ActionType::CloseTicket, __('Ticket closed.', 'ticketflow'), ['changed' => true])
            : ActionResult::failure(ActionType::CloseTicket, __('The ticket could not be closed.', 'ticketflow'));
    }
}
