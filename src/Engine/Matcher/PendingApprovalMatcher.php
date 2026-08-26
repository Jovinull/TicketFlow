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

use CommonITILValidation;
use GlpiPlugin\Ticketclock\Engine\MatchResult;
use GlpiPlugin\Ticketclock\Engine\OccurrenceKey;
use GlpiPlugin\Ticketclock\Engine\RuleDefinition;
use GlpiPlugin\Ticketclock\Engine\TicketContext;
use GlpiPlugin\Ticketclock\Enum\RuleType;
use GlpiPlugin\Ticketclock\Enum\StartEvent;

use function Safe\strtotime;

/**
 * "An approval request has been waiting for a decision."
 *
 * V1 semantics: the unit is one approval request (one glpi_ticketvalidations row), not the
 * whole approval workflow. A ticket with three approvers therefore has three independent
 * clocks. This is the only reading that stays unambiguous when approvers answer at
 * different times, and it is the same granularity core's own approval reminder cron uses
 * (CommonITILValidationCron::cronApprovalReminder). See docs/rules.md and ADR-005.
 */
final class PendingApprovalMatcher extends AbstractMatcher
{
    /**
     * Kept as a class constant rather than an inline literal so the value is resolved from
     * core when GLPI is loaded, and still usable in unit tests when it is not.
     */
    public const STATUS_WAITING = 2;

    public function supports(RuleType $type): bool
    {
        return $type === RuleType::PendingApproval;
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

        $validation = $context->validation;
        if ($validation === null) {
            return MatchResult::noMatch('no_validation');
        }

        if ($validation->status !== $this->waitingStatus()) {
            return MatchResult::noMatch('validation_answered');
        }

        if ($validation->submission_date === '') {
            return MatchResult::noMatch('no_reference_date');
        }

        $reference = $validation->submission_date;

        // Same gate as the pending rule when the rule asks for it: only chase the approval
        // while the last word on the ticket was ours. If somebody outside the group replied
        // after the request went out, the conversation has moved on.
        if ($rule->start_event === StartEvent::LastTargetGroupMessage) {
            $message = $context->last_message;
            if ($message === null) {
                return MatchResult::noMatch('no_message');
            }

            if (!$message->authorBelongsToAnyOf($rule->effectiveTargetGroups($context->assigned_groups))) {
                return MatchResult::noMatch('last_message_not_from_target_group');
            }

            if (strtotime($message->date) > strtotime($reference)) {
                $reference = $message->date;
            }
        }

        // A status change restarts the countdown in both modes.
        if (
            $context->status_changed_at !== null
            && strtotime($context->status_changed_at) > strtotime($reference)
        ) {
            $reference = $context->status_changed_at;
        }

        $occurrence_key = OccurrenceKey::forPendingApproval($validation->id, $reference);

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

    private function waitingStatus(): int
    {
        return class_exists(CommonITILValidation::class)
            ? CommonITILValidation::WAITING
            : self::STATUS_WAITING;
    }
}
