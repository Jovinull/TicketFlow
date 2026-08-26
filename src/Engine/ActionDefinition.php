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

use GlpiPlugin\Ticketclock\Enum\ActionType;

use function Safe\json_decode;

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
     * @param array<string, mixed> $row A glpi_plugin_ticketclock_ruleactions row.
     */
    public static function fromRow(array $row): ?self
    {
        $type = ActionType::tryFromString(isset($row['action_type']) ? (string) $row['action_type'] : null);
        if ($type === null) {
            return null;
        }

        $params = [];
        if (isset($row['params']) && is_string($row['params']) && $row['params'] !== '') {
            $decoded = json_decode($row['params'], true);
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }

        return new self(
            (int) ($row['id'] ?? 0),
            $type,
            (int) ($row['ranking'] ?? 0),
            $params,
        );
    }
}
