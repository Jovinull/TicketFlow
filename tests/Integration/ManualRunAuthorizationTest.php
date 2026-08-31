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
use GlpiPlugin\Ticketclock\Engine\RuleEngine;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use GlpiPlugin\Ticketclock\Rule;
use GlpiPlugin\Ticketclock\RuleAction;
use GlpiPlugin\Ticketclock\RuleGroup;
use ITILFollowup;
use PHPUnit\Framework\TestCase;
use Session;
use Ticket;

/**
 * The "Run for real" button must obey the operator's own permissions, action by action.
 *
 * GLPI keeps these apart: adding a followup keys on `ITILFollowup::$rightname` and its own
 * bits, and an operator can hold `ticket` UPDATE without any of them. A single ticket-level
 * check at the entry point therefore was not enough, and this screen would still write
 * followups for somebody who cannot write one by hand.
 *
 * The scheduled run is the opposite case and these tests pin that too. It has no session and
 * no profile, so `isAllowedStatus()` answers false for everything and the same checks would
 * refuse every legitimate automated run. That is why the policy is handed only to the manual
 * caller instead of living inside the actions.
 */
final class ManualRunAuthorizationTest extends TestCase
{
    private int $groups_id = 0;
    private int $rules_id = 0;
    private int $calendars_id = 0;
    private int $tickets_id = 0;
    private ?string $cron_before = null;
    /** @var array<string, mixed> */
    private array $profile_before = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cron_before    = $_SESSION['glpicronuserrunning'] ?? null;
        $this->profile_before = $_SESSION['glpiactiveprofile'];
        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        Session::changeActiveEntities(0, true);

        $suffix = uniqid();

        $this->calendars_id = (int) (new Calendar())->add([
            'name' => 'TicketFlow auth calendar ' . $suffix, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        for ($day = 1; $day <= 5; $day++) {
            (new CalendarSegment())->add([
                'calendars_id' => $this->calendars_id, 'entities_id' => 0,
                'day' => $day, 'begin' => '00:00:00', 'end' => '23:59:59',
            ]);
        }
        (new Calendar())->updateDurationCache($this->calendars_id);

        $this->groups_id = (int) (new Group())->add([
            'name' => 'TicketFlow auth group ' . $suffix,
            'entities_id' => 0, 'is_recursive' => 1, 'is_assign' => 1,
        ]);

        $rule = new Rule();
        $this->rules_id = (int) $rule->add([
            'name' => 'TicketFlow auth rule', 'entities_id' => 0, 'is_recursive' => 1,
            'rule_type' => 'pending_inactivity', 'target_status' => Ticket::WAITING,
            'delay_value' => 1, 'delay_unit' => 'business_days',
            'calendar_mode' => 'specific', 'calendars_id' => $this->calendars_id,
            'reset_events' => 'requester_followup',
        ]);
        $rule->update(['id' => $this->rules_id, 'is_active' => 1]);
        RuleGroup::setGroupsForRule($this->rules_id, [$this->groups_id]);
        RuleAction::setActionsForRule($this->rules_id, [
            'add_followup' => ['enabled' => 1, 'content' => 'Please answer.'],
        ]);

        Config::set(['execution_enabled' => 1, 'dry_run_global' => 0]);

        $ticket = new Ticket();
        $this->tickets_id = (int) $ticket->add([
            'name' => 'TicketFlow auth ticket', 'content' => 'waiting',
            'entities_id' => 0, 'status' => Ticket::INCOMING,
        ]);
        (new Group_Ticket())->add([
            'tickets_id' => $this->tickets_id, 'groups_id' => $this->groups_id,
            'type' => CommonITILActor::ASSIGN,
        ]);
        $ticket->update(['id' => $this->tickets_id, 'status' => Ticket::WAITING]);

        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(Ticket::getTable(), [
            'begin_waiting_date' => date('Y-m-d H:i:s', strtotime('-30 days')),
        ], ['id' => $this->tickets_id]);
    }

    protected function tearDown(): void
    {
        Config::set(['execution_enabled' => 0, 'dry_run_global' => 1]);
        $_SESSION['glpiactiveprofile'] = $this->profile_before;
        if ($this->cron_before !== null) {
            $_SESSION['glpicronuserrunning'] = $this->cron_before;
        }

        foreach ([[Ticket::class, $this->tickets_id], [Rule::class, $this->rules_id],
            [Group::class, $this->groups_id], [Calendar::class, $this->calendars_id]] as [$class, $id]) {
            if ($id > 0) {
                (new $class())->delete(['id' => $id], true);
            }
        }

        parent::tearDown();
    }

    public function testAnOperatorWithoutFollowupRightsCannotWriteOneThroughTheManualRun(): void
    {
        $this->becomeOperator(followup_rights: 0);

        $report = RuleEngine::forOperator()->runRule($this->rule());

        self::assertSame(0, $this->followupsOnTicket(), 'the manual run wrote a followup the operator could not write by hand');
        self::assertSame(0, $report->executed);
        self::assertSame(1, $report->failed, 'the refusal must be recorded, not silently skipped');
    }

    public function testAnOperatorWithFollowupRightsCanWriteOne(): void
    {
        $this->becomeOperator(followup_rights: ITILFollowup::ADDALLITEM);

        $report = RuleEngine::forOperator()->runRule($this->rule());

        self::assertSame(1, $this->followupsOnTicket(), 'an operator who may add followups was blocked');
        self::assertSame(1, $report->executed);
    }

    /**
     * The scheduled run carries no profile at all, so every capability method core exposes
     * answers false. If the policy ever leaks into this path, this is the test that says so.
     */
    public function testTheScheduledRunIsNotSubjectToTheOperatorPolicy(): void
    {
        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $this->followupsOnTicket(), 'the scheduled run was blocked by an operator-only check');
        self::assertSame(1, $report->executed);
    }

