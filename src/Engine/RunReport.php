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
 * What one run did, for the cron volume counter, the dry-run screen and the logs.
 */
final class RunReport
{
    public int $analyzed = 0;
    public int $matched = 0;
    public int $expired = 0;
    public int $executed = 0;
    public int $failed = 0;
    public int $skipped = 0;
    public int $already_processed = 0;
    public int $simulated = 0;

    /**
     * Rows for the simulation screen.
     *
     * Empty unless somebody asked for them. A cron pass never reads this, and building a
     * row per examined ticket made memory grow with the size of the run for no reader --
     * measurably: 30k candidates cost about 34 MB of preview nobody looked at.
     *
     * @var list<PreviewRow>
     */
    public array $preview = [];

    /** How many rows were examined but not kept, once the limit was reached. */
    public int $preview_omitted = 0;

    /** 0 keeps nothing; the simulation screen raises it to what it intends to render. */
    public int $preview_limit = 0;

    /** @var list<string> */
    public array $errors = [];

    /**
     * Offer a preview row, built only if it will be kept.
     *
     * The closure is the point: with no reader -- a cron pass -- the row is never built at
     * all. Once the limit is reached the rows are counted instead, so the screen can say how
     * many it is not showing rather than pretending it showed everything.
     */
    public function notePreview(callable $build): void
    {
        if ($this->preview_limit <= 0) {
            return;
        }

        if (count($this->preview) < $this->preview_limit) {
            $this->preview[] = $build();
            return;
        }

        $this->preview_omitted++;
    }

    public function merge(self $other): void
    {
        $this->analyzed          += $other->analyzed;
        $this->matched           += $other->matched;
        $this->expired           += $other->expired;
        $this->executed          += $other->executed;
        $this->failed            += $other->failed;
        $this->skipped           += $other->skipped;
        $this->already_processed += $other->already_processed;
        $this->simulated         += $other->simulated;

        $this->preview_omitted += $other->preview_omitted;

        foreach ($other->preview as $row) {
            $this->notePreview(static fn(): PreviewRow => $row);
        }

        $this->errors = array_merge($this->errors, $other->errors);
    }

    /** Work units to report to GLPI's CronTask volume counter. */
    public function volume(): int
    {
        return $this->executed + $this->failed + $this->simulated;
    }

    /**
     * @return array<string, int>
     */
    public function counters(): array
    {
        return [
            'analyzed'          => $this->analyzed,
            'matched'           => $this->matched,
            'expired'           => $this->expired,
            'executed'          => $this->executed,
            'failed'            => $this->failed,
            'skipped'           => $this->skipped,
            'already_processed' => $this->already_processed,
            'simulated'         => $this->simulated,
        ];
    }
}
