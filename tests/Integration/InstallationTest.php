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
use GlpiPlugin\Ticketclock\EntityConfig;
use GlpiPlugin\Ticketclock\Cron;
use GlpiPlugin\Ticketclock\Execution;
use GlpiPlugin\Ticketclock\Install;
use GlpiPlugin\Ticketclock\Profile as PluginProfile;
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
     * Installing must not decide, on the administrator's behalf, who else may use the plugin.
     *
     * It used to grant the plugin right to every profile holding core's `config`, which on a
     * multi-entity instance includes the administrator of one subsidiary. Handing a delegated
     * administrator control over rules that act on tickets is a decision for the person
     * running the instance, made in Setup > Profiles -- not a side effect of installing.
     *
     * The profile doing the install still gets it, because being locked out of a plugin one
     * has just installed is not a defensible default either.
     */
    public function testInstallingDoesNotGrantThePluginRightToOtherConfigurationProfiles(): void
    {
        $profile = new \Profile();
        $profiles_id = (int) $profile->add([
            'name'      => 'TicketFlow config-only ' . uniqid(),
            'interface' => 'central',
        ]);
        self::assertGreaterThan(0, $profiles_id);

        $active_before = $_SESSION['glpiactiveprofile'] ?? null;

        try {
            ProfileRight::updateProfileRights($profiles_id, [
                'config'          => ALLSTANDARDRIGHT,
                Rule::$rightname  => 0,
            ]);

            // The install runs under whoever is installing. Pointing that at a third profile
            // is what makes the assertion below about the *other* profile and not about this
            // one -- otherwise the session's own grant would mask the behaviour under test.
            $_SESSION['glpiactiveprofile'] = ['id' => -1];

            PluginProfile::installRights();

            $rights = ProfileRight::getProfileRights($profiles_id, [Rule::$rightname]);
            self::assertSame(
                0,
                (int) ($rights[Rule::$rightname] ?? 0),
                'a profile holding config must not be handed the plugin right by an install',
            );
        } finally {
            if ($active_before !== null) {
                $_SESSION['glpiactiveprofile'] = $active_before;
            }
            $profile->delete(['id' => $profiles_id], true);
        }
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
        // The two switches are the root entity's policy now, and every other entity inherits
        // it, so asserting the root is asserting the whole tree on a fresh install.
        self::assertSame(0, EntityConfig::ROOT_DEFAULTS['execution_enabled'], 'execution ships off');
        self::assertSame(1, EntityConfig::ROOT_DEFAULTS['dry_run'], 'dry run ships on');
        self::assertFalse(EntityConfig::isExecutionEnabled(0), 'the root is not armed');
        self::assertTrue(EntityConfig::isDryRun(0), 'the root is simulated');

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
     * The catalogue manifest has to name the version this code base is.
     *
     * It is the one version number no test was watching, and the one with the worst failure
     * mode. The release workflow fires on a tag and builds the archive from the tagged commit,
     * so a manifest updated afterwards is not in the package at all: the published tarball
     * carries the previous version's metadata for good, and the catalogue offers a download
     * whose contents disagree with the entry that pointed at it. That has already happened
     * once on 1.0.1, where the manifest named an archive the tag had not produced yet.
     *
     * Asserted against the file rather than a parsed release process, because the file is what
     * ships. Reading it with SimpleXML rather than matching on text: the point is the value in
     * `<num>`, not where the whitespace falls.
     */
    public function testTheCatalogueManifestNamesThisVersion(): void
    {
        $path = dirname(__DIR__, 2) . '/ticketclock.xml';
        self::assertFileExists($path, 'the catalogue manifest is missing');

        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml, 'the catalogue manifest is not valid XML');

        $versions = $xml->versions->version ?? [];
        self::assertCount(1, $versions, 'the manifest should advertise exactly one version');

        $declared = (string) $versions[0]->num;
        self::assertSame(
            Version::VERSION,
            $declared,
            'the manifest advertises a different version than the code; a release built from this '
            . 'commit would ship metadata for another one',
        );

        // The archive name is fixed by the official release workflow, so the URL can be derived
        // rather than trusted: a hand-edited number in either half of it is the failure this
        // guards against.
        $expected_url = sprintf(
            'https://github.com/Jovinull/ticketclock/releases/download/%1$s/glpi-ticketclock-%1$s.tar.bz2',
            Version::VERSION,
        );
        self::assertSame(
            $expected_url,
            (string) $versions[0]->download_url,
            'the manifest points at an archive the tag for this version would not produce',
        );
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
