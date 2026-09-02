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
use GlpiPlugin\Ticketclock\EntityConfig;
use GlpiPlugin\Ticketclock\Engine\RuleEngine;
use GlpiPlugin\Ticketclock\Rule;
use GlpiPlugin\Ticketclock\RuleAction;
use GlpiPlugin\Ticketclock\RuleGroup;
use ITILFollowup;
use PHPUnit\Framework\TestCase;
use Session;
use Ticket;

/**
 * A rule the engine cannot read in full must not run at all.
 *
 * Unreadable stored parameters used to decode to an empty array and the action ran anyway;
 * once that was fixed the action was dropped instead, and the rule carried on with whatever
 * survived. Both are wrong in the same way. A rule configured as "add a followup, then
 * close" silently becomes "add a followup", the execution is recorded as an ordinary run,
 * and the only trace is a line in the server log. That is a wrong outcome on every ticket
 * the rule touches, for as long as nobody audits by hand.
 *
 * Refusing costs one rule until somebody fixes the row. Continuing costs correctness, and
 * hides it.
 */
final class UnreadableRuleTest extends TestCase
{
    private int $groups_id = 0;
    private int $rules_id = 0;
    private int $healthy_rules_id = 0;
    private int $calendars_id = 0;
    private int $tickets_id = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        Session::changeActiveEntities(0, true);

        $suffix = uniqid();

        $this->calendars_id = (int) (new Calendar())->add([
            'name' => 'TicketFlow unreadable calendar ' . $suffix, 'entities_id' => 0, 'is_recursive' => 1,
        ]);
        for ($day = 1; $day <= 5; $day++) {
            (new CalendarSegment())->add([
                'calendars_id' => $this->calendars_id, 'entities_id' => 0,
                'day' => $day, 'begin' => '00:00:00', 'end' => '23:59:59',
            ]);
        }
        (new Calendar())->updateDurationCache($this->calendars_id);

        $this->groups_id = (int) (new Group())->add([
            'name' => 'TicketFlow unreadable group ' . $suffix,
            'entities_id' => 0, 'is_recursive' => 1, 'is_assign' => 1,
        ]);

        $this->rules_id        = $this->createRule('TicketFlow unreadable rule');
        $this->healthy_rules_id = $this->createRule('TicketFlow healthy rule');

        EntityConfig::setForEntity(0, ['execution_enabled' => 1, 'dry_run' => 0]);

