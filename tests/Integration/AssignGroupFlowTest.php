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

namespace GlpiPlugin\Ticketclock\Tests\Integration;

use Calendar;
use CalendarSegment;
use CommonITILActor;
use Group;
use Group_Ticket;
use GlpiPlugin\Ticketclock\Config;
use GlpiPlugin\Ticketclock\Engine\Action\AssignGroupAction;
use GlpiPlugin\Ticketclock\Engine\RuleEngine;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use GlpiPlugin\Ticketclock\Execution;
use GlpiPlugin\Ticketclock\Rule;
use GlpiPlugin\Ticketclock\RuleAction;
use GlpiPlugin\Ticketclock\RuleGroup;
use PHPUnit\Framework\TestCase;
use Session;
use Ticket;

/**
 * Handing a ticket to another group when nobody answered.
 *
 * Escalation driven by elapsed business time, which nothing in the GLPI catalogue does:
 * Escalade knows how to escalate and not when, the engine knew when and could not escalate.
 */
final class AssignGroupFlowTest extends TestCase
{
    private int $first_group = 0;
    private int $second_group = 0;
    private int $rules_id = 0;
    private int $calendars_id = 0;
    private int $tickets_id = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        Session::changeActiveEntities(0, true);
        $suffix = uniqid();

        $this->calendars_id = (int) (new Calendar())->add([
            'name' => 'TicketFlow assign calendar ' . $suffix, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        for ($day = 1; $day <= 5; $day++) {
            (new CalendarSegment())->add([
                'calendars_id' => $this->calendars_id, 'entities_id' => 0,
                'day' => $day, 'begin' => '00:00:00', 'end' => '23:59:59',
            ]);
        }
        (new Calendar())->updateDurationCache($this->calendars_id);

        $this->first_group  = $this->assignableGroup('N1 ' . $suffix);
        $this->second_group = $this->assignableGroup('N2 ' . $suffix);

        $rule = new Rule();
        $this->rules_id = (int) $rule->add([
            'name' => 'TicketFlow escalation rule', 'entities_id' => 0, 'is_recursive' => 1,
            'rule_type' => 'pending_inactivity', 'target_status' => Ticket::WAITING,
            'delay_value' => 1, 'delay_unit' => 'business_days',
            'calendar_mode' => 'specific', 'calendars_id' => $this->calendars_id,
            'reset_events' => '',
        ]);
        $rule->update(['id' => $this->rules_id, 'is_active' => 1]);
        RuleGroup::setGroupsForRule($this->rules_id, [$this->first_group]);

        Config::set(['execution_enabled' => 1, 'dry_run_global' => 0]);

        $this->tickets_id = $this->pendingTicketAssignedTo($this->first_group);
    }

    protected function tearDown(): void
    {
        Config::set(['execution_enabled' => 0, 'dry_run_global' => 1]);

        foreach ([[Ticket::class, $this->tickets_id], [Rule::class, $this->rules_id],
            [Group::class, $this->first_group], [Group::class, $this->second_group],
            [Calendar::class, $this->calendars_id]] as [$class, $id]) {
            if ($id > 0) {
                (new $class())->delete(['id' => $id], true);
            }
        }

        parent::tearDown();
    }

    public function testTheTicketIsHandedToTheConfiguredGroup(): void
    {
        $this->armWith(['groups_id' => $this->second_group, 'replace' => false]);

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $report->executed, implode(' | ', $report->errors));
        self::assertSame([$this->first_group, $this->second_group], $this->assignedGroups());
    }

    /**
     * The group is added before the old ones are removed, never the other way round: a ticket
     * assigned to two groups is one two teams can see, a ticket assigned to none has fallen
     * off every queue.
     */
    public function testReplacingLeavesOnlyTheNewGroup(): void
    {
        $this->armWith(['groups_id' => $this->second_group, 'replace' => true]);

        (new RuleEngine())->runRule($this->rule());

        self::assertSame([$this->second_group], $this->assignedGroups());
    }

    public function testADryRunChangesNothing(): void
    {
        $this->armWith(['groups_id' => $this->second_group, 'replace' => true]);

        $report = (new RuleEngine())->runRule($this->rule(), force_dry_run: true);

        self::assertSame(1, $report->simulated);
        self::assertSame([$this->first_group], $this->assignedGroups());
    }

