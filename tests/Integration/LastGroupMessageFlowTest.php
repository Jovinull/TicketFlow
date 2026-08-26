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

use CommonITILActor;
use Group;
use Group_Ticket;
use Group_User;
use GlpiPlugin\Ticketflow\Config;
use GlpiPlugin\Ticketflow\Engine\Action\AddFollowupAction;
use GlpiPlugin\Ticketflow\Engine\RuleEngine;
use GlpiPlugin\Ticketflow\Enum\ActionType;
use GlpiPlugin\Ticketflow\Enum\StartEvent;
use GlpiPlugin\Ticketflow\Rule;
use GlpiPlugin\Ticketflow\RuleAction;
use GlpiPlugin\Ticketflow\RuleGroup;
use ITILFollowup;
use Log;
use PHPUnit\Framework\TestCase;
use Session;
use Ticket;
use User;

/**
 * "We answered and nobody came back to us", against a real GLPI.
 *
 * The unit suite already proves the arithmetic; what only a real instance can prove is
 * that the resolver reads the right rows — the last public followup, its author's group
 * membership, and the status-change date out of GLPI's own history.
 */
final class LastGroupMessageFlowTest extends TestCase
{
    private int $team_group = 0;
    private int $team_member = 0;
    private int $requester = 0;
    private int $rules_id = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        Session::changeActiveEntities(0, true);

        $this->team_group = (int) (new Group())->add([
            'name'        => 'TicketFlow team ' . uniqid(),
            'entities_id' => 0,
            'is_assign'   => 1,
        ]);

        $this->team_member = (int) (new User())->add([
            'name'        => 'ticketflow_team_' . uniqid(),
            'entities_id' => 0,
        ]);
        (new Group_User())->add([
            'users_id'  => $this->team_member,
            'groups_id' => $this->team_group,
        ]);

        $this->requester = (int) (new User())->add([
            'name'        => 'ticketflow_req_' . uniqid(),
            'entities_id' => 0,
        ]);

        $rule = new Rule();
        $this->rules_id = (int) $rule->add([
            'name'          => 'TicketFlow last-group-message rule',
            'entities_id'   => 0,
            'is_recursive'  => 1,
            'rule_type'     => 'pending_inactivity',
            'target_status' => Ticket::WAITING,
            'start_event'   => StartEvent::LastTargetGroupMessage->value,
            'delay_value'   => 5,
            'delay_unit'    => 'calendar_days',
            'calendar_mode' => 'none',
        ]);
        $rule->update(['id' => $this->rules_id, 'is_active' => 1]);

        RuleGroup::setGroupsForRule($this->rules_id, [$this->team_group]);
        RuleAction::setActionsForRule($this->rules_id, [
            'add_followup' => ['enabled' => 1, 'content' => 'No answer since {{reference}}.'],
            'final'        => ['type' => ActionType::AddSolution->value, 'content' => 'Closed by TicketFlow.'],
        ]);

