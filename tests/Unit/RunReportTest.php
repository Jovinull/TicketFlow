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

use GlpiPlugin\Ticketclock\Engine\PreviewRow;
use GlpiPlugin\Ticketclock\Engine\RunReport;
use PHPUnit\Framework\TestCase;

/**
 * The preview is the one part of a run that grows with its size.
 *
 * A scheduled pass never reads it. Building a row per examined ticket anyway cost about
 * 34 MB over 30k candidates and bought nothing, so collection is opt-in and bounded, and
 * what is left out is counted rather than quietly dropped.
 */
final class RunReportTest extends TestCase
{
    private function row(int $tickets_id): PreviewRow
    {
        return new PreviewRow(
            tickets_id: $tickets_id,
            name: 'ticket ' . $tickets_id,
            entities_id: 0,
            status: 4,
            groups_id: [],
            reference_date: '2026-08-03 09:00:00',
            deadline_date: '2026-08-10 09:00:00',
            overdue_seconds: 0,
            calendar_name: '',
            used_elapsed_fallback: true,
            would_execute: true,
            reason: 'would_execute',
            actions: [],
            validations_id: 0,
        );
    }

    public function testNothingIsBuiltWhenNobodyAskedForAPreview(): void
    {
        $report = new RunReport();

        $built = 0;
        for ($i = 0; $i < 10; $i++) {
            $report->notePreview(function () use (&$built, $i): PreviewRow {
                $built++;
                return $this->row($i);
            });
        }

        self::assertSame([], $report->preview);
        self::assertSame(0, $report->preview_omitted, 'rows nobody wanted are not "omitted"');
        self::assertSame(0, $built, 'the row must not even be constructed');
    }

    public function testCollectionStopsAtTheLimitAndCountsTheRest(): void
    {
        $report = new RunReport();
        $report->preview_limit = 3;

        $built = 0;
        for ($i = 0; $i < 10; $i++) {
            $report->notePreview(function () use (&$built, $i): PreviewRow {
                $built++;
                return $this->row($i);
            });
        }

        self::assertCount(3, $report->preview);
        self::assertSame(7, $report->preview_omitted);
        self::assertSame(3, $built, 'past the limit the row is counted, not built');
        self::assertSame([0, 1, 2], array_map(static fn(PreviewRow $r): int => $r->tickets_id, $report->preview));
    }

    public function testMergingRespectsTheReceivingLimit(): void
    {
        $first = new RunReport();
        $first->preview_limit = 2;
        $first->notePreview(fn(): PreviewRow => $this->row(1));

        $second = new RunReport();
        $second->preview_limit = 10;
        foreach ([2, 3, 4] as $id) {
            $second->notePreview(fn(): PreviewRow => $this->row($id));
        }

        $first->merge($second);

        self::assertCount(2, $first->preview, 'the merged report keeps its own ceiling');
        self::assertSame(2, $first->preview_omitted, 'and counts what would not fit');
    }

    public function testCountersAddUpAcrossAMerge(): void
    {
        $a = new RunReport();
        $a->analyzed = 5;
        $a->expired  = 3;
        $a->executed = 2;

        $b = new RunReport();
        $b->analyzed = 7;
        $b->expired  = 1;
        $b->failed   = 1;

        $a->merge($b);

        self::assertSame(12, $a->analyzed);
        self::assertSame(4, $a->expired);
        self::assertSame(2, $a->executed);
        self::assertSame(1, $a->failed);
    }
}
