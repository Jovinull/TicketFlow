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

use ITILFollowup;
use GlpiPlugin\Ticketflow\Engine\ActionContext;
use GlpiPlugin\Ticketflow\Engine\ActionDefinition;
use GlpiPlugin\Ticketflow\Engine\ActionResult;
use GlpiPlugin\Ticketflow\Enum\ActionType;
use Throwable;

/**
 * Writes the rule's message into the ticket timeline.
 *
 * Two details matter more than the insert itself:
 *
 *  - the content carries {@see self::MARKER}, so TicketFlow can tell its own messages from
 *    human ones and never resets its own clock (the resolver filters these out);
 *  - `_no_reopen` / `_do_not_compute_status` are passed because core's ParentStatus feature
 *    otherwise reopens a WAITING ticket when a followup is added
 *    (Glpi\Features\ParentStatus::updateParentStatus()). Without them, adding the warning
 *    message would clear `begin_waiting_date` and destroy the very clock we are acting on.
 */
final class AddFollowupAction implements ActionInterface
{
    /** Invisible in the rendered timeline, greppable in SQL, stable across versions. */
    public const MARKER = '<!-- ticketflow-generated -->';

    public function supports(ActionType $type): bool
    {
        return $type === ActionType::AddFollowup;
    }

    public function describe(ActionDefinition $definition): string
    {
        return $definition->boolParam('is_private')
            ? __('add a private followup', 'ticketflow')
            : __('add a followup', 'ticketflow');
    }

    public function execute(ActionDefinition $definition, ActionContext $context): ActionResult
    {
        $content = $context->renderer->render($definition->stringParam('content'));
        if (trim(strip_tags($content)) === '') {
            return ActionResult::failure(ActionType::AddFollowup, __('The followup content is empty.', 'ticketflow'));
        }

        $body = self::MARKER . "\n" . $content;

        if ($context->dry_run) {
            return ActionResult::simulated(
                ActionType::AddFollowup,
                __('A followup would be added.', 'ticketflow'),
                ['content' => $content],
            );
        }

        $input = [
            'itemtype'                        => 'Ticket',
            'items_id'                        => $context->ticket->tickets_id,
            'content'                         => $body,
            'is_private'                      => $definition->boolParam('is_private') ? 1 : 0,
            // Keep the ticket exactly where it is: see the class docblock.
            '_no_reopen'                      => true,
            '_do_not_compute_status'          => true,
            '_do_not_compute_takeintoaccount' => true,
        ];

        // Only set optional foreign keys when they hold a real value; passing 0 would make
        // core store a reference to a row that does not exist.
        if ($context->actor_users_id > 0) {
            $input['users_id'] = $context->actor_users_id;
        }
        if ($definition->intParam('requesttypes_id') > 0) {
            $input['requesttypes_id'] = $definition->intParam('requesttypes_id');
        }

        try {
            $followup = new ITILFollowup();
            $id = $followup->add($input);
        } catch (Throwable $e) {
            return ActionResult::failure(ActionType::AddFollowup, $e->getMessage());
        }

        if (!is_int($id) || $id <= 0) {
            return ActionResult::failure(ActionType::AddFollowup, __('The followup could not be created.', 'ticketflow'));
        }

        return ActionResult::success(
            ActionType::AddFollowup,
            __('Followup added.', 'ticketflow'),
            ['itilfollowups_id' => $id],
        );
    }
}
