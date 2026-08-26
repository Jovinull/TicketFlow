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

namespace GlpiPlugin\Ticketflow\Engine;

use Dropdown;
use Glpi\Error\ErrorHandler;
use GlpiPlugin\Ticketflow\Calendar\BusinessTimeCalculator;
use GlpiPlugin\Ticketflow\Calendar\Deadline;
use GlpiPlugin\Ticketflow\Calendar\GlpiCalendarEngine;
use GlpiPlugin\Ticketflow\Config;
use GlpiPlugin\Ticketflow\Engine\Action\AddFollowupAction;
use GlpiPlugin\Ticketflow\Engine\Action\AddSolutionAction;
use GlpiPlugin\Ticketflow\Engine\Action\ChangeStatusAction;
use GlpiPlugin\Ticketflow\Engine\Action\CloseTicketAction;
use GlpiPlugin\Ticketflow\Engine\Action\SendNotificationAction;
use GlpiPlugin\Ticketflow\Engine\Matcher\MatcherInterface;
use GlpiPlugin\Ticketflow\Engine\Matcher\PendingApprovalMatcher;
use GlpiPlugin\Ticketflow\Engine\Matcher\PendingInactivityMatcher;
use GlpiPlugin\Ticketflow\Enum\ExecutionState;
use GlpiPlugin\Ticketflow\Execution;
use GlpiPlugin\Ticketflow\Rule;
use Throwable;

/**
 * Coordinates one run: candidates in, actions and audit rows out.
 *
 * The engine owns the *order* of things, not the details. Finding candidates, resolving
 * context, deciding a match, computing a deadline, running an action and recording it are
 * each somebody else's job. What lives here is the part that is genuinely about
 * sequencing and safety:
 *
 *  - a failure on one ticket never stops the run;
 *  - nothing destructive happens without a fresh re-read of the ticket;
 *  - nothing happens twice, because the occurrence is claimed first.
 */
