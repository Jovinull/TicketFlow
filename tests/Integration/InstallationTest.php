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

use Config as CoreConfig;
use CronTask;
use GlpiPlugin\Ticketclock\Config;
use GlpiPlugin\Ticketclock\Cron;
use GlpiPlugin\Ticketclock\Execution;
use GlpiPlugin\Ticketclock\Install;
use GlpiPlugin\Ticketclock\Rule;
use GlpiPlugin\Ticketclock\Version;
use PHPUnit\Framework\TestCase;
use ProfileRight;

use function countElementsInTable;
use function plugin_version_ticketclock;

/**
 * The plugin must be installable, and a fresh install must be inert.
 */
final class InstallationTest extends TestCase
{
    public function testEveryTableExists(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (Install::tables() as $table) {
            self::assertTrue($DB->tableExists($table), "missing table {$table}");
        }
    }

    public function testTableNamesFollowTheGlpiPluginConvention(): void
    {
        self::assertSame('glpi_plugin_ticketclock_rules', Rule::getTable());
        self::assertSame('glpi_plugin_ticketclock_executions', Execution::getTable());
    }

    public function testSchemaVersionIsRecorded(): void
    {
        self::assertNotNull(Install::getInstalledSchemaVersion());
    }

    public function testClaimKeyIsUnique(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        // information_schema rather than SHOW INDEX: GLPI's iterator refuses raw queries.
        $rows = iterator_to_array($DB->request([
            'SELECT' => ['INDEX_NAME', 'NON_UNIQUE'],
            'FROM'   => 'information_schema.STATISTICS',
            'WHERE'  => [
                'TABLE_SCHEMA' => new \Glpi\DBAL\QueryExpression('DATABASE()'),
                'TABLE_NAME'   => Execution::getTable(),
                'INDEX_NAME'   => 'claim_key',
            ],
        ]), false);

        self::assertNotEmpty($rows, 'the claim_key index is missing');
        foreach ($rows as $row) {
            self::assertSame(
                0,
                (int) $row['NON_UNIQUE'],
                'claim_key must be a UNIQUE index; idempotency depends on it',
            );
        }
    }

    public function testBothAutomaticActionsAreRegistered(): void
    {
        $task = new CronTask();

        self::assertTrue($task->getFromDBbyName(Cron::class, Cron::TASK_PROCESS));
        self::assertTrue($task->getFromDBbyName(Cron::class, Cron::TASK_PURGE));
    }

    /**
     * Registration must produce a *disabled* processing task.
     *
     * Reading whatever the row currently says would not test that: on any instance where an
     * administrator has legitimately armed the task -- which is what you do in production --
     * the assertion would fail while nothing is wrong. So the row is removed and registered
     * again, which is exactly what installing does, and the result of that is asserted.
     */
    public function testTheProcessingTaskShipsDisabled(): void
    {
        $task = new CronTask();
        self::assertTrue($task->getFromDBbyName(Cron::class, Cron::TASK_PROCESS));

        $restore = $task->fields;
        self::assertTrue($task->delete(['id' => $task->getID()], true));

        try {
            Cron::register();

            $fresh = new CronTask();
            self::assertTrue(
                $fresh->getFromDBbyName(Cron::class, Cron::TASK_PROCESS),
                'registering must recreate the processing task',
            );
            self::assertSame(
                CronTask::STATE_DISABLE,
                (int) $fresh->fields['state'],
                'installing the plugin must never start acting on tickets',
            );
        } finally {
            // Put the instance back the way it was found, armed or not.
            $current = new CronTask();
            if ($current->getFromDBbyName(Cron::class, Cron::TASK_PROCESS)) {
                $current->update(['id' => $current->getID(), 'state' => (int) $restore['state']]);
            }
        }
    }

    public function testTheRightIsRegistered(): void
    {
        self::assertArrayHasKey(Rule::$rightname, ProfileRight::getAllPossibleRights());
    }

