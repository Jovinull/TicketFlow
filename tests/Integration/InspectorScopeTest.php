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
use CommonITILActor;
use Entity;
use Group;
use Group_Ticket;
use GlpiPlugin\Ticketclock\Inspector;
use PHPUnit\Framework\TestCase;
use Ticket;

/**
 * The diagnostics page must show the reader their own entities, and nothing else.
 *
 * The page is gated on the plugin's UPDATE right, which `Profile::installRights()` used to
 * grant to every profile that can configure GLPI. On a multi-tenant instance that includes
 * the administrator of a single subsidiary, whose core access stops at their own branch of
 * the tree -- and while the install no longer widens the grant, instances that already ran it
 * keep those rights. Before this was fixed, `Inspector::report()` ran instance-wide
 * aggregates with no entity restriction at all, so that operator could read entity names,
 * ticket volumes, group workloads and approval counts belonging to tenants they have no
 * access to. Found by GLPI's pre-publication security review.
 *
 * The second test matters as much as the first: a restriction that also hides data from a
 * genuine global administrator would be a regression dressed up as a fix.
 */
final class InspectorScopeTest extends TestCase
{
    private int $mine = 0;
    private int $theirs = 0;
    private int $my_group = 0;
    private int $their_group = 0;
    private int $my_calendar = 0;
    private int $their_calendar = 0;
    private string $suffix = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->suffix = uniqid();
        $this->seeEverything();

        $this->mine = $this->createEntity('TicketFlow tenant mine ' . $this->suffix);
        $this->theirs = $this->createEntity('TicketFlow tenant theirs ' . $this->suffix);

        $this->my_calendar = $this->createCalendar($this->mine, 'mine');
        $this->their_calendar = $this->createCalendar($this->theirs, 'theirs');

        $this->my_group = $this->createGroup($this->mine, 'mine');
        $this->their_group = $this->createGroup($this->theirs, 'theirs');