        $ticket = new Ticket();
        $this->tickets_id = (int) $ticket->add([
            'name' => 'TicketFlow unreadable ticket', 'content' => 'waiting',
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
        EntityConfig::setForEntity(0, ['execution_enabled' => 0, 'dry_run' => 1]);

        foreach ([[Ticket::class, $this->tickets_id], [Rule::class, $this->rules_id],
            [Rule::class, $this->healthy_rules_id], [Group::class, $this->groups_id],
            [Calendar::class, $this->calendars_id]] as [$class, $id]) {
            if ($id > 0) {
                (new $class())->delete(['id' => $id], true);
            }
        }

        parent::tearDown();
    }

    public function testARuleWithUnreadableParametersTouchesNothing(): void
    {
        $this->corruptTheActionOf($this->rules_id);

        $report = (new RuleEngine())->runRule($this->rule($this->rules_id));

        self::assertSame(0, $report->analyzed, 'the rule was evaluated despite being unreadable');
        self::assertSame(0, $this->followupsOnTicket(), 'the rule acted on a ticket it should have refused');
    }

    public function testTheRefusalSaysWhichRuleAndWhy(): void
    {
        $this->corruptTheActionOf($this->rules_id);

        $report = (new RuleEngine())->runRule($this->rule($this->rules_id));
        $errors = implode(' | ', $report->errors);

        self::assertNotSame('', $errors, 'the rule was refused without saying so anywhere');
        self::assertStringContainsString('TicketFlow unreadable rule', $errors, 'the message must name the rule');
        self::assertStringContainsString('unreadable', $errors, 'the message must say what was wrong');
    }

    /**
     * An unknown action type is the same defect wearing different clothes: an action the
     * administrator configured that this code will not carry out.
     */
    public function testAnUnknownActionTypeIsRefusedToo(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(RuleAction::getTable(), ['action_type' => 'summon_a_technician'], [
            'plugin_ticketclock_rules_id' => $this->rules_id,
        ]);

        $report = (new RuleEngine())->runRule($this->rule($this->rules_id));

        self::assertSame(0, $this->followupsOnTicket());
        self::assertStringContainsString('summon_a_technician', implode(' | ', $report->errors));
    }

    /**
     * The refusal has to be local. One corrupt row must not become an outage for every other
     * rule in the same cron pass.
     */
    public function testOtherRulesInTheSamePassStillRun(): void
    {
        $this->corruptTheActionOf($this->rules_id);

        $report = (new RuleEngine())->runAll();

        self::assertGreaterThan(0, $report->executed, 'a healthy rule was stopped by an unrelated corrupt one');
        self::assertNotSame([], $report->errors, 'the corrupt rule was skipped without a word');
    }

    /**
     * And the way out has to stay open. The rule form reads the same rows, and a corrupt row
     * is exactly what somebody needs the form for.
     */
    public function testTheRuleFormStillOpensOnACorruptRule(): void
    {
        $this->corruptTheActionOf($this->rules_id);

        $values = RuleAction::getFormValues($this->rules_id);

        self::assertArrayHasKey('add_followup', $values);
        self::assertArrayHasKey('final', $values);
    }

    private function createRule(string $name): int
    {
        $rule = new Rule();
        $rules_id = (int) $rule->add([
            'name' => $name, 'entities_id' => 0, 'is_recursive' => 1,
            'rule_type' => 'pending_inactivity', 'target_status' => Ticket::WAITING,
            'delay_value' => 1, 'delay_unit' => 'business_days',
            'calendar_mode' => 'specific', 'calendars_id' => $this->calendars_id,
            'reset_events' => 'requester_followup',
        ]);
        $rule->update(['id' => $rules_id, 'is_active' => 1]);
        RuleGroup::setGroupsForRule($rules_id, [$this->groups_id]);
        RuleAction::setActionsForRule($rules_id, [
            'add_followup' => ['enabled' => 1, 'content' => 'Please answer.'],
        ]);

        return $rules_id;
    }

    /**
     * Written straight to the column: there is no supported way to store this, which is the
     * point. It models a botched migration, a restored backup, or somebody with database
     * access being helpful.
     */
    private function corruptTheActionOf(int $rules_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(RuleAction::getTable(), ['params' => '{"content": "unterminated'], [
            'plugin_ticketclock_rules_id' => $rules_id,
        ]);
    }

    private function rule(int $rules_id): \GlpiPlugin\Ticketclock\Engine\RuleDefinition
    {
        $rule = new Rule();
        self::assertTrue($rule->getFromDB($rules_id));

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
     * The half of #8 that stopping the run does not answer.
     *
     * A refusal happens before any ticket is chosen, so there is no execution row to carry
     * the reason, and inventing one would put a record with no ticket into a log that is
     * otherwise strictly one row per ticket. The problem belongs to the rule, so it is kept
     * on the rule -- which is also where somebody would look.
     */
    public function testTheReasonIsKeptOnTheRuleAndNotOnlyInTheLog(): void
    {
        $this->corruptTheActionOf($this->rules_id);

        (new RuleEngine())->runRule($this->rule($this->rules_id));

        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));
        self::assertNotEmpty($rule->fields['last_error'], 'the reason survived only in the run report');
        self::assertNotEmpty($rule->fields['last_error_date'], 'no timestamp to tell when it started');
        self::assertStringContainsString('unreadable', (string) $rule->fields['last_error']);
    }

    /**
     * Counted apart from `failed`, which counts tickets whose actions were attempted and did
     * not work. A refused rule attempted nothing.
     */
    public function testTheRunReportCountsTheRefusalSeparately(): void
    {
        $this->corruptTheActionOf($this->rules_id);

        $report = (new RuleEngine())->runRule($this->rule($this->rules_id));

        self::assertSame(1, $report->refused);
        self::assertSame(0, $report->failed, 'a refusal is not a failed ticket');
    }

