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

namespace GlpiPlugin\Ticketflow\Engine\Matcher;

use GlpiPlugin\Ticketflow\Engine\MatchResult;
use GlpiPlugin\Ticketflow\Engine\RuleDefinition;
use GlpiPlugin\Ticketflow\Engine\TicketContext;
use GlpiPlugin\Ticketflow\Enum\RuleType;

/**
 * Decides whether a rule applies to a ticket occurrence and when it expires.
 *
 * Matchers are pure: same inputs, same answer, no database, no clock of their own.
 * Adding a rule type means adding a matcher and registering it — nothing else.
 */
interface MatcherInterface
{
    public function supports(RuleType $type): bool;

    /**
     * @param string $now 'Y-m-d H:i:s' — injected so tests and dry runs can pick their own instant
     */
    public function evaluate(RuleDefinition $rule, TicketContext $context, string $now): MatchResult;
}