    /**
     * The shipped defaults must leave the plugin unable to touch a ticket.
     *
     * Asserted on Config::DEFAULTS rather than on the stored values, for the same reason as
     * the task above: an instance where someone has armed the plugin on purpose is not a
     * broken instance, and a test that reddens there is telling the wrong story.
     */
    public function testAFreshInstallIsInert(): void
    {
        self::assertSame('0', Config::DEFAULTS['execution_enabled'], 'execution ships off');
        self::assertSame('1', Config::DEFAULTS['dry_run_global'], 'global dry run ships on');

        // And every default has to be written at install time, not merely fallen back to:
        // Config::get() replaces missing keys from DEFAULTS, so reading it back would prove
        // nothing. The row itself is the evidence.
        foreach (array_keys(Config::DEFAULTS) as $name) {
            self::assertSame(
                1,
                countElementsInTable(
                    CoreConfig::getTable(),
                    ['context' => Config::CONTEXT, 'name' => $name],
                ),
                sprintf('"%s" is a documented default but installing wrote no row for it', $name),
            );
        }
    }

    /**
     * A zero batch size or run ceiling must not be storable.
     *
     * Zero does not mean "no limit" for either of these -- it means the engine examines no
     * tickets at all and then reports a successful, empty run. The form declares min="1",
     * but that is a browser hint: a hand-made POST, an import, or a call from code gets
     * past it. So the floor is enforced where the value is written, and again where it is
     * read, in case one was stored before the guard existed.
     */
    public function testTheBatchAndRunCeilingCannotBeDrivenToZero(): void
    {
        $before = [
            'batch_size'          => Config::getInt('batch_size'),
            'max_tickets_per_run' => Config::getInt('max_tickets_per_run'),
        ];

        try {
            Config::set(['batch_size' => 0, 'max_tickets_per_run' => 0]);

            self::assertSame(1, Config::getInt('batch_size'), 'a zero batch size would load nothing');
            self::assertSame(1, Config::getInt('max_tickets_per_run'), 'a zero ceiling would analyse nothing');

            Config::set(['batch_size' => -5, 'max_tickets_per_run' => -1]);

            self::assertSame(1, Config::getInt('batch_size'));
            self::assertSame(1, Config::getInt('max_tickets_per_run'));

            // A value written straight into the table, bypassing set() entirely.
            CoreConfig::setConfigurationValues(Config::CONTEXT, ['max_tickets_per_run' => '0']);
            Config::reload();

            self::assertSame(
                1,
                Config::getInt('max_tickets_per_run'),
                'reading must clamp too, or a row written by hand stops the engine',
            );
        } finally {
            Config::set($before);
        }
    }

    /**
     * The literals in setup.php and the class constants must agree.
     *
     * setup.php has to spell the version and the GLPI range out as quoted strings: the
     * official release workflow greps this file for `PLUGIN_<KEY>_MIN_GLPI` with a literal
     * value, to put the supported range on the GitHub release page, and a constant
     * reference greps to nothing. That leaves two copies of the same fact, so this pins
     * them together -- the only reason the duplication is acceptable.
     */
    public function testTheSetupConstantsMatchTheVersionClass(): void
    {
        self::assertSame(Version::VERSION, PLUGIN_TICKETCLOCK_VERSION);
        self::assertSame(Version::MIN_GLPI, PLUGIN_TICKETCLOCK_MIN_GLPI);
        self::assertSame(Version::MAX_GLPI, PLUGIN_TICKETCLOCK_MAX_GLPI);
        self::assertSame(Version::SCHEMA, PLUGIN_TICKETCLOCK_SCHEMA_VERSION);
    }

    /**
     * What the plugin reports to GLPI must be the same thing again.
     */
    public function testThePluginReportsItsOwnVersionToGlpi(): void
    {
        $info = plugin_version_ticketclock();

        self::assertSame(Version::VERSION, $info['version']);
        self::assertSame(Version::MIN_GLPI, $info['requirements']['glpi']['min']);
        self::assertSame(Version::MAX_GLPI, $info['requirements']['glpi']['max']);
    }

    public function testCronInfoIsAvailableForBothTasks(): void
    {
        self::assertIsArray(Cron::cronInfo(Cron::TASK_PROCESS));
        self::assertIsArray(Cron::cronInfo(Cron::TASK_PURGE));
        self::assertNull(Cron::cronInfo('NoSuchTask'));
    }

    /**
     * GLPI dispatches a plugin task as `<itemtype>::cron<name>`; a typo here means the task
     * shows up in the UI and silently never runs.
     */
    public function testCronCallbacksExistWithTheNamesGlpiWillCall(): void
    {
        self::assertTrue(is_callable([Cron::class, 'cron' . Cron::TASK_PROCESS]));
        self::assertTrue(is_callable([Cron::class, 'cron' . Cron::TASK_PURGE]));
    }
}
