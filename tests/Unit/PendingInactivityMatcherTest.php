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

namespace GlpiPlugin\Ticketflow\Tests\Unit;

use GlpiPlugin\Ticketflow\Engine\Matcher\PendingInactivityMatcher;
use GlpiPlugin\Ticketflow\Enum\ResetEvent;
use GlpiPlugin\Ticketflow\Enum\RuleType;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance scenarios A, B, D and E of the specification, expressed as unit tests.
 */
final class PendingInactivityMatcherTest extends TestCase
{
    private function matcher(): PendingInactivityMatcher
    {
        // Entity 0 is the root; every entity descends from it.
        $ancestors = static fn(int $entities_id): array => [0, $entities_id];

        return new PendingInactivityMatcher(CalendarFactory::calculator(), 0, $ancestors);
    }

    public function testSupportsOnlyItsOwnRuleType(): void
    {
        self::assertTrue($this->matcher()->supports(RuleType::PendingInactivity));
        self::assertFalse($this->matcher()->supports(RuleType::PendingApproval));
    }

    public function testStillInsideTheWindowDoesNotFire(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(),
            '2026-08-07 10:00:00',
        );

        self::assertTrue($result->matched);
        self::assertFalse($result->expired);
        self::assertSame('2026-08-10 10:00:00', $result->deadline?->deadline_date);
    }

    public function testExactlyOnTheDeadlineFires(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(),
            '2026-08-10 10:00:00',
        );

        self::assertTrue($result->expired);
    }

    public function testAfterTheDeadlineFires(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($result->expired);
        self::assertSame('pi:7657:20260803100000', $result->occurrence_key);
    }

    /**
     * Scenario B: the requester answers before the deadline, so the clock restarts.
     */
    public function testRequesterAnswerRestartsTheClock(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(last_events: [
                ResetEvent::RequesterFollowup->value => '2026-08-07 09:00:00',
                ResetEvent::AnyFollowup->value       => '2026-08-07 09:00:00',
            ]),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($result->matched);
        self::assertFalse($result->expired, 'The requester answered, so the deadline moved forward');
        self::assertSame('2026-08-07 09:00:00', $result->deadline?->reference_date);
        self::assertSame('2026-08-14 09:00:00', $result->deadline?->deadline_date);
    }

    /**
     * The distinction the whole plugin exists for: when the rule waits for the requester,
     * a technician's reply must not buy the ticket another five days.
     */
    public function testTechnicianAnswerDoesNotRestartAClockThatWaitsForTheRequester(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(reset_events: [ResetEvent::RequesterFollowup]),
            DomainFactory::ticket(last_events: [
                ResetEvent::AssigneeFollowup->value => '2026-08-07 09:00:00',
                ResetEvent::AnyFollowup->value      => '2026-08-07 09:00:00',
            ]),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($result->expired);
        self::assertSame('2026-08-03 10:00:00', $result->deadline?->reference_date);
    }

    public function testAnyFollowupResetWhenTheRuleAsksForIt(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(reset_events: [ResetEvent::AnyFollowup]),
            DomainFactory::ticket(last_events: [
                ResetEvent::AssigneeFollowup->value => '2026-08-07 09:00:00',
                ResetEvent::AnyFollowup->value      => '2026-08-07 09:00:00',
            ]),
            '2026-08-11 09:00:00',
        );

        self::assertFalse($result->expired);
    }

    public function testAResetEventOlderThanTheCycleIsIgnored(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(last_events: [
                // The requester answered before the ticket went pending again.
                ResetEvent::RequesterFollowup->value => '2026-07-20 09:00:00',
            ]),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($result->expired);
        self::assertSame('2026-08-03 10:00:00', $result->deadline?->reference_date);
    }

    public function testInactiveRuleNeverMatches(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(is_active: false),
            DomainFactory::ticket(),
            '2026-08-11 09:00:00',
        );

        self::assertFalse($result->matched);
        self::assertSame('rule_inactive', $result->reason);
    }

    public function testDeletedTicketNeverMatches(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(is_deleted: true),
            '2026-08-11 09:00:00',
        );

        self::assertSame('ticket_deleted', $result->reason);
    }

    /**
     * Scenario E: a rule for one group must not touch another group's tickets.
     */
    public function testRuleForAnotherGroupDoesNotMatch(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(groups_id: [DomainFactory::GROUP_DEV]),
            DomainFactory::ticket(assigned_groups: [DomainFactory::GROUP_SUPPORT]),
            '2026-08-11 09:00:00',
        );

        self::assertFalse($result->matched);
        self::assertSame('group_mismatch', $result->reason);
    }

    /**
     * Most tickets in the reference installation carry more than one assigned group, so a
     * rule has to match when its group is among them, not only when it is the only one.
     */
    public function testRuleMatchesWhenItsGroupIsOneOfSeveralAssignedGroups(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(groups_id: [DomainFactory::GROUP_DEV]),
            DomainFactory::ticket(assigned_groups: [DomainFactory::GROUP_SUPPORT, DomainFactory::GROUP_DEV]),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($result->expired);
    }

    public function testEmptyGroupListMatchesAnyGroup(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(groups_id: []),
            DomainFactory::ticket(assigned_groups: [999]),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($result->expired);
    }

    public function testStatusMismatchDoesNotMatch(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(status: DomainFactory::STATUS_ASSIGNED),
            '2026-08-11 09:00:00',
        );

        self::assertSame('status_mismatch', $result->reason);
    }

    public function testPendingReasonMismatchDoesNotMatch(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(pendingreasons_id: 3),
            DomainFactory::ticket(pendingreasons_id: 7),
            '2026-08-11 09:00:00',
        );

        self::assertSame('pendingreason_mismatch', $result->reason);
    }

    public function testPendingReasonZeroOnTheRuleMeansAny(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(pendingreasons_id: 0),
            DomainFactory::ticket(pendingreasons_id: 7),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($result->expired);
    }

    public function testTicketOutsideTheRuleEntityDoesNotMatch(): void
    {
        $ancestors = static fn(int $entities_id): array => match ($entities_id) {
            2       => [0, 2],
            default => [0, $entities_id],
        };
        $matcher = new PendingInactivityMatcher(CalendarFactory::calculator(), 0, $ancestors);

        $result = $matcher->evaluate(
            DomainFactory::rule(entities_id: 1, is_recursive: true),
            DomainFactory::ticket(entities_id: 2),
            '2026-08-11 09:00:00',
        );

        self::assertSame('entity_mismatch', $result->reason);
    }

    public function testNonRecursiveRuleOnlyMatchesItsOwnEntity(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(entities_id: 0, is_recursive: false),
            DomainFactory::ticket(entities_id: 3),
            '2026-08-11 09:00:00',
        );

        self::assertSame('entity_mismatch', $result->reason);
    }

    public function testMissingReferenceDateIsRefusedRatherThanGuessed(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(begin_waiting_date: null),
            '2026-08-11 09:00:00',
        );

        self::assertFalse($result->matched);
        self::assertSame('no_reference_date', $result->reason);
    }

    /**
     * A ticket that leaves pending and comes back gets a new occurrence, so it can fire
     * again without idempotency getting in the way.
     */
    public function testANewPendingCycleProducesANewOccurrenceKey(): void
    {
        $first = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(begin_waiting_date: '2026-08-03 10:00:00'),
            '2026-08-11 09:00:00',
        );

        $second = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(begin_waiting_date: '2026-09-03 10:00:00'),
            '2026-09-11 09:00:00',
        );

        self::assertNotSame($first->occurrence_key, $second->occurrence_key);
    }

    /**
     * An answer inside the cycle must not create a second occurrence: the key is tied to
     * the cycle start, not to the (possibly reset) reference date.
     */
    public function testTheOccurrenceKeyDoesNotChangeWhenTheClockIsReset(): void
    {
        $without = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(),
            '2026-08-11 09:00:00',
        );

        $with = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(last_events: [
                ResetEvent::RequesterFollowup->value => '2026-08-05 09:00:00',
            ]),
            '2026-08-11 09:00:00',
        );

        self::assertSame($without->occurrence_key, $with->occurrence_key);
    }

    public function testHolidayCalendarIsHonouredEndToEnd(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(calendars_id: CalendarFactory::WITH_HOLIDAY),
            DomainFactory::ticket(),
            '2026-08-10 12:00:00',
        );

        // Without the holiday this would already be expired; with it, one more day is owed.
        self::assertFalse($result->expired);
        self::assertSame('2026-08-11 10:00:00', $result->deadline?->deadline_date);
    }
}
