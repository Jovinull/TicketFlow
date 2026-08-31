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

use Glpi\Error\ErrorHandler;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use RuntimeException;

/**
 * One configured action of a rule, decoupled from its database row.
 */
final readonly class ActionDefinition
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public int $id,
        public ActionType $type,
        public int $ranking,
        public array $params = [],
    ) {}

    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    public function stringParam(string $name, string $default = ''): string
    {
        $value = $this->params[$name] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function intParam(string $name, int $default = 0): int
    {
        $value = $this->params[$name] ?? $default;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function boolParam(string $name, bool $default = false): bool
    {
        $value = $this->params[$name] ?? null;

        return $value === null ? $default : (bool) $value;
    }

    /**
     * @param array<string, mixed> $row     A glpi_plugin_ticketclock_ruleactions row.
     * @param string|null          $problem set when the row cannot be turned into an action,
     *                                      so the caller can decide what that means. The rule
     *                                      engine refuses the whole rule; the rule form
     *                                      ignores it, because a corrupt row is precisely
     *                                      what somebody needs to open the form to fix.
     */
    public static function fromRow(array $row, ?string &$problem = null): ?self
    {
        $problem = null;
        $id      = (int) ($row['id'] ?? 0);

        $type = ActionType::tryFromString(isset($row['action_type']) ? (string) $row['action_type'] : null);
        if ($type === null) {
            // Treated exactly like unreadable parameters, because the outcome is the same: an
            // action the administrator configured that this code will not carry out. A rule
            // reading "add a followup, then close" would quietly become "add a followup".
            $problem = sprintf(
                __('Action #%1$d has an action type this version does not know: "%2$s".', 'ticketclock'),
                $id,
                (string) ($row['action_type'] ?? ''),
            );

            return null;
        }

        $raw_params = $row['params'] ?? null;
        if (!is_string($raw_params) || $raw_params === '') {
            $problem = self::reportInvalidParameters($row, 'missing JSON object');
            return null;
        }

        $params = json_decode($raw_params, true);
        if (!is_array($params) || json_last_error() !== JSON_ERROR_NONE) {
            $problem = self::reportInvalidParameters($row, json_last_error_msg());
            return null;
        }

        return new self(
            (int) ($row['id'] ?? 0),
            $type,
            (int) ($row['ranking'] ?? 0),
            $params,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return string the message handed back to the caller, so the same words reach both the
     *                server log and whoever is looking at the run
     */
    private static function reportInvalidParameters(array $row, string $reason): string
    {
        $message = sprintf(
            __('Action #%1$d has unreadable stored parameters: %2$s.', 'ticketclock'),
            (int) ($row['id'] ?? 0),
            $reason,
        );

        // Logged as well as reported. The run report tells whoever triggered the run; the
        // server log is what somebody has weeks later when they are working out why a rule
        // stopped. Logging must not raise: a corrupt row written by a privileged user is not
        // a reason to take down every remaining rule in the pass. GLPI has this logger in
        // production; the error_log fallback keeps the GLPI-free unit harness usable.
        if (class_exists(ErrorHandler::class)) {
            ErrorHandler::logCaughtException(new RuntimeException($message));

            return $message;
        }

        error_log($message);

        return $message;
    }
}
