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

namespace GlpiPlugin\Ticketclock\Enum;

/**
 * Lifecycle of one rule occurrence on one ticket.
 */
enum ExecutionState: string
{
    /** Claimed by a worker; actions not finished yet. */
    case Processing = 'processing';

    /** All actions ran successfully. */
    case Executed = 'executed';

    /** At least one action failed; remaining actions were aborted. */
    case Failed = 'failed';

    /** Conditions no longer held at re-validation time. */
    case Skipped = 'skipped';

    /** Simulation only: nothing was modified. */
    case DryRun = 'dry_run';

    public function label(): string
    {
        return match ($this) {
            self::Processing => __('Processing', 'ticketclock'),
            self::Executed   => __('Executed', 'ticketclock'),
            self::Failed     => __('Failed', 'ticketclock'),
            self::Skipped    => __('Skipped', 'ticketclock'),
            self::DryRun     => __('Dry run', 'ticketclock'),
        };
    }

    /**
     * A claim in this state blocks a new claim for the same occurrence.
     *
     * Dry runs and skips deliberately do not block: a simulated occurrence must still be
     * executable for real afterwards.
     */
    public function blocksNewClaim(): bool
    {
        return match ($this) {
            self::Processing, self::Executed, self::Failed => true,
            self::Skipped, self::DryRun                    => false,
        };
    }

    /**
     * @return array<string, string> value => translated label
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
