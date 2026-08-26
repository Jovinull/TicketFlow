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

use GlpiPlugin\Ticketclock\Calendar\Deadline;

/**
 * What a matcher concluded about one (rule, ticket occurrence) pair.
 *
 * Three outcomes matter: the conditions do not hold, they hold but the clock is still
 * running, or they hold and the deadline has passed.
 */
final readonly class MatchResult
{
    private function __construct(
        public bool $matched,
        public bool $expired,
        public ?Deadline $deadline,
        public ?string $occurrence_key,
        public string $reason,
    ) {}

    public static function noMatch(string $reason): self
    {
        return new self(false, false, null, null, $reason);
    }

    /** Conditions hold, deadline not reached yet. */
    public static function running(Deadline $deadline, string $occurrence_key): self
    {
        return new self(true, false, $deadline, $occurrence_key, 'not_expired');
    }

    /** Conditions hold and the deadline has passed. */
    public static function expired(Deadline $deadline, string $occurrence_key): self
    {
        return new self(true, true, $deadline, $occurrence_key, 'expired');
    }
}