    /**
     * Same lesson as the pending reason: a rule that cannot do what it was configured to do
     * says so rather than doing something else. Writing a deleted group into the ticket's
     * actors would put it somewhere GLPI's own screens would never have offered.
     */
    public function testADeletedGroupStopsTheRule(): void
    {
        $this->armWith(['groups_id' => $this->second_group, 'replace' => true]);
        (new Group())->delete(['id' => $this->second_group], true);
        $deleted = $this->second_group;
        $this->second_group = 0;

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(0, $report->executed);
        self::assertSame(1, $report->failed);
        self::assertSame([$this->first_group], $this->assignedGroups(), 'the ticket was moved anyway');

        $execution = new Execution();
        self::assertTrue($execution->getFromDBByCrit(['tickets_id' => $this->tickets_id]));
        self::assertStringContainsString((string) $deleted, (string) $execution->fields['error']);
    }

    /**
     * A group an administrator has since unticked as assignable is not a group GLPI would
     * offer on the ticket form either.
     */
    public function testAGroupNoLongerMarkedAssignableStopsTheRule(): void
    {
        $this->armWith(['groups_id' => $this->second_group, 'replace' => true]);
        (new Group())->update(['id' => $this->second_group, 'is_assign' => 0]);

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $report->failed);
        self::assertSame([$this->first_group], $this->assignedGroups());
    }

    /**
     * @param array<string, mixed> $assign
     */
    private function armWith(array $assign): void
    {
        RuleAction::setActionsForRule($this->rules_id, ['assign_group' => ['enabled' => 1] + $assign]);
    }

    private function rule(): \GlpiPlugin\Ticketclock\Engine\RuleDefinition
    {
        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));

        return $rule->toDefinition();
    }

    /**
     * @return list<int>
     */
    private function assignedGroups(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => 'groups_id',
            'FROM'   => Group_Ticket::getTable(),
            'WHERE'  => ['tickets_id' => $this->tickets_id, 'type' => CommonITILActor::ASSIGN],
            'ORDER'  => 'groups_id ASC',
        ]);
        foreach ($iterator as $row) {
            $out[] = (int) $row['groups_id'];
        }

        return $out;
    }

    private function assignableGroup(string $name): int
    {
        return (int) (new Group())->add([
            'name' => 'TicketFlow ' . $name, 'entities_id' => 0, 'is_recursive' => 1, 'is_assign' => 1,
        ]);
    }

    private function pendingTicketAssignedTo(int $groups_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ticket = new Ticket();
        $tickets_id = (int) $ticket->add([
            'name' => 'TicketFlow escalation ticket', 'content' => 'waiting',
            'entities_id' => 0, 'status' => Ticket::INCOMING,
        ]);
        (new Group_Ticket())->add([
            'tickets_id' => $tickets_id, 'groups_id' => $groups_id, 'type' => CommonITILActor::ASSIGN,
        ]);
        $ticket->update(['id' => $tickets_id, 'status' => Ticket::WAITING]);
        $DB->update(Ticket::getTable(), [
            'begin_waiting_date' => date('Y-m-d H:i:s', strtotime('-30 days')),
        ], ['id' => $tickets_id]);

        return $tickets_id;
    }
    /**
     * The one part of this action that another plugin can break, pinned here because the CI
     * matrix does not install that plugin.
     *
     * Measured with Escalade 2.10.7 on GLPI 11, both installed, a rule reassigning a ticket
     * from a cron context:
     *
     *     with `_plugin_escalade_rules_only`     groups [N2]   technicians [7]
     *     without it                             groups [N2]   technicians []
     *
     * Escalade's own `pre_item_add` on `Group_Ticket` strips the assigned technicians unless
     * the caller opts out, and it documents the key for exactly this case. Dropping it turns
     * "hand this ticket to the next line" into "empty this ticket", silently.
     */
    public function testTheAssignmentCarriesEscaladesOptOut(): void
    {
        $input = AssignGroupAction::assignmentInput($this->tickets_id, $this->second_group);

        self::assertSame(1, $input['_plugin_escalade_rules_only'] ?? null);
        self::assertSame(CommonITILActor::ASSIGN, $input['type']);
        self::assertSame($this->second_group, $input['groups_id']);
    }
}
