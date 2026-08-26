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
use CommonITILValidation;
use Group;
use Group_Ticket;
use GlpiPlugin\Ticketflow\Config;
use GlpiPlugin\Ticketflow\Engine\Action\AddFollowupAction;
use GlpiPlugin\Ticketflow\Engine\RuleEngine;
use GlpiPlugin\Ticketflow\Enum\ActionType;
use GlpiPlugin\Ticketflow\Rule;
use GlpiPlugin\Ticketflow\RuleAction;
use GlpiPlugin\Ticketflow\RuleGroup;
use ITILFollowup;
use PHPUnit\Framework\TestCase;
use Session;
use Ticket;
use TicketValidation;
use User;

/**
 * Acceptance scenario C: an approval left unanswered for two business days.
 */
final class PendingApprovalFlowTest extends TestCase
{
    private int $groups_id = 0;
    private int $rules_id = 0;
    private int $approver_id = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        Session::changeActiveEntities(0, true);

        $this->groups_id = (int) (new Group())->add([
            'name'        => 'TicketFlow approval group ' . uniqid(),
            'entities_id' => 0,
            'is_assign'   => 1,
        ]);

        $this->approver_id = (int) (new User())->add([
            'name'        => 'ticketflow_approver_' . uniqid(),
            'entities_id' => 0,
        ]);

        $rule = new Rule();
        $this->rules_id = (int) $rule->add([
            'name'          => 'TicketFlow approval rule',
            'entities_id'   => 0,
            'is_recursive'  => 1,
            'rule_type'     => 'pending_approval',
            'target_status' => 0,
            'delay_value'   => 2,
            'delay_unit'    => 'calendar_days',
            'calendar_mode' => 'none',
        ]);
        $rule->update(['id' => $this->rules_id, 'is_active' => 1]);

        RuleGroup::setGroupsForRule($this->rules_id, [$this->groups_id]);
        RuleAction::setActionsForRule($this->rules_id, [
            'add_followup' => [
                'enabled' => 1,
                'content' => 'No approval decision within {{delay}} {{delay_unit}} (due {{deadline}}).',
            ],
            'final' => ['type' => ActionType::ChangeStatus->value, 'status' => Ticket::ASSIGNED],
        ]);

