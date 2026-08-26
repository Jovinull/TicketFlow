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

namespace GlpiPlugin\Ticketclock;

use CommonDBTM;
use CronTask;
use GlpiPlugin\Ticketclock\Engine\RuleEngine;
use Toolbox;

/**
 * TicketFlow's GLPI Automatic Actions.
 *
 * Two tasks, both discoverable under Setup > Automatic actions and runnable by the
 * standard GLPI scheduler (internal or external CLI). There is no daemon of TicketFlow's
 * own: GLPI's scheduler already solves locking, run modes, logging and the UI, and a
 * second scheduler would only add a way for the two to disagree.
 *
 * The cron itself is intentionally thin — it decides nothing. It calls the engine, reports
 * the volume, and writes a line to the cron log.
 */
class Cron extends CommonDBTM
{
    public const TASK_PROCESS = 'ProcessRules';
    public const TASK_PURGE   = 'PurgeLogs';

    public static function getTypeName($nb = 0)
    {
        return __('TicketFlow', 'ticketclock');
    }

    /**
     * @return array{description: string}|null
     */
    public static function cronInfo(string $name): ?array
    {
        return match ($name) {
            self::TASK_PROCESS => [
                'description' => __('TicketFlow: evaluate rules and run their actions', 'ticketclock'),
            ],
            self::TASK_PURGE => [
                'description' => __('TicketFlow: purge old execution logs', 'ticketclock'),
            ],
            default => null,
        };
    }

    /**
     * Evaluate every active rule.
     *
     * @return int 1 when work was done, 0 when there was nothing to do, -1 on error
     */
    public static function cronProcessRules(CronTask $task): int
    {
        $engine = new RuleEngine();
        $report = $engine->runAll();

        $task->addVolume($report->volume());

        $summary = sprintf(
            'TicketFlow: analyzed=%d matched=%d expired=%d executed=%d failed=%d skipped=%d already=%d simulated=%d',
            $report->analyzed,
            $report->matched,
            $report->expired,
            $report->executed,
            $report->failed,
            $report->skipped,
            $report->already_processed,
            $report->simulated,
        );

        $task->log($summary);
        Toolbox::logInFile('ticketclock', $summary . "\n");

        foreach ($report->errors as $error) {
            Toolbox::logInFile('ticketclock', 'ERROR ' . $error . "\n");
        }

        if ($report->errors !== [] && $report->executed === 0 && $report->simulated === 0) {
            return -1;
        }

        return $report->analyzed > 0 ? 1 : 0;
    }

    /**
     * Apply the configured log retention.
     */
    public static function cronPurgeLogs(CronTask $task): int
    {
        $days = Config::getInt('log_retention_days', 90);
        if ($days <= 0) {
            return 0;
        }

        $deleted = Execution::purgeOlderThan($days);
        $task->addVolume($deleted);

        return $deleted > 0 ? 1 : 0;
    }

    /** Called from the plugin install routine. */
    public static function register(): void
    {
        CronTask::register(
            self::class,
            self::TASK_PROCESS,
            HOUR_TIMESTAMP,
            [
                'comment'   => __('Evaluates TicketFlow rules and executes their actions.', 'ticketclock'),
                // Off by default: installing the plugin must never start acting on tickets.
                'state'     => CronTask::STATE_DISABLE,
                'mode'      => CronTask::MODE_EXTERNAL,
                'allowmode' => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
                'hourmin'   => 0,
                'hourmax'   => 24,
            ],
        );

        CronTask::register(
            self::class,
            self::TASK_PURGE,
            DAY_TIMESTAMP,
            [
                'comment'   => __('Deletes TicketFlow execution logs older than the configured retention.', 'ticketclock'),
                'state'     => CronTask::STATE_WAITING,
                'allowmode' => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
            ],
        );
    }
}
