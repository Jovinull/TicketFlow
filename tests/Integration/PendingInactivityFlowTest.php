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
use ITILFollowup;
use ITILSolution;
use GlpiPlugin\Ticketclock\Config;
use GlpiPlugin\Ticketclock\Engine\Action\AddFollowupAction;
use GlpiPlugin\Ticketclock\Engine\RuleEngine;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use GlpiPlugin\Ticketclock\Enum\ExecutionState;
use GlpiPlugin\Ticketclock\EntityConfig;
use GlpiPlugin\Ticketclock\Execution;
use GlpiPlugin\Ticketclock\Rule;
use GlpiPlugin\Ticketclock\RuleAction;
use GlpiPlugin\Ticketclock\RuleGroup;
use PHPUnit\Framework\TestCase;
use Session;
use Ticket;
use User;

/**
 * Acceptance scenarios A, B and F end to end against a real GLPI.
 *
 * Everything is created by the test; no production data is touched.
 */
final class PendingInactivityFlowTest extends TestCase
{
    private int $groups_id = 0;
    private int $calendars_id = 0;
    private int $rules_id = 0;
    private int $requester_id = 0;
    private string $marks_before = '';

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        Session::changeActiveEntities(0, true);

        $this->groups_id = (int) (new Group())->add([
            'name'        => 'TicketFlow test group ' . uniqid(),
            'entities_id' => 0,
            'is_assign'   => 1,
        ]);

        $this->calendars_id = (int) (new Calendar())->add([
            'name'         => 'TicketFlow test calendar ' . uniqid(),
            'entities_id'  => 0,
            'is_recursive' => 1,
        ]);
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

        $this->requester_id = (int) (new User())->add([
            'name'         => 'ticketclock_requester_' . uniqid(),
            'entities_id'  => 0,
            '_profiles_id' => 0,
        ]);

        $rule = new Rule();
        $this->rules_id = (int) $rule->add([
            'name'          => 'TicketFlow test rule',
            'entities_id'   => 0,
            'is_recursive'  => 1,
            'rule_type'     => 'pending_inactivity',
            'target_status' => Ticket::WAITING,
            'delay_value'   => 5,
            'delay_unit'    => 'business_days',
            'calendar_mode' => 'specific',
            'calendars_id'  => $this->calendars_id,
            'reset_events'  => 'requester_followup',
        ]);
        // Rules are always created inactive; arm it explicitly, as an administrator would.
        $rule->update(['id' => $this->rules_id, 'is_active' => 1]);

        RuleGroup::setGroupsForRule($this->rules_id, [$this->groups_id]);
        RuleAction::setActionsForRule($this->rules_id, [
            'add_followup' => ['enabled' => 1, 'content' => 'Closing ticket {{ticket.id}} — no answer by {{deadline}}.'],
            'final'        => ['type' => ActionType::AddSolution->value, 'content' => 'Automatically solved by TicketFlow.'],
        ]);

        $this->marks_before = Config::get('ignored_message_marks');

