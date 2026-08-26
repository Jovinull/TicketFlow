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

namespace GlpiPlugin\Ticketclock\Engine;

use GlpiPlugin\Ticketclock\Enum\CalendarMode;
use GlpiPlugin\Ticketclock\Enum\DelayUnit;
use GlpiPlugin\Ticketclock\Enum\ResetEvent;
use GlpiPlugin\Ticketclock\Enum\RuleType;
use GlpiPlugin\Ticketclock\Enum\StartEvent;

/**
 * Everything the engine needs to evaluate a rule, without any GLPI dependency.
 *
 * Building this object from a Rule row is the only place that knows about persistence,
 * which keeps the matchers and the calculator unit-testable on their own.
 */
final readonly class RuleDefinition
{
    /**
     * @param list<int>        $groups_id   Assigned groups the rule targets; empty means "any group".
     * @param list<ResetEvent> $reset_events
     * @param list<ActionDefinition> $actions
     */
    public function __construct(
        public int $id,
        public string $name,
        public RuleType $type,
        public int $entities_id,
        public bool $is_recursive,
        public bool $is_active,
        public int $ranking,
        public array $groups_id,
        public int $target_status,
        public int $pendingreasons_id,
        public int $delay_value,
        public DelayUnit $delay_unit,
        public CalendarMode $calendar_mode,
        public int $calendars_id,
        public array $reset_events,
        public array $actions,
        public bool $is_dry_run,
        public StartEvent $start_event = StartEvent::PendingStart,
    ) {}

    public function targetsGroup(int $groups_id): bool
    {
        return $this->groups_id === [] || in_array($groups_id, $this->groups_id, true);
    }

    /**
     * @param list<int> $assigned_groups
     */
    public function matchesAnyAssignedGroup(array $assigned_groups): bool
    {
        if ($this->groups_id === []) {
            return true;
        }

        return array_intersect($this->groups_id, $assigned_groups) !== [];
    }

    /**
     * The groups whose members count as "us" for this ticket.
     *
     * A rule that names groups means those groups. A rule that names none means "any
     * group", and for the purpose of "was the last word ours?" that has to resolve to the
     * ticket's own assigned groups — otherwise the question has no answer.
     *
     * @param list<int> $assigned_groups
     * @return list<int>
     */
    public function effectiveTargetGroups(array $assigned_groups): array
    {
        return $this->groups_id !== [] ? $this->groups_id : $assigned_groups;
    }

    public function hasResetEvent(ResetEvent $event): bool
    {
        return in_array($event, $this->reset_events, true);
    }

    /** True when at least one configured action modifies the ticket irreversibly. */
    public function isDestructive(): bool
    {
        foreach ($this->actions as $action) {
            if ($action->type->isDestructive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Actions sorted the way they must run.
     *
     * @return list<ActionDefinition>
     */
    public function orderedActions(): array
    {
        $actions = $this->actions;
        usort($actions, static fn(ActionDefinition $a, ActionDefinition $b): int
            => [$a->ranking, $a->id] <=> [$b->ranking, $b->id]);

        return $actions;
    }

    public function delayInSeconds(): int
    {
        return $this->delay_unit->toSeconds($this->delay_value);
    }

    /**
     * Which calendar this rule's clock must use for a ticket in a given entity.
     *
     * `entity` mode is the default because it is the only one that stays correct in a
     * multi-entity installation; `specific` pins a calendar; `none` opts out of business
     * time entirely. Returning 0 means "no calendar", which BusinessTimeCalculator turns
     * into plain elapsed time (and records as such).
     *
     * @param int $entity_calendars_id calendar inherited from the ticket's entity, 0 if none
     * @param int $fallback_calendars_id plugin-wide fallback, 0 if none
     */
    public function resolveCalendarId(int $entity_calendars_id, int $fallback_calendars_id = 0): int
    {
        return match ($this->calendar_mode) {
            CalendarMode::Specific => $this->calendars_id,
            CalendarMode::None     => 0,
            CalendarMode::Entity   => $entity_calendars_id > 0 ? $entity_calendars_id : $fallback_calendars_id,
        };
    }
}
