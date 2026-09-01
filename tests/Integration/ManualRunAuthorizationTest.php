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
use Glpi\Exception\Http\AccessDeniedHttpException;
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

    /** @var list<int> */
    private array $extra_groups = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extra_groups   = [];
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

        $cleanup = [[Ticket::class, $this->tickets_id], [Rule::class, $this->rules_id],
            [Group::class, $this->groups_id], [Calendar::class, $this->calendars_id]];
        foreach ($this->extra_groups as $extra) {
            $cleanup[] = [Group::class, $extra];
        }

        foreach ($cleanup as [$class, $id]) {
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
        self::assertSame(0, $report->failed, 'a refusal is not an action failure');
        self::assertSame(1, $report->skipped, 'the refusal must be visible, not silent');

        // The denied manual attempt took a claim briefly, but it must release it before
        // returning. Otherwise one under-privileged click disables the scheduled rule for
        // this ticket permanently.
        $scheduled = (new RuleEngine())->runRule($this->rule());
        self::assertSame(1, $this->followupsOnTicket(), 'the denied manual run reserved the occurrence against cron');
        self::assertSame(1, $scheduled->executed);
    }

    public function testAnOperatorWithFollowupRightsCanWriteOne(): void
    {
        $this->becomeOperator(followup_rights: ITILFollowup::ADDALLITEM);

        $report = RuleEngine::forOperator()->runRule($this->rule());

        self::assertSame(1, $this->followupsOnTicket(), 'an operator who may add followups was blocked');
        self::assertSame(1, $report->executed);
    }

    public function testAManualFollowupIsAttributedToTheOperatorNotTheSystemUser(): void
    {
        $this->becomeOperator(followup_rights: ITILFollowup::ADDALLITEM);
        $operator_users_id = (int) Session::getLoginUserID();

        RuleEngine::forOperator()->runRule($this->rule());

        /** @var \DBmysql $DB */
        global $DB;
        $row = $DB->request([
            'FROM'  => ITILFollowup::getTable(),
            'WHERE' => ['itemtype' => Ticket::class, 'items_id' => $this->tickets_id],
        ])->current();

        self::assertNotFalse($row, 'the operator run did not create its followup');
        self::assertSame($operator_users_id, (int) $row['users_id']);
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
    private function becomeOperator(int $followup_rights, array $status_matrix = [], ?int $ticket_rights = null): void
    {
        // A manual run is not a cron run; Session::isCron() would answer yes to every right.
        unset($_SESSION['glpicronuserrunning']);

        // Shaped like GLPI's own central profiles, which carry an empty status matrix.
        $_SESSION['glpiactiveprofile'] = [
            'id' => 4, 'name' => 'ticketclock-operator', 'interface' => 'central',
            'ticket_status' => $status_matrix,
            // ALLSTANDARDRIGHT does not include Ticket::ASSIGN: that is a separate bit, and a
            // profile carrying every standard right still may not hand tickets to a team.
            'ticket'        => $ticket_rights ?? ALLSTANDARDRIGHT,
            'followup'      => $followup_rights,
        ];
    }

    /**
     * Assigning is its own right in GLPI. `Ticket::canAssign()` tests `Ticket::ASSIGN`, a bit
     * apart from UPDATE, so a profile that may edit a ticket is not thereby allowed to move it
     * between teams -- core's own actor form is hidden from those operators.
     *
     * Nothing downstream would object: `Group_Ticket::add()` and `delete()` authorize nothing
     * on their own, which is the same gap the followup and status actions were fixed for. So
     * the manual run would let an operator escalate tickets they could not escalate by hand.
     */
    public function testAnOperatorWithoutTheAssignRightCannotMoveTheTicketBetweenGroups(): void
    {
        $target = $this->assignTargetGroup();
        $this->armAssignTo($target);
        $this->becomeOperator(followup_rights: 0, ticket_rights: ALLSTANDARDRIGHT);

        $report = RuleEngine::forOperator()->runRule($this->rule());

        self::assertSame(0, $report->executed);
        self::assertSame([$this->groups_id], $this->assignedGroups());
    }

    public function testTheSameOperatorMayMoveItOnceTheProfileGrantsAssignment(): void
    {
        $target = $this->assignTargetGroup();
        $this->armAssignTo($target);
        $this->becomeOperator(followup_rights: 0, ticket_rights: ALLSTANDARDRIGHT | Ticket::ASSIGN);

        $report = RuleEngine::forOperator()->runRule($this->rule());

        self::assertSame(1, $report->executed, implode(' | ', $report->errors));
        self::assertSame([$target], $this->assignedGroups());
    }

    private function assignTargetGroup(): int
    {
        $groups_id = (int) (new Group())->add([
            'name' => 'TicketFlow auth target ' . uniqid(),
            'entities_id' => 0, 'is_recursive' => 1, 'is_assign' => 1,
        ]);
        $this->extra_groups[] = $groups_id;

        return $groups_id;
    }

    private function armAssignTo(int $groups_id): void
    {
        RuleAction::setActionsForRule($this->rules_id, [
            'assign_group' => ['enabled' => 1, 'groups_id' => $groups_id, 'replace' => true],
        ]);
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
        self::assertSame(0, $report->failed);
        self::assertSame(1, $report->skipped, 'a refused transition must release its occurrence for cron');
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
    /**
     * `close_ticket` shares the transition check with `change_status` but reaches it by a
     * different route: its target is not configurable, it is always CLOSED. Covering only
     * the configurable one would leave the hard close resting on an untested assumption.
     */
    public function testTheHardCloseIsRefusedWhenTheProfileDeniesClosing(): void
    {
        RuleAction::setActionsForRule($this->rules_id, [
            'final' => ['type' => ActionType::CloseTicket->value],
        ]);

        $this->becomeOperator(followup_rights: ITILFollowup::ADDALLITEM, status_matrix: [
            Ticket::WAITING => [Ticket::CLOSED => 0],
        ]);

        $report = RuleEngine::forOperator()->runRule($this->rule());

        $ticket = new Ticket();
        self::assertTrue($ticket->getFromDB($this->tickets_id));
        self::assertSame(Ticket::WAITING, (int) $ticket->fields['status'], 'the hard close ignored the profile\'s status matrix');
        self::assertSame(0, $report->failed);
        self::assertSame(1, $report->skipped, 'a refused hard close must release its occurrence for cron');
    }

    public function testTheHardCloseRunsWhenTheProfileAllowsIt(): void
    {
        RuleAction::setActionsForRule($this->rules_id, [
            'final' => ['type' => ActionType::CloseTicket->value],
        ]);

        $this->becomeOperator(followup_rights: ITILFollowup::ADDALLITEM);

        $report = RuleEngine::forOperator()->runRule($this->rule());

        $ticket = new Ticket();
        self::assertTrue($ticket->getFromDB($this->tickets_id));
        self::assertSame(Ticket::CLOSED, (int) $ticket->fields['status']);
        self::assertSame(1, $report->executed);
    }
    /**
     * A rule inherited from a parent entity may be read, but not run for real.
     *
     * Asserted against the plugin's own guard, not against whichever core method it happens
     * to sit next to. `CommonDBTM::canUpdateItem()` answered this differently inside the
     * range the plugin supports -- `checkEntity(true)` up to GLPI 11.0.4, `checkEntity()`
     * from 11.0.5 -- so an earlier version of this test passed on the CI's GLPI while the
     * behaviour it claimed to protect was absent on 11.0.0 through 11.0.4. Testing the
     * policy instead of the mechanism is what makes the result mean something on all of them.
     *
     * Why the policy: a real run writes to the rule row. Being able to see a rule is not the
     * same as administering it, and a recursive rule is visible from every child by design.
     */
    public function testAnInheritedRuleIsReadableButNotRunnableForReal(): void
    {
        $suffix = uniqid();
        $before = [$_SESSION['glpiactiveentities'], $_SESSION['glpiactive_entity']];
        $show   = $_SESSION['glpishowallentities'] ?? null;

        $_SESSION['glpishowallentities'] = 1;
        Session::changeActiveEntities(0, true);

        $parent = (int) (new Entity())->add(['name' => 'TicketFlow parent ' . $suffix, 'entities_id' => 0]);
        $child  = (int) (new Entity())->add(['name' => 'TicketFlow child ' . $suffix, 'entities_id' => $parent]);

        $inherited = $this->ruleIn($parent, recursive: true);
        $own       = $this->ruleIn($child, recursive: false);

        try {
            unset($_SESSION['glpishowallentities']);
            $_SESSION['glpiactiveentities']        = [$child];
            $_SESSION['glpiactive_entity']         = $child;
            $_SESSION['glpiactiveentities_string'] = "'" . $child . "'";

            $rule = new Rule();
            self::assertTrue($rule->getFromDB($inherited));
            self::assertTrue($rule->can($inherited, READ), 'an inherited rule must stay visible');

            $refused = false;
            try {
                Rule::checkOperatorAdministersRule($rule);
            } catch (AccessDeniedHttpException) {
                $refused = true;
            }
            self::assertTrue(
                $refused,
                'a child entity could run and stamp metadata onto a rule it only inherits',
            );

            // The other direction matters as much: a guard that also blocked the operator's
            // own rule would be a regression wearing a fix's clothes.
            $mine = new Rule();
            self::assertTrue($mine->getFromDB($own));
            Rule::checkOperatorAdministersRule($mine);
            self::addToAssertionCount(1);
        } finally {
            $_SESSION['glpishowallentities'] = 1;
            Session::changeActiveEntities(0, true);

            foreach ([$inherited, $own] as $id) {
                (new Rule())->delete(['id' => $id], true);
            }
            foreach ([$child, $parent] as $id) {
                (new Entity())->delete(['id' => $id], true);
            }

            [$_SESSION['glpiactiveentities'], $_SESSION['glpiactive_entity']] = $before;
            if ($show !== null) {
                $_SESSION['glpishowallentities'] = $show;
            } else {
                unset($_SESSION['glpishowallentities']);
            }
        }
    }

    private function ruleIn(int $entities_id, bool $recursive): int
    {
        $rule = new Rule();

        return (int) $rule->add([
            'name'         => 'TicketFlow entity rule ' . uniqid(),
            'entities_id'  => $entities_id,
            'is_recursive' => $recursive ? 1 : 0,
            'rule_type'    => 'pending_inactivity',
            'target_status' => Ticket::WAITING,
            'delay_value'  => 1,
            'delay_unit'   => 'business_days',
        ]);
    }
    /**
     * A refusal that arrives after an action already ran keeps the occurrence.
     *
     * Releasing it is right when nothing happened: the operator was told no, the ticket is
     * untouched, and the scheduled run should still get its turn. It is wrong the moment an
     * earlier action succeeded. The followup is already on the ticket, and handing the
     * occurrence back would have cron write it a second time -- turning a permissions refusal
     * into duplicated content on somebody's ticket.
     *
     * So a partial run stays `failed`, which retains the claim. The cost is that the
     * occurrence needs a human to look at it; the alternative costs correctness.
     */
    public function testARefusalAfterAnActionKeepsTheOccurrenceSoCronCannotRepeatIt(): void
    {
        // Allowed first, denied second: the followup lands, the close does not.
        RuleAction::setActionsForRule($this->rules_id, [
            'add_followup' => ['enabled' => 1, 'content' => 'Please answer.'],
            'final'        => ['type' => ActionType::CloseTicket->value],
        ]);

        $this->becomeOperator(followup_rights: ITILFollowup::ADDALLITEM, status_matrix: [
            Ticket::WAITING => [Ticket::CLOSED => 0],
        ]);

        $report = RuleEngine::forOperator()->runRule($this->rule());

        self::assertSame(1, $this->followupsOnTicket(), 'the first action should have run');
        self::assertSame(1, $report->failed, 'a partial run is a failure, not a clean refusal');
        self::assertSame(0, $report->skipped, 'releasing here would let the followup be written twice');

        $ticket = new Ticket();
        self::assertTrue($ticket->getFromDB($this->tickets_id));
        self::assertSame(Ticket::WAITING, (int) $ticket->fields['status'], 'the denied close happened anyway');

        // The scheduled run is not subject to the operator policy, so if the occurrence had
        // been handed back it would run the whole rule again: a second followup, and the
        // close the operator was refused.
        $scheduled = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $this->followupsOnTicket(), 'cron repeated an action that had already run');
        self::assertSame(0, $scheduled->executed);
        self::assertSame(1, $scheduled->already_processed, 'the occurrence was not retained');
    }
}
