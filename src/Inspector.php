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

        $out = [];
        foreach ($DB->request(['FROM' => Calendar::getTable(), 'ORDER' => 'id']) as $row) {
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

        $out = [];
        foreach ($DB->request(['FROM' => Entity::getTable(), 'ORDER' => 'completename', 'LIMIT' => 200]) as $row) {
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
        $iterator = $DB->request([
            'SELECT' => ['groups_id', 'COUNT DISTINCT' => 'tickets_id AS nb'],
            'FROM'   => Group_Ticket::getTable(),
            'WHERE'  => ['type' => CommonITILActor::ASSIGN],
            'GROUPBY' => 'groups_id',
        ]);
        foreach ($iterator as $row) {
            $counts[(int) $row['groups_id']] = (int) $row['nb'];
        }

        $out = [];
        foreach ($DB->request(['FROM' => Group::getTable(), 'WHERE' => ['is_assign' => 1], 'ORDER' => 'completename']) as $row) {
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

        $waiting = countElementsInTable(Ticket::getTable(), [
            'status'     => Ticket::WAITING,
            'is_deleted' => 0,
        ]);

        $waiting_without_reference = countElementsInTable(Ticket::getTable(), [
            'status'             => Ticket::WAITING,
            'is_deleted'         => 0,
            'begin_waiting_date' => null,
        ]);

        $reasons = [];
        foreach ($DB->request(['FROM' => PendingReason::getTable(), 'ORDER' => 'name']) as $row) {
            $reasons[] = [
                'id'   => (int) $row['id'],
                'name' => (string) $row['name'],
                'in_use' => countElementsInTable(PendingReason_Item::getTable(), [
                    'pendingreasons_id' => $row['id'],
                    'itemtype'          => Ticket::class,
                ]),
            ];
        }

        $items_without_reason = countElementsInTable(PendingReason_Item::getTable(), [
            'itemtype'          => Ticket::class,
            'pendingreasons_id' => 0,
        ]);

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

        $by_status = [];
        $iterator = $DB->request([
            'SELECT'  => ['status', 'COUNT' => 'id AS nb'],
            'FROM'    => TicketValidation::getTable(),
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
            'group_targets'  => countElementsInTable(TicketValidation::getTable(), ['itemtype_target' => Group::class]),
            'user_targets'   => countElementsInTable(TicketValidation::getTable(), ['itemtype_target' => 'User']),
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

        $per_ticket = [];
        $iterator = $DB->request([
            'SELECT'  => ['tickets_id', 'COUNT' => 'id AS nb'],
            'FROM'    => Group_Ticket::getTable(),
            'WHERE'   => ['type' => CommonITILActor::ASSIGN],
            'GROUPBY' => 'tickets_id',
        ]);

        foreach ($iterator as $row) {
            $nb = (int) $row['nb'];
            $per_ticket[$nb] = ($per_ticket[$nb] ?? 0) + 1;
        }

        ksort($per_ticket);

        return $per_ticket;
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
