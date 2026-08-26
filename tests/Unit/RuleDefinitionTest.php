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

use GlpiPlugin\Ticketclock\Engine\ActionDefinition;
use GlpiPlugin\Ticketclock\Engine\OccurrenceKey;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use GlpiPlugin\Ticketclock\Enum\CalendarMode;
use GlpiPlugin\Ticketclock\Enum\DelayUnit;
use GlpiPlugin\Ticketclock\Enum\ResetEvent;
use PHPUnit\Framework\TestCase;

final class RuleDefinitionTest extends TestCase
{
    public function testCalendarModeEntityPrefersTheEntityCalendar(): void
    {
        $rule = DomainFactory::rule(calendar_mode: CalendarMode::Entity, calendars_id: 99);

        self::assertSame(7, $rule->resolveCalendarId(7, 5));
    }

    public function testCalendarModeEntityFallsBackToThePluginDefault(): void
    {
        $rule = DomainFactory::rule(calendar_mode: CalendarMode::Entity, calendars_id: 99);

        self::assertSame(5, $rule->resolveCalendarId(0, 5));
    }

    public function testCalendarModeEntityWithoutAnyCalendarReturnsZero(): void
    {
        $rule = DomainFactory::rule(calendar_mode: CalendarMode::Entity, calendars_id: 99);

        self::assertSame(0, $rule->resolveCalendarId(0, 0));
    }

    public function testCalendarModeSpecificIgnoresTheEntity(): void
    {
        $rule = DomainFactory::rule(calendar_mode: CalendarMode::Specific, calendars_id: 99);

        self::assertSame(99, $rule->resolveCalendarId(7, 5));
    }

    public function testCalendarModeNoneOptsOutOfBusinessTime(): void
    {
        $rule = DomainFactory::rule(calendar_mode: CalendarMode::None, calendars_id: 99);

        self::assertSame(0, $rule->resolveCalendarId(7, 5));
    }

    public function testDestructiveDetection(): void
    {
        $harmless = DomainFactory::rule(actions: [
            new ActionDefinition(1, ActionType::AddFollowup, 10),
            new ActionDefinition(2, ActionType::SendNotification, 30),
        ]);
        $dangerous = DomainFactory::rule(actions: [
            new ActionDefinition(1, ActionType::AddFollowup, 10),
            new ActionDefinition(2, ActionType::CloseTicket, 20),
        ]);

        self::assertFalse($harmless->isDestructive());
        self::assertTrue($dangerous->isDestructive());
    }

    public function testDelayConversionMatchesTheUnit(): void
    {
        self::assertSame(5 * 86400, DomainFactory::rule(delay_value: 5, delay_unit: DelayUnit::BusinessDays)->delayInSeconds());
        self::assertSame(4 * 3600, DomainFactory::rule(delay_value: 4, delay_unit: DelayUnit::BusinessHours)->delayInSeconds());
        self::assertSame(2 * 86400, DomainFactory::rule(delay_value: 2, delay_unit: DelayUnit::CalendarDays)->delayInSeconds());
        self::assertSame(3 * 3600, DomainFactory::rule(delay_value: 3, delay_unit: DelayUnit::Hours)->delayInSeconds());
    }

    public function testOnlyDayBasedUnitsUseCoreWorkInDaysMode(): void
    {
        self::assertTrue(DelayUnit::BusinessDays->isDayBased());
        self::assertTrue(DelayUnit::CalendarDays->isDayBased());
        self::assertFalse(DelayUnit::BusinessHours->isDayBased());
        self::assertFalse(DelayUnit::Hours->isDayBased());
    }

    public function testOnlyBusinessUnitsNeedACalendar(): void
    {
        self::assertTrue(DelayUnit::BusinessDays->usesCalendar());
        self::assertTrue(DelayUnit::BusinessHours->usesCalendar());
        self::assertFalse(DelayUnit::CalendarDays->usesCalendar());
        self::assertFalse(DelayUnit::Hours->usesCalendar());
    }

    public function testResetEventListRoundTripsThroughStorage(): void
    {
        $encoded = ResetEvent::encodeList([ResetEvent::RequesterFollowup, ResetEvent::SolutionAdded]);

        self::assertSame('requester_followup,solution_added', $encoded);
        self::assertSame(
            [ResetEvent::RequesterFollowup, ResetEvent::SolutionAdded],
            ResetEvent::decodeList($encoded),
        );
    }

    public function testResetEventListIgnoresUnknownAndDuplicateTokens(): void
    {
        self::assertSame(
            [ResetEvent::RequesterFollowup],
            ResetEvent::decodeList('requester_followup, bogus ,requester_followup'),
        );
        self::assertSame([], ResetEvent::decodeList(null));
        self::assertSame([], ResetEvent::decodeList(''));
    }

    public function testActionParamsAreDecodedFromTheStoredRow(): void
    {
        $definition = ActionDefinition::fromRow([
            'id'          => 3,
            'action_type' => 'add_followup',
            'ranking'     => 10,
            'params'      => '{"content":"hello","is_private":true}',
        ]);

        self::assertNotNull($definition);
        self::assertSame('hello', $definition->stringParam('content'));
        self::assertTrue($definition->boolParam('is_private'));
        self::assertSame(0, $definition->intParam('missing'));
        self::assertSame('fallback', $definition->stringParam('missing', 'fallback'));
    }

    public function testAnUnknownActionTypeIsDiscardedRatherThanCrashing(): void
    {
        self::assertNull(ActionDefinition::fromRow(['id' => 1, 'action_type' => 'teleport_ticket']));
    }

    public function testOccurrenceKeysAreStableCompactAndDistinct(): void
    {
        $key = OccurrenceKey::forPendingInactivity(7657, '2026-08-03 10:00:00');

        self::assertSame('pi:7657:20260803100000', $key);
        self::assertSame($key, OccurrenceKey::forPendingInactivity(7657, '2026-08-03 10:00:00'));
        self::assertNotSame($key, OccurrenceKey::forPendingInactivity(7657, '2026-08-03 10:00:01'));
        self::assertNotSame($key, OccurrenceKey::forPendingInactivity(7658, '2026-08-03 10:00:00'));
        self::assertLessThanOrEqual(OccurrenceKey::MAX_LENGTH, strlen($key));
    }

    public function testApprovalOccurrenceKeysAreNamespacedApartFromPendingOnes(): void
    {
        self::assertStringStartsWith('pa:', OccurrenceKey::forPendingApproval(42, '2026-08-06 14:30:00'));
        self::assertStringStartsWith('pi:', OccurrenceKey::forPendingInactivity(42, '2026-08-06 14:30:00'));
    }
}
