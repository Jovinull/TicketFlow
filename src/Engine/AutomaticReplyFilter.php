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

use GlpiPlugin\Ticketclock\Config;

/**
 * Keeps machine-written followups out of the queries that decide who holds a ticket.
 *
 * An out-of-office reply reaches GLPI through the mail collector as an ordinary public
 * followup signed by the requester, which is exactly what the engine looks for. Read as an
 * answer it silences a rule permanently, and silently: the ticket is never chased, never
 * solved, and nothing is logged.
 *
 * Its own class because the escaping is the whole difficulty and it deserves to be tested
 * without an engine around it. Two layers meet in a LIKE pattern and only one of them is the
 * query builder's:
 *
 *  - the builder quotes the value for SQL already, so escaping it first escapes it twice and
 *    a mark carrying an apostrophe -- which is how half of these phrases are written --
 *    matches nothing at all;
 *  - `%` and `_` are wildcards inside the pattern, which the builder neither knows nor cares
 *    about. A mark of `100%` would match everything starting with `100`, and a mark of `%`
 *    on its own would match every followup ever written, making the engine read answered
 *    tickets as unanswered and carry on acting on them.
 *
 * Both failures are quiet. Nothing errors; the wrong tickets are simply acted on, or the
 * right ones are not.
 */
final class AutomaticReplyFilter
{
    /**
     * Adds one exclusion per configured mark to a followup query.
     *
     * Applied in SQL rather than afterwards, because the queries involved pick and order
     * rows: anything removed later has already influenced the answer.
     *
     * @param array<int|string, mixed> $where modified in place
     */
    public static function apply(array &$where): void
    {
        foreach (Config::ignoredMessageMarks() as $mark) {
            $where[] = ['NOT' => ['content' => ['LIKE', '%' . self::likeLiteral($mark) . '%']]];
        }
    }

    /**
     * Escapes LIKE's own syntax so a mark means the characters it contains.
     *
     * Deliberately not `$DB->escape()`: that is the layer the query builder already applies.
     * This one is about `%`, `_` and the escape character itself, which the builder leaves
     * alone because at the SQL level they are ordinary text.
     */
    public static function likeLiteral(string $mark): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $mark);
    }
}
