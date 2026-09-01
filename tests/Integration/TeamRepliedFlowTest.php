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
use Group_User;
use GlpiPlugin\Ticketclock\Config;
use GlpiPlugin\Ticketclock\Engine\RuleEngine;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use GlpiPlugin\Ticketclock\Rule;
use GlpiPlugin\Ticketclock\RuleAction;
use GlpiPlugin\Ticketclock\RuleGroup;
use ITILFollowup;
use PendingReason;
use PendingReason_Item;
use PHPUnit\Framework\TestCase;
use Session;
use Ticket;
use User;

/**
 * Putting a ticket back into "pending" once the team has answered.
 *
 * The most-asked-for thing this plugin's neighbours do not do: Moreticket shipped it, dropped
 * it in 1.7.1, and the request to bring it back has been the most discussed issue in that
 * repository since 2023. GLPI core does not cover it either -- `PendingReason_Item` moves a
 * ticket to pending only when a technician explicitly ticks "pending" and picks a reason.
 *
 * The engine could already express it: a rule that starts its clock from the last message
 * belonging to the assigned group, watching a status that is not pending, whose action moves
 * the ticket to pending. Nothing said so anywhere, which is a poor way to ship a feature, and
 * the half that was genuinely missing is the pending reason -- see the second test.
 */
final class TeamRepliedFlowTest extends TestCase
{
    private int $groups_id = 0;
    private int $rules_id = 0;
    private int $calendars_id = 0;
    private int $tickets_id = 0;
    private int $technician_id = 0;
    private int $requester_id = 0;
    private int $pendingreasons_id = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        Session::changeActiveEntities(0, true);
        $suffix = uniqid();

        $this->calendars_id = (int) (new Calendar())->add([
            'name' => 'TicketFlow team calendar ' . $suffix, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        for ($day = 1; $day <= 5; $day++) {
            (new CalendarSegment())->add([
                'calendars_id' => $this->calendars_id, 'entities_id' => 0,
                'day' => $day, 'begin' => '00:00:00', 'end' => '23:59:59',
            ]);
        }
        (new Calendar())->updateDurationCache($this->calendars_id);

        $this->groups_id = (int) (new Group())->add([
            'name' => 'TicketFlow team group ' . $suffix,
            'entities_id' => 0, 'is_recursive' => 1, 'is_assign' => 1,
        ]);
        $this->technician_id = (int) (new User())->add([
            'name' => 'ticketclock_tech_' . $suffix, 'entities_id' => 0, '_profiles_id' => 0,
        ]);
        (new Group_User())->add(['groups_id' => $this->groups_id, 'users_id' => $this->technician_id]);
        $this->requester_id = (int) (new User())->add([
            'name' => 'ticketclock_req_' . $suffix, 'entities_id' => 0, '_profiles_id' => 0,
        ]);

        $this->pendingreasons_id = (int) (new PendingReason())->add([
            'name'                        => 'TicketFlow waiting on requester ' . $suffix,
            'entities_id'                 => 0,
            'is_recursive'                => 1,
            'followup_frequency'          => 86400,
            'followups_before_resolution' => 3,
        ]);

        $rule = new Rule();
        $this->rules_id = (int) $rule->add([
            'name' => 'TicketFlow team replied rule', 'entities_id' => 0, 'is_recursive' => 1,
            'rule_type' => 'pending_inactivity',
            // Not pending: this rule watches tickets that are still somebody's work.
            'target_status' => Ticket::ASSIGNED,
            'start_event' => 'last_target_group_message',
            'delay_value' => 1, 'delay_unit' => 'business_days',
            'calendar_mode' => 'specific', 'calendars_id' => $this->calendars_id,
            'reset_events' => '',
        ]);
        $rule->update(['id' => $this->rules_id, 'is_active' => 1]);
        RuleGroup::setGroupsForRule($this->rules_id, [$this->groups_id]);

        Config::set(['execution_enabled' => 1, 'dry_run_global' => 0]);

        $this->tickets_id = $this->assignedTicketAnsweredByTheTeam();
    }

    protected function tearDown(): void
    {
        Config::set(['execution_enabled' => 0, 'dry_run_global' => 1]);

        foreach ([[Ticket::class, $this->tickets_id], [Rule::class, $this->rules_id],
            [Group::class, $this->groups_id], [Calendar::class, $this->calendars_id],
            [PendingReason::class, $this->pendingreasons_id],
            [User::class, $this->technician_id], [User::class, $this->requester_id]] as [$class, $id]) {
            if ($id > 0) {
                (new $class())->delete(['id' => $id], true);
            }
        }

        parent::tearDown();
    }

    /**
     * The capability itself, pinned because nothing else does.
     */
    public function testATicketTheTeamAnsweredGoesBackToPending(): void
    {
        RuleAction::setActionsForRule($this->rules_id, [
            'final' => ['type' => ActionType::ChangeStatus->value, 'status' => Ticket::WAITING],
        ]);

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $report->executed, implode(' | ', $report->errors));

        $ticket = new Ticket();
        self::assertTrue($ticket->getFromDB($this->tickets_id));
        self::assertSame(Ticket::WAITING, (int) $ticket->fields['status']);
    }

