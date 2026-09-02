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
use Entity;
use GlpiPlugin\Ticketclock\Engine\RuleEngine;
use GlpiPlugin\Ticketclock\EntityConfig;
use GlpiPlugin\Ticketclock\Rule;
use GlpiPlugin\Ticketclock\RuleAction;
use GlpiPlugin\Ticketclock\RuleGroup;
use Group;
use Group_Ticket;
use PHPUnit\Framework\TestCase;
use Session;
use Ticket;

/**
 * Policy that belongs to an entity: whether the engine may act here, and on whose calendar.
 *
 * These three settings used to be one global row for the whole instance, which meant a branch
 * could not pause its own engine, and -- worse, because it was silent -- a calendar belonging
 * to one entity computed the deadlines of every other.
 *
 * The tree built here is root > region > site. The site defines nothing of its own, so every
 * inheritance assertion below has to travel two levels to be worth anything: a one-level test
 * would pass against an implementation that only ever looked at the direct parent.
 */
final class EntityPolicyTest extends TestCase
{
    private int $region = 0;
    private int $site = 0;
    private int $foreign_entity = 0;
    private int $root_calendar = 0;
    private int $foreign_calendar = 0;
    private int $groups_id = 0;
    private int $rules_id = 0;
    private int $site_ticket = 0;
    private int $region_ticket = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        Session::changeActiveEntities(0, true);

        $suffix = uniqid();

        $this->region = (int) (new Entity())->add([
            'name' => 'TicketFlow region ' . $suffix, 'entities_id' => 0,
        ]);
        $this->site = (int) (new Entity())->add([
            'name' => 'TicketFlow site ' . $suffix, 'entities_id' => $this->region,
        ]);
        // A sibling of the region, so nothing links it to the site: neither is an ancestor of
        // the other, which is what makes a calendar of its own unusable down there.
        $this->foreign_entity = (int) (new Entity())->add([
            'name' => 'TicketFlow other branch ' . $suffix, 'entities_id' => 0,
        ]);
        self::assertGreaterThan(0, $this->site);

        $this->root_calendar    = $this->createCalendar('TicketFlow root calendar ' . $suffix, 0, true);
        $this->foreign_calendar = $this->createCalendar('TicketFlow foreign calendar ' . $suffix, $this->foreign_entity, false);

        $this->groups_id = (int) (new Group())->add([
            'name' => 'TicketFlow policy group ' . $suffix,
            'entities_id' => 0, 'is_recursive' => 1, 'is_assign' => 1,
        ]);

        // Recursive from the root, so one rule covers both tickets and the only thing that can
        // make them behave differently is the entity policy.
        $rule = new Rule();
        $this->rules_id = (int) $rule->add([
            'name' => 'TicketFlow policy rule ' . $suffix, 'entities_id' => 0, 'is_recursive' => 1,
            'rule_type' => 'pending_inactivity', 'target_status' => Ticket::WAITING,
            'delay_value' => 1, 'delay_unit' => 'calendar_days',
            'calendar_mode' => 'none', 'reset_events' => 'requester_followup',
        ]);
        $rule->update(['id' => $this->rules_id, 'is_active' => 1]);
        RuleGroup::setGroupsForRule($this->rules_id, [$this->groups_id]);
        RuleAction::setActionsForRule($this->rules_id, [
            'add_followup' => ['enabled' => 1, 'content' => 'Please answer.'],
        ]);

