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

namespace GlpiPlugin\Ticketflow\Tests\Unit;

use GlpiPlugin\Ticketflow\Engine\MessageRenderer;
use PHPUnit\Framework\TestCase;

final class MessageRendererTest extends TestCase
{
    private function renderer(): MessageRenderer
    {
        return new MessageRenderer([
            'ticket.id'   => 7657,
            'ticket.name' => 'Printer <b>on fire</b>',
            'rule.name'   => 'Dev — no answer',
            'deadline'    => '2026-08-10 10:00:00',
            'group.name'  => 'the target group',
        ]);
    }

    public function testReplacesKnownPlaceholders(): void
    {
        $out = $this->renderer()->render('Ticket {{ticket.id}} handled by {{rule.name}} (due {{deadline}}).');

        self::assertSame('Ticket 7657 handled by Dev — no answer (due 2026-08-10 10:00:00).', $out);
    }

    public function testWhitespaceInsideBracesIsTolerated(): void
    {
        self::assertSame('7657', $this->renderer()->render('{{  ticket.id  }}'));
    }

    public function testPlaceholderNamesAreCaseInsensitive(): void
    {
        self::assertSame('7657', $this->renderer()->render('{{TICKET.ID}}'));
    }

    public function testValuesAreEscapedForTheTimeline(): void
    {
        $out = $this->renderer()->render('{{ticket.name}}');

        self::assertSame('Printer &lt;b&gt;on fire&lt;/b&gt;', $out);
    }

    public function testEscapingCanBeDisabledForPlainTextContexts(): void
    {
        $out = $this->renderer()->render('{{ticket.name}}', false);

        self::assertSame('Printer <b>on fire</b>', $out);
    }

    /**
     * Blanking an unknown placeholder would hide a typo until somebody reads a timeline
     * with a hole in it; leaving it visible makes the mistake obvious in the dry run.
     */
    public function testUnknownPlaceholdersAreLeftUntouched(): void
    {
        self::assertSame('{{tickte.name}}', $this->renderer()->render('{{tickte.name}}'));
    }

    public function testUnknownPlaceholdersCanBeListedForValidation(): void
    {
        $unknown = $this->renderer()->unknownPlaceholders('{{ticket.id}} {{nope}} {{also.missing}} {{nope}}');

        self::assertSame(['nope', 'also.missing'], $unknown);
    }

    public function testTemplateWithoutPlaceholdersIsReturnedAsIs(): void
    {
        self::assertSame('Nothing to see here.', $this->renderer()->render('Nothing to see here.'));
        self::assertSame([], $this->renderer()->unknownPlaceholders('Nothing to see here.'));
    }

    public function testNullValuesRenderAsEmptyString(): void
    {
        $renderer = new MessageRenderer(['calendar.name' => null]);

        self::assertSame('[]', $renderer->render('[{{calendar.name}}]'));
    }

    public function testAvailableNamesAreExposedForTheUi(): void
    {
        self::assertContains('ticket.id', $this->renderer()->availableNames());
        self::assertContains('group.name', $this->renderer()->availableNames());
    }

    /**
     * The renderer is a substitution table, not an interpreter: anything that looks like
     * an expression stays literal text.
     */
    public function testNoExpressionEvaluationHappens(): void
    {
        $renderer = new MessageRenderer(['a' => '1']);

        self::assertSame('{{a + 1}}', $renderer->render('{{a + 1}}'));
        self::assertSame('{{ phpinfo() }}', $renderer->render('{{ phpinfo() }}'));
    }
}