        Config::set([
            'execution_enabled' => 1,
            'dry_run_global'    => 0,
            'system_users_id'   => $this->team_member,
        ]);
    }

    protected function tearDown(): void
    {
        Config::set(['execution_enabled' => 0, 'dry_run_global' => 1, 'system_users_id' => 0]);
        parent::tearDown();
    }

    private function createPendingTicket(): int
    {
        $ticket = new Ticket();
        $tickets_id = (int) $ticket->add([
            'name'                => 'TicketFlow conversation ticket',
            'content'             => 'waiting',
            'entities_id'         => 0,
            '_users_id_requester' => $this->requester,
        ]);

        (new Group_Ticket())->add([
            'tickets_id' => $tickets_id,
            'groups_id'  => $this->team_group,
            'type'       => CommonITILActor::ASSIGN,
        ]);

        $ticket->update(['id' => $tickets_id, 'status' => Ticket::WAITING]);

        return $tickets_id;
    }

    private function addMessage(int $tickets_id, int $users_id, string $when, bool $private = false): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $followup = new ITILFollowup();
        $id = (int) $followup->add([
            'itemtype'               => 'Ticket',
            'items_id'               => $tickets_id,
            'content'                => 'message from ' . $users_id,
            'users_id'               => $users_id,
            'is_private'             => $private ? 1 : 0,
            '_no_reopen'             => true,
            '_do_not_compute_status' => true,
        ]);
        self::assertGreaterThan(0, $id);

        $DB->update(ITILFollowup::getTable(), ['date' => $when], ['id' => $id]);
    }

    /** Move the ticket's whole status history back, so the clock is not restarted by setUp. */
    private function backdateStatusHistory(int $tickets_id, string $when): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(Log::getTable(), ['date_mod' => $when], ['itemtype' => 'Ticket', 'items_id' => $tickets_id]);
    }

    private function countGenerated(int $tickets_id): int
    {
        return countElementsInTable(ITILFollowup::getTable(), [
            'itemtype' => 'Ticket',
            'items_id' => $tickets_id,
            ['content' => ['LIKE', '%' . AddFollowupAction::MARKER . '%']],
        ]);
    }

    public function testFiresWhenTheTeamHadTheLastWordAndNobodyCameBack(): void
    {
        $tickets_id = $this->createPendingTicket();
        $long_ago = date('Y-m-d H:i:s', strtotime('-30 days'));

        $this->addMessage($tickets_id, $this->requester, date('Y-m-d H:i:s', strtotime('-40 days')));
        $this->addMessage($tickets_id, $this->team_member, $long_ago);
        $this->backdateStatusHistory($tickets_id, $long_ago);

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(1, $report->executed, implode(' | ', $report->errors));
        self::assertSame(1, $this->countGenerated($tickets_id));

        $second = (new RuleEngine())->runRule($rule->toDefinition());
        self::assertSame(0, $second->executed);
    }

    /**
     * The requester answered last: the ball is back with the team, so the rule must stay
     * silent no matter how old the conversation is.
     */
    public function testStaysSilentWhenSomebodyElseHadTheLastWord(): void
    {
        $tickets_id = $this->createPendingTicket();
        $long_ago = date('Y-m-d H:i:s', strtotime('-30 days'));

        $this->addMessage($tickets_id, $this->team_member, date('Y-m-d H:i:s', strtotime('-40 days')));
        $this->addMessage($tickets_id, $this->requester, $long_ago);
        $this->backdateStatusHistory($tickets_id, $long_ago);

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(0, $report->executed);
        self::assertSame(0, $this->countGenerated($tickets_id));
    }

    /**
     * A private note is invisible to the requester, so it cannot be the message that put
     * the ball in their court.
     */
    public function testAPrivateNoteIsNotTheLastMessage(): void
    {
        $tickets_id = $this->createPendingTicket();
        $long_ago = date('Y-m-d H:i:s', strtotime('-30 days'));

        $this->addMessage($tickets_id, $this->team_member, date('Y-m-d H:i:s', strtotime('-40 days')));
        $this->addMessage($tickets_id, $this->requester, $long_ago);
        $this->addMessage($tickets_id, $this->team_member, date('Y-m-d H:i:s', strtotime('-29 days')), private: true);
        $this->backdateStatusHistory($tickets_id, $long_ago);

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(0, $report->executed, 'a private note must not hand the wait back to the requester');
    }

    /**
     * A status change after the message restarts the countdown — read from GLPI's own
     * history, which is the only place that records it for every status.
     */
    public function testAStatusChangeRestartsTheCountdown(): void
    {
        $tickets_id = $this->createPendingTicket();

        $this->addMessage($tickets_id, $this->team_member, date('Y-m-d H:i:s', strtotime('-30 days')));
        // The status history is left at "now", as if the ticket had just moved into pending.
        $this->backdateStatusHistory($tickets_id, date('Y-m-d H:i:s'));

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(0, $report->executed, 'the status change restarted the clock');
        self::assertSame(0, $this->countGenerated($tickets_id));
    }

    public function testATicketWithNoConversationIsNeverTimed(): void
    {
        $tickets_id = $this->createPendingTicket();
        $this->backdateStatusHistory($tickets_id, date('Y-m-d H:i:s', strtotime('-30 days')));

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(0, $report->executed);
    }

    /**
     * The message must come from the *rule's* group, not merely from an assigned one.
     */
    public function testAMessageFromAnotherAssignedGroupDoesNotStartTheClock(): void
    {
        $tickets_id = $this->createPendingTicket();
        $long_ago = date('Y-m-d H:i:s', strtotime('-30 days'));

        $other_group = (int) (new Group())->add([
            'name'        => 'TicketFlow other team ' . uniqid(),
            'entities_id' => 0,
            'is_assign'   => 1,
        ]);
        $other_member = (int) (new User())->add([
            'name'        => 'ticketflow_other_' . uniqid(),
            'entities_id' => 0,
        ]);
        (new Group_User())->add(['users_id' => $other_member, 'groups_id' => $other_group]);
        (new Group_Ticket())->add([
            'tickets_id' => $tickets_id,
            'groups_id'  => $other_group,
            'type'       => CommonITILActor::ASSIGN,
        ]);

        $this->addMessage($tickets_id, $other_member, $long_ago);
        $this->backdateStatusHistory($tickets_id, $long_ago);

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(0, $report->executed);
    }
}