    /**
     * @param array<int, array<int, int>> $status_matrix core's own shape; empty means the
     *                                                   profile denies no transition
     */
    private function becomeOperator(int $followup_rights, array $status_matrix = []): void
    {
        // A manual run is not a cron run; Session::isCron() would answer yes to every right.
        unset($_SESSION['glpicronuserrunning']);

        // Shaped like GLPI's own central profiles, which carry an empty status matrix.
        $_SESSION['glpiactiveprofile'] = [
            'id' => 4, 'name' => 'ticketclock-operator', 'interface' => 'central',
            'ticket_status' => $status_matrix,
            'ticket'        => ALLSTANDARDRIGHT,
            'followup'      => $followup_rights,
        ];
    }

    private function rule(): \GlpiPlugin\Ticketclock\Engine\RuleDefinition
    {
        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));

        return $rule->toDefinition();
    }

    private function followupsOnTicket(): int
    {
        return countElementsInTable(ITILFollowup::getTable(), [
            'itemtype' => Ticket::class,
            'items_id' => $this->tickets_id,
        ]);
    }
    /**
     * `Ticket::update()` does not enforce the profile's status matrix; core applies it when
     * it builds the status dropdown, so by hand an operator is never offered a transition
     * their profile denies. The manual run used to hand them one anyway.
     */
    public function testAnOperatorCannotMakeATransitionTheirProfileDenies(): void
    {
        RuleAction::setActionsForRule($this->rules_id, [
            'final' => ['type' => ActionType::ChangeStatus->value, 'status' => Ticket::CLOSED],
        ]);

        // Same shape core stores: status id => target id => allowed.
        $this->becomeOperator(followup_rights: ITILFollowup::ADDALLITEM, status_matrix: [
            Ticket::WAITING => [Ticket::CLOSED => 0],
        ]);

        $report = RuleEngine::forOperator()->runRule($this->rule());

        $ticket = new Ticket();
        self::assertTrue($ticket->getFromDB($this->tickets_id));
        self::assertSame(Ticket::WAITING, (int) $ticket->fields['status'], 'the ticket was moved to a status the profile denies');
        self::assertSame(1, $report->failed);
    }

    public function testTheSameTransitionIsAllowedWhenTheProfilePermitsIt(): void
    {
        RuleAction::setActionsForRule($this->rules_id, [
            'final' => ['type' => ActionType::ChangeStatus->value, 'status' => Ticket::CLOSED],
        ]);

        $this->becomeOperator(followup_rights: ITILFollowup::ADDALLITEM);

        $report = RuleEngine::forOperator()->runRule($this->rule());

        $ticket = new Ticket();
        self::assertTrue($ticket->getFromDB($this->tickets_id));
        self::assertSame(Ticket::CLOSED, (int) $ticket->fields['status']);
        self::assertSame(1, $report->executed);
    }
}