        Config::set([
            'execution_enabled' => 1,
            'dry_run_global'    => 0,
            'system_users_id'   => $this->approver_id,
        ]);
    }

    protected function tearDown(): void
    {
        Config::set(['execution_enabled' => 0, 'dry_run_global' => 1, 'system_users_id' => 0]);
        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: int} tickets_id, validations_id
     */
    private function createTicketWithWaitingApproval(string $submitted_at): array
    {
        $ticket = new Ticket();
        $tickets_id = (int) $ticket->add([
            'name'        => 'TicketFlow approval ticket',
            'content'     => 'needs approval',
            'entities_id' => 0,
        ]);

        (new Group_Ticket())->add([
            'tickets_id' => $tickets_id,
            'groups_id'  => $this->groups_id,
            'type'       => CommonITILActor::ASSIGN,
        ]);

        $validation = new TicketValidation();
        $validations_id = (int) $validation->add([
            'tickets_id'      => $tickets_id,
            'entities_id'     => 0,
            'itemtype_target' => User::class,
            'items_id_target' => $this->approver_id,
            'comment_submission' => 'please approve',
        ]);
        self::assertGreaterThan(0, $validations_id);

        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(
            TicketValidation::getTable(),
            ['submission_date' => $submitted_at, 'status' => CommonITILValidation::WAITING],
            ['id' => $validations_id],
        );

        // A real ticket whose approval went out ten days ago also changed status ten days
        // ago. Leaving the history at "now" would make the status-change reset fire on
        // every run, which is correct behaviour on a scenario that cannot occur.
        $this->backdateStatusHistory($tickets_id, $submitted_at);

        return [$tickets_id, $validations_id];
    }

    /**
     * Move this ticket's status history back in time, so the clock is not restarted by the
     * status change the test itself just caused.
     */
    private function backdateStatusHistory(int $tickets_id, string $when): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(\Log::getTable(), ['date_mod' => $when], [
            'itemtype' => 'Ticket',
            'items_id' => $tickets_id,
        ]);
    }

    private function countTicketFlowFollowups(int $tickets_id): int
    {
        return countElementsInTable(ITILFollowup::getTable(), [
            'itemtype' => 'Ticket',
            'items_id' => $tickets_id,
            ['content' => ['LIKE', '%' . AddFollowupAction::MARKER . '%']],
        ]);
    }

    public function testUnansweredApprovalIsProcessedOnce(): void
    {
        [$tickets_id] = $this->createTicketWithWaitingApproval(date('Y-m-d H:i:s', strtotime('-10 days')));

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);

        $report = (new RuleEngine())->runRule($rule->toDefinition());
        self::assertSame(1, $report->executed, implode(' | ', $report->errors));
        self::assertSame(1, $this->countTicketFlowFollowups($tickets_id));

        $second = (new RuleEngine())->runRule($rule->toDefinition());
        self::assertSame(0, $second->executed);
        self::assertSame(1, $this->countTicketFlowFollowups($tickets_id));
    }

    public function testAnAnsweredApprovalIsLeftAlone(): void
    {
        [$tickets_id, $validations_id] = $this->createTicketWithWaitingApproval(date('Y-m-d H:i:s', strtotime('-10 days')));

        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(
            TicketValidation::getTable(),
            ['status' => CommonITILValidation::ACCEPTED, 'validation_date' => date('Y-m-d H:i:s')],
            ['id' => $validations_id],
        );

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(0, $report->executed);
        self::assertSame(0, $this->countTicketFlowFollowups($tickets_id));
    }

    public function testAFreshApprovalIsNotTouched(): void
    {
        [$tickets_id] = $this->createTicketWithWaitingApproval(date('Y-m-d H:i:s', strtotime('-1 hour')));

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(0, $report->executed);
        self::assertSame(0, $this->countTicketFlowFollowups($tickets_id));
    }

    /**
     * Several approvers on one ticket each get their own clock and their own occurrence.
     */
    public function testTwoWaitingApprovalsOnTheSameTicketAreIndependent(): void
    {
        [$tickets_id] = $this->createTicketWithWaitingApproval(date('Y-m-d H:i:s', strtotime('-10 days')));

        $second_approver = (int) (new User())->add([
            'name'        => 'ticketflow_approver2_' . uniqid(),
            'entities_id' => 0,
        ]);

        $validation = new TicketValidation();
        $second_id = (int) $validation->add([
            'tickets_id'         => $tickets_id,
            'entities_id'        => 0,
            'itemtype_target'    => User::class,
            'items_id_target'    => $second_approver,
            'comment_submission' => 'please approve too',
        ]);

        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(
            TicketValidation::getTable(),
            ['submission_date' => date('Y-m-d H:i:s', strtotime('-10 days')), 'status' => CommonITILValidation::WAITING],
            ['id' => $second_id],
        );
        $this->backdateStatusHistory($tickets_id, date('Y-m-d H:i:s', strtotime('-10 days')));

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(2, $report->analyzed, 'both approval requests are candidates');
        self::assertSame(2, $report->executed, 'each request has its own clock and its own occurrence');
        self::assertSame(2, $this->countTicketFlowFollowups($tickets_id));
    }

    /**
     * An approval submitted just now must not be dragged along by an older, overdue one on
     * the same ticket — the candidate query filters on each request's own submission date.
     */
    public function testAFreshApprovalOnATicketWithAnOverdueOneIsNotTouched(): void
    {
        [$tickets_id] = $this->createTicketWithWaitingApproval(date('Y-m-d H:i:s', strtotime('-10 days')));

        $second_approver = (int) (new User())->add([
            'name'        => 'ticketflow_approver3_' . uniqid(),
            'entities_id' => 0,
        ]);

        $validation = new TicketValidation();
        $second_id = (int) $validation->add([
            'tickets_id'         => $tickets_id,
            'entities_id'        => 0,
            'itemtype_target'    => User::class,
            'items_id_target'    => $second_approver,
            'comment_submission' => 'please approve too',
        ]);

        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(
            TicketValidation::getTable(),
            ['submission_date' => date('Y-m-d H:i:s'), 'status' => CommonITILValidation::WAITING],
            ['id' => $second_id],
        );
        $this->backdateStatusHistory($tickets_id, date('Y-m-d H:i:s', strtotime('-10 days')));

        $rule = new Rule();
        $rule->getFromDB($this->rules_id);
        $report = (new RuleEngine())->runRule($rule->toDefinition());

        self::assertSame(1, $report->analyzed, 'the fresh request is filtered out before any work is done');
        self::assertSame(1, $report->executed);
        self::assertSame(1, $this->countTicketFlowFollowups($tickets_id));
    }
}
