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

namespace GlpiPlugin\Ticketclock\Tests\Unit;

use GlpiPlugin\Ticketclock\Engine\Candidate;
use PHPUnit\Framework\TestCase;

final class CandidateTest extends TestCase
{
    /**
     * Batching pages on this value. A pending rule advances through tickets; an approval
     * rule advances through approval requests, since one ticket can yield several.
     */
    public function testPendingCandidatesPageOnTheTicketId(): void
    {
        self::assertSame(7657, (new Candidate(7657, 0))->cursor());
    }

    public function testApprovalCandidatesPageOnTheValidationId(): void
    {
        self::assertSame(42, (new Candidate(7657, 42))->cursor());
    }

    /**
     * Two approval requests on the same ticket must not collapse to one page position,
     * or the second one would never be reached.
     */
    public function testTwoApprovalsOnOneTicketHaveDistinctCursors(): void
    {
        self::assertNotSame(
            (new Candidate(7657, 42))->cursor(),
            (new Candidate(7657, 43))->cursor(),
        );
    }
}
