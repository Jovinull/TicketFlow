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

use Dropdown;
use Glpi\Error\ErrorHandler;
use GlpiPlugin\Ticketclock\Calendar\BusinessTimeCalculator;
use GlpiPlugin\Ticketclock\Calendar\Deadline;
use GlpiPlugin\Ticketclock\Calendar\GlpiCalendarEngine;
use GlpiPlugin\Ticketclock\Config;
use GlpiPlugin\Ticketclock\EntityConfig;
use GlpiPlugin\Ticketclock\Engine\Action\AddFollowupAction;
use GlpiPlugin\Ticketclock\Engine\Action\AssignGroupAction;
use GlpiPlugin\Ticketclock\Engine\Action\AddSolutionAction;
use GlpiPlugin\Ticketclock\Engine\Action\ChangeStatusAction;
use GlpiPlugin\Ticketclock\Engine\Action\CloseTicketAction;
use GlpiPlugin\Ticketclock\Engine\Action\SendNotificationAction;
use GlpiPlugin\Ticketclock\Engine\Matcher\MatcherInterface;
use GlpiPlugin\Ticketclock\Engine\Matcher\PendingApprovalMatcher;
use GlpiPlugin\Ticketclock\Engine\Matcher\PendingInactivityMatcher;
use GlpiPlugin\Ticketclock\Enum\ExecutionState;
use GlpiPlugin\Ticketclock\Execution;
use GlpiPlugin\Ticketclock\Rule;
use Throwable;
use Session;

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

    /**
     * An engine narrowed to the entities the logged-in operator may act in.
     *
     * The scheduled run deliberately uses the rule's own scope: it has no session, and it
     * must act on what the rule was configured for. A manual run is the opposite case.
     * A recursive rule stored on a parent entity is readable from every child, so without
     * this an operator confined to one branch could open that rule, press "Run for real"
     * and reach tickets in a sibling entity. Named rather than a flag, because the
     * difference between the two callers is the whole point.
     */
    public static function forOperator(): self
    {
        // array_values because getActiveEntities() is keyed, and the finder wants a list.
        return new self(
            new CandidateFinder(array_values(array_map(intval(...), Session::getActiveEntities()))),
            authorization: new OperatorAuthorization(),
            actor_users_id: (int) Session::getLoginUserID(),
        );
    }

    private ActionExecutor $executor;

    public function __construct(
        private CandidateFinder $finder = new CandidateFinder(),
        private TicketContextResolver $resolver = new TicketContextResolver(),
        ?BusinessTimeCalculator $calculator = null,
        ?ActionExecutor $executor = null,
        ?OperatorAuthorization $authorization = null,
        /** Null for cron; the manual path attributes generated content to its operator. */
        private ?int $actor_users_id = null,
    ) {
        $calculator ??= new BusinessTimeCalculator(new GlpiCalendarEngine());

        // Resolved per ticket, not once here: the fallback calendar belongs to an entity, and
        // at this point neither a rule nor a ticket is known.
        $fallback = static fn(int $entities_id): int => EntityConfig::getFallbackCalendarId($entities_id);

        // getAncestorsOf() returns an id-keyed array; the matchers only compare values, so
        // hand them a plain list.
        $ancestors = static fn(int $entities_id): array => array_values(array_map(
            intval(...),
            getAncestorsOf('glpi_entities', $entities_id) + [$entities_id => $entities_id],
        ));

        $this->matchers = [
            new PendingInactivityMatcher($calculator, $fallback, $ancestors),
            new PendingApprovalMatcher($calculator, $fallback, $ancestors),
        ];

        $this->executor = $executor ?? new ActionExecutor([
            new AssignGroupAction(),
            new AddFollowupAction(),
            new AddSolutionAction(),
            new ChangeStatusAction(),
            new CloseTicketAction(),
            new SendNotificationAction(),
        ], $authorization);
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

        // Refused before anything is read, let alone written. A rule whose stored actions
        // cannot all be read is not the rule somebody configured, and running the survivors
        // would give a wrong outcome on every ticket it touches without saying so. One rule
        // stops until the row is fixed; the rest of the pass is unaffected, because runAll()
        // asks each rule separately and merges what comes back.
        // Decided before anything is written, because two of the callers must not write at
        // all. A preview is reached with READ on the rule, and a rule left in simulation has
        // asked for a run that changes nothing. "Nothing" has to include the rule's own
        // bookkeeping, or the guarantee is not a guarantee: without this a read-only operator
        // could stamp an error onto a rule, or wipe an existing one off a parent-entity rule
        // they only inherit.
        $rule_dry_run = $force_dry_run || $rule->is_dry_run;

        // Two decisions, not one. This flag governs the rule's own bookkeeping below, so it
        // also asks whether the rule's entity is inert. The per-ticket decision is taken again
        // further down against the ticket's entity, because a recursive rule stored on a
        // parent acts on tickets in several children and each of them owns its own brake --
        // composing the two with `||` here would let a paused parent veto a child that
        // explicitly armed itself.
        $dry_run = $rule_dry_run || $this->isInertFor($rule->entities_id);

        if ($rule->unusable !== []) {
            $report->refused++;

            $messages = [];
            foreach ($rule->unusable as $problem) {
                $messages[] = sprintf(
                    __('Rule "%1$s" was not run: %2$s', 'ticketclock'),
                    $rule->name,
                    $problem,
                );
            }

            $report->errors = array_merge($report->errors, $messages);

            // Kept on the rule, not only in this report. A scheduled run has nobody reading
            // its report, and the file log is not the plugin's audit trail: without this the
            // only way to learn why a rule went quiet is to know the log exists and go
            // looking. The rule's own screen is where somebody will look.
            //
            // A dry run still reports and still counts; it just does not write. Whoever
            // asked for the simulation sees the reason on screen, and the record appears the
            // first time a real run reaches the rule.
            //
            // "Writes nothing" means the plugin's data and its audit trail. Reading a corrupt
            // rule still puts a line in the server error log, because that happens where the
            // row is parsed and before anybody knows what kind of run this is. That is
            // diagnostics about a broken row, not a record of the run.
            if (!$dry_run) {
                Rule::recordRefusal($rule->id, implode(' ', $rule->unusable));
            }

            return $report;
        }

        // A rule that runs is a rule that is no longer broken. Cheap: the UPDATE is guarded
        // on the column being set, so it matches nothing in the normal case. Skipped in a dry
        // run for the same reason as above, and this direction is the worse one to get wrong:
        // clearing is destructive, and a preview must not be able to erase the record of why
        // a rule stopped.
        if (!$dry_run) {
            Rule::clearRefusal($rule->id);
        }

        $matcher = $this->matcherFor($rule);
        if ($matcher === null) {
            $report->errors[] = sprintf(__('No matcher supports rule type "%s".', 'ticketclock'), $rule->type->value);
            return $report;
        }

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
                    // The ticket's entity, not the rule's: pausing a branch has to stop the
                    // tickets in that branch and no others.
                    $this->processCandidate(
                        $rule,
                        $matcher,
                        $context,
                        $candidate,
                        $now,
                        $rule_dry_run || $this->isInertFor($context->entities_id),
                        $report,
                    );
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
                fn(): PreviewRow => $this->buildPreviewRow($context, $deadline, $now, false, 'not_expired', $rule),
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
            $this->actingUserId(),
            false,
        );

        $outcome = $this->executor->run($action_context);

        if ($outcome['refused']) {
            $message = $this->firstError($outcome['results']);
            // A refused first action has made no ticket change, so releasing the occurrence
            // lets cron process it under the automation policy. If an earlier action already
            // ran, retaining a failed claim is safer: re-running the batch could duplicate
            // that side effect.
            $partial = count($outcome['results']) > 1;
            Execution::complete(
                $executions_id,
                $partial ? ExecutionState::Failed : ExecutionState::Skipped,
                $outcome['results'],
                $message,
            );
            $report->errors[] = sprintf('#%d: %s', $fresh->tickets_id, $message);
            if ($partial) {
                $report->failed++;
            } else {
                $report->skipped++;
            }
            $report->notePreview(fn(): PreviewRow => $this->buildPreviewRow(
                $fresh,
                $recheck->deadline ?? $deadline,
                $now,
                false,
                $partial ? 'failed' : 'refused',
                $rule,
            ));
            return;
        }

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
            $this->actingUserId(),
            true,
        );

        $outcome = $this->executor->run($action_context);
        $report->simulated++;

        if (Config::getBool('log_dry_runs', true) && $rule->id > 0) {
            Execution::logDryRun($rule, $context, $deadline, $occurrence_key, $outcome['results']);
        }

        $report->notePreview(
            fn(): PreviewRow => $this->buildPreviewRow($context, $deadline, $now, true, 'would_execute', $rule),
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
     * True when this entity's rules must not touch anything.
     *
     * Two independent switches on purpose: `execution_enabled` is the "is TicketFlow
     * allowed to act at all" master switch, `dry_run` is the "let it run but keep it
     * simulated" switch used while tuning rules. A fresh install has both set to inert.
     *
     * Both are the entity's, inherited from the parent when it defines none, so pausing a
     * branch pauses that branch and nothing else.
     */
    private function isInertFor(int $entities_id): bool
    {
        return !EntityConfig::isExecutionEnabled($entities_id) || EntityConfig::isDryRun($entities_id);
    }

    private function actingUserId(): int
    {
        return $this->actor_users_id ?? Config::getActingUserId();
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