        $this->site_ticket   = $this->createWaitingTicket('TicketFlow site ticket', $this->site);
        $this->region_ticket = $this->createWaitingTicket('TicketFlow region ticket', $this->region);
    }

    protected function tearDown(): void
    {
        // Deleted, not reset to "inherit": the entities themselves are purged just below, and
        // writing a row for each of them first would leave one orphan per test run.
        foreach ([$this->region, $this->site, $this->foreign_entity] as $entity) {
            if ($entity > 0) {
                (new EntityConfig())->deleteByCriteria(['entities_id' => $entity], true);
            }
        }
        EntityConfig::setForEntity(0, EntityConfig::ROOT_DEFAULTS);

        foreach ([[Ticket::class, $this->site_ticket], [Ticket::class, $this->region_ticket],
            [Rule::class, $this->rules_id], [Group::class, $this->groups_id],
            [Calendar::class, $this->root_calendar], [Calendar::class, $this->foreign_calendar],
            [Entity::class, $this->site], [Entity::class, $this->region],
            [Entity::class, $this->foreign_entity]] as [$class, $id]) {
            if ($id > 0) {
                (new $class())->delete(['id' => $id], true);
            }
        }

        EntityConfig::reload();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------------
    // Inheritance
    // -----------------------------------------------------------------------------

    public function testAnEntityWithNoRowOfItsOwnFollowsTheRootThroughItsParent(): void
    {
        EntityConfig::setForEntity(0, ['execution_enabled' => 1, 'dry_run' => 0]);

        // Neither the region nor the site was ever written to. An entity created after the
        // plugin was configured has to behave like its parent without anybody saving a form.
        self::assertTrue(EntityConfig::isExecutionEnabled($this->site));
        self::assertFalse(EntityConfig::isDryRun($this->site));
    }

    public function testAnEntityStopsAtTheFirstConcreteValueOnTheWayUp(): void
    {
        EntityConfig::setForEntity(0, ['execution_enabled' => 1, 'dry_run' => 0]);
        EntityConfig::setForEntity($this->region, ['execution_enabled' => 0]);

        // The region says no; the site inherits and must not reach past it to the root's yes.
        self::assertFalse(EntityConfig::isExecutionEnabled($this->site));
        // dry_run was left on inherit at both levels, so it still travels all the way up.
        self::assertFalse(EntityConfig::isDryRun($this->site));
    }

    public function testAnEntityCanContradictItsParent(): void
    {
        EntityConfig::setForEntity(0, ['execution_enabled' => 0, 'dry_run' => 1]);
        EntityConfig::setForEntity($this->site, ['execution_enabled' => 1, 'dry_run' => 0]);

        self::assertTrue(EntityConfig::isExecutionEnabled($this->site));
        self::assertFalse(EntityConfig::isExecutionEnabled($this->region), 'the parent is unchanged');
    }

    public function testTheRootCannotInherit(): void
    {
        // Nothing above it to inherit from: the walk would end with no concrete value and the
        // engine would be reading a default nobody chose.
        self::assertFalse(EntityConfig::setForEntity(0, ['execution_enabled' => Entity::CONFIG_PARENT]));
        self::assertContains(EntityConfig::getUsedValue('execution_enabled', 0), [0, 1]);
    }

    // -----------------------------------------------------------------------------
    // What the engine does with it
    // -----------------------------------------------------------------------------

    public function testPausingOneEntityLeavesItsSiblingRunning(): void
    {
        EntityConfig::setForEntity(0, ['execution_enabled' => 1, 'dry_run' => 0]);
        EntityConfig::setForEntity($this->site, ['execution_enabled' => 0]);

        $report = (new RuleEngine())->runRule($this->ruleDefinition());

        // One rule, two tickets, one entity paused. Before this change the switch was global:
        // either both tickets were acted on or neither was.
        self::assertSame(1, $report->executed, implode(' | ', $report->errors));
        self::assertSame(1, $report->simulated);
        self::assertSame(1, $this->followupCount($this->region_ticket));
        self::assertSame(0, $this->followupCount($this->site_ticket));
    }

    public function testAnEntityUnderItsOwnDryRunOnlySimulates(): void
    {
        EntityConfig::setForEntity(0, ['execution_enabled' => 1, 'dry_run' => 0]);
        EntityConfig::setForEntity($this->site, ['dry_run' => 1]);

        $report = (new RuleEngine())->runRule($this->ruleDefinition());

        self::assertSame(1, $report->executed);
        self::assertSame(1, $report->simulated);
        self::assertSame(0, $this->followupCount($this->site_ticket));
    }

    // -----------------------------------------------------------------------------
    // The fallback calendar
    // -----------------------------------------------------------------------------

    public function testACalendarFromAnotherBranchIsRefusedWhenTheEntityIsSaved(): void
    {
        self::assertFalse(EntityConfig::setForEntity($this->site, [
            'fallback_calendars_id' => $this->foreign_calendar,
        ]));
        self::assertSame(0, EntityConfig::getFallbackCalendarId($this->site));
    }

    public function testACalendarThatBecomesForeignAfterwardsIsIgnoredAtRunTime(): void
    {
        // Saved while it was still legitimate, then moved out from under the entity. The stored
        // id is now valid and wrong: nothing about this plugin was touched, so a check that only
        // ran on save would keep handing this entity somebody else's opening hours.
        $movable = $this->createCalendar('TicketFlow movable calendar ' . uniqid(), 0, true);
        self::assertTrue(EntityConfig::setForEntity($this->site, ['fallback_calendars_id' => $movable]));
        self::assertSame($movable, EntityConfig::getFallbackCalendarId($this->site));

        (new Calendar())->update(['id' => $movable, 'entities_id' => $this->foreign_entity, 'is_recursive' => 0]);
        EntityConfig::reload();

        // 0 puts the rule on plain elapsed time, which every execution row records, instead of
        // on business hours that belong to another branch, which nothing would record.
        self::assertSame(0, EntityConfig::getFallbackCalendarId($this->site));

        (new Calendar())->delete(['id' => $movable], true);
    }

    public function testARecursiveCalendarFromAnAncestorIsStillAccepted(): void
    {
        // The guard rejects other branches, not the entity tree: a recursive calendar on the
        // root is exactly what a site is supposed to fall back on.
        self::assertTrue(EntityConfig::setForEntity($this->site, [
            'fallback_calendars_id' => $this->root_calendar,
        ]));
        self::assertSame($this->root_calendar, EntityConfig::getFallbackCalendarId($this->site));
    }

    public function testTheFallbackIsInheritedLikeTheSwitches(): void
    {
        EntityConfig::setForEntity(0, ['fallback_calendars_id' => $this->root_calendar]);

        self::assertSame($this->root_calendar, EntityConfig::getFallbackCalendarId($this->site));
    }

    // -----------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------

    private function ruleDefinition(): \GlpiPlugin\Ticketclock\Engine\RuleDefinition
    {
        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));

        return $rule->toDefinition();
    }

    private function createCalendar(string $name, int $entities_id, bool $recursive): int
    {
        $calendars_id = (int) (new Calendar())->add([
            'name' => $name, 'entities_id' => $entities_id, 'is_recursive' => $recursive ? 1 : 0,
        ]);
        for ($day = 1; $day <= 5; $day++) {
            (new CalendarSegment())->add([
                'calendars_id' => $calendars_id, 'entities_id' => $entities_id,
                'day' => $day, 'begin' => '00:00:00', 'end' => '23:59:59',
            ]);
        }
        (new Calendar())->updateDurationCache($calendars_id);

        return $calendars_id;
    }

    private function createWaitingTicket(string $name, int $entities_id): int
    {
        $ticket = new Ticket();
        $tickets_id = (int) $ticket->add([
            'name' => $name, 'content' => 'waiting',
            'entities_id' => $entities_id, 'status' => Ticket::INCOMING,
        ]);
        (new Group_Ticket())->add([
            'tickets_id' => $tickets_id, 'groups_id' => $this->groups_id,
            'type' => CommonITILActor::ASSIGN,
        ]);
        $ticket->update(['id' => $tickets_id, 'status' => Ticket::WAITING]);

        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(Ticket::getTable(), [
            'begin_waiting_date' => date('Y-m-d H:i:s', strtotime('-30 days')),
        ], ['id' => $tickets_id]);

        return $tickets_id;
    }

    private function followupCount(int $tickets_id): int
    {
        return countElementsInTable(\ITILFollowup::getTable(), [
            'itemtype' => Ticket::class,
            'items_id' => $tickets_id,
        ]);
    }
}