    /**
     * The half that was actually missing.
     *
     * A ticket parked in pending with no reason is parked and forgotten: core's bump followups
     * and its auto-solve both read `PendingReason_Item`, so without one nothing chases the
     * ticket and nothing closes it. The rule would take it out of somebody's queue and give
     * nothing the job of moving it on.
     */
    public function testTheConfiguredPendingReasonIsRegisteredSoCoreCanChaseTheTicket(): void
    {
        RuleAction::setActionsForRule($this->rules_id, [
            'final' => [
                'type'              => ActionType::ChangeStatus->value,
                'status'            => Ticket::WAITING,
                'pendingreasons_id' => $this->pendingreasons_id,
            ],
        ]);

        (new RuleEngine())->runRule($this->rule());

        $registered = new PendingReason_Item();
        self::assertTrue(
            $registered->getFromDBByCrit(['itemtype' => Ticket::class, 'items_id' => $this->tickets_id]),
            'the ticket is pending with no reason, so nothing will ever chase it',
        );
        self::assertSame($this->pendingreasons_id, (int) $registered->fields['pendingreasons_id']);
        self::assertSame(86400, (int) $registered->fields['followup_frequency'], 'the reason\'s own cadence was not carried over');
        self::assertSame(3, (int) $registered->fields['followups_before_resolution']);
        self::assertSame(
            Ticket::ASSIGNED,
            (int) $registered->fields['previous_status'],
            'core restores this when the ticket leaves pending, so it must be the status it really had',
        );
    }

    /**
     * Without a reason configured, nothing is registered. The setting is optional and the
     * absence of it must not invent one.
     */
    public function testNoReasonIsRegisteredWhenNoneIsConfigured(): void
    {
        RuleAction::setActionsForRule($this->rules_id, [
            'final' => ['type' => ActionType::ChangeStatus->value, 'status' => Ticket::WAITING],
        ]);

        (new RuleEngine())->runRule($this->rule());

        self::assertFalse(
            (new PendingReason_Item())->getFromDBByCrit(['itemtype' => Ticket::class, 'items_id' => $this->tickets_id]),
        );
    }

    private function rule(): \GlpiPlugin\Ticketclock\Engine\RuleDefinition
    {
        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));

        return $rule->toDefinition();
    }

    /**
     * A ticket assigned to the team ten days ago, answered by a technician five days ago and
     * untouched by the requester since. The ordering matters: a status change restarts this
     * clock, so a ticket assigned *after* the reply is correctly not a candidate.
     */
    private function assignedTicketAnsweredByTheTeam(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ticket = new Ticket();
        $tickets_id = (int) $ticket->add([
            'name' => 'TicketFlow team replied ticket', 'content' => 'question',
            'entities_id' => 0, 'status' => Ticket::INCOMING,
            '_users_id_requester' => $this->requester_id,
        ]);
        (new Group_Ticket())->add([
            'tickets_id' => $tickets_id, 'groups_id' => $this->groups_id,
            'type' => CommonITILActor::ASSIGN,
        ]);
        $ticket->update(['id' => $tickets_id, 'status' => Ticket::ASSIGNED]);

        $DB->update('glpi_logs', ['date_mod' => date('Y-m-d H:i:s', strtotime('-10 days'))], [
            'itemtype' => 'Ticket', 'items_id' => $tickets_id, 'id_search_option' => 12,
        ]);

        $answer = (int) (new ITILFollowup())->add([
            'itemtype' => Ticket::class, 'items_id' => $tickets_id,
            'content' => 'Could you confirm the serial number?',
            'users_id' => $this->technician_id, 'is_private' => 0,
        ]);
        $DB->update(ITILFollowup::getTable(), [
            'date' => date('Y-m-d H:i:s', strtotime('-5 days')),
        ], ['id' => $answer]);

        return $tickets_id;
    }
}
