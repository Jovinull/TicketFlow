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

/**
 * The outcome of one action, kept verbatim on the execution row.
 *
 * Partial execution is never hidden: if the second of three actions fails, the audit row
 * shows one success, one failure and one action that never ran.
 */
final readonly class ActionResult
{
    /**
     * @param array<string, mixed> $data extra facts worth auditing (created ids, old/new status…)
     */
    private function __construct(
        public ActionType $type,
        public bool $success,
        public string $message,
        public array $data = [],
        public bool $simulated = false,
        /** True when the logged-in operator was not allowed to attempt the action. */
        public bool $refused = false,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function success(ActionType $type, string $message = '', array $data = []): self
    {
        return new self($type, true, $message, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function failure(ActionType $type, string $message, array $data = []): self
    {
        return new self($type, false, $message, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function simulated(ActionType $type, string $message = '', array $data = []): self
    {
        return new self($type, true, $message, $data, true);
    }

    /**
     * The action was not attempted because the manual operator lacks the corresponding right.
     *
     * This is deliberately distinct from a failed action. If it is the first result, the
     * engine releases the occurrence claim so the scheduler can process it under the
     * automation policy. A refusal after an earlier action remains failed to avoid replaying
     * that earlier side effect.
     *
     * @param array<string, mixed> $data
     */
    public static function refused(ActionType $type, string $message, array $data = []): self
    {
        return new self($type, false, $message, $data, false, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type'      => $this->type->value,
            'success'   => $this->success,
            'simulated' => $this->simulated,
            'refused'   => $this->refused,
            'message'   => $this->message,
            'data'      => $this->data,
        ];
    }
}
