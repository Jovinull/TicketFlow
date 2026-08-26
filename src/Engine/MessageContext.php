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
 * The most recent human message on a ticket, with enough about its author to decide whose
 * turn it is.
 *
 * Only the *last* message is carried, not the timeline: the rule that needs this asks
 * "was the last word ours?", which is a question about one row. Loading a whole timeline
 * per ticket to answer it would be the expensive way to get the same answer.
 */
final readonly class MessageContext
{
    /**
     * @param list<int> $author_groups groups the author belongs to
     */
    public function __construct(
        public int $itilfollowups_id,
        public string $date,
        public int $users_id,
        public array $author_groups,
    ) {}

    /**
     * @param list<int> $groups_id
     */
    public function authorBelongsToAnyOf(array $groups_id): bool
    {
        if ($groups_id === [] || $this->users_id <= 0) {
            return false;
        }

        return array_intersect($this->author_groups, $groups_id) !== [];
    }
}
