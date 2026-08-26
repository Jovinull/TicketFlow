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

use ITILSolution;
use GlpiPlugin\Ticketflow\Engine\ActionContext;
use GlpiPlugin\Ticketflow\Engine\ActionDefinition;
use GlpiPlugin\Ticketflow\Engine\ActionResult;
use GlpiPlugin\Ticketflow\Enum\ActionType;
use Throwable;

/**
 * Solves the ticket the way GLPI expects: by adding a solution.
 *
 * This is deliberately *not* a status write. In GLPI, adding an ITILSolution is what makes
 * a ticket solved — core moves the status to SOLVED (or straight to CLOSED when the
 * entity's `autoclose_delay` is 0), keeps solution approval and reopening working, and
 * fires the usual notifications (ITILSolution::post_addItem()). Core's own
 * PendingReasonCron auto-resolution does exactly this.
 *
 * `_disable_auto_assign` mirrors core so the acting system user is not silently added as
 * the ticket's technician.
 */
final class AddSolutionAction implements ActionInterface
{
    public function supports(ActionType $type): bool
    {
        return $type === ActionType::AddSolution;
    }

    public function describe(ActionDefinition $definition): string
    {
        return __('solve the ticket by adding a solution', 'ticketflow');
    }

    public function execute(ActionDefinition $definition, ActionContext $context): ActionResult
    {
        if ($context->actor_users_id <= 0) {
            return ActionResult::failure(
                ActionType::AddSolution,
                __('No acting user is configured; a solution cannot be attributed to anybody.', 'ticketflow'),
            );
        }

        $content = $context->renderer->render($definition->stringParam('content'));
        if (trim(strip_tags($content)) === '') {
            return ActionResult::failure(ActionType::AddSolution, __('The solution content is empty.', 'ticketflow'));
        }

        if ($context->dry_run) {
            return ActionResult::simulated(
                ActionType::AddSolution,
                __('A solution would be added and the ticket would be solved.', 'ticketflow'),
                ['content' => $content],
            );
        }

        try {
            $solution = new ITILSolution();
            $id = $solution->add([
                'itemtype'             => 'Ticket',
                'items_id'             => $context->ticket->tickets_id,
                'content'              => $content,
                'solutiontypes_id'     => $definition->intParam('solutiontypes_id'),
                'users_id'             => $context->actor_users_id,
                '_disable_auto_assign' => true,
            ]);
        } catch (Throwable $e) {
            return ActionResult::failure(ActionType::AddSolution, $e->getMessage());
        }

        if (!is_int($id) || $id <= 0) {
            return ActionResult::failure(ActionType::AddSolution, __('The solution could not be created.', 'ticketflow'));
        }

        return ActionResult::success(
            ActionType::AddSolution,
            __('Solution added; the ticket is now solved.', 'ticketflow'),
            ['itilsolutions_id' => $id],
        );
    }
}
