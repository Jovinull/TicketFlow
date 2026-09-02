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

use Entity;
use GlpiPlugin\Ticketclock\Config;
use GlpiPlugin\Ticketclock\Engine\Action\AddFollowupAction;
use GlpiPlugin\Ticketclock\Engine\TicketContextResolver;
use GlpiPlugin\Ticketclock\Inspector;
use ITILFollowup;
use PHPUnit\Framework\TestCase;
use Ticket;

/**
 * The mark counts on the diagnostics page have to be the sample they claim to be.
 *
 * A mark decides which followups are read as somebody answering, and getting it wrong fails
 * quietly both ways. The screen exists so an administrator can judge a phrase before trusting
 * it, which needs the numbers to mean something precise -- not that they replay the engine,
 * but that what is claimed about them holds. Three things would break that, and they are what
 * these tests hold:
 *
 *  - counting with a different escaping than {@see AutomaticReplyFilter::likeLiteral()}, so a
 *    mark containing `%` or `_` is measured as a wildcard and reported as healthy. This is the
 *    one part the screen calls exact;
 *  - counting outside the reader's entities, which is both a disclosure and a wrong answer;
 *  - drifting from the stated population, which is the one both engine queries share. Private
 *    followups belong to it and never decide the latest message, and the last test below pins
 *    both halves of that so neither the screen nor the engine can move without being noticed.
 */
final class AutomaticReplyMarksTest extends TestCase
{
    private string $suffix = '';
    private int $mine = 0;
    private int $theirs = 0;
    private int $my_ticket = 0;
    private int $their_ticket = 0;
    private string $marks_before = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->suffix       = uniqid();
        $this->marks_before = Config::get('ignored_message_marks');
        $this->seeEverything();

        $this->mine   = $this->createEntity('TicketFlow marks mine ');
        $this->theirs = $this->createEntity('TicketFlow marks theirs ');

