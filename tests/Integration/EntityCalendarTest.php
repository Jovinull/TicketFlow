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

namespace GlpiPlugin\Ticketflow\Tests\Integration;

use Calendar;
use CalendarSegment;
use CommonITILActor;
use Entity;
use Group;
use Group_Ticket;
use GlpiPlugin\Ticketflow\Config;
use GlpiPlugin\Ticketflow\Engine\RuleEngine;
use GlpiPlugin\Ticketflow\Enum\ActionType;
use GlpiPlugin\Ticketflow\Rule;
use GlpiPlugin\Ticketflow\RuleAction;
use GlpiPlugin\Ticketflow\RuleGroup;
use PHPUnit\Framework\TestCase;
use Session;
use Ticket;

/**
 * "Entity calendar" mode, across a real entity tree.
 *
 * CalendarParityTest proves the arithmetic. This proves the wiring around it: that a rule
 * left on "entity calendar" finds the calendar the entity tree actually resolves to,
 * including through inheritance, and reports honestly when there is none.
 *
 * It also pins down how the calendar is asked for. `Entity::getUsedConfig('calendars_id',
 * …)` still answers correctly in GLPI 11, but core rewrites it internally and
 * `trigger_error()`s a deprecation every time. This runs once per entity per cron pass, so
 * the wrong form is a log full of notices — and on an instance configured to escalate
 * notices, worse than that.
 */
final class EntityCalendarTest extends TestCase
{
    private int $groups_id = 0;
    private int $rules_id = 0;
    private int $parent_entity = 0;
    private int $child_entity = 0;
    private int $calendars_id = 0;
    private int $root_calendar_before = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        Session::changeActiveEntities(0, true);

        // Half of these tests are about what happens when the tree offers no calendar, so
        // the root must not carry one. Whatever this instance had is put back in tearDown:
        // an instance where an administrator attached a calendar to the root is a normal
        // instance, not a broken one, and a test that reddens there says nothing useful.
        $root = new Entity();
        self::assertTrue($root->getFromDB(0));
        $this->root_calendar_before = (int) $root->fields['calendars_id'];
        $root->update(['id' => 0, 'calendars_id' => 0, 'calendars_strategy' => 0]);

        $suffix = uniqid();

        $this->calendars_id = (int) (new Calendar())->add([
            'name'         => 'TicketFlow entity calendar ' . $suffix,
            'entities_id'  => 0,
            'is_recursive' => 1,
        ]);
        // Monday to Friday, all day: a weekend must cost two days, nothing else.
        for ($day = 1; $day <= 5; $day++) {
            (new CalendarSegment())->add([
                'calendars_id' => $this->calendars_id,
                'entities_id'  => 0,
                'day'          => $day,
                'begin'        => '00:00:00',
                'end'          => '23:59:59',
            ]);
        }
        (new Calendar())->updateDurationCache($this->calendars_id);

        $this->parent_entity = (int) (new Entity())->add([
            'name'         => 'TicketFlow region ' . $suffix,
            'entities_id'  => 0,
            'calendars_id' => $this->calendars_id,
        ]);
        // No calendar of its own: it must inherit the one above.
        $this->child_entity = (int) (new Entity())->add([
            'name'        => 'TicketFlow site ' . $suffix,
            'entities_id' => $this->parent_entity,
        ]);
        self::assertGreaterThan(0, $this->child_entity);

        Session::changeActiveEntities(0, true);

        $this->groups_id = (int) (new Group())->add([
            'name'         => 'TicketFlow entity group ' . $suffix,
            'entities_id'  => 0,
            'is_recursive' => 1,
            'is_assign'    => 1,
        ]);

        $rule = new Rule();
        $this->rules_id = (int) $rule->add([
            'name'          => 'TicketFlow entity calendar rule',
            'entities_id'   => 0,
            'is_recursive'  => 1,
            'rule_type'     => 'pending_inactivity',
            'target_status' => Ticket::WAITING,
            'delay_value'   => 5,
            'delay_unit'    => 'business_days',
            'calendar_mode' => 'entity',
            'reset_events'  => 'requester_followup',
        ]);
        $rule->update(['id' => $this->rules_id, 'is_active' => 1, 'is_dry_run' => 1]);

        RuleGroup::setGroupsForRule($this->rules_id, [$this->groups_id]);
        RuleAction::setActionsForRule($this->rules_id, [
            'add_followup' => ['enabled' => 1, 'content' => 'still waiting'],
        ]);

