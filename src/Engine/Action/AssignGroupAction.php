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

use CommonITILActor;
use Group;
use Group_Ticket;
use GlpiPlugin\Ticketclock\Engine\ActionContext;
use GlpiPlugin\Ticketclock\Engine\ActionDefinition;
use GlpiPlugin\Ticketclock\Engine\ActionResult;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use RuntimeException;
use Throwable;

/**
 * Hands the ticket to another group.
 *
 * The thing an unanswered ticket most often needs, and the one the engine could not do: it
 * knows *when* to escalate and had no way to escalate. Written through `Group_Ticket`, like
 * every other change this plugin makes, so history, notifications and any plugin listening on
 * that hook all see it.
 *
 * Escalade is the plugin that owns escalation in GLPI, and the two coexist rather than
 * compete. Measured with both installed, from a cron context: the write goes through, nothing
 * errors, and Escalade does not act on it -- its logic is gated on session flags its own
 * screens set. So this action does the assignment itself rather than expecting Escalade to
 * finish the job.
 *
 * It does set `_plugin_escalade_rules_only`, which Escalade documents for exactly this case:
 * "callers that assign a technician group on their own (other plugins, scripts)". Without it,
 * a manual run happening in a session where somebody had just used Escalade's own form could
 * pick up that session state and unassign the ticket's technicians. GLPI drops unknown
 * underscore-prefixed input, so the key is inert when Escalade is not installed -- verified.
 *
 * Climbing a group hierarchy is deliberately not here. That is Escalade's feature, it is
 * maintained by the GLPI team, and reimplementing it would be competing where interoperating
 * costs nothing.
 */
final class AssignGroupAction implements ActionInterface
{
    public function supports(ActionType $type): bool
    {
        return $type === ActionType::AssignGroup;
    }

    public function describe(ActionDefinition $definition): string
    {
        $group = new Group();
        $groups_id = $definition->intParam('groups_id');

        return sprintf(
            __('assign the ticket to %s', 'ticketclock'),
            $groups_id > 0 && $group->getFromDB($groups_id)
                ? (string) ($group->fields['completename'] ?: $group->fields['name'])
                : (string) $groups_id,
        );
    }

    public function execute(ActionDefinition $definition, ActionContext $context): ActionResult
    {
        $groups_id = $definition->intParam('groups_id');
        if ($groups_id <= 0) {
            return ActionResult::failure(ActionType::AssignGroup, __('No target group is configured.', 'ticketclock'));
        }

        $replace = $definition->boolParam('replace');

        try {
            $group = $this->targetGroup($groups_id, $context->ticket->entities_id);
        } catch (RuntimeException $e) {
            return ActionResult::failure(ActionType::AssignGroup, $e->getMessage());
        }

        $assigned = $this->assignedGroups($context->ticket->tickets_id);

        if ($assigned === [$groups_id]) {
            return ActionResult::success(
                ActionType::AssignGroup,
                __('The ticket is already assigned to that group alone.', 'ticketclock'),
                ['groups_id' => $groups_id, 'changed' => false],
            );
        }

        if ($context->dry_run) {
            return ActionResult::simulated(
                ActionType::AssignGroup,
                sprintf(
                    $replace
                        ? __('The ticket would be reassigned to %s.', 'ticketclock')
                        : __('The ticket would also be assigned to %s.', 'ticketclock'),
                    (string) ($group->fields['completename'] ?: $group->fields['name']),
                ),
                ['groups_id' => $groups_id, 'from' => $assigned, 'replace' => $replace],
            );
        }

        try {
            if (!in_array($groups_id, $assigned, true)) {
                $link = new Group_Ticket();
                $added = $link->add(self::assignmentInput($context->ticket->tickets_id, $groups_id));

                if (!$added) {
                    return ActionResult::failure(
                        ActionType::AssignGroup,
                        __('The group could not be assigned to the ticket.', 'ticketclock'),
                    );
                }
            }

            if ($replace && !$this->removeOtherGroups($context->ticket->tickets_id, $groups_id)) {
                // The new group is on the ticket and at least one old one would not come off.
                // Not rolled back: hooks and other plugins have already seen the addition, and
                // undoing it would fire that machinery a second time. Reported instead, because
                // "reassigned" and "now assigned to both" are different tickets to work.
                return ActionResult::failure(
                    ActionType::AssignGroup,
                    __('The ticket was assigned to the new group, but at least one previous group could not be removed, so it is now assigned to both.', 'ticketclock'),
                );
            }
        } catch (Throwable $e) {
            return ActionResult::failure(ActionType::AssignGroup, $e->getMessage());
        }

        return ActionResult::success(
            ActionType::AssignGroup,
            $replace ? __('Ticket reassigned.', 'ticketclock') : __('Group assigned.', 'ticketclock'),
            ['groups_id' => $groups_id, 'from' => $assigned, 'replace' => $replace, 'changed' => true],
        );
    }

