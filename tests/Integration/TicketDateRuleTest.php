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

use CommonITILActor;
use Entity;
use GlpiPlugin\Ticketclock\Engine\RuleEngine;
use GlpiPlugin\Ticketclock\Engine\TicketContextResolver;
use GlpiPlugin\Ticketclock\EntityConfig;
use GlpiPlugin\Ticketclock\Enum\ReferenceField;
use GlpiPlugin\Ticketclock\Execution;
use GlpiPlugin\Ticketclock\Rule;
use GlpiPlugin\Ticketclock\RuleAction;
use GlpiPlugin\Ticketclock\RuleGroup;
use Group;
use Group_Ticket;
use ITILFollowup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Session;
use Ticket;

use function countElementsInTable;

/**
 * Counting from a date somebody put on the ticket.
 *
 * The third reference source. The arithmetic, the calendar and the actions are unchanged --
 * what moves is where the clock starts, so most of what these tests hold is the plumbing
 * around that: the prefilter and the matcher reading the same column, an empty field being
 * reported rather than guessed, and a column nobody offered never reaching a query.
 *
 * The field list is curated and fixed rather than discovered from the schema. `glpi_tickets`
 * carries thirteen date columns and most are machinery -- `date_mod` moves whenever anybody
 * edits the ticket, including when this plugin writes a followup, so a rule timed from it
 * would push its own deadline away every time it acted.
 */
final class TicketDateRuleTest extends TestCase
{
    private string $suffix = '';
    private int $groups_id = 0;
    private int $rules_id = 0;
    private int $tickets_id = 0;
    private int $child_entity = 0;
    private int $child_ticket = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
        Session::changeActiveEntities(0, true);

        $this->suffix = uniqid();

        $this->groups_id = (int) (new Group())->add([
            'name' => 'TicketFlow date group ' . $this->suffix,
            'entities_id' => 0, 'is_recursive' => 1, 'is_assign' => 1,
        ]);

        $this->child_entity = (int) (new Entity())->add([
            'name' => 'TicketFlow date child ' . $this->suffix, 'entities_id' => 0,
        ]);

        $this->rules_id   = $this->createRule(ReferenceField::TimeToResolve);
        $this->tickets_id = $this->createTicket(0);
        $this->child_ticket = $this->createTicket($this->child_entity);