        EntityConfig::setForEntity(0, ['execution_enabled' => 1, 'dry_run' => 0]);
        Config::set(['system_users_id' => $this->requester_id, 'ignored_message_marks' => Config::DEFAULT_IGNORED_MARKS]);
        Config::reload();
    }

    protected function tearDown(): void
    {
        EntityConfig::setForEntity(0, ['execution_enabled' => 0, 'dry_run' => 1]);
        Config::set(['system_users_id' => 0, 'ignored_message_marks' => $this->marks_before]);
        Config::reload();
        parent::tearDown();
    }

    private function createPendingTicket(string $began_at): int
    {
        $ticket = new Ticket();
        $tickets_id = (int) $ticket->add([
            'name'        => 'TicketFlow integration ticket',
            'content'     => 'waiting for the requester',
            'entities_id' => 0,
            'status'      => Ticket::INCOMING,
            '_users_id_requester' => $this->requester_id,
        ]);
        self::assertGreaterThan(0, $tickets_id);

        (new Group_Ticket())->add([
            'tickets_id' => $tickets_id,
            'groups_id'  => $this->groups_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);

        $ticket->update(['id' => $tickets_id, 'status' => Ticket::WAITING]);

        // Backdate the pending start so the deadline is already behind us. Writing the
        // column directly is acceptable here: the test is simulating the passage of time,
        // not exercising a code path.
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(Ticket::getTable(), ['begin_waiting_date' => $began_at], ['id' => $tickets_id]);

        return $tickets_id;
    }

    private function countTicketFlowFollowups(int $tickets_id): int
    {
        return countElementsInTable(ITILFollowup::getTable(), [
            'itemtype' => 'Ticket',
            'items_id' => $tickets_id,
            ['content' => ['LIKE', '%' . AddFollowupAction::MARKER . '%']],
        ]);
    }

    /**
     * Scenario A: pending for more than five business days, nothing answered.
     */
    public function testExpiredTicketGetsAMessageAndIsSolvedExactlyOnce(): void
    {
        $tickets_id = $this->createPendingTicket(date('Y-m-d H:i:s', strtotime('-30 days')));

        $engine = new RuleEngine();
        $rule = new Rule();
        $rule->getFromDB($this->rules_id);

        $report = $engine->runRule($rule->toDefinition());

        self::assertSame(1, $report->executed, implode(' | ', $report->errors));
        self::assertSame(1, $this->countTicketFlowFollowups($tickets_id));

        $ticket = new Ticket();
        $ticket->getFromDB($tickets_id);
        self::assertContains((int) $ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED]);

        self::assertSame(1, countElementsInTable(ITILSolution::getTable(), [
            'itemtype' => 'Ticket',
            'items_id' => $tickets_id,
        ]));

        // Scenario A, second half: running the cron again must change nothing.
        $second = $engine->runRule($rule->toDefinition());

        self::assertSame(0, $second->executed);
        self::assertSame(1, $this->countTicketFlowFollowups($tickets_id));
        self::assertSame(1, countElementsInTable(ITILSolution::getTable(), [
            'itemtype' => 'Ticket',
            'items_id' => $tickets_id,
        ]));
    }

    public function testTheExecutionIsAudited(): void
    {
        $tickets_id = $this->createPendingTicket(date('Y-m-d H:i:s', strtotime('-30 days')));

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        (new RuleEngine())->runRule($rule->toDefinition());

        $execution = new Execution();
        self::assertTrue($execution->getFromDBByCrit([
            'plugin_ticketclock_rules_id' => $this->rules_id,
            'tickets_id'                 => $tickets_id,
        ]));

        self::assertSame(ExecutionState::Executed->value, $execution->fields['state']);
        self::assertNotEmpty($execution->fields['reference_date']);
        self::assertNotEmpty($execution->fields['deadline_date']);
        self::assertSame($this->calendars_id, (int) $execution->fields['calendars_id']);
        self::assertCount(2, $execution->getDecodedResults());
    }

    /**
     * Scenario B: the requester answers inside the window.
     */
    public function testARequesterAnswerKeepsTheTicketOpen(): void
    {
        $tickets_id = $this->createPendingTicket(date('Y-m-d H:i:s', strtotime('-30 days')));

        (new ITILFollowup())->add([
            'itemtype'               => 'Ticket',
            'items_id'               => $tickets_id,
            'content'                => 'Still need help, sorry for the delay.',
            'users_id'               => $this->requester_id,
            '_no_reopen'             => true,
            '_do_not_compute_status' => true,
        ]);

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(0, $report->executed);
        self::assertSame(0, $this->countTicketFlowFollowups($tickets_id));

        $ticket = new Ticket();
        $ticket->getFromDB($tickets_id);
        self::assertSame(Ticket::WAITING, (int) $ticket->fields['status']);
    }

    /**
     * Scenario F: a dry run must leave the database exactly as it found it.
     */
    public function testDryRunChangesNothing(): void
    {
        $tickets_id = $this->createPendingTicket(date('Y-m-d H:i:s', strtotime('-30 days')));

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition(), null, true, preview_limit: 10);

        self::assertSame(1, $report->simulated);
        self::assertSame(0, $report->executed);
        self::assertCount(1, $report->preview);

        self::assertSame(0, $this->countTicketFlowFollowups($tickets_id));
        self::assertSame(0, countElementsInTable(ITILSolution::getTable(), [
            'itemtype' => 'Ticket',
            'items_id' => $tickets_id,
        ]));

        $ticket = new Ticket();
        $ticket->getFromDB($tickets_id);
        self::assertSame(Ticket::WAITING, (int) $ticket->fields['status']);

        // A simulated occurrence must not block the real execution that follows it.
        $real = (new RuleEngine())->runRule($rule->toDefinition());
        self::assertSame(1, $real->executed);

        // And the scheduled path collects no preview at all: nothing reads it there, and
        // building a row per examined ticket made memory grow with the size of the run.
        $scheduled = (new RuleEngine())->runRule($rule->toDefinition(), null, true);
        self::assertSame([], $scheduled->preview, 'a run that was not asked for a preview must not build one');
        self::assertSame(0, $scheduled->preview_omitted, 'and must not count rows it never considered keeping');
    }

    /**
     * A ticket still inside its window must be left alone.
     */
    public function testTicketInsideTheWindowIsUntouched(): void
    {
        $tickets_id = $this->createPendingTicket(date('Y-m-d H:i:s', strtotime('-1 day')));

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(0, $report->executed);
        self::assertSame(0, $this->countTicketFlowFollowups($tickets_id));
    }

    /**
     * The plugin's own followup must not be read back as activity.
     */
    public function testGeneratedFollowupsDoNotResetTheClock(): void
    {
        $tickets_id = $this->createPendingTicket(date('Y-m-d H:i:s', strtotime('-30 days')));

        (new ITILFollowup())->add([
            'itemtype'               => 'Ticket',
            'items_id'               => $tickets_id,
            'content'                => AddFollowupAction::MARKER . "\nAutomated reminder.",
            'users_id'               => $this->requester_id,
            '_no_reopen'             => true,
            '_do_not_compute_status' => true,
        ]);

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(1, $report->executed, 'a TicketFlow-generated followup must not count as an answer');
    }

    public function testARuleTargetingAnotherGroupDoesNothing(): void
    {
        $tickets_id = $this->createPendingTicket(date('Y-m-d H:i:s', strtotime('-30 days')));

        $other_group = (int) (new Group())->add([
            'name'        => 'TicketFlow other group ' . uniqid(),
            'entities_id' => 0,
            'is_assign'   => 1,
        ]);
        RuleGroup::setGroupsForRule($this->rules_id, [$other_group]);

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(0, $report->executed);
        self::assertSame(0, $this->countTicketFlowFollowups($tickets_id));
    }
    /**
     * An out of office reply must not count as the requester answering.
     *
     * It reaches GLPI through the mail collector as an ordinary public followup signed by
     * the requester, which is exactly what the engine looks for when it decides who holds
     * the ticket. Before this was filtered, such a reply silenced the rule for good: the
     * clock stopped, the ticket was never chased and never solved, and nothing was written
     * anywhere, so the only way to notice was to audit old tickets by hand.
     */
    public function testAnAutomaticReplyDoesNotStopTheClock(): void
    {
        $tickets_id = $this->createPendingTicket(date('Y-m-d H:i:s', strtotime('-30 days')));

        // Signed by the requester, public, arriving after the ticket went pending.
        (new ITILFollowup())->add([
            'itemtype' => Ticket::class,
            'items_id' => $tickets_id,
            'content'  => 'Automatic reply: I am out of the office until Monday.',
            'users_id' => $this->requester_id,
            'is_private' => 0,
        ]);

        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(1, $report->executed, implode(' | ', $report->errors));
    }

    /**
     * The other direction, which is what stops the filter from being a blunt instrument:
     * a real answer from the requester still stops the clock.
     */
    public function testAGenuineAnswerStillStopsTheClock(): void
    {
        $tickets_id = $this->createPendingTicket(date('Y-m-d H:i:s', strtotime('-30 days')));

        (new ITILFollowup())->add([
            'itemtype' => Ticket::class,
            'items_id' => $tickets_id,
            'content'  => 'Here is the information you asked for.',
            'users_id' => $this->requester_id,
            'is_private' => 0,
        ]);

        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(0, $report->executed, 'a real answer must still stop the clock');
    }
}
