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

/**
 * One approval request of a ticket.
 *
 * TicketFlow's V1 semantics treat each waiting request as its own clock: a ticket with
 * three approvers has three independent deadlines. See docs/rules.md and ADR-005.
 */
final readonly class ValidationContext
{
    public function __construct(
        public int $id,
        public int $status,
        public string $submission_date,
        public ?string $validation_date,
        public string $itemtype_target,
        public int $items_id_target,
        public int $validationsteps_id,
    ) {}

    /**
     * @param array<string, mixed> $row A glpi_ticketvalidations row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (int) ($row['status'] ?? 0),
            (string) ($row['submission_date'] ?? ''),
            isset($row['validation_date']) ? (string) $row['validation_date'] : null,
            (string) ($row['itemtype_target'] ?? ''),
            (int) ($row['items_id_target'] ?? 0),
            (int) ($row['itils_validationsteps_id'] ?? 0),
        );
    }
}