        EntityConfig::setForEntity(0, ['execution_enabled' => 1, 'dry_run' => 0]);
    }

    protected function tearDown(): void
    {
        EntityConfig::setForEntity(0, EntityConfig::ROOT_DEFAULTS);

        /** @var \DBmysql $DB */
        global $DB;
        foreach ([$this->tickets_id, $this->child_ticket] as $tickets_id) {
            if ($tickets_id > 0) {
                $DB->delete(ITILFollowup::getTable(), ['itemtype' => Ticket::class, 'items_id' => $tickets_id]);
                (new Ticket())->delete(['id' => $tickets_id], true);
            }
        }
        foreach ([[Rule::class, $this->rules_id], [Group::class, $this->groups_id],
            [Entity::class, $this->child_entity]] as [$class, $id]) {
            if ($id > 0) {
                (new $class())->delete(['id' => $id], true);
            }
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------------------
    // The clock
    // -----------------------------------------------------------------------------

    public function testAReferenceInThePastPastTheDelayFires(): void
    {
        $this->setDate($this->tickets_id, ReferenceField::TimeToResolve, '-10 days');

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $report->executed, implode(' | ', $report->errors));
        self::assertSame(1, $this->followupsOn($this->tickets_id));
    }

    public function testAReferenceInTheFutureDoesNotFire(): void
    {
        $this->setDate($this->tickets_id, ReferenceField::TimeToResolve, '+10 days');

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(0, $report->executed);
        self::assertSame(0, $this->followupsOn($this->tickets_id));
    }

    /**
     * The delay is added to the date, not ignored. A rule counting from an SLA target acts
     * after the target, and a ticket one day past a five-day rule is not yet due.
     */
    public function testAReferenceInsideTheDelayDoesNotFireYet(): void
    {
        $this->setDate($this->tickets_id, ReferenceField::TimeToResolve, '-1 day');

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(0, $report->executed);
    }

    /**
     * The honesty rule the issue asks for: an empty field is not "now".
     *
     * Reported rather than guessed. Timing from a missing SLA target would act on every ticket
     * that never had one, which is every ticket on most instances.
     */
    public function testAnEmptyReferenceIsReportedRatherThanGuessed(): void
    {
        $this->setDate($this->tickets_id, ReferenceField::TimeToResolve, null);

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(0, $report->executed);
        self::assertSame(0, $this->followupsOn($this->tickets_id));
    }

    /** Every field on the list has to work, not only the one the other tests happen to use. */
    #[DataProvider('allowedFields')]
    public function testEveryAllowedFieldCanDriveARule(string $field): void
    {
        $reference = ReferenceField::from($field);
        (new Rule())->update(['id' => $this->rules_id, 'reference_field' => $reference->value]);
        $this->setDate($this->tickets_id, $reference, '-10 days');

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $report->executed, $field . ': ' . implode(' | ', $report->errors));
    }

    /** @return array<string, array{string}> */
    public static function allowedFields(): array
    {
        $out = [];
        foreach (ReferenceField::cases() as $case) {
            $out[$case->value] = [$case->value];
        }

        return $out;
    }

    // -----------------------------------------------------------------------------
    // The whitelist
    // -----------------------------------------------------------------------------

    /**
     * `date_mod` is the field this list exists to keep out. It is a real, dated column of
     * `glpi_tickets`, so nothing but the whitelist stands between it and a rule.
     */
    public function testAColumnOutsideTheListIsRefusedWhenTheRuleIsSaved(): void
    {
        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));

        self::assertFalse($rule->update(['id' => $this->rules_id, 'reference_field' => 'date_mod']));

        $stored = new Rule();
        self::assertTrue($stored->getFromDB($this->rules_id));
        self::assertSame(ReferenceField::TimeToResolve->value, (string) $stored->fields['reference_field']);
    }

    public function testCountingFromATicketDateWithNoFieldChosenIsRefused(): void
    {
        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));

        self::assertFalse($rule->update(['id' => $this->rules_id, 'reference_field' => '']));
    }

    /**
     * The row is the other door. A restore, an import or a direct UPDATE never passes through
     * the form, so the engine refuses the rule when it reads it rather than trusting the value.
     */
    public function testAColumnOutsideTheListWrittenStraightToTheRowRefusesTheRule(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(Rule::getTable(), ['reference_field' => 'date_mod'], ['id' => $this->rules_id]);
        $this->setDate($this->tickets_id, ReferenceField::TimeToResolve, '-10 days');

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $report->refused);
        self::assertSame(0, $report->executed);
        self::assertStringContainsString('date_mod', implode(' | ', $report->errors));
        self::assertSame(0, $this->followupsOn($this->tickets_id));
    }

    // -----------------------------------------------------------------------------
    // Prefilter and matcher agreeing
    // -----------------------------------------------------------------------------

    /**
     * The prefilter and the matcher must read the same column.
     *
     * A ticket whose chosen field is overdue and whose *other* dates are not has to be found
     * and acted on; the reverse must not happen. If the prefilter read one column and the
     * arithmetic another, the run would silently skip due tickets while reporting that it had
     * examined them, which is the worst shape this kind of bug takes.
     */
    public function testThePrefilterFindsTheTicketByTheChosenFieldAlone(): void
    {
        (new Rule())->update(['id' => $this->rules_id, 'reference_field' => ReferenceField::TimeToOwn->value]);

        $this->setDate($this->tickets_id, ReferenceField::TimeToOwn, '-10 days');
        // Every other date is either empty or in the future, so only the chosen one can match.
        $this->setDate($this->tickets_id, ReferenceField::TimeToResolve, '+10 days');

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $report->analyzed, 'the prefilter did not select on the chosen column');
        self::assertSame(1, $report->executed);
    }

    public function testATicketOverdueOnAnotherFieldIsNotSelected(): void
    {
        (new Rule())->update(['id' => $this->rules_id, 'reference_field' => ReferenceField::TimeToOwn->value]);

        $this->setDate($this->tickets_id, ReferenceField::TimeToOwn, null);
        $this->setDate($this->tickets_id, ReferenceField::TimeToResolve, '-10 days');

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(0, $report->analyzed);
        self::assertSame(0, $report->executed);
    }

    // -----------------------------------------------------------------------------
    // What does not move this clock
    // -----------------------------------------------------------------------------

    /**
     * Reset events do not push a date that lives on the ticket.
     *
     * The decision, written down as a test because it is the one a future tidy-up would undo.
     * `pending_start` and `last_target_group_message` time something a reply genuinely
     * restarts; an SLA target is not restarted because somebody wrote a followup. If resets
     * moved this clock, a rule set to act after a target would never act on a busy ticket --
     * every message would push the deadline further away, quietly, forever.
     */
    public function testAResetEventAfterTheDateDoesNotPushTheClock(): void
    {
        // `any_followup` rather than a narrower event on purpose: it needs no actor to line up,
        // so if this test ever goes quiet it is because the behaviour changed and not because
        // the fixture stopped qualifying.
        (new Rule())->update([
            'id' => $this->rules_id,
            'reset_events' => 'any_followup',
        ]);
        $this->setDate($this->tickets_id, ReferenceField::TimeToResolve, '-10 days');

        // Written now, which is five days past the deadline this rule computes. On a
        // pending_start rule this would push the reference to today and nothing would fire.
        $followup = (int) (new ITILFollowup())->add([
            'itemtype' => Ticket::class,
            'items_id' => $this->tickets_id,
            'content'  => 'Any news?',
            'is_private' => 0,
        ]);
        self::assertGreaterThan(0, $followup);

        // Proves the fixture is doing its job: the reset event is visible to the engine, and
        // it is later than the reference. Without this the test would pass just as happily
        // against a followup the resolver never saw.
        $context = (new TicketContextResolver())->resolveOne($this->tickets_id);
        self::assertNotNull($context);
        self::assertNotNull(
            $context->latestResetDate($this->rule()->reset_events),
            'the fixture produced no reset event, so this test would prove nothing',
        );

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $report->executed, implode(' | ', $report->errors));
    }

    // -----------------------------------------------------------------------------
    // Rule types that do not implement it
    // -----------------------------------------------------------------------------

    /**
     * Approval rules are timed from the approval request, and only the inactivity matcher
     * implements this event. Accepting the combination would store a setting the engine then
     * ignores: the screen would say "time to resolve" while the rule timed the submission.
     */
    public function testAnApprovalRuleCannotCountFromATicketDate(): void
    {
        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));

        self::assertFalse($rule->update([
            'id' => $this->rules_id,
            'rule_type' => 'pending_approval',
        ]));
    }

    /** And the row is the other door, as everywhere else here. */
    public function testAnApprovalRuleWrittenStraightToTheRowIsRefused(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(Rule::getTable(), ['rule_type' => 'pending_approval'], ['id' => $this->rules_id]);
        $this->setDate($this->tickets_id, ReferenceField::TimeToResolve, '-10 days');

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $report->refused);
        self::assertSame(0, $report->executed);
        self::assertStringContainsString('only inactivity rules support', implode(' | ', $report->errors));
    }

    // -----------------------------------------------------------------------------
    // Occurrence
    // -----------------------------------------------------------------------------

    /**
     * Switching the field starts a new cycle even when the two columns hold the same instant.
     *
     * Without the field in the occurrence key the second configuration would inherit the claim
     * of the first, and the rule would look like it had already run for a cycle it never saw.
     */
    public function testChangingTheFieldProducesANewOccurrence(): void
    {
        $when = date('Y-m-d H:i:s', strtotime('-10 days'));
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(Ticket::getTable(), [
            'time_to_resolve' => $when,
            'time_to_own'     => $when,
        ], ['id' => $this->tickets_id]);

        self::assertSame(1, (new RuleEngine())->runRule($this->rule())->executed);

        (new Rule())->update(['id' => $this->rules_id, 'reference_field' => ReferenceField::TimeToOwn->value]);

        // Same ticket, same instant, different field: a second occurrence, not a replay of the
        // first one's claim.
        self::assertSame(1, (new RuleEngine())->runRule($this->rule())->executed);
        self::assertSame(2, countElementsInTable(Execution::getTable(), [
            'plugin_ticketclock_rules_id' => $this->rules_id,
        ]));
    }

    public function testTheSameFieldAndDateDoesNotFireTwice(): void
    {
        $this->setDate($this->tickets_id, ReferenceField::TimeToResolve, '-10 days');

        self::assertSame(1, (new RuleEngine())->runRule($this->rule())->executed);
        self::assertSame(0, (new RuleEngine())->runRule($this->rule())->executed);
        self::assertSame(1, $this->followupsOn($this->tickets_id));
    }

    // -----------------------------------------------------------------------------
    // Entities
    // -----------------------------------------------------------------------------

    /**
     * A recursive rule reads the child's ticket, and the child entity's own policy decides
     * whether it may act -- the rule's entity does not.
     */
    public function testARecursiveRuleUsesTheChildTicketsOwnDate(): void
    {
        $this->setDate($this->tickets_id, ReferenceField::TimeToResolve, null);
        $this->setDate($this->child_ticket, ReferenceField::TimeToResolve, '-10 days');

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $report->executed, implode(' | ', $report->errors));
        self::assertSame(1, $this->followupsOn($this->child_ticket));
        self::assertSame(0, $this->followupsOn($this->tickets_id));
    }

    public function testPausingTheChildEntityStopsItsTicketOnly(): void
    {
        $this->setDate($this->tickets_id, ReferenceField::TimeToResolve, '-10 days');
        $this->setDate($this->child_ticket, ReferenceField::TimeToResolve, '-10 days');
        EntityConfig::setForEntity($this->child_entity, ['execution_enabled' => 0]);

        $report = (new RuleEngine())->runRule($this->rule());

        self::assertSame(1, $report->executed);
        self::assertSame(1, $report->simulated);
        self::assertSame(1, $this->followupsOn($this->tickets_id));
        self::assertSame(0, $this->followupsOn($this->child_ticket));

        EntityConfig::setForEntity($this->child_entity, ['execution_enabled' => \Entity::CONFIG_PARENT]);
    }

    // -----------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------

    private function createRule(ReferenceField $field): int
    {
        $rule = new Rule();
        $rules_id = (int) $rule->add([
            'name' => 'TicketFlow date rule ' . $this->suffix, 'entities_id' => 0, 'is_recursive' => 1,
            'rule_type' => 'pending_inactivity', 'target_status' => Ticket::WAITING,
            'start_event' => 'ticket_date_field', 'reference_field' => $field->value,
            'delay_value' => 5, 'delay_unit' => 'calendar_days',
            'calendar_mode' => 'none', 'reset_events' => '',
        ]);
        self::assertGreaterThan(0, $rules_id);
        $rule->update(['id' => $rules_id, 'is_active' => 1]);
        RuleGroup::setGroupsForRule($rules_id, [$this->groups_id]);
        RuleAction::setActionsForRule($rules_id, [
            'add_followup' => ['enabled' => 1, 'content' => 'The date has passed.'],
        ]);

        return $rules_id;
    }

    private function createTicket(int $entities_id): int
    {
        $ticket = new Ticket();
        $tickets_id = (int) $ticket->add([
            'name' => 'TicketFlow date ticket ' . $this->suffix, 'content' => 'waiting',
            'entities_id' => $entities_id, 'status' => Ticket::INCOMING,
        ]);
        self::assertGreaterThan(0, $tickets_id);

        (new Group_Ticket())->add([
            'tickets_id' => $tickets_id, 'groups_id' => $this->groups_id,
            'type' => CommonITILActor::ASSIGN,
        ]);
        $ticket->update(['id' => $tickets_id, 'status' => Ticket::WAITING]);

        return $tickets_id;
    }

    /**
     * Written straight to the column: core refuses some of these through update(), and the
     * point here is the value the engine reads, not how a person would set it.
     */
    private function setDate(int $tickets_id, ReferenceField $field, ?string $when): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(
            Ticket::getTable(),
            [$field->column() => $when === null ? null : date('Y-m-d H:i:s', strtotime($when))],
            ['id' => $tickets_id],
        );
    }

    private function followupsOn(int $tickets_id): int
    {
        return countElementsInTable(ITILFollowup::getTable(), [
            'itemtype' => Ticket::class,
            'items_id' => $tickets_id,
        ]);
    }

    private function rule(): \GlpiPlugin\Ticketclock\Engine\RuleDefinition
    {
        $rule = new Rule();
        self::assertTrue($rule->getFromDB($this->rules_id));

        return $rule->toDefinition();
    }
}
