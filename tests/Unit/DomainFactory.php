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

use GlpiPlugin\Ticketflow\Engine\ActionDefinition;
use GlpiPlugin\Ticketflow\Engine\MessageContext;
use GlpiPlugin\Ticketflow\Engine\RuleDefinition;
use GlpiPlugin\Ticketflow\Engine\TicketContext;
use GlpiPlugin\Ticketflow\Engine\ValidationContext;
use GlpiPlugin\Ticketflow\Enum\ActionType;
use GlpiPlugin\Ticketflow\Enum\CalendarMode;
use GlpiPlugin\Ticketflow\Enum\DelayUnit;
use GlpiPlugin\Ticketflow\Enum\ResetEvent;
use GlpiPlugin\Ticketflow\Enum\RuleType;
use GlpiPlugin\Ticketflow\Enum\StartEvent;

/**
 * Builders for the value objects the matchers consume.
 *
 * Named arguments keep every test readable: each one states only the field it is actually
 * about.
 */
final class DomainFactory
{
    public const STATUS_WAITING = 4;
    public const STATUS_ASSIGNED = 2;
    public const GROUP_DEV = 9;
    public const GROUP_SUPPORT = 6;

    /**
     * @param list<int>              $groups_id
     * @param list<ResetEvent>       $reset_events
     * @param list<ActionDefinition> $actions
     */
    public static function rule(
        int $id = 1,
        string $name = 'Test rule',
        RuleType $type = RuleType::PendingInactivity,
        int $entities_id = 0,
        bool $is_recursive = true,
        bool $is_active = true,
        array $groups_id = [self::GROUP_DEV],
        int $target_status = self::STATUS_WAITING,
        int $pendingreasons_id = 0,
        int $delay_value = 5,
        DelayUnit $delay_unit = DelayUnit::BusinessDays,
        CalendarMode $calendar_mode = CalendarMode::Specific,
        int $calendars_id = CalendarFactory::OFFICE,
        array $reset_events = [ResetEvent::RequesterFollowup],
        array $actions = [],
        bool $is_dry_run = false,
        StartEvent $start_event = StartEvent::PendingStart,
    ): RuleDefinition {
        if ($actions === []) {
            $actions = [
                new ActionDefinition(1, ActionType::AddFollowup, 10, ['content' => 'Auto message']),
                new ActionDefinition(2, ActionType::AddSolution, 20, ['content' => 'Auto solution']),
            ];
        }

        return new RuleDefinition(
            $id,
            $name,
            $type,
            $entities_id,
            $is_recursive,
            $is_active,
            0,
            $groups_id,
            $target_status,
            $pendingreasons_id,
            $delay_value,
            $delay_unit,
            $calendar_mode,
            $calendars_id,
            $reset_events,
            $actions,
            $is_dry_run,
            $start_event,
        );
    }

    /**
     * @param list<int>                  $assigned_groups
     * @param array<string, string|null> $last_events
     */
    public static function ticket(
        int $tickets_id = 7657,
        string $name = 'Printer is on fire',
        int $entities_id = 0,
        int $status = self::STATUS_WAITING,
        bool $is_deleted = false,
        ?string $begin_waiting_date = '2026-08-03 10:00:00',
        array $assigned_groups = [self::GROUP_DEV],
        int $pendingreasons_id = 0,
        array $last_events = [],
        int $entity_calendars_id = CalendarFactory::OFFICE,
        ?ValidationContext $validation = null,
        ?MessageContext $last_message = null,
        ?string $status_changed_at = null,
    ): TicketContext {
        return new TicketContext(
            $tickets_id,
            $name,
            $entities_id,
            $status,
            $is_deleted,
            $begin_waiting_date,
            '2026-08-03 10:00:00',
            $assigned_groups,
            [100],
            [],
            [200],
            $pendingreasons_id,
            $last_events,
            $entity_calendars_id,
            $validation,
            $last_message,
            $status_changed_at,
        );
    }

    public static function validation(
        int $id = 42,
        int $status = 2,
        string $submission_date = '2026-08-06 14:30:00',
        ?string $validation_date = null,
        string $itemtype_target = 'Group',
        int $items_id_target = 13,
    ): ValidationContext {
        return new ValidationContext(
            $id,
            $status,
            $submission_date,
            $validation_date,
            $itemtype_target,
            $items_id_target,
            1,
        );
    }
}
