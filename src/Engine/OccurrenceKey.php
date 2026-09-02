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

/**
 * Identifies one *cycle* of a rule on a ticket.
 *
 * Idempotency in TicketFlow is per occurrence, not per (rule, ticket) forever: a ticket
 * that leaves pending and comes back must be able to fire again. Embedding the cycle's
 * start timestamp in the key gives exactly that, with no extra bookkeeping — the same
 * cycle always produces the same key, a new cycle always produces a different one.
 *
 * The key is stored in a column of limited width and participates in a UNIQUE index, so
 * it is kept short and ASCII.
 */
final class OccurrenceKey
{
    public const MAX_LENGTH = 100;

    public static function forPendingInactivity(int $tickets_id, string $started_at): string
    {
        return self::normalize(sprintf('pi:%d:%s', $tickets_id, self::compactDate($started_at)));
    }

    public static function forPendingApproval(int $validations_id, string $submitted_at): string
    {
        return self::normalize(sprintf('pa:%d:%s', $validations_id, self::compactDate($submitted_at)));
    }

    /**
     * "The group spoke last and nobody came back" — keyed on the moment the clock started.
     *
     * Unlike the pending-state key, this one is anchored to the reference itself, because
     * here the reference *is* the cycle: a newer message from the group, or a status
     * change, genuinely starts a new waiting period and deserves a new occurrence.
     */
    public static function forGroupSilence(int $tickets_id, string $reference_at): string
    {
        return self::normalize(sprintf('gs:%d:%s', $tickets_id, self::compactDate($reference_at)));
    }

    /**
     * A date stored on the ticket, keyed on which date it was.
     *
     * The field is part of the key on purpose. Without it, switching a rule from one column to
     * another would silently reuse the claim of the previous configuration whenever the two
     * columns held the same instant -- the rule would look like it had already run for a cycle
     * it never saw.
     *
     * The column name is short and comes from {@see ReferenceField}, so it cannot push the key
     * past its width on its own.
     */
    public static function forTicketDate(int $tickets_id, string $field, string $reference_at): string
    {
        return self::normalize(sprintf('td:%d:%s:%s', $tickets_id, $field, self::compactDate($reference_at)));
    }

    private static function compactDate(string $date): string
    {
        // Both natives on purpose: an occurrence key must always be producible. A date we
        // cannot parse degrades to its digits rather than throwing and aborting the run.
        // @phpstan-ignore theCodingMachineSafe.function
        $time = \strtotime($date);

        // @phpstan-ignore theCodingMachineSafe.function
        return $time === false ? \preg_replace('/[^0-9]/', '', $date) ?? '0' : date('YmdHis', $time);
    }

    private static function normalize(string $key): string
    {
        return substr($key, 0, self::MAX_LENGTH);
    }
}
