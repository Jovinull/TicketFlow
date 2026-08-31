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

use CommonITILActor;
use CommonITILValidation;
use GlpiPlugin\Ticketclock\Engine\Action\AddFollowupAction;
use GlpiPlugin\Ticketclock\Enum\RuleType;
use GlpiPlugin\Ticketclock\Enum\StartEvent;
use ITILFollowup;
use Ticket;
use TicketValidation;
use GlpiPlugin\Ticketclock\Support\Time;

/**
 * Narrows "every ticket in the database" down to the handful a rule could possibly act on.
 *
 * The point of this class is that the expensive work — building contexts, walking
 * timelines — never runs for tickets that cannot match. Everything here is a single
 * indexed SQL query with a LIMIT, so the cost grows with the number of *candidates*, not
 * with the size of the ticket table.
 *
 * The time prefilter deserves a note: a delay of N business days always spans at least N
 * calendar days (weekends and holidays only ever push the deadline later), and N business
 * hours always span at least N clock hours. So `reference <= now - delay` is a necessary
 * condition for expiry under every supported unit, and using it as a SQL prefilter can
 * never hide a ticket that would have matched.
 *
 * Batches are paged with a cursor (`id > $after_id`), never with OFFSET. Acting on a
 * candidate can remove it from the result set — solving a ticket drops it out of a pending
 * query — and with OFFSET the rows behind it shift up, so the next page would silently skip
 * them. A cursor is anchored to a value that does not move.
 */
