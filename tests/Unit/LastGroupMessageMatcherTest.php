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

namespace GlpiPlugin\Ticketclock\Tests\Unit;

use GlpiPlugin\Ticketclock\Engine\Matcher\PendingInactivityMatcher;
use GlpiPlugin\Ticketclock\Engine\MessageContext;
use GlpiPlugin\Ticketclock\Enum\StartEvent;
use PHPUnit\Framework\TestCase;

/**
 * The "we answered, nobody came back to us" clock.
 *
 * The rule only runs while the last word on the ticket belongs to the target group, the
 * countdown starts at that message, and a status change restarts everything.
 */
final class LastGroupMessageMatcherTest extends TestCase
{
    private const OTHER_GROUP = 77;

    private function matcher(): PendingInactivityMatcher
    {
        $ancestors = static fn(int $entities_id): array => [0, $entities_id];

        return new PendingInactivityMatcher(CalendarFactory::calculator(), 0, $ancestors);
    }

    /**
     * @param list<int> $author_groups
     */
    private function message(string $date, array $author_groups, int $users_id = 500): MessageContext
    {
        return new MessageContext(1, $date, $users_id, $author_groups);
    }

    private function rule(int $delay = 5, array $groups = [DomainFactory::GROUP_DEV]): \GlpiPlugin\Ticketclock\Engine\RuleDefinition
    {
        return DomainFactory::rule(
            groups_id: $groups,
            delay_value: $delay,
            reset_events: [],
            start_event: StartEvent::LastTargetGroupMessage,
        );
    }

