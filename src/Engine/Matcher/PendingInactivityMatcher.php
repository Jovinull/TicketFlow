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

namespace GlpiPlugin\Ticketclock\Engine\Matcher;

use GlpiPlugin\Ticketclock\Engine\MatchResult;
use GlpiPlugin\Ticketclock\Engine\OccurrenceKey;
use GlpiPlugin\Ticketclock\Engine\RuleDefinition;
use GlpiPlugin\Ticketclock\Engine\TicketContext;
use GlpiPlugin\Ticketclock\Enum\RuleType;
use GlpiPlugin\Ticketclock\Enum\StartEvent;

use function Safe\strtotime;

/**
 * "The ticket has been sitting in a state without the answer we are waiting for."
 *
 * Two ways to time that, chosen per rule:
 *
 * **`pending_start`** — the clock runs from `glpi_tickets.begin_waiting_date`, which core
 * writes exactly when the status becomes `WAITING` and clears when it leaves
 * (CommonITILObject::post_updateItem). Reset events configured on the rule push it forward.
 *
 * **`last_target_group_message`** — the clock runs from the last message on the ticket,
 * and only while that message was written by a member of the rule's target group. This is
 * the "we answered and nobody came back to us" case: the moment anybody outside the group
 * replies, the last word is no longer ours and the rule stops matching. It works for any
 * status, including ones core never stamps a date on, because the reference comes from the
 * conversation rather than from the state.
 *
 * In both modes a status change restarts the countdown, and produces a new occurrence, so
 * a ticket that moves between states is never acted on with a stale clock.
 */
final class PendingInactivityMatcher extends AbstractMatcher
{
    public function supports(RuleType $type): bool
    {
        return $type === RuleType::PendingInactivity;
    }

    public function evaluate(RuleDefinition $rule, TicketContext $context, string $now): MatchResult
    {
        $skip = $this->checkCommonConditions($rule, $context);
        if ($skip !== null) {
            return MatchResult::noMatch($skip);
        }

        if ($rule->target_status > 0 && $context->status !== $rule->target_status) {
            return MatchResult::noMatch('status_mismatch');
        }

        if ($rule->pendingreasons_id > 0 && $context->pendingreasons_id !== $rule->pendingreasons_id) {
            return MatchResult::noMatch('pendingreason_mismatch');
        }

        return $rule->start_event === StartEvent::LastTargetGroupMessage
            ? $this->evaluateFromLastGroupMessage($rule, $context, $now)
            : $this->evaluateFromPendingStart($rule, $context, $now);
    }

    private function evaluateFromPendingStart(RuleDefinition $rule, TicketContext $context, string $now): MatchResult
    {
        $started_at = $context->begin_waiting_date;
        if ($started_at === null || $started_at === '') {
            // A pending ticket without begin_waiting_date cannot be timed reliably.
            return MatchResult::noMatch('no_reference_date');
        }

        // The occurrence is identified by the cycle start, not by the (possibly reset)
        // clock, so a mid-cycle answer does not create a second occurrence.
        $occurrence_key = OccurrenceKey::forPendingInactivity($context->tickets_id, $started_at);

        $reference = $started_at;
        $reset     = $context->latestResetDate($rule->reset_events);
        if ($reset !== null && strtotime($reset) > strtotime($reference)) {
            $reference = $reset;
        }

        return $this->finish($rule, $context, $reference, $occurrence_key, $now);
    }

    private function evaluateFromLastGroupMessage(RuleDefinition $rule, TicketContext $context, string $now): MatchResult
    {
        $message = $context->last_message;
        if ($message === null) {
            return MatchResult::noMatch('no_message');
        }

        $targets = $rule->effectiveTargetGroups($context->assigned_groups);
        if (!$message->authorBelongsToAnyOf($targets)) {
            // Somebody outside the group had the last word: it is our turn, not theirs.
            return MatchResult::noMatch('last_message_not_from_target_group');
        }

        // A status change restarts the countdown even when the last message is older, so
        // the clock always reflects the current state of the ticket.
        $reference = $message->date;
        if (
            $context->status_changed_at !== null
            && strtotime($context->status_changed_at) > strtotime($reference)
        ) {
            $reference = $context->status_changed_at;
        }

        // Anything that restarts this clock — a newer message from the group, a status
        // change — moves the reference, and therefore starts a new occurrence.
        $occurrence_key = OccurrenceKey::forGroupSilence($context->tickets_id, $reference);

        return $this->finish($rule, $context, $reference, $occurrence_key, $now);
    }

    private function finish(
        RuleDefinition $rule,
        TicketContext $context,
        string $reference,
        string $occurrence_key,
        string $now,
    ): MatchResult {
        $deadline = $this->calculator->computeDeadline(
            $reference,
            $rule->delay_value,
            $rule->delay_unit,
            $this->resolveCalendar($rule, $context),
        );

        return $deadline->isExpiredAt($now)
            ? MatchResult::expired($deadline, $occurrence_key)
            : MatchResult::running($deadline, $occurrence_key);
    }
}
