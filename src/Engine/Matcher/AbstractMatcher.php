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

namespace GlpiPlugin\Ticketflow\Engine\Matcher;

use GlpiPlugin\Ticketflow\Calendar\BusinessTimeCalculator;
use GlpiPlugin\Ticketflow\Engine\RuleDefinition;
use GlpiPlugin\Ticketflow\Engine\TicketContext;

/**
 * Conditions every rule type shares: the rule must be live, the ticket must be inside
 * the rule's entity scope, and the rule's target groups must be among the ticket's
 * assigned groups.
 */
abstract class AbstractMatcher implements MatcherInterface
{
    public function __construct(
        protected readonly BusinessTimeCalculator $calculator,
        /** Plugin-wide fallback calendar, used when an entity defines none. */
        protected readonly int $fallback_calendars_id = 0,
        /** @var callable(int): list<int>|null resolves an entity id to itself plus its ancestors */
        protected readonly mixed $entity_ancestors_resolver = null,
    ) {}

    protected function resolveCalendar(RuleDefinition $rule, TicketContext $context): int
    {
        return $rule->resolveCalendarId($context->entity_calendars_id, $this->fallback_calendars_id);
    }

    /**
     * @return string|null a skip reason, or null when the shared conditions hold
     */
    protected function checkCommonConditions(RuleDefinition $rule, TicketContext $context): ?string
    {
        if (!$rule->is_active) {
            return 'rule_inactive';
        }

        if ($context->is_deleted) {
            return 'ticket_deleted';
        }

        if (!$this->entityInScope($rule, $context->entities_id)) {
            return 'entity_mismatch';
        }

        if (!$rule->matchesAnyAssignedGroup($context->assigned_groups)) {
            return 'group_mismatch';
        }

        return null;
    }

    protected function entityInScope(RuleDefinition $rule, int $entities_id): bool
    {
        if ($rule->entities_id === $entities_id) {
            return true;
        }

        if (!$rule->is_recursive) {
            return false;
        }

        $resolver = $this->entity_ancestors_resolver;
        if (!is_callable($resolver)) {
            // Without an entity tree resolver we cannot prove ancestry, so we refuse rather
            // than risk a restricted rule leaking into a sibling entity.
            return false;
        }

        return in_array($rule->entities_id, $resolver($entities_id), true);
    }
}