    /**
     * A stale error is worse than none: it sends somebody looking for a problem that is gone.
     */
    public function testTheReasonIsClearedOnceTheRuleRunsAgain(): void
    {
        $this->corruptTheActionOf($this->rules_id);
        (new RuleEngine())->runRule($this->rule($this->rules_id));

        // What an administrator does: reopen the rule and save it, which rewrites the actions.
        RuleAction::setActionsForRule($this->rules_id, [
            'add_followup' => ['enabled' => 1, 'content' => 'Please answer.'],
        ]);
        (new RuleEngine())->runRule($this->rule($this->rules_id));

        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));
        self::assertEmpty($rule->fields['last_error'], 'the rule still claims to be broken after it ran');
        self::assertEmpty($rule->fields['last_error_date']);
    }

    /**
     * And it has to be on the screen, not just in the column. Somebody opening a rule that
     * stopped working should not have to search the log to find out why.
     */
    public function testTheRuleFormShowsWhyItIsNotRunning(): void
    {
        $this->corruptTheActionOf($this->rules_id);
        (new RuleEngine())->runRule($this->rule($this->rules_id));

        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));

        ob_start();
        try {
            $rule->showForm($this->rules_id);
        } catch (\Throwable $e) {
            ob_end_clean();
            self::fail('showForm() threw: ' . $e->getMessage());
        }
        $html = (string) ob_get_clean();

        self::assertStringContainsString('not running', $html, 'the form said nothing about the refusal');
        self::assertStringContainsString('unreadable', $html, 'the form did not say what was wrong');
    }
    /**
     * A preview must change nothing, and "nothing" has to include the rule's own bookkeeping.
     *
     * The simulation screen is reached with READ on the rule. If a dry run recorded the
     * refusal, a read-only operator could stamp an error onto a rule they may only look at,
     * including one inherited from a parent entity they do not administer.
     */
    public function testADryRunDoesNotRecordTheRefusal(): void
    {
        $this->corruptTheActionOf($this->rules_id);

        $report = (new RuleEngine())->runRule($this->rule($this->rules_id), force_dry_run: true);

        self::assertSame(1, $report->refused, 'the simulation must still say the rule is unusable');
        self::assertNotSame([], $report->errors, 'and still say why');

        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));
        self::assertEmpty($rule->fields['last_error'], 'a preview wrote to the rule');
    }

    /**
     * The worse direction of the same mistake. Clearing is destructive: a preview that wiped
     * the record would erase the evidence of why a rule stopped, and could be triggered by
     * somebody with nothing but READ.
     */
    public function testADryRunDoesNotClearAnExistingRefusal(): void
    {
        $this->corruptTheActionOf($this->rules_id);
        (new RuleEngine())->runRule($this->rule($this->rules_id));

        // Repaired, so a real run would legitimately clear the record.
        RuleAction::setActionsForRule($this->rules_id, [
            'add_followup' => ['enabled' => 1, 'content' => 'Please answer.'],
        ]);

        (new RuleEngine())->runRule($this->rule($this->rules_id), force_dry_run: true);

        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));
        self::assertNotEmpty($rule->fields['last_error'], 'a preview erased a recorded refusal');
    }

    /**
     * The same restraint applies without anybody at a screen: a rule left in simulation, or
     * an instance still under the global dry run, has asked for a run that changes nothing.
     */
    public function testARuleInSimulationNeverWritesItsOwnError(): void
    {
        $this->corruptTheActionOf($this->rules_id);

        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));
        $rule->update(['id' => $this->rules_id, 'is_dry_run' => 1]);

        (new RuleEngine())->runRule($this->rule($this->rules_id));

        $reloaded = new Rule();
        self::assertTrue($reloaded->getFromDB($this->rules_id));
        self::assertEmpty($reloaded->fields['last_error'], 'a rule in simulation wrote to itself');
    }
}