final readonly class CandidateFinder
{
    /**
     * @param list<int>|null $limit_entities entities the caller may act in, or null for the
     *                                       scheduled run, which has no session to narrow to
     */
    public function __construct(private ?array $limit_entities = null) {}

    /**
     * @return list<Candidate>
     */
    public function find(RuleDefinition $rule, string $now, int $limit, int $after_id = 0): array
    {
        return match ($rule->type) {
            RuleType::PendingInactivity => $this->findPendingInactivity($rule, $now, $limit, $after_id),
            RuleType::PendingApproval   => $this->findPendingApproval($rule, $now, $limit, $after_id),
        };
    }

    /**
     * @return list<Candidate>
     */
    private function findPendingInactivity(RuleDefinition $rule, string $now, int $limit, int $after_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $tickets = Ticket::getTable();

        $threshold = $this->latestReference($rule, $now);

        $where = ["$tickets.is_deleted" => 0];

        if ($rule->start_event === StartEvent::LastTargetGroupMessage) {
            // The clock hangs off the conversation, not off the state, so the prefilter has
            // to as well. Filtering on begin_waiting_date here would drop every ticket whose
            // pending state is newer than its last message — and would exclude outright the
            // statuses core never stamps a date on.
            $this->restrictToStaleConversation($where, $tickets, $threshold);
        } else {
            $where["$tickets.begin_waiting_date"] = ['<=', $threshold];
            $where[] = ['NOT' => ["$tickets.begin_waiting_date" => null]];
        }

        $where[] = ["$tickets.status" => $rule->target_status > 0 ? $rule->target_status : Ticket::WAITING];

        if ($after_id > 0) {
            $where["$tickets.id"] = ['>', $after_id];
        }

        $where["$tickets.entities_id"] = $this->entityScope($rule);

        $criteria = [
            'SELECT' => ["$tickets.id AS tickets_id"],
            'FROM'   => $tickets,
            'WHERE'  => $where,
            'ORDER'  => "$tickets.id ASC",
            'LIMIT'  => $limit,
        ];

        $this->restrictToAssignedGroups($criteria, $rule, $tickets);

        $out = [];
        foreach ($DB->request($criteria) as $row) {
            $out[] = new Candidate((int) $row['tickets_id'], 0);
        }

        return $out;
    }

    /**
     * @return list<Candidate>
     */
    private function findPendingApproval(RuleDefinition $rule, string $now, int $limit, int $after_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $tickets     = Ticket::getTable();
        $validations = TicketValidation::getTable();

        $where = [
            "$validations.status"          => CommonITILValidation::WAITING,
            "$validations.submission_date" => ['<=', $this->latestReference($rule, $now)],
            "$tickets.is_deleted"          => 0,
            // Same guard core's own approval reminder uses: never chase approvals on a
            // ticket that is already solved or closed.
            Ticket::getOpenCriteria(),
        ];

        if ($rule->target_status > 0) {
            $where["$tickets.status"] = $rule->target_status;
        }

        if ($after_id > 0) {
            $where["$validations.id"] = ['>', $after_id];
        }

        $where["$tickets.entities_id"] = $this->entityScope($rule);

        $criteria = [
            'SELECT' => ["$tickets.id AS tickets_id", "$validations.id AS validations_id"],
            'FROM'   => $validations,
            'INNER JOIN' => [
                $tickets => [
                    'ON' => [
                        $tickets     => 'id',
                        $validations => 'tickets_id',
                    ],
                ],
            ],
            'WHERE'  => $where,
            'ORDER'  => "$validations.id ASC",
            'LIMIT'  => $limit,
        ];

        $this->restrictToAssignedGroups($criteria, $rule, $tickets);

        $out = [];
        foreach ($DB->request($criteria) as $row) {
            $out[] = new Candidate((int) $row['tickets_id'], (int) $row['validations_id']);
        }

        return $out;
    }

    /**
     * Keep only tickets whose most recent visible message is already older than the
     * threshold.
     *
     * Expressed as "there is an old message AND there is no newer one" rather than as a
     * MAX() subquery, so both halves can use the (itemtype, items_id) index instead of
     * aggregating the whole followup table.
     *
     * This is a *necessary* condition, not a sufficient one: whether that last message came
     * from the right group, and whether a status change moved the clock, are decided by the
     * matcher on real data.
     *
     * @param array<int|string, mixed> $where
     */
    private function restrictToStaleConversation(array &$where, string $tickets, string $threshold): void
    {
        $followups = ITILFollowup::getTable();

        $base = [
            'itemtype'   => Ticket::class,
            'is_private' => 0,
            // Our own generated followups are not part of the conversation.
            ['NOT' => ['content' => ['LIKE', '%' . AddFollowupAction::MARKER . '%']]],
        ];

        $where[] = [
            "$tickets.id" => new \Glpi\DBAL\QuerySubQuery([
                'SELECT' => 'items_id',
                'FROM'   => $followups,
                'WHERE'  => $base + ['date' => ['<=', $threshold]],
            ]),
        ];

        $where[] = [
            'NOT' => [
                "$tickets.id" => new \Glpi\DBAL\QuerySubQuery([
                    'SELECT' => 'items_id',
                    'FROM'   => $followups,
                    'WHERE'  => $base + ['date' => ['>', $threshold]],
                ]),
            ],
        ];
    }

    /**
     * Add the "one of the ticket's assigned groups is targeted by the rule" condition.
     *
     * Expressed as a subquery rather than a join so a ticket with several matching groups
     * still yields a single row.
     *
     * @param array<string, mixed> $criteria
     */
    private function restrictToAssignedGroups(array &$criteria, RuleDefinition $rule, string $tickets): void
    {
        if ($rule->groups_id === []) {
            return;
        }

        $link = \Group_Ticket::getTable();

        $criteria['WHERE'][] = [
            "$tickets.id" => new \Glpi\DBAL\QuerySubQuery([
                'SELECT' => 'tickets_id',
                'FROM'   => $link,
                'WHERE'  => [
                    'type'      => CommonITILActor::ASSIGN,
                    'groups_id' => $rule->groups_id,
                ],
            ]),
        ];
    }

    /**
     * Entity ids the rule may touch.
     *
     * @return list<int>
     */
    private function entityScope(RuleDefinition $rule): array
    {
        if (!$rule->is_recursive) {
            $scope = [$rule->entities_id];
        } else {
            $sons  = getSonsOf('glpi_entities', $rule->entities_id);
            $scope = array_values(array_map(intval(...), $sons));
        }

        if ($this->limit_entities === null) {
            return $scope;
        }

        // A manual run is narrowed to what the operator may see. A recursive rule stored on
        // a parent entity is readable from every child -- that is what `is_recursive` means
        // -- so without this an operator confined to one branch could open such a rule,
        // press "Run for real", and act on tickets in a sibling entity they have no access
        // to at all. The rule's own scope stays the ceiling; the session is a second, lower
        // one. An empty intersection means the run touches nothing, which is correct.
        return array_values(array_intersect($scope, $this->limit_entities));
    }

    /**
     * The newest reference date that could already have expired. See the class docblock.
     */
    private function latestReference(RuleDefinition $rule, string $now): string
    {
        $seconds = $rule->delayInSeconds();

        return date('Y-m-d H:i:s', Time::stamp($now) - $seconds);
    }
}