    /**
     * What this action hands to `Group_Ticket::add()`.
     *
     * Its own method because `_plugin_escalade_rules_only` is a contract with another plugin
     * and the CI matrix does not install that plugin, so this is the only place the key can
     * be pinned. Measured with Escalade 2.10.7 on GLPI 11: with the key, a reassignment moves
     * the group and leaves the ticket's technicians alone; without it, Escalade's own
     * `pre_item_add` strips every assigned technician. That is not a defensive nicety, it is
     * the difference between handing a ticket over and emptying it.
     *
     * @return array<string, mixed>
     */
    public static function assignmentInput(int $tickets_id, int $groups_id): array
    {
        return [
            'tickets_id' => $tickets_id,
            'groups_id'  => $groups_id,
            'type'       => CommonITILActor::ASSIGN,
            '_plugin_escalade_rules_only' => 1,
        ];
    }

    /**
     * The group to assign, refused rather than guessed at.
     *
     * A group configured once and deleted since, or one an administrator has since marked as
     * not assignable, would otherwise be written into the ticket's actors where GLPI's own
     * screens would never have offered it. Same reasoning as the pending reason: a rule that
     * cannot do what it was configured to do says so instead of doing something else.
     *
     * @throws RuntimeException when the group cannot be assigned
     */
    private function targetGroup(int $groups_id, int $entities_id): Group
    {
        $group = new Group();
        if (!$group->getFromDB($groups_id)) {
            throw new RuntimeException(sprintf(
                __('Group %d no longer exists, so the ticket was left where it was.', 'ticketclock'),
                $groups_id,
            ));
        }

        if (!(bool) $group->fields['is_assign']) {
            throw new RuntimeException(sprintf(
                __('Group "%s" is no longer marked as assignable to tickets, so the ticket was left where it was.', 'ticketclock'),
                (string) ($group->fields['completename'] ?: $group->fields['name']),
            ));
        }

        if (!self::groupIsVisibleIn($group, $entities_id)) {
            throw new RuntimeException(sprintf(
                __('Group "%s" does not belong to the ticket\'s entity, so the ticket was left where it was.', 'ticketclock'),
                (string) ($group->fields['completename'] ?: $group->fields['name']),
            ));
        }

        return $group;
    }

    /**
     * Whether GLPI would offer this group on an item in that entity.
     *
     * The form's dropdown is already restricted, but a dropdown restricts a browser, not the
     * database: the id arrives in a POST, and an administrator delegated to one entity can
     * submit the id of a group belonging to another. Nothing downstream would object --
     * `Group_Ticket::add()` authorizes nothing -- and the scheduled run has no session for
     * core's own entity machinery to consult. So the rule would quietly hand tickets to a
     * group outside their entity, making them visible to people the entity separation exists
     * to keep them from.
     *
     * Same rule core applies: the group's own entity, or an ancestor of it when the group is
     * recursive. Written against the ticket's entity, not the session's, because the
     * scheduled run has no session and the ticket is what is being changed.
     *
     * @see \DbUtils::getEntitiesRestrictCriteria() the query form of this condition
     */
    public static function groupIsVisibleIn(Group $group, int $entities_id): bool
    {
        $group_entity = (int) $group->fields['entities_id'];

        if ($group_entity === $entities_id) {
            return true;
        }

        if (!(bool) $group->fields['is_recursive']) {
            return false;
        }

        $ancestors = array_map(intval(...), getAncestorsOf('glpi_entities', $entities_id));

        return in_array($group_entity, $ancestors, true);
    }

    /**
     * @return list<int>
     */
    private function assignedGroups(int $tickets_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => 'groups_id',
            'FROM'   => Group_Ticket::getTable(),
            'WHERE'  => ['tickets_id' => $tickets_id, 'type' => CommonITILActor::ASSIGN],
            'ORDER'  => 'groups_id ASC',
        ]);

        foreach ($iterator as $row) {
            $out[] = (int) $row['groups_id'];
        }

        return $out;
    }

    /**
     * Removes every other assigned group, once the new one is in place.
     *
     * @return bool false when at least one group would not come off
     *
     * In that order on purpose: a ticket briefly assigned to two groups is a ticket two teams
     * can see, while a ticket briefly assigned to none is one that has fallen off every queue.
     * Deleted through the item API rather than by SQL, so the removal reaches the history the
     * same way the addition did.
     */
    private function removeOtherGroups(int $tickets_id, int $keep): bool
    {
        $removed = true;

        foreach ($this->assignedGroups($tickets_id) as $groups_id) {
            if ($groups_id === $keep) {
                continue;
            }

            $link = new Group_Ticket();
            if (!$link->getFromDBByCrit([
                'tickets_id' => $tickets_id,
                'groups_id'  => $groups_id,
                'type'       => CommonITILActor::ASSIGN,
            ])) {
                continue;
            }

            // A hook, a business rule or another plugin can refuse this. Carrying on to the
            // remaining groups is deliberate -- removing three of four is closer to what was
            // asked than stopping at the first refusal -- but the caller is told.
            if (!$link->delete(['id' => $link->fields['id']])) {
                $removed = false;
            }
        }

        return $removed;
    }
}
