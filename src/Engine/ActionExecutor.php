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

use GlpiPlugin\Ticketflow\Engine\Action\ActionInterface;
use GlpiPlugin\Ticketflow\Enum\ActionType;
use Throwable;

/**
 * Runs a rule's actions in order and reports exactly what happened.
 *
 * There is no transaction around the batch on purpose. The actions call core APIs
 * (ITILFollowup, ITILSolution, Ticket) which trigger notifications, history entries and
 * other plugins' hooks; rolling a DB transaction back would undo the rows but not those
 * side effects, leaving a state that is *less* consistent than an honestly recorded
 * partial run. So instead: stop at the first failure, keep every ActionResult, and mark
 * the execution `failed` with the partial results visible in the log.
 */
final readonly class ActionExecutor
{
    /** @var list<ActionInterface> */
    private array $actions;

    /**
     * @param list<ActionInterface> $actions
     */
    public function __construct(array $actions)
    {
        $this->actions = array_values($actions);
    }

    public function handlerFor(ActionType $type): ?ActionInterface
    {
        foreach ($this->actions as $action) {
            if ($action->supports($type)) {
                return $action;
            }
        }

        return null;
    }

    /**
     * @return array{results: list<ActionResult>, success: bool}
     */
    public function run(ActionContext $context): array
    {
        $results = [];
        $success = true;

        foreach ($context->rule->orderedActions() as $definition) {
            $handler = $this->handlerFor($definition->type);
            if ($handler === null) {
                $results[] = ActionResult::failure(
                    $definition->type,
                    sprintf(__('No handler is registered for action "%s".', 'ticketflow'), $definition->type->value),
                );
                $success = false;
                break;
            }

            try {
                $result = $handler->execute($definition, $context);
            } catch (Throwable $e) {
                // An action must never take the whole cron down.
                $result = ActionResult::failure($definition->type, $e->getMessage());
            }

            $results[] = $result;

            if (!$result->success) {
                $success = false;
                break;
            }
        }

        return ['results' => $results, 'success' => $success];
    }

    /**
     * Human-readable summary of what a rule does, for the preview and the dry run.
     *
     * @return list<string>
     */
    public function describe(RuleDefinition $rule): array
    {
        $out = [];
        foreach ($rule->orderedActions() as $definition) {
            $handler = $this->handlerFor($definition->type);
            $out[] = $handler !== null ? $handler->describe($definition) : $definition->type->value;
        }

        return $out;
    }
}