    public function testClockRunsFromTheGroupsLastMessage(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                begin_waiting_date: '2026-07-01 09:00:00',
                last_message: $this->message('2026-08-03 10:00:00', [DomainFactory::GROUP_DEV]),
                status_changed_at: '2026-07-01 09:00:00',
            ),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($result->expired);
        // The message, not begin_waiting_date, is what the deadline is measured from.
        self::assertSame('2026-08-03 10:00:00', $result->deadline?->reference_date);
        self::assertSame('2026-08-10 10:00:00', $result->deadline?->deadline_date);
        self::assertSame('gs:7657:20260803100000', $result->occurrence_key);
    }

    public function testStillInsideTheWindowDoesNotFire(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                last_message: $this->message('2026-08-03 10:00:00', [DomainFactory::GROUP_DEV]),
                status_changed_at: '2026-07-01 09:00:00',
            ),
            '2026-08-07 10:00:00',
        );

        self::assertTrue($result->matched);
        self::assertFalse($result->expired);
    }

    /**
     * The requester answered after us: the ball is back in our court, so the rule must go
     * quiet — no message, no solution, nothing.
     */
    public function testAReplyFromOutsideTheGroupStopsTheRule(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                last_message: $this->message('2026-08-04 08:00:00', [self::OTHER_GROUP], users_id: 900),
                status_changed_at: '2026-07-01 09:00:00',
            ),
            '2026-08-20 09:00:00',
        );

        self::assertFalse($result->matched);
        self::assertSame('last_message_not_from_target_group', $result->reason);
    }

    public function testAnAuthorWithNoGroupAtAllStopsTheRule(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                last_message: $this->message('2026-08-04 08:00:00', []),
                status_changed_at: '2026-07-01 09:00:00',
            ),
            '2026-08-20 09:00:00',
        );

        self::assertSame('last_message_not_from_target_group', $result->reason);
    }

    public function testATicketWithNoMessageAtAllIsNotTimed(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(last_message: null, status_changed_at: '2026-07-01 09:00:00'),
            '2026-08-20 09:00:00',
        );

        self::assertFalse($result->matched);
        self::assertSame('no_message', $result->reason);
    }

    /**
     * An author who belongs to several groups still counts as long as one of them is
     * targeted — people are in more than one team.
     */
    public function testAnAuthorInSeveralGroupsCountsWhenOneIsTargeted(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                last_message: $this->message('2026-08-03 10:00:00', [self::OTHER_GROUP, DomainFactory::GROUP_DEV]),
                status_changed_at: '2026-07-01 09:00:00',
            ),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($result->expired);
    }

    /**
     * A status change after the message restarts the countdown — and produces a different
     * occurrence, so the ticket is judged fresh rather than acted on with a stale clock.
     */
    public function testAStatusChangeAfterTheMessageRestartsTheCountdown(): void
    {
        $before = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                last_message: $this->message('2026-08-03 10:00:00', [DomainFactory::GROUP_DEV]),
                status_changed_at: '2026-08-03 09:00:00',
            ),
            '2026-08-11 09:00:00',
        );

        $after = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                last_message: $this->message('2026-08-03 10:00:00', [DomainFactory::GROUP_DEV]),
                status_changed_at: '2026-08-10 15:00:00',
            ),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($before->expired);
        self::assertFalse($after->expired, 'the status change restarted the clock');
        self::assertSame('2026-08-10 15:00:00', $after->deadline?->reference_date);
        self::assertNotSame($before->occurrence_key, $after->occurrence_key);
    }

    /**
     * A newer message from the group is a new waiting period, and therefore a new
     * occurrence — the rule may fire again for it later.
     */
    public function testANewerGroupMessageStartsANewOccurrence(): void
    {
        $first = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(last_message: $this->message('2026-08-03 10:00:00', [DomainFactory::GROUP_DEV])),
            '2026-08-11 09:00:00',
        );

        $second = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(last_message: $this->message('2026-08-12 10:00:00', [DomainFactory::GROUP_DEV])),
            '2026-08-20 09:00:00',
        );

        self::assertNotSame($first->occurrence_key, $second->occurrence_key);
        self::assertTrue($second->expired);
    }

    public function testWeekendsAreStillSkipped(): void
    {
        // Thursday + 2 business days lands on Monday, not Saturday.
        $result = $this->matcher()->evaluate(
            $this->rule(delay: 2),
            DomainFactory::ticket(last_message: $this->message('2026-08-06 14:30:00', [DomainFactory::GROUP_DEV])),
            '2026-08-09 23:00:00',
        );

        self::assertFalse($result->expired);
        self::assertSame('2026-08-10 14:30:00', $result->deadline?->deadline_date);
    }

    /**
     * This mode does not need begin_waiting_date, which is what lets it work on statuses
     * core never stamps a date on — "Approval", for one.
     */
    public function testItWorksOnAStatusWithoutBeginWaitingDate(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(
                groups_id: [DomainFactory::GROUP_DEV],
                target_status: 10,
                delay_value: 2,
                reset_events: [],
                start_event: StartEvent::LastTargetGroupMessage,
            ),
            DomainFactory::ticket(
                status: 10,
                begin_waiting_date: null,
                last_message: $this->message('2026-08-06 14:30:00', [DomainFactory::GROUP_DEV]),
            ),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($result->expired);
        self::assertSame('2026-08-10 14:30:00', $result->deadline?->deadline_date);
    }

    public function testTheGroupConditionOnTheTicketStillApplies(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                assigned_groups: [DomainFactory::GROUP_SUPPORT],
                last_message: $this->message('2026-08-03 10:00:00', [DomainFactory::GROUP_DEV]),
            ),
            '2026-08-11 09:00:00',
        );

        self::assertSame('group_mismatch', $result->reason);
    }

    /**
     * With no group named on the rule, "ours" can only mean the ticket's own assigned
     * groups — otherwise the question has no answer.
     */
    public function testARuleWithoutGroupsFallsBackToTheTicketsAssignedGroups(): void
    {
        $matches = $this->matcher()->evaluate(
            $this->rule(groups: []),
            DomainFactory::ticket(
                assigned_groups: [DomainFactory::GROUP_SUPPORT],
                last_message: $this->message('2026-08-03 10:00:00', [DomainFactory::GROUP_SUPPORT]),
            ),
            '2026-08-11 09:00:00',
        );

        $does_not = $this->matcher()->evaluate(
            $this->rule(groups: []),
            DomainFactory::ticket(
                assigned_groups: [DomainFactory::GROUP_SUPPORT],
                last_message: $this->message('2026-08-03 10:00:00', [self::OTHER_GROUP]),
            ),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($matches->expired);
        self::assertSame('last_message_not_from_target_group', $does_not->reason);
    }

    /**
     * The other mode must keep behaving exactly as before.
     */
    public function testPendingStartModeIsUnaffected(): void
    {
        $result = $this->matcher()->evaluate(
            DomainFactory::rule(),
            DomainFactory::ticket(
                last_message: $this->message('2026-08-04 10:00:00', [self::OTHER_GROUP]),
            ),
            '2026-08-11 09:00:00',
        );

        self::assertTrue($result->expired);
        self::assertSame('2026-08-03 10:00:00', $result->deadline?->reference_date);
        self::assertStringStartsWith('pi:', (string) $result->occurrence_key);
    }
}
