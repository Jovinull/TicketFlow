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

use GlpiPlugin\Ticketflow\Enum\ActionType;

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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type'      => $this->type->value,
            'success'   => $this->success,
            'simulated' => $this->simulated,
            'message'   => $this->message,
            'data'      => $this->data,
        ];
    }
}