        $this->my_ticket    = $this->createTicket($this->mine);
        $this->their_ticket = $this->createTicket($this->theirs);
    }

    protected function tearDown(): void
    {
        $this->seeEverything();
        Config::set(['ignored_message_marks' => $this->marks_before]);

        /** @var \DBmysql $DB */
        global $DB;
        foreach ([$this->my_ticket, $this->their_ticket] as $tickets_id) {
            if ($tickets_id > 0) {
                $DB->delete(ITILFollowup::getTable(), ['itemtype' => Ticket::class, 'items_id' => $tickets_id]);
                (new Ticket())->delete(['id' => $tickets_id], true);
            }
        }
        foreach ([$this->mine, $this->theirs] as $entities_id) {
            if ($entities_id > 0) {
                (new Entity())->delete(['id' => $entities_id], true);
            }
        }

        parent::tearDown();
    }

    /**
     * The one that matters: `%` inside a mark is text, not a wildcard.
     *
     * `100%` is a phrase a real gateway could use, and it is also the shape that breaks. Left
     * unescaped the pattern becomes `%100%%`, which matches anything containing `100` -- so
     * "1000 units delivered" counts, the mark looks like it is catching plenty, and the
     * engine goes on discarding genuine answers. The count must see one followup here, not two.
     */
    public function testAMarkContainingAPercentSignIsCountedAsText(): void
    {
        $this->followup($this->my_ticket, 'We are at 100% capacity this week.');
        $this->followup($this->my_ticket, '1000 units delivered, thanks.');

        Config::set(['ignored_message_marks' => '100%']);

        self::assertSame(1, $this->matchesFor('100%'));
    }

    /** Same reasoning for LIKE's other wildcard, which matches exactly one character. */
    public function testAMarkContainingAnUnderscoreIsCountedAsText(): void
    {
        $this->followup($this->my_ticket, 'Ticket auto_reply generated by the gateway.');
        $this->followup($this->my_ticket, 'Ticket autoXreply generated by the gateway.');

        Config::set(['ignored_message_marks' => 'auto_reply']);

        self::assertSame(1, $this->matchesFor('auto_reply'));
    }

    public function testFollowupsFromAnotherTenantAreNeitherCountedNorMatched(): void
    {
        $this->followup($this->my_ticket, 'Out of Office until Monday.');
        $this->followup($this->their_ticket, 'Out of Office until Monday.');
        $this->followup($this->their_ticket, 'A perfectly ordinary answer.');

        Config::set(['ignored_message_marks' => 'Out of Office']);

        $this->seeOnly($this->mine);
        $marks = Inspector::report()['marks'];

        self::assertSame(1, $marks['total'], 'the window counted followups outside the reader\'s entities');
        self::assertSame(1, $marks['marks'][0]['matches']);
    }

    /**
     * TicketFlow's own followups are not somebody answering, and the engine excludes them at
     * both call sites. Counting them would inflate the denominator with messages no mark is
     * ever asked about, and every share on the screen would read low.
     */
    public function testTicketFlowsOwnFollowupsAreNotPartOfTheWindow(): void
    {
        $this->followup($this->my_ticket, 'A genuine answer.');
        $this->followup($this->my_ticket, 'Please answer. ' . AddFollowupAction::MARKER);

        Config::set(['ignored_message_marks' => 'Out of Office']);

        $this->seeOnly($this->mine);

        self::assertSame(1, Inspector::report()['marks']['total']);
    }

    /**
     * The signal the screen exists to give: a mark nobody's gateway writes reports zero, next
     * to a window that is not empty. Zero out of zero would say nothing at all.
     */
    public function testAMarkThatMatchesNothingIsReportedAsZeroAgainstANonEmptyWindow(): void
    {
        $this->followup($this->my_ticket, 'A genuine answer.');

        Config::set(['ignored_message_marks' => "Out of Office\nAbwesenheitsnotiz"]);

        $this->seeOnly($this->mine);
        $marks = Inspector::report()['marks'];

        self::assertSame(1, $marks['total']);
        self::assertSame(0, $marks['marks'][0]['matches']);
        self::assertSame(0.0, $marks['marks'][0]['share']);
    }

    /**
     * Older traffic is out of scope on purpose: a gateway that changed its wording months ago
     * would otherwise keep a dead mark looking healthy forever.
     */
    public function testFollowupsOlderThanTheWindowAreNotCounted(): void
    {
        $recent = $this->followup($this->my_ticket, 'Out of Office until Monday.');
        $old    = $this->followup($this->my_ticket, 'Out of Office until Monday.');

        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(
            ITILFollowup::getTable(),
            ['date' => date('Y-m-d H:i:s', strtotime('-' . (Inspector::MARK_SAMPLE_DAYS + 5) . ' days'))],
            ['id' => $old],
        );
        self::assertGreaterThan(0, $recent);

        Config::set(['ignored_message_marks' => 'Out of Office']);

        $this->seeOnly($this->mine);
        $marks = Inspector::report()['marks'];

        self::assertSame(1, $marks['total']);
        self::assertSame(1, $marks['marks'][0]['matches']);
    }

    public function testTheShareIsExpressedAgainstTheWindow(): void
    {
        $this->followup($this->my_ticket, 'Out of Office until Monday.');
        $this->followup($this->my_ticket, 'A genuine answer.');

        Config::set(['ignored_message_marks' => 'Out of Office']);

        $this->seeOnly($this->mine);
        $marks = Inspector::report()['marks'];

        self::assertSame(2, $marks['total']);
        self::assertSame(50.0, $marks['marks'][0]['share']);
    }

    /**
     * A private followup counts in the sample and does not decide the latest message.
     *
     * The two are different populations and the screen must not be read as a replay of the
     * engine. `loadLastMessages()` drops `is_private = 1`, so a mark appearing only in internal
     * notes can show matches here while never influencing who is holding the ticket.
     *
     * Pinned from both ends on purpose: the private note is the most recent thing written on
     * the ticket, the sample counts it, and the engine's latest message is still the older
     * public one. Asserting only the count would leave the second half of the claim untested,
     * and it is the half the wording on the screen depends on.
     */
    public function testAPrivateFollowupCountsInTheSampleButIsNotTheLatestMessage(): void
    {
        $answer = $this->followup($this->my_ticket, 'A genuine answer.');
        $marked = $this->followup($this->my_ticket, 'Out of Office until Monday.', private: true);
        // A private note carrying no mark, and the most recent thing on the ticket. Without it
        // the engine would still answer `$answer` even with privacy ignored, because the marked
        // note is excluded by its mark -- and the second half of this test would prove nothing.
        $plain = $this->followup($this->my_ticket, 'Internal note, nothing to send out.', private: true);

        /** @var \DBmysql $DB */
        global $DB;
        foreach ([$answer => '-3 hours', $marked => '-2 hours', $plain => '-1 hour'] as $id => $when) {
            $DB->update(ITILFollowup::getTable(), ['date' => date('Y-m-d H:i:s', strtotime($when))], ['id' => $id]);
        }

        Config::set(['ignored_message_marks' => 'Out of Office']);
        $this->seeOnly($this->mine);

        $marks = Inspector::report()['marks'];
        self::assertSame(3, $marks['total'], 'the sample is the population both engine queries share');
        self::assertSame(1, $marks['marks'][0]['matches'], 'the private note carries the mark and is counted');

        $context = (new TicketContextResolver())->resolveOne($this->my_ticket);
        self::assertNotNull($context);
        self::assertNotNull($context->last_message);
        self::assertSame(
            $answer,
            $context->last_message->itilfollowups_id,
            'private notes are the two most recent followups, but the latest-message path sees neither',
        );
    }

    // -----------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------

    /** The match count for one mark, read from the report as the screen reads it. */
    private function matchesFor(string $mark): int
    {
        $this->seeOnly($this->mine);

        foreach (Inspector::report()['marks']['marks'] as $row) {
            if ($row['mark'] === $mark) {
                return (int) $row['matches'];
            }
        }

        self::fail(sprintf('the report carries no row for the mark "%s"', $mark));
    }

    private function followup(int $tickets_id, string $content, bool $private = false): int
    {
        $id = (int) (new ITILFollowup())->add([
            'itemtype'   => Ticket::class,
            'items_id'   => $tickets_id,
            'content'    => $content,
            'is_private' => $private ? 1 : 0,
        ]);
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function createEntity(string $name): int
    {
        $id = (int) (new Entity())->add(['name' => $name . $this->suffix, 'entities_id' => 0]);
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function createTicket(int $entities_id): int
    {
        $id = (int) (new Ticket())->add([
            'name'        => 'TicketFlow marks ticket ' . $this->suffix,
            'content'     => 'body',
            'entities_id' => $entities_id,
            'status'      => Ticket::INCOMING,
        ]);
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function seeOnly(int $entities_id): void
    {
        unset($_SESSION['glpishowallentities']);
        $_SESSION['glpiactiveentities']          = [$entities_id];
        $_SESSION['glpiactive_entity']           = $entities_id;
        $_SESSION['glpiactiveentities_string']   = "'" . $entities_id . "'";
        $_SESSION['glpiactive_entity_recursive'] = false;
    }

    private function seeEverything(): void
    {
        $_SESSION['glpishowallentities']         = 1;
        $_SESSION['glpiactiveentities']          = [0];
        $_SESSION['glpiactive_entity']           = 0;
        $_SESSION['glpiactiveentities_string']   = "'0'";
        $_SESSION['glpiactive_entity_recursive'] = true;
    }
}
