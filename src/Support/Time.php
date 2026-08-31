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

namespace GlpiPlugin\Ticketclock\Support;

use RuntimeException;

/**
 * `strtotime()` that refuses to answer when it does not know.
 *
 * The whole plugin is arithmetic on dates, and PHP's `strtotime()` signals failure by
 * returning `false`, which silently becomes `0` the moment it is compared or added to.
 * A deadline computed from the Unix epoch looks like a deadline that expired decades ago,
 * so an unreadable date would not raise an error: it would close somebody's ticket.
 *
 * This used to be `thecodingmachine/safe`. That library was declared as a dev dependency
 * while fourteen runtime files imported from it, and the plugin only worked because GLPI
 * core happens to require the same package -- so the archive shipped without it and ran on
 * the host application's copy. Depending on a transitive dependency of the host is a trap
 * that springs the day the host drops it. GLPI's pre-publication security review flagged
 * the misdeclaration; owning the four lines removes the dependency instead of patching how
 * it is declared.
 */
final class Time
{
    /**
     * @throws RuntimeException when the string is not a date this installation can read
     */
    public static function stamp(string $date): int
    {
        $time = strtotime($date);
        if ($time === false) {
            throw new RuntimeException(sprintf('"%s" is not a date TicketFlow can read.', $date));
        }

        return $time;
    }

    /**
     * The same guarantee, for the callers that want a formatted string back.
     *
     * @throws RuntimeException when the string is not a date this installation can read
     */
    public static function at(string $date, string $format = 'Y-m-d H:i:s'): string
    {
        return date($format, self::stamp($date));
    }
}