        $this->createWaitingTicket($this->mine, $this->my_group);
        $this->createWaitingTicket($this->theirs, $this->their_group);
        $this->createWaitingTicket($this->theirs, $this->their_group);
    }

    protected function tearDown(): void
    {
        $this->seeEverything();

        foreach ([$this->my_group, $this->their_group] as $id) {
            if ($id > 0) {
                (new Group())->delete(['id' => $id], true);
            }
        }
        foreach ([$this->my_calendar, $this->their_calendar] as $id) {
            if ($id > 0) {
                (new Calendar())->delete(['id' => $id], true);
            }
        }

        /** @var \DBmysql $DB */
        global $DB;
        foreach ([$this->mine, $this->theirs] as $id) {
            if ($id > 0) {
                $DB->delete(Ticket::getTable(), ['entities_id' => $id]);
                (new Entity())->delete(['id' => $id], true);
            }
        }

        parent::tearDown();
    }

    public function testATenantAdministratorSeesOnlyTheirOwnEntity(): void
    {
        $this->seeOnly($this->mine);
        $report = Inspector::report();

        $names = array_column($report['entities'], 'name');
        self::assertTrue(
            $this->containing($names, 'tenant mine ' . $this->suffix),
            'the reader must still see their own entity',
        );
        self::assertFalse(
            $this->containing($names, 'tenant theirs ' . $this->suffix),
            'another tenant\'s entity name leaked into the diagnostics page',
        );
    }

    public function testAnotherTenantsGroupsAndCalendarsStayHidden(): void
    {
        $this->seeOnly($this->mine);
        $report = Inspector::report();

        $groups = array_column($report['groups'], 'name');
        self::assertTrue($this->containing($groups, 'group mine ' . $this->suffix));
        self::assertFalse(
            $this->containing($groups, 'group theirs ' . $this->suffix),
            'another tenant\'s group name leaked, along with its ticket count',
        );

        $calendars = array_column($report['calendars'], 'name');
        self::assertTrue($this->containing($calendars, 'calendar mine ' . $this->suffix));
        self::assertFalse(
            $this->containing($calendars, 'calendar theirs ' . $this->suffix),
            'another tenant\'s calendar leaked',
        );
    }

    public function testTicketVolumesAreCountedOnlyWithinReach(): void
    {
        $this->seeOnly($this->mine);
        $mine = Inspector::report();

        $this->seeOnly($this->theirs);
        $theirs = Inspector::report();

        // One waiting ticket was created in "mine" and two in "theirs". The exact totals
        // depend on whatever else the instance holds, so the assertion is on the delta
        // between two readers rather than on an absolute number.
        self::assertSame(1, $this->waitingIn($mine, 'tenant mine ' . $this->suffix));
        self::assertSame(0, $this->waitingIn($mine, 'tenant theirs ' . $this->suffix));
        self::assertSame(2, $this->waitingIn($theirs, 'tenant theirs ' . $this->suffix));
        self::assertSame(0, $this->waitingIn($theirs, 'tenant mine ' . $this->suffix));

        self::assertSame(1, $mine['pending']['waiting_tickets'], 'counted beyond the reader\'s entity');
        self::assertSame(2, $theirs['pending']['waiting_tickets'], 'counted beyond the reader\'s entity');
    }

    public function testAGlobalAdministratorStillSeesEverything(): void
    {
        $this->seeEverything();
        $report = Inspector::report();

        // Asserted on the count rather than on the listed rows: the table stops at
        // Inspector::ENTITY_LIMIT, so on a large instance a specific entity may legitimately
        // not appear. `entities_total` is the number the restriction actually allowed, which
        // is what "sees everything" means here.
        self::assertGreaterThanOrEqual(
            $this->countAllEntities(),
            $report['entities_total'],
            'the restriction must not hide entities from a reader who holds the whole tree',
        );

        self::assertGreaterThanOrEqual(3, $report['pending']['waiting_tickets']);
    }

    /**
     * @param array<int, array<string, mixed>> $report
     */
    private function waitingIn(array $report, string $needle): int
    {
        foreach ($report['entities'] as $row) {
            if (str_contains((string) $row['name'], $needle)) {
                return (int) $row['tickets'];
            }
        }

        return 0;
    }

    private function countAllEntities(): int
    {
        return countElementsInTable(Entity::getTable());
    }

    /**
     * @param list<mixed> $haystack
     */
    private function containing(array $haystack, string $needle): bool
    {
        foreach ($haystack as $value) {
            if (str_contains((string) $value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function seeOnly(int $entities_id): void
    {
        unset($_SESSION['glpishowallentities']);
        $_SESSION['glpiactiveentities']          = [$entities_id];
        $_SESSION['glpiactive_entity']           = $entities_id;
        $_SESSION['glpiactiveentities_string']   = "'" . $entities_id . "'";
        $_SESSION['glpiactive_entity_recursive'] = false;
    }

    private function seeEverything(): void
    {
        $_SESSION['glpishowallentities']         = 1;
        $_SESSION['glpiactiveentities']          = [0];
        $_SESSION['glpiactive_entity']           = 0;
        $_SESSION['glpiactiveentities_string']   = "'0'";
        $_SESSION['glpiactive_entity_recursive'] = true;
    }

    private function createEntity(string $name): int
    {
        $id = (int) (new Entity())->add(['name' => $name, 'entities_id' => 0]);
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function createCalendar(int $entities_id, string $label): int
    {
        return (int) (new Calendar())->add([
            'name'         => 'TicketFlow calendar ' . $label . ' ' . $this->suffix,
            'entities_id'  => $entities_id,
            'is_recursive' => 0,
        ]);
    }

    private function createGroup(int $entities_id, string $label): int
    {
        return (int) (new Group())->add([
            'name'         => 'TicketFlow group ' . $label . ' ' . $this->suffix,
            'entities_id'  => $entities_id,
            'is_recursive' => 0,
            'is_assign'    => 1,
        ]);
    }

    private function createWaitingTicket(int $entities_id, int $groups_id): void
    {
        $ticket = new Ticket();
        $tickets_id = (int) $ticket->add([
            'name'        => 'TicketFlow scope ticket ' . $this->suffix,
            'content'     => 'waiting',
            'entities_id' => $entities_id,
            'status'      => Ticket::INCOMING,
        ]);
        self::assertGreaterThan(0, $tickets_id);

        (new Group_Ticket())->add([
            'tickets_id' => $tickets_id,
            'groups_id'  => $groups_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);
        $ticket->update(['id' => $tickets_id, 'status' => Ticket::WAITING]);
    }
    /**
     * With no session there is no reader, and a report about entities shows none of them.
     *
     * `getEntitiesRestrictCriteria()` is not the same function across the range this plugin
     * supports: GLPI 11.0.8 added an explicit "no active session and no privileged context,
     * deny everything" branch that 11.0.0 does not have. This page is only reachable through
     * `front/inspect.php`, which requires a session, so the difference cannot bite today --
     * but the page exists to disclose aggregates, and its scoping should not depend on which
     * patch of the host answered the question.
     */
    public function testWithNoSessionEntitiesTheReportShowsNothing(): void
    {
        $before = $_SESSION['glpiactiveentities'] ?? null;
        $show   = $_SESSION['glpishowallentities'] ?? null;

        try {
            unset($_SESSION['glpishowallentities']);
            $_SESSION['glpiactiveentities'] = [];

            $report = Inspector::report();

            self::assertSame([], $report['entities'], 'a report with no reader listed entities');
            self::assertSame([], $report['groups']);
            self::assertSame([], $report['calendars']);
            self::assertSame(0, $report['pending']['waiting_tickets']);
            self::assertSame(0, $report['entities_total']);
        } finally {
            if ($before !== null) {
                $_SESSION['glpiactiveentities'] = $before;
            }
            if ($show !== null) {
                $_SESSION['glpishowallentities'] = $show;
            }
        }
    }
}
