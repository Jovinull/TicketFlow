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

namespace GlpiPlugin\Ticketclock\Tests\Integration;

use GlpiPlugin\Ticketclock\Config;
use GlpiPlugin\Ticketclock\Engine\AutomaticReplyFilter;
use ITILFollowup;
use PHPUnit\Framework\TestCase;
use Ticket;

/**
 * The automatic-reply marks must match as substrings, exactly as documented.
 *
 * Tested against real rows rather than through the engine on purpose. What can go wrong here
 * is escaping, and escaping either matches a row or does not; routing that through deadline
 * arithmetic and a status matrix would only add ways for the test to be right for the wrong
 * reason. PendingInactivityFlowTest covers the end-to-end behaviour separately.
 */
final class AutomaticReplyFilterTest extends TestCase
{
    /** @var list<int> */
    private array $followups = [];
    private string $marks_before = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->marks_before = Config::get('ignored_message_marks');
    }

    protected function tearDown(): void
    {
        foreach ($this->followups as $id) {
            (new ITILFollowup())->delete(['id' => $id], true);
        }
        Config::set(['ignored_message_marks' => $this->marks_before]);
        Config::reload();

        parent::tearDown();
    }

    /**
     * An apostrophe is not exotic: half the phrases people configure carry one. This is the
     * case `$DB->escape()` broke, by escaping a value the query builder escapes again.
     */
    public function testAMarkContainingAnApostropheMatches(): void
    {
        self::assertTrue($this->excluded("I'm away", "I'm away until Monday."));
    }

    public function testAMarkContainingABackslashMatches(): void
    {
        self::assertTrue($this->excluded('C:\\away', 'Set by C:\\away on the gateway.'));
    }

    /**
     * `%` is a LIKE wildcard. Configured as a mark it has to mean the character, or a
     * followup that merely starts the same way is thrown away as machine noise.
     */
    public function testAPercentSignIsLiteral(): void
    {
        self::assertTrue($this->excluded('100%', 'Progress 100% — out for the week.'));
        self::assertFalse($this->excluded('100%', 'We have 1000 items to check.'));
    }

    public function testAnUnderscoreIsLiteral(): void
    {
        self::assertTrue($this->excluded('auto_reply', 'Tagged auto_reply by the gateway.'));
        self::assertFalse($this->excluded('auto_reply', 'autoxreply is not the same thing.'));
    }

    /**
     * The dangerous shape. Treated as a wildcard, a lone `%` excludes every followup ever
     * written, so every answered ticket reads as unanswered and the engine keeps acting on
     * tickets that already had a reply.
     */
    public function testALonePercentSignDoesNotSwallowEveryAnswer(): void
    {
        self::assertFalse($this->excluded('%', 'Here is the information you asked for.'));
    }

    /**
     * Not a guarantee the code makes on its own: it follows from the collation GLPI gives
     * the followup content. Asserted so the README's wording stays true, and so a schema
     * change that makes it case-sensitive fails here rather than in somebody's instance.
     */
    public function testMatchingIgnoresCase(): void
    {
        self::assertTrue($this->excluded('out of office', 'OUT OF OFFICE until Friday.'));
    }

    public function testAnEmptyListExcludesNothing(): void
    {
        self::assertFalse($this->excluded('', 'Automatic reply: I am away.'));
    }

    /**
     * Writes one followup, applies the filter as the resolver does, and reports whether the
     * row was thrown away.
     */
    private function excluded(string $mark, string $content): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        Config::set(['ignored_message_marks' => $mark]);
        Config::reload();

        $followups_id = (int) (new ITILFollowup())->add([
            'itemtype'   => Ticket::class,
            'items_id'   => 1,
            'content'    => $content,
            'users_id'   => 2,
            'is_private' => 0,
        ]);
        self::assertGreaterThan(0, $followups_id);
        $this->followups[] = $followups_id;

        $where = ['id' => $followups_id];
        AutomaticReplyFilter::apply($where);

        return count(iterator_to_array($DB->request([
            'FROM'  => ITILFollowup::getTable(),
            'WHERE' => $where,
        ]))) === 0;
    }
}
