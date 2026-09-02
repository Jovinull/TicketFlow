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

use GlpiPlugin\Ticketclock\Engine\Matcher\PendingApprovalMatcher;
use GlpiPlugin\Ticketclock\Enum\RuleType;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance scenario C: an approval left unanswered for two business days.
 */
final class PendingApprovalMatcherTest extends TestCase
{
    private const STATUS_ACCEPTED = 3;
    private const STATUS_REFUSED = 4;

    private function matcher(): PendingApprovalMatcher
    {
        $ancestors = static fn(int $entities_id): array => [0, $entities_id];

        return new PendingApprovalMatcher(CalendarFactory::calculator(), null, $ancestors);
    }

    private function rule(int $delay = 2): \GlpiPlugin\Ticketclock\Engine\RuleDefinition
    {
        return DomainFactory::rule(
            type: RuleType::PendingApproval,
            // Approval rules do not care about the pending status.
            target_status: 0,
            delay_value: $delay,
        );
    }

    public function testSupportsOnlyItsOwnRuleType(): void
    {
        self::assertTrue($this->matcher()->supports(RuleType::PendingApproval));
        self::assertFalse($this->matcher()->supports(RuleType::PendingInactivity));
    }

    public function testUnansweredApprovalPastTwoBusinessDaysFires(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                status: DomainFactory::STATUS_ASSIGNED,
                validation: DomainFactory::validation(submission_date: '2026-08-06 14:30:00'),
            ),
            '2026-08-10 15:00:00',
        );

        // Thursday + 2 business days = Monday; the weekend does not count.
        self::assertTrue($result->expired);
        self::assertSame('2026-08-10 14:30:00', $result->deadline?->deadline_date);
        self::assertSame('pa:42:20260806143000', $result->occurrence_key);
    }

    public function testApprovalStillInsideTheWindowDoesNotFire(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                status: DomainFactory::STATUS_ASSIGNED,
                validation: DomainFactory::validation(submission_date: '2026-08-06 14:30:00'),
            ),
            '2026-08-07 18:00:00',
        );

        self::assertTrue($result->matched);
        self::assertFalse($result->expired);
    }

    public function testAcceptedApprovalNeverFires(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                status: DomainFactory::STATUS_ASSIGNED,
                validation: DomainFactory::validation(
                    status: self::STATUS_ACCEPTED,
                    validation_date: '2026-08-07 09:00:00',
                ),
            ),
            '2026-08-20 15:00:00',
        );

        self::assertFalse($result->matched);
        self::assertSame('validation_answered', $result->reason);
    }

    public function testRefusedApprovalNeverFires(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                status: DomainFactory::STATUS_ASSIGNED,
                validation: DomainFactory::validation(
                    status: self::STATUS_REFUSED,
                    validation_date: '2026-08-07 09:00:00',
                ),
            ),
            '2026-08-20 15:00:00',
        );

        self::assertSame('validation_answered', $result->reason);
    }

    public function testWithoutAValidationThereIsNothingToEvaluate(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(status: DomainFactory::STATUS_ASSIGNED),
            '2026-08-20 15:00:00',
        );

        self::assertSame('no_validation', $result->reason);
    }

    /**
     * Each approval request carries its own clock, so several approvers on one ticket
     * never share (or steal) an occurrence.
     */
    public function testEachApprovalRequestHasItsOwnOccurrenceKey(): void
    {
        $first = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                status: DomainFactory::STATUS_ASSIGNED,
                validation: DomainFactory::validation(id: 42, submission_date: '2026-08-06 14:30:00'),
            ),
            '2026-08-10 15:00:00',
        );

        $second = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                status: DomainFactory::STATUS_ASSIGNED,
                validation: DomainFactory::validation(id: 43, submission_date: '2026-08-06 14:30:00'),
            ),
            '2026-08-10 15:00:00',
        );

        self::assertNotSame($first->occurrence_key, $second->occurrence_key);
        self::assertTrue($first->expired);
        self::assertTrue($second->expired);
    }

    public function testApprovalTargetedAtAGroupIsHandledLikeAnyOther(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                status: DomainFactory::STATUS_ASSIGNED,
                validation: DomainFactory::validation(itemtype_target: 'Group', items_id_target: 13),
            ),
            '2026-08-10 15:00:00',
        );

        self::assertTrue($result->expired);
    }

    public function testGroupConditionStillApplies(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                status: DomainFactory::STATUS_ASSIGNED,
                assigned_groups: [DomainFactory::GROUP_SUPPORT],
                validation: DomainFactory::validation(),
            ),
            '2026-08-10 15:00:00',
        );

        self::assertSame('group_mismatch', $result->reason);
    }

    public function testMissingSubmissionDateIsRefused(): void
    {
        $result = $this->matcher()->evaluate(
            $this->rule(),
            DomainFactory::ticket(
                status: DomainFactory::STATUS_ASSIGNED,
                validation: DomainFactory::validation(submission_date: ''),
            ),
            '2026-08-10 15:00:00',
        );

        self::assertSame('no_reference_date', $result->reason);
    }
}