final readonly class RuleEngine
{
    /** @var list<MatcherInterface> */
    private array $matchers;

    private ActionExecutor $executor;

    public function __construct(
        private CandidateFinder $finder = new CandidateFinder(),
        private TicketContextResolver $resolver = new TicketContextResolver(),
        ?BusinessTimeCalculator $calculator = null,
        ?ActionExecutor $executor = null,
    ) {
        $calculator ??= new BusinessTimeCalculator(new GlpiCalendarEngine());
        $fallback = Config::getInt('fallback_calendars_id');

        // getAncestorsOf() returns an id-keyed array; the matchers only compare values, so
        // hand them a plain list.
        $ancestors = static fn(int $entities_id): array => array_values(array_map(
            'intval',
            getAncestorsOf('glpi_entities', $entities_id) + [$entities_id => $entities_id],
        ));

        $this->matchers = [
            new PendingInactivityMatcher($calculator, $fallback, $ancestors),
            new PendingApprovalMatcher($calculator, $fallback, $ancestors),
        ];

        $this->executor = $executor ?? new ActionExecutor([
            new AddFollowupAction(),
            new AddSolutionAction(),
            new ChangeStatusAction(),
            new CloseTicketAction(),
            new SendNotificationAction(),
        ]);
    }

    public function getActionExecutor(): ActionExecutor
    {
        return $this->executor;
    }

    /**
     * Run every active rule.
     *
     * @param int $max_total hard ceiling on the number of candidates examined this run
     * @param int $preview_limit how many preview rows to keep; 0 keeps none, which is what
     *                           the scheduled task wants -- it never reads them
     */
    public function runAll(?string $now = null, int $max_total = 0, int $preview_limit = 0): RunReport
    {
        $now ??= $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $max_total = $max_total > 0 ? $max_total : Config::getInt('max_tickets_per_run', 1000);

        $report    = new RunReport();
        $report->preview_limit = max($preview_limit, 0);
        $remaining = $max_total;

        foreach (Rule::getActiveDefinitions() as $rule) {
            if ($remaining <= 0) {
                break;
            }

            $rule_report = $this->runRule($rule, $now, false, $remaining, $preview_limit);
            $report->merge($rule_report);
            $remaining -= $rule_report->analyzed;
        }

        return $report;
    }

    /**
     * Evaluate a single rule.
     *
     * @param bool $force_dry_run true for the simulation screen; never writes anything
     * @param int  $max_candidates 0 for the configured default
     * @param int  $preview_limit  how many preview rows to keep; 0 keeps none
     */
    public function runRule(
        RuleDefinition $rule,
        ?string $now = null,
        bool $force_dry_run = false,
        int $max_candidates = 0,
        int $preview_limit = 0,
    ): RunReport {
        $now ??= $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $report = new RunReport();
        $report->preview_limit = max($preview_limit, 0);
        $matcher = $this->matcherFor($rule);
        if ($matcher === null) {
            $report->errors[] = sprintf(__('No matcher supports rule type "%s".', 'ticketflow'), $rule->type->value);
            return $report;
        }

        $dry_run = $force_dry_run || $this->isGloballyInert() || $rule->is_dry_run;

        $batch_size = max(1, Config::getInt('batch_size', 200));
        $ceiling    = $max_candidates > 0 ? $max_candidates : Config::getInt('max_tickets_per_run', 1000);
        $after_id   = 0;

        while ($report->analyzed < $ceiling) {
            $limit = min($batch_size, $ceiling - $report->analyzed);
            $candidates = $this->finder->find($rule, $now, $limit, $after_id);
            if ($candidates === []) {
                break;
            }

            $contexts = $this->resolver->resolveBatch($candidates);

            foreach ($candidates as $candidate) {
                $report->analyzed++;
                $after_id = max($after_id, $candidate->cursor());

                $context = $contexts[$this->resolver->key($candidate->tickets_id, $candidate->validations_id)] ?? null;
                if ($context === null) {
                    continue;
                }

                try {
                    $this->processCandidate($rule, $matcher, $context, $candidate, $now, $dry_run, $report);
                } catch (Throwable $e) {
                    // One bad ticket must never abort the whole run.
                    $report->failed++;
                    $report->errors[] = sprintf('#%d: %s', $candidate->tickets_id, $e->getMessage());
                    $this->logException($e);
                }
            }

            if (count($candidates) < $limit) {
                break;
            }
        }

        if (!$dry_run && $rule->id > 0) {
            $this->touchLastExecution($rule->id, $now);
        }

        return $report;
    }

    private function processCandidate(
        RuleDefinition $rule,
        MatcherInterface $matcher,
        TicketContext $context,
        Candidate $candidate,
        string $now,
        bool $dry_run,
        RunReport $report,
    ): void {
        $match = $matcher->evaluate($rule, $context, $now);

        if (!$match->matched) {
            $report->skipped++;
            return;
        }

        $report->matched++;

        $deadline = $match->deadline;
        $key      = $match->occurrence_key;
        if ($deadline === null || $key === null) {
            return;
        }

        if (!$match->expired) {
            $report->notePreview(
                fn(): PreviewRow => $this->buildPreviewRow($context, $deadline, $now, false, 'not_expired', $rule)
            );
            return;
        }

        $report->expired++;

        if ($dry_run) {
            $this->simulate($rule, $context, $deadline, $key, $now, $report);
            return;
        }

        $executions_id = Execution::claim($rule, $context, $deadline, $key);
        if ($executions_id === null) {
            $report->already_processed++;
            return;
        }

        // Re-validate against a fresh read: between the candidate query and this line a
        // requester may have answered, an approver may have decided, or somebody may have
        // moved the ticket. Acting on stale state is the one mistake that is not
        // recoverable.
        $fresh = $this->resolver->resolveOne($candidate->tickets_id, $candidate->validations_id);
        if ($fresh === null) {
            Execution::complete($executions_id, ExecutionState::Skipped, [], 'ticket_unavailable');
            $report->skipped++;
            return;
        }

        $recheck = $matcher->evaluate($rule, $fresh, $now);
        if (!$recheck->matched || !$recheck->expired || $recheck->occurrence_key !== $key) {
            Execution::complete($executions_id, ExecutionState::Skipped, [], 'state_changed');
            $report->skipped++;
            return;
        }

        $action_context = new ActionContext(
            $rule,
            $fresh,
            $recheck->deadline ?? $deadline,
            $this->buildRenderer($rule, $fresh, $recheck->deadline ?? $deadline),
            Config::getActingUserId(),
            false,
        );

        $outcome = $this->executor->run($action_context);

        Execution::complete(
            $executions_id,
            $outcome['success'] ? ExecutionState::Executed : ExecutionState::Failed,
            $outcome['results'],
            $outcome['success'] ? null : $this->firstError($outcome['results']),
        );

        if ($outcome['success']) {
            $report->executed++;
        } else {
            $report->failed++;
        }

        $report->notePreview(fn(): PreviewRow => $this->buildPreviewRow(
            $fresh,
            $recheck->deadline ?? $deadline,
            $now,
            true,
            $outcome['success'] ? 'executed' : 'failed',
            $rule,
        ));
    }

    private function simulate(
        RuleDefinition $rule,
        TicketContext $context,
        Deadline $deadline,
        string $occurrence_key,
        string $now,
        RunReport $report,
    ): void {
        $action_context = new ActionContext(
            $rule,
            $context,
            $deadline,
            $this->buildRenderer($rule, $context, $deadline),
            Config::getActingUserId(),
            true,
        );

        $outcome = $this->executor->run($action_context);
        $report->simulated++;

        if (Config::getBool('log_dry_runs', true) && $rule->id > 0) {
            Execution::logDryRun($rule, $context, $deadline, $occurrence_key, $outcome['results']);
        }

        $report->notePreview(
            fn(): PreviewRow => $this->buildPreviewRow($context, $deadline, $now, true, 'would_execute', $rule)
        );
    }

    private function buildPreviewRow(
        TicketContext $context,
        Deadline $deadline,
        string $now,
        bool $would_execute,
        string $reason,
        RuleDefinition $rule,
    ): PreviewRow {
        return new PreviewRow(
            $context->tickets_id,
            $context->name,
            $context->entities_id,
            $context->status,
            $context->assigned_groups,
            $deadline->reference_date,
            $deadline->deadline_date,
            $deadline->overdueSeconds($now),
            $deadline->calendar_name,
            $deadline->used_elapsed_time_fallback,
            $would_execute,
            $reason,
            $this->executor->describe($rule),
            $context->validation->id ?? 0,
        );
    }

    /**
     * Placeholder values available to a rule's messages.
     *
     * Kept to the handful that are genuinely useful and cheap to compute; every value is
     * escaped by the renderer before it reaches the timeline.
     */
    public function buildRenderer(RuleDefinition $rule, TicketContext $context, Deadline $deadline): MessageRenderer
    {
        $group_names = [];
        foreach ($context->assigned_groups as $groups_id) {
            $group_names[] = Dropdown::getDropdownName('glpi_groups', $groups_id);
        }

        return new MessageRenderer([
            'ticket.id'      => $context->tickets_id,
            'ticket.name'    => $context->name,
            'ticket.status'  => $context->status,
            'rule.name'      => $rule->name,
            'reference'      => $deadline->reference_date,
            'deadline'       => $deadline->deadline_date,
            'delay'          => $deadline->delay_value,
            'delay_unit'     => $deadline->delay_unit->label(),
            'business_days'  => $deadline->delay_unit->isDayBased() ? $deadline->delay_value : '',
            'calendar.name'  => $deadline->calendar_name,
            'group.name'     => implode(', ', $group_names),
            'entity.name'    => Dropdown::getDropdownName('glpi_entities', $context->entities_id),
        ]);
    }

    /**
     * True when the plugin as a whole must not touch anything.
     *
     * Two independent switches on purpose: `execution_enabled` is the "is TicketFlow
     * allowed to act at all" master switch, `dry_run_global` is the "let it run but keep
     * it simulated" switch used while tuning rules. A fresh install has both set to inert.
     */
    private function isGloballyInert(): bool
    {
        return !Config::getBool('execution_enabled') || Config::getBool('dry_run_global');
    }

    private function matcherFor(RuleDefinition $rule): ?MatcherInterface
    {
        foreach ($this->matchers as $matcher) {
            if ($matcher->supports($rule->type)) {
                return $matcher;
            }
        }

        return null;
    }

    /**
     * @param list<ActionResult> $results
     */
    private function firstError(array $results): ?string
    {
        foreach ($results as $result) {
            if (!$result->success) {
                return $result->message;
            }
        }

        return null;
    }

    private function touchLastExecution(int $rules_id, string $now): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(Rule::getTable(), ['last_execution_date' => $now], ['id' => $rules_id]);
    }

    private function logException(Throwable $e): void
    {
        ErrorHandler::logCaughtException($e);
    }
}
