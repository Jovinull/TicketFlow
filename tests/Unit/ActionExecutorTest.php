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

use GlpiPlugin\Ticketclock\Engine\Action\ActionInterface;
use GlpiPlugin\Ticketclock\Engine\ActionContext;
use GlpiPlugin\Ticketclock\Engine\ActionDefinition;
use GlpiPlugin\Ticketclock\Engine\ActionExecutor;
use GlpiPlugin\Ticketclock\Engine\ActionResult;
use GlpiPlugin\Ticketclock\Engine\MessageRenderer;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use GlpiPlugin\Ticketclock\Enum\DelayUnit;
use GlpiPlugin\Ticketclock\Calendar\Deadline;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Ordering, partial failure and error containment — the parts of action execution that
 * do not need GLPI to be meaningful.
 */
final class ActionExecutorTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function spy(ActionType $type, bool $succeeds = true, bool $throws = false): ActionInterface
    {
        $calls = &$this->calls;

        return new class ($type, $succeeds, $throws, $calls) implements ActionInterface {
            /**
             * @param list<string> $calls
             */
            public function __construct(
                private readonly ActionType $type,
                private readonly bool $succeeds,
                private readonly bool $throws,
                private array &$calls,
            ) {}

            public function supports(ActionType $type): bool
            {
                return $type === $this->type;
            }

            public function describe(ActionDefinition $definition): string
            {
                return 'spy:' . $this->type->value;
            }

            public function execute(ActionDefinition $definition, ActionContext $context): ActionResult
            {
                $this->calls[] = $this->type->value;

                if ($this->throws) {
                    throw new RuntimeException('boom');
                }

                if ($context->dry_run) {
                    return ActionResult::simulated($this->type, 'simulated');
                }

                return $this->succeeds
                    ? ActionResult::success($this->type, 'ok')
                    : ActionResult::failure($this->type, 'nope');
            }
        };
    }

    private function context(ActionDefinition ...$actions): ActionContext
    {
        $rule = DomainFactory::rule(actions: array_values($actions));

        $deadline = new Deadline(
            '2026-08-03 10:00:00',
            '2026-08-10 10:00:00',
            5,
            DelayUnit::BusinessDays,
            CalendarFactory::OFFICE,
            'Office',
            false,
        );

        return new ActionContext($rule, DomainFactory::ticket(), $deadline, new MessageRenderer(), 42, false);
    }

    public function testActionsRunInRankingOrderNotInsertionOrder(): void
    {
        $executor = new ActionExecutor([
            $this->spy(ActionType::AddSolution),
            $this->spy(ActionType::AddFollowup),
        ]);

        $context = $this->context(
            new ActionDefinition(2, ActionType::AddSolution, 20),
            new ActionDefinition(1, ActionType::AddFollowup, 10),
        );

        $outcome = $executor->run($context);

        self::assertTrue($outcome['success']);
        self::assertSame(['add_followup', 'add_solution'], $this->calls);
    }

    public function testAFailureStopsTheRemainingActionsAndIsReported(): void
    {
        $executor = new ActionExecutor([
            $this->spy(ActionType::AddFollowup, succeeds: false),
            $this->spy(ActionType::AddSolution),
        ]);

        $outcome = $executor->run($this->context(
            new ActionDefinition(1, ActionType::AddFollowup, 10),
            new ActionDefinition(2, ActionType::AddSolution, 20),
        ));

        self::assertFalse($outcome['success']);
        self::assertCount(1, $outcome['results'], 'the solution must not run after the message failed');
        self::assertSame(['add_followup'], $this->calls);
        self::assertSame('nope', $outcome['results'][0]->message);
    }

    /**
     * A throwing action must be recorded, not propagated: one bad ticket cannot be allowed
     * to abort a cron run that still has hundreds of tickets to go.
     */
    public function testAThrownExceptionBecomesAFailedResult(): void
    {
        $executor = new ActionExecutor([$this->spy(ActionType::AddFollowup, throws: true)]);

        $outcome = $executor->run($this->context(new ActionDefinition(1, ActionType::AddFollowup, 10)));

        self::assertFalse($outcome['success']);
        self::assertSame('boom', $outcome['results'][0]->message);
    }

    public function testAnUnregisteredActionTypeIsAFailureNotACrash(): void
    {
        $executor = new ActionExecutor([]);

        $outcome = $executor->run($this->context(new ActionDefinition(1, ActionType::CloseTicket, 10)));

        self::assertFalse($outcome['success']);
        self::assertCount(1, $outcome['results']);
    }

    public function testDryRunPropagatesToEveryAction(): void
    {
        $executor = new ActionExecutor([
            $this->spy(ActionType::AddFollowup),
            $this->spy(ActionType::AddSolution),
        ]);

        $context = $this->context(
            new ActionDefinition(1, ActionType::AddFollowup, 10),
            new ActionDefinition(2, ActionType::AddSolution, 20),
        );

        $dry = new ActionContext(
            $context->rule,
            $context->ticket,
            $context->deadline,
            $context->renderer,
            $context->actor_users_id,
            true,
        );

        $outcome = $executor->run($dry);

        self::assertTrue($outcome['success']);
        foreach ($outcome['results'] as $result) {
            self::assertTrue($result->simulated);
        }
    }

    public function testDescribeProducesOneLinePerAction(): void
    {
        $executor = new ActionExecutor([
            $this->spy(ActionType::AddFollowup),
            $this->spy(ActionType::AddSolution),
        ]);

        $rule = DomainFactory::rule(actions: [
            new ActionDefinition(2, ActionType::AddSolution, 20),
            new ActionDefinition(1, ActionType::AddFollowup, 10),
        ]);

        self::assertSame(['spy:add_followup', 'spy:add_solution'], $executor->describe($rule));
    }
}