        Config::set(['execution_enabled' => 0, 'dry_run_global' => 1, 'fallback_calendars_id' => 0]);
    }

    protected function tearDown(): void
    {
        Config::set(['fallback_calendars_id' => 0]);

        (new Entity())->update([
            'id'                 => 0,
            'calendars_id'       => $this->root_calendar_before,
            'calendars_strategy' => 0,
        ]);

        parent::tearDown();
    }

    private function createPendingTicket(int $entities_id, string $began_at): int
    {
        $ticket = new Ticket();
        $tickets_id = (int) $ticket->add([
            'name'        => 'TicketFlow entity ticket',
            'content'     => 'waiting',
            'entities_id' => $entities_id,
            'status'      => Ticket::INCOMING,
        ]);
        self::assertGreaterThan(0, $tickets_id);

        (new Group_Ticket())->add([
            'tickets_id' => $tickets_id,
            'groups_id'  => $this->groups_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);
        $ticket->update(['id' => $tickets_id, 'status' => Ticket::WAITING]);

        // Simulating elapsed time, not exercising a code path.
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(Ticket::getTable(), ['begin_waiting_date' => $began_at], ['id' => $tickets_id]);

        return $tickets_id;
    }

    /**
     * @return array<int, \GlpiPlugin\Ticketflow\Engine\PreviewRow>
     */
    private function preview(): array
    {
        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));

        $rows = [];
        // The preview is opt-in: a scheduled run never reads it, so it is not collected
        // unless the caller says how many rows it intends to look at.
        $report = (new RuleEngine())->runRule($rule->toDefinition(), preview_limit: 100);

        foreach ($report->preview as $row) {
            $rows[(int) $row->tickets_id] = $row;
        }

        return $rows;
    }

    /**
     * A ticket in a child entity uses the calendar inherited from its parent.
     *
     * Monday the 3rd plus five working days is Monday the 10th, not Saturday the 8th.
     */
    public function testAChildEntityInheritsItsParentCalendar(): void
    {
        $tickets_id = $this->createPendingTicket($this->child_entity, '2026-08-03 09:00:00');

        $row = $this->preview()[$tickets_id] ?? null;
        self::assertNotNull($row, 'the rule must see a pending ticket in a child entity');

        self::assertFalse($row->used_elapsed_fallback, 'the inherited calendar must be used');
        self::assertStringContainsString('TicketFlow entity calendar', $row->calendar_name);
        self::assertSame('2026-08-10 09:00:00', $row->deadline_date);
    }

    /**
     * An entity with no calendar anywhere above it falls back, and says so.
     *
     * Silently treating calendar days as business days would be the worst possible
     * outcome here, because the numbers still look plausible.
     */
    public function testAnEntityWithoutACalendarFallsBackAndReportsIt(): void
    {
        $tickets_id = $this->createPendingTicket(0, '2026-08-03 09:00:00');

        $row = $this->preview()[$tickets_id] ?? null;
        self::assertNotNull($row);

        self::assertTrue($row->used_elapsed_fallback);
        self::assertSame('', $row->calendar_name);
        self::assertSame('2026-08-08 09:00:00', $row->deadline_date, 'five calendar days, weekend included');
    }

    /**
     * The configured fallback calendar takes over when the tree offers none.
     */
    public function testTheFallbackCalendarIsUsedWhenTheTreeHasNone(): void
    {
        Config::set(['fallback_calendars_id' => $this->calendars_id]);

        $tickets_id = $this->createPendingTicket(0, '2026-08-03 09:00:00');

        $row = $this->preview()[$tickets_id] ?? null;
        self::assertNotNull($row);

        self::assertFalse($row->used_elapsed_fallback);
        self::assertSame('2026-08-10 09:00:00', $row->deadline_date);
    }

    /**
     * A rule confined to one entity must not reach into a sibling.
     *
     * This is the failure nobody would notice until it had already happened: a rule written
     * for one branch of the tree quietly solving tickets in another. `is_recursive` decides
     * how far down it reaches, and nothing should make it reach sideways or up.
     */
    public function testARuleConfinedToOneEntityIgnoresItsSiblings(): void
    {
        $sibling = (int) (new Entity())->add([
            'name'        => 'TicketFlow sibling ' . uniqid(),
            'entities_id' => 0,
        ]);
        self::assertGreaterThan(0, $sibling);
        Session::changeActiveEntities(0, true);

        $inside  = $this->createPendingTicket($this->child_entity, '2026-08-03 09:00:00');
        $outside = $this->createPendingTicket($sibling, '2026-08-03 09:00:00');

        // Move the rule down to the parent branch, still recursive.
        (new Rule())->update([
            'id'           => $this->rules_id,
            'entities_id'  => $this->parent_entity,
            'is_recursive' => 1,
        ]);

        $seen = $this->preview();
        self::assertArrayHasKey($inside, $seen, 'a ticket below the rule\'s entity must be seen');
        self::assertArrayNotHasKey($outside, $seen, 'a ticket in a sibling entity must not be');
    }

    /**
     * A non-recursive rule does not reach its own children either.
     */
    public function testANonRecursiveRuleStaysInItsOwnEntity(): void
    {
        $here  = $this->createPendingTicket($this->parent_entity, '2026-08-03 09:00:00');
        $below = $this->createPendingTicket($this->child_entity, '2026-08-03 09:00:00');

        (new Rule())->update([
            'id'           => $this->rules_id,
            'entities_id'  => $this->parent_entity,
            'is_recursive' => 0,
        ]);

        $seen = $this->preview();
        self::assertArrayHasKey($here, $seen);
        self::assertArrayNotHasKey($below, $seen, 'without recursion a rule must not reach a child entity');
    }

    /**
     * Resolving the calendar must not raise a deprecation notice.
     */
    public function testResolvingTheEntityCalendarRaisesNoDeprecation(): void
    {
        $this->createPendingTicket($this->child_entity, '2026-08-03 09:00:00');

        $notices = [];
        set_error_handler(
            static function (int $errno, string $message) use (&$notices): bool {
                $notices[] = $message;
                return true;
            },
            E_USER_NOTICE | E_USER_DEPRECATED | E_DEPRECATED,
        );

        try {
            $this->preview();
        } finally {
            // restore_error_handler(), not set_error_handler($previous): passing back a
            // null previous handler installs a *new* one, and PHPUnit rightly calls that
            // leaving a handler behind.
            restore_error_handler();
        }

        $about_config = array_values(array_filter(
            $notices,
            static fn(string $m): bool => str_contains($m, 'Entity config'),
        ));

        self::assertSame(
            [],
            $about_config,
            'the entity calendar must be read through its strategy field, not the deprecated one',
        );
    }
}
