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

namespace GlpiPlugin\Ticketclock;

use Calendar;
use CalendarSegment;
use Calendar_Holiday;
use CommonITILActor;
use CommonITILValidation;
use CronTask;
use Entity;
use Glpi\DBAL\QueryExpression;
use Group;
use Group_Ticket;
use PendingReason;
use PendingReason_Item;
use Ticket;
use TicketValidation;

/**
 * Answers "how does *this* installation actually model that?" — with facts, not guesses.
 *
 * Every temporal rule depends on assumptions about the local data: which statuses are in
 * use, whether pending reasons exist at all, whether tickets carry one assigned group or
 * five, whether any calendar is configured. Getting those wrong is silent and expensive.
 * Rather than hard-coding assumptions, TicketFlow ships this read-only report so the
 * answers can be looked up before a rule is written.
 *
 * Read-only by construction: aggregate queries, no writes.
 */
final class Inspector
{
    /**
     * @return array<string, mixed>
     */
    public static function report(): array
    {
        return [
            'environment'  => self::environment(),
            'config'       => Config::all(),
            'warnings'     => Config::getHealthWarnings(),
            'calendars'    => self::calendars(),
            'entities'     => self::entities(),
            'entities_total' => self::countEntities(),
            'groups'       => self::assignableGroups(),
            'pending'      => self::pending(),
            'approvals'    => self::approvals(),
            'assignment'   => self::groupAssignmentShape(),
            'crontasks'    => self::crontasks(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        return [
            'glpi_version'   => GLPI_VERSION,
            'php_version'    => PHP_VERSION,
            'plugin_version' => Version::VERSION,
            'schema_version' => Install::getInstalledSchemaVersion() ?? '—',
            'timezone'       => date_default_timezone_get(),
            'now'            => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function calendars(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $criteria = ['FROM' => Calendar::getTable(), 'ORDER' => 'id'];
        self::restrict($criteria, Calendar::getTable(), '', true);

        $out = [];
        foreach ($DB->request($criteria) as $row) {
            $calendar = new Calendar();
            $calendar->getFromDB($row['id']);

            $out[] = [
                'id'            => (int) $row['id'],
                'name'          => (string) $row['name'],
                'entities_id'   => (int) $row['entities_id'],
                'is_recursive'  => (bool) $row['is_recursive'],
                'segments'      => countElementsInTable(CalendarSegment::getTable(), ['calendars_id' => $row['id']]),
                'holidays'      => countElementsInTable(Calendar_Holiday::getTable(), ['calendars_id' => $row['id']]),
                'has_working_day' => $calendar->hasAWorkingDay(),
            ];
        }

        return $out;
    }

    /**
     * Which calendar each entity actually resolves to, inheritance included. This is what a
     * rule in "entity calendar" mode will really use.
     *
     * @return list<array<string, mixed>>
     */
    private static function entities(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $criteria = [
            'FROM'  => Entity::getTable(),
            'ORDER' => 'completename',
            'LIMIT' => self::ENTITY_LIMIT,
        ];
        self::restrict($criteria, Entity::getTable(), 'id');

        $out = [];
        foreach ($DB->request($criteria) as $row) {
            // Asked for by its *strategy* field, which is what GLPI 11 expects. Passing
            // 'calendars_id' as the reference still works -- core rewrites it -- but it
            // trigger_error()s a deprecation on every single call, and this one runs once per
            // entity per cron pass.
            $resolved = Entity::getUsedConfig('calendars_strategy', (int) $row['id'], 'calendars_id', 0);

            $out[] = [
                'id'           => (int) $row['id'],
                'name'         => (string) ($row['completename'] ?: $row['name']),
                'own'          => (int) $row['calendars_id'],
                'resolved'     => is_numeric($resolved) ? (int) $resolved : 0,
                'tickets'      => countElementsInTable(Ticket::getTable(), [
                    'entities_id' => $row['id'],
                    'is_deleted'  => 0,
                ]),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function assignableGroups(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $counts = [];
        $criteria = self::assignedGroupTickets();
        $criteria['SELECT']  = [
            Group_Ticket::getTable() . '.groups_id',
            'COUNT DISTINCT' => Group_Ticket::getTable() . '.tickets_id AS nb',
        ];
        $criteria['GROUPBY'] = Group_Ticket::getTable() . '.groups_id';

        foreach ($DB->request($criteria) as $row) {
            $counts[(int) $row['groups_id']] = (int) $row['nb'];
        }

        $groups = ['FROM' => Group::getTable(), 'WHERE' => ['is_assign' => 1], 'ORDER' => 'completename'];
        self::restrict($groups, Group::getTable(), '', true);

        $out = [];
        foreach ($DB->request($groups) as $row) {
            $out[] = [
                'id'          => (int) $row['id'],
                'name'        => (string) ($row['completename'] ?: $row['name']),
                'entities_id' => (int) $row['entities_id'],
                'tickets'     => $counts[(int) $row['id']] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * How this installation represents "pending".
     *
     * @return array<string, mixed>
     */
    private static function pending(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $waiting = self::countTickets([
            'status'     => Ticket::WAITING,
            'is_deleted' => 0,
        ]);

        $waiting_without_reference = self::countTickets([
            'status'             => Ticket::WAITING,
            'is_deleted'         => 0,
            'begin_waiting_date' => null,
        ]);

        $reason_criteria = ['FROM' => PendingReason::getTable(), 'ORDER' => 'name'];
        self::restrict($reason_criteria, PendingReason::getTable(), '', true);

        $reasons = [];
        foreach ($DB->request($reason_criteria) as $row) {
            $reasons[] = [
                'id'     => (int) $row['id'],
                'name'   => (string) $row['name'],
                'in_use' => self::countPendingItems(['pendingreasons_id' => $row['id']]),
            ];
        }

        $items_without_reason = self::countPendingItems(['pendingreasons_id' => 0]);

        return [
            'waiting_tickets'           => $waiting,
            'waiting_without_reference' => $waiting_without_reference,
            'reasons'                   => $reasons,
            'items_without_reason'      => $items_without_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function approvals(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $validations = ['FROM' => TicketValidation::getTable()];
        self::restrict($validations, TicketValidation::getTable());

        $by_status = [];
        $iterator = $DB->request($validations + [
            'SELECT'  => ['status', 'COUNT' => 'id AS nb'],
            'GROUPBY' => 'status',
        ]);
        foreach ($iterator as $row) {
            $by_status[(int) $row['status']] = (int) $row['nb'];
        }

        $labels = CommonITILValidation::getAllStatusArray(false, true);

        $rows = [];
        foreach ($by_status as $status => $nb) {
            $rows[] = [
                'status' => $status,
                'label'  => $labels[$status] ?? (string) $status,
                'count'  => $nb,
            ];
        }

        return [
            'by_status'      => $rows,
            'waiting'        => $by_status[CommonITILValidation::WAITING] ?? 0,
            'group_targets'  => self::countValidations(['itemtype_target' => Group::class]),
            'user_targets'   => self::countValidations(['itemtype_target' => 'User']),
        ];
    }

    /**
     * How many assigned groups tickets actually carry.
     *
     * This is the fact that decides the semantics of "a rule for group X": if most tickets
     * carry several assigned groups, a rule that required a single one would be useless.
     *
     * @return array<int, int> number of assigned groups => number of tickets
     */
    private static function groupAssignmentShape(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $criteria = self::assignedGroupTickets();
        $criteria['SELECT']  = [
            Group_Ticket::getTable() . '.tickets_id',
            'COUNT' => Group_Ticket::getTable() . '.id AS nb',
        ];
        $criteria['GROUPBY'] = Group_Ticket::getTable() . '.tickets_id';

        $per_ticket = [];
        $iterator = $DB->request($criteria);

        foreach ($iterator as $row) {
            $nb = (int) $row['nb'];
            $per_ticket[$nb] = ($per_ticket[$nb] ?? 0) + 1;
        }

        ksort($per_ticket);

        return $per_ticket;
    }

    /**
     * How many entities the table lists at most.
     *
     * The row cost is real: each one resolves its calendar through the tree and counts its
     * tickets. But a page whose whole job is to state facts must not quietly show the first
     * two hundred and let the reader believe that is all of them, so `countEntities()`
     * reports the true total alongside and the template says when it stopped short.
     */
    private const ENTITY_LIMIT = 200;

    private static function countEntities(): int
    {
        $criteria = ['FROM' => Entity::getTable()];
        self::restrict($criteria, Entity::getTable(), 'id');

        return self::count($criteria);
    }

    /**
     * Narrows a query to the entities the reader is actually allowed to see.
     *
     * The diagnostics page is gated on this plugin's own UPDATE right, and `installRights()`
     * hands that right to every profile that can configure GLPI -- which includes the
     * entity-scoped administrator of a single subsidiary. Without this, such an operator
     * would read entity names, ticket volumes, group workloads and approval counts for the
     * whole instance, which is precisely what core keeps entity-scoped everywhere else.
     * Reported by GLPI's pre-publication security review.
     *
     * Core returns no criteria at all when the reader can genuinely see everything, so an
     * empty result is "no restriction needed", not a failure.
     *
     * @param array<string, mixed> $criteria query being built; modified in place
     */
    private static function restrict(array &$criteria, string $table, string $field = '', bool $recursive = false): void
    {
        $criteria['WHERE'] ??= [];

        // No session means no reader, and a report with nobody to read it shows nothing.
        //
        // Not defensiveness for its own sake. `getEntitiesRestrictCriteria()` changed inside
        // the range this plugin supports: GLPI 11.0.8 added an explicit "no active session
        // and no privileged context, deny everything" branch that 11.0.0 does not have. This
        // page is only reachable through `front/inspect.php`, which needs a session, so the
        // difference cannot bite today -- but the whole page exists to disclose aggregates,
        // and its scoping should not depend on which patch of the host answered.
        if (($_SESSION['glpiactiveentities'] ?? []) === []) {
            $criteria['WHERE'][] = new QueryExpression('0');

            return;
        }

        $restriction = getEntitiesRestrictCriteria($table, $field, '', $recursive);
        if ($restriction === []) {
            // Core's way of saying "this reader may see everything", which it answers for a
            // genuine global administrator.
            return;
        }

        $criteria['WHERE'][] = $restriction;
    }

    /**
     * Assigned-group rows joined to their ticket, so the entity restriction has something to
     * apply to: `glpi_groups_tickets` carries no `entities_id` of its own.
     *
     * @return array<string, mixed>
     */
    private static function assignedGroupTickets(): array
    {
        $criteria = [
            'FROM'       => Group_Ticket::getTable(),
            'INNER JOIN' => [
                Ticket::getTable() => [
                    'ON' => [
                        Group_Ticket::getTable() => 'tickets_id',
                        Ticket::getTable()       => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                Group_Ticket::getTable() . '.type' => CommonITILActor::ASSIGN,
                Ticket::getTable() . '.is_deleted' => 0,
            ],
        ];
        self::restrict($criteria, Ticket::getTable());

        return $criteria;
    }

    /**
     * @param array<string, mixed> $where
     */
    private static function countTickets(array $where): int
    {
        $criteria = ['FROM' => Ticket::getTable(), 'WHERE' => $where];
        self::restrict($criteria, Ticket::getTable());

        return self::count($criteria);
    }

    /**
     * Pending-reason rows attached to tickets. Same join reason as assignedGroupTickets():
     * the link table has no entity of its own, the ticket it points at does.
     *
     * @param array<string, mixed> $where columns of the link table, unqualified
     */
    private static function countPendingItems(array $where): int
    {
        $link = PendingReason_Item::getTable();

        $qualified = [$link . '.itemtype' => Ticket::class];
        foreach ($where as $field => $value) {
            $qualified[$link . '.' . $field] = $value;
        }

        $criteria = [
            'FROM'       => $link,
            'INNER JOIN' => [
                Ticket::getTable() => [
                    'ON' => [
                        $link              => 'items_id',
                        Ticket::getTable() => 'id',
                    ],
                ],
            ],
            'WHERE'      => $qualified,
        ];
        self::restrict($criteria, Ticket::getTable());

        return self::count($criteria);
    }

    /**
     * @param array<string, mixed> $where
     */
    private static function countValidations(array $where): int
    {
        $criteria = ['FROM' => TicketValidation::getTable(), 'WHERE' => $where];
        self::restrict($criteria, TicketValidation::getTable());

        return self::count($criteria);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private static function count(array $criteria): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $criteria['COUNT'] = 'nb';
        $row = $DB->request($criteria)->current();

        return is_array($row) ? (int) $row['nb'] : 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function crontasks(): array
    {
        $out = [];
        foreach ([Cron::TASK_PROCESS, Cron::TASK_PURGE] as $name) {
            $task = new CronTask();
            if (!$task->getFromDBbyName(Cron::class, $name)) {
                $out[] = ['name' => $name, 'registered' => false];
                continue;
            }

            $out[] = [
                'name'       => $name,
                'registered' => true,
                'state'      => (int) $task->fields['state'],
                'mode'       => (int) $task->fields['mode'],
                'frequency'  => (int) $task->fields['frequency'],
                'lastrun'    => $task->fields['lastrun'],
                'lastcode'   => $task->fields['lastcode'],
            ];
        }

        return $out;
    }
}
