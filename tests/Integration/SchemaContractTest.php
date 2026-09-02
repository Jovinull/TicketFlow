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

use Calendar;
use Config as CoreConfig;
use Entity;
use GlpiPlugin\Ticketclock\Config;
use GlpiPlugin\Ticketclock\EntityConfig;
use GlpiPlugin\Ticketclock\Execution;
use GlpiPlugin\Ticketclock\Install;
use GlpiPlugin\Ticketclock\Rule;
use GlpiPlugin\Ticketclock\RuleAction;
use GlpiPlugin\Ticketclock\RuleGroup;
use GlpiPlugin\Ticketclock\Version;
use Migration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function countElementsInTable;

/**
 * The schema is the same whether it was installed or upgraded into.
 *
 * A plugin builds its tables twice, by two different pieces of code: `CREATE TABLE` for a
 * fresh install, and a chain of `addField()` calls for an instance that already had an older
 * version. Nothing makes them agree. They drift a column at a time -- a default here, a
 * nullability there, a column appended in a different place -- and the drift is invisible
 * until somebody upgrades and hits a bug that reproduces on no fresh install anywhere.
 *
 * So the shape is written down. Whichever path built the database this suite runs against,
 * it has to match: CI installs clean, a developer's instance has usually been upgraded, and
 * a migration that diverges from the install fails on one of them.
 *
 * Raised as a gap by an independent review, after the two paths had been compared by hand
 * once. Comparing by hand once is how you learn they agree today; this is how you learn when
 * they stop.
 *
 * When a migration deliberately changes the schema, this list changes with it. That is the
 * point, not an inconvenience.
 */
final class SchemaContractTest extends TestCase
{
    /**
     * Column signatures, in order, exactly as `information_schema` reports them.
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED = [
        'entityconfigs' => [
            'id int(10) unsigned NOT NULL default NULL',
            'entities_id int(10) unsigned NOT NULL default 0',
            // Signed: -2 is core's Entity::CONFIG_PARENT, the "inherit from the parent" value.
            'execution_enabled int(11) NOT NULL default -2',
            'dry_run int(11) NOT NULL default -2',
            'fallback_calendars_id int(11) NOT NULL default -2',
            'date_creation timestamp NULL default NULL',
            'date_mod timestamp NULL default NULL',
        ],
        'executions' => [
            'id int(10) unsigned NOT NULL default NULL',
            'plugin_ticketclock_rules_id int(10) unsigned NOT NULL default 0',
            'tickets_id int(10) unsigned NOT NULL default 0',
            'entities_id int(10) unsigned NOT NULL default 0',
            'occurrence_key varchar(100) NOT NULL default \'\'',
            'claim_key varchar(150) NULL default NULL',
            'state varchar(20) NOT NULL default \'processing\'',
            'reference_date timestamp NULL default NULL',
            'deadline_date timestamp NULL default NULL',
            'calendars_id int(10) unsigned NOT NULL default 0',
            'calendar_name varchar(255) NULL default NULL',
            'delay_value int(11) NOT NULL default 0',
            'delay_unit varchar(20) NOT NULL default \'\'',
            'used_elapsed_fallback tinyint(4) NOT NULL default 0',
            'itilvalidations_id int(10) unsigned NOT NULL default 0',
            'triggered_at timestamp NULL default NULL',
            'completed_at timestamp NULL default NULL',
            'actions_result longtext NULL default NULL',
            'error text NULL default NULL',
            'date_creation timestamp NULL default NULL',
        ],
        'ruleactions' => [
            'id int(10) unsigned NOT NULL default NULL',
            'plugin_ticketclock_rules_id int(10) unsigned NOT NULL default 0',
            'action_type varchar(50) NOT NULL default \'\'',
            'ranking int(11) NOT NULL default 0',
            'params text NULL default NULL',
        ],
        'rulegroups' => [
            'id int(10) unsigned NOT NULL default NULL',
            'plugin_ticketclock_rules_id int(10) unsigned NOT NULL default 0',
            'groups_id int(10) unsigned NOT NULL default 0',
        ],
        'rules' => [
            'id int(10) unsigned NOT NULL default NULL',
            'entities_id int(10) unsigned NOT NULL default 0',
            'is_recursive tinyint(4) NOT NULL default 0',
            'name varchar(255) NULL default NULL',
            'comment text NULL default NULL',
            'is_active tinyint(4) NOT NULL default 0',
            'ranking int(11) NOT NULL default 0',
            'rule_type varchar(50) NOT NULL default \'pending_inactivity\'',
            'target_status int(11) NOT NULL default 0',
            'pendingreasons_id int(10) unsigned NOT NULL default 0',
            'start_event varchar(50) NOT NULL default \'pending_start\'',
            'delay_value int(11) NOT NULL default 5',
            'delay_unit varchar(20) NOT NULL default \'business_days\'',
            'calendar_mode varchar(20) NOT NULL default \'entity\'',
            'calendars_id int(10) unsigned NOT NULL default 0',
            'reset_events varchar(255) NULL default NULL',
            'is_dry_run tinyint(4) NOT NULL default 0',
            'last_execution_date timestamp NULL default NULL',
            'last_error text NULL default NULL',
            'last_error_date timestamp NULL default NULL',
            'date_creation timestamp NULL default NULL',
            'date_mod timestamp NULL default NULL',
        ],    ];

    public function testEveryPluginTableHasTheExpectedShape(): void
    {
        foreach (self::EXPECTED as $suffix => $expected) {
            self::assertSame(
                array_map($this->normaliseSignature(...), $expected),
                $this->signatureOf('glpi_plugin_ticketclock_' . $suffix),
                sprintf(
                    'glpi_plugin_ticketclock_%s does not match the recorded schema. If a migration '
                    . 'changed it on purpose, update this list; if it did not, the install and the '
                    . 'upgrade have drifted apart.',
                    $suffix,
                ),
            );
        }
    }

    /**
     * The tables the plugin owns, and no others. A table added by a migration and not by the
     * installer, or the other way round, is the same drift seen from outside.
     */
    public function testThePluginOwnsExactlyTheseTables(): void
    {
        $declared = array_map(
            static fn(string $class): string => $class::getTable(),
            [Rule::class, RuleGroup::class, RuleAction::class, Execution::class, EntityConfig::class],
        );
        sort($declared);

        $expected = array_map(static fn(string $s): string => 'glpi_plugin_ticketclock_' . $s, array_keys(self::EXPECTED));
        sort($expected);

        self::assertSame($expected, $declared);
        self::assertSame($expected, $this->pluginTablesInDatabase());
    }

    /**
     * @return list<string>
     */
    private function signatureOf(string $table): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['COLUMN_NAME', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT'],
            'FROM'   => 'information_schema.COLUMNS',
            'WHERE'  => ['TABLE_SCHEMA' => $DB->dbdefault, 'TABLE_NAME' => $table],
            'ORDER'  => 'ORDINAL_POSITION ASC',
        ]);

        foreach ($iterator as $row) {
            $out[] = $this->normaliseSignature(sprintf(
                '%s %s %s default %s',
                $row['COLUMN_NAME'],
                $row['COLUMN_TYPE'],
                $row['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL',
                $row['COLUMN_DEFAULT'] ?? 'NULL',
            ));
        }

        self::assertNotSame([], $out, sprintf('%s does not exist', $table));

        return $out;
    }

    /**
     * Two engines describe the same column differently. This is about the description.
     *
     * The contract is written in MariaDB's spelling because that is what the plugin is
     * developed against. Both sides of the comparison come through here, so it could equally
     * have been written in MySQL's; what matters is that neither engine's habits can make a
     * correct schema look wrong.
     *
     * Integer display widths: MariaDB reports `int(10) unsigned`, MySQL 8 reports
     * `int unsigned`. The width never restricted the range and MySQL removed it from its
     * metadata. Only integers are touched, so `varchar(100)` keeps the length that does mean
     * something.
     *
     * Quoting of defaults: MariaDB reports `'processing'` and `''`, MySQL reports
     * `processing` and an empty string. Both measured, not assumed.
     *
     * One difference needs no handling, and it is worth saying why so nobody deletes the
     * `?? 'NULL'` upstream thinking it redundant: for a column declared `DEFAULT NULL`,
     * MariaDB reports the four-character string `NULL` while MySQL reports a real SQL NULL.
     * The coalesce turns MySQL's into the same four characters, so the two already agree by
     * the time they reach this method.
     */
    private function normaliseSignature(string $signature): string
    {
        $signature = preg_replace(
            '/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)(?=(?: unsigned)?(?: NULL| NOT NULL))/',
            '$1',
            $signature,
        ) ?? $signature;

        return preg_replace('/ default \'(.*)\'$/', ' default $1', $signature) ?? $signature;
    }

    /**
     * @return list<string>
     */
    private function pluginTablesInDatabase(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => 'TABLE_NAME',
            'FROM'   => 'information_schema.TABLES',
            'WHERE'  => [
                'TABLE_SCHEMA' => $DB->dbdefault,
                ['TABLE_NAME' => ['LIKE', 'glpi\_plugin\_ticketclock\_%']],
            ],
            'ORDER'  => 'TABLE_NAME ASC',
        ]);

        foreach ($iterator as $row) {
            $out[] = (string) $row['TABLE_NAME'];
        }

        return $out;
    }
    /**
     * The upgrade path lands on the same schema as a clean install.
     *
     * The contract above checks whichever database the suite happens to run against, and on
     * CI that database is always installed clean. So it proves the installer and says nothing
     * about the migrations -- which is the half that actually drifts, because they are
     * written months apart from the `CREATE TABLE` they have to agree with.
     *
     * This one builds the older schema on purpose and upgrades into the current one. The
     * database is put back either way: a test that leaves a half-migrated instance behind
     * would take every later test with it.
     *
     * @param list<string> $columns_to_drop
     */
    #[DataProvider('olderSchemas')]
    public function testUpgradingFromAnOlderSchemaProducesTheInstalledShape(string $from, array $columns_to_drop): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = Rule::getTable();

        // GLPI caches table metadata per request, and this test alters tables underneath it.
        // Without clearing, the second data set asks about a column the cache last saw being
        // dropped and gets an answer from before the migration put it back.
        $DB->clearSchemaCache();

        try {
            // Collapse InnoDB's instant-column history first. Each add/drop cycle leaves the
            // old column versions in the row, and after a few runs the rebuild a DROP needs
            // exceeds the 8126-byte row limit -- the table fails to alter and the suite leaves
            // a half-migrated instance behind. FORCE rewrites the table so the next cycle
            // starts from a clean row, which makes these tests repeatable rather than
            // working until they suddenly do not.
            $DB->doQuery('ALTER TABLE ' . $DB->quoteName($table) . ' FORCE');

            foreach ($columns_to_drop as $column) {
                self::assertTrue(
                    $DB->fieldExists($table, $column),
                    sprintf('%s is missing before the test starts; the database is not at the current schema', $column),
                );
                $DB->doQuery('ALTER TABLE ' . $DB->quoteName($table) . ' DROP COLUMN ' . $DB->quoteName($column));
            }

            // Recorded directly rather than through a test-only setter on Install: the test
            // is entitled to say which version this instance claims to be, not to reach into
            // the migration chain.
            CoreConfig::setConfigurationValues(Config::CONTEXT, [Install::SCHEMA_VERSION_KEY => $from]);
            Config::reload();

            self::assertTrue(Install::install(new Migration(Version::VERSION)));

            self::assertSame(
                Version::SCHEMA,
                Install::getInstalledSchemaVersion(),
                'the upgrade did not record the version it migrated to',
            );

            self::assertSame(
                array_map($this->normaliseSignature(...), self::EXPECTED['rules']),
                $this->signatureOf($table),
                sprintf(
                    'upgrading from %s produced a different schema than a clean install. The migration '
                    . 'and the CREATE TABLE have drifted apart, which is invisible until somebody upgrades.',
                    $from,
                ),
            );
        } finally {
            // Whatever happened above, the instance the rest of the suite runs on has to be
            // whole again. Reset the recorded starting point before cleanup so it replays the
            // complete chain even if a future change moves the version marker or leaves a
            // migration only partly applied. The cleanup must not trust whatever version the
            // failed path happened to record.
            CoreConfig::setConfigurationValues(Config::CONTEXT, [Install::SCHEMA_VERSION_KEY => '1.0.0']);
            Config::reload();
            Install::install(new Migration(Version::VERSION));
            $DB->clearSchemaCache();
        }
    }

    /**
     * 1.2.0 -> 1.3.0 in full: the table has to be created, and the instance's settings have to
     * survive the move into it.
     *
     * The data-driven test above cannot reach this one. It starts from a database that is
     * already at the current schema and only drops columns from the rules table, so
     * `migrateTo130()` always finds `glpi_plugin_ticketclock_entityconfigs` in place and takes
     * its "table exists" branch. The branch that creates it, and the copy of the three global
     * values into the root entity, were never executed by anything.
     *
     * The values written below are deliberately the opposite of {@see EntityConfig::ROOT_DEFAULTS}
     * -- execution on, dry run off, a real calendar. That is what makes this an ordering test as
     * well: if the migration deleted the old configuration rows before reading them, the root
     * would come out holding the defaults, and every assertion on the values would fail rather
     * than quietly passing on a coincidence.
     */
    public function testUpgradingFrom120CreatesTheEntityPolicyTableAndCarriesTheSettingsIntoIt(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table    = EntityConfig::getTable();
        $old_keys = ['execution_enabled', 'dry_run_global', 'fallback_calendars_id'];

        $calendars_id = (int) (new Calendar())->add([
            'name'         => 'TicketFlow migration calendar ' . uniqid(),
            'entities_id'  => 0,
            'is_recursive' => 1,
        ]);
        $child = (int) (new Entity())->add([
            'name'        => 'TicketFlow migration child ' . uniqid(),
            'entities_id' => 0,
        ]);
        self::assertGreaterThan(0, $child);

        $DB->clearSchemaCache();

        try {
            $DB->doQuery('DROP TABLE IF EXISTS ' . $DB->quoteName($table));
            $DB->clearSchemaCache();
            EntityConfig::reload();

            // What a 1.2.0 instance looks like: the policy lives in the global context, under
            // the old name for the dry run.
            CoreConfig::setConfigurationValues(Config::CONTEXT, [
                'execution_enabled'          => '1',
                'dry_run_global'             => '0',
                'fallback_calendars_id'      => (string) $calendars_id,
                Install::SCHEMA_VERSION_KEY  => '1.2.0',
            ]);
            Config::reload();

            self::assertTrue(Install::install(new Migration(Version::VERSION)));
            $DB->clearSchemaCache();
            EntityConfig::reload();

            self::assertTrue($DB->tableExists($table), 'the upgrade did not create the entity policy table');

            self::assertSame(
                array_map($this->normaliseSignature(...), self::EXPECTED['entityconfigs']),
                $this->signatureOf($table),
                'the table the migration creates differs from the one a clean install creates',
            );

            // The instance was armed before the upgrade and has to still be armed after it.
            // An upgrade that silently re-inerts a working installation would stop every rule
            // on the instance, and nothing would say why.
            self::assertSame(1, EntityConfig::getUsedValue('execution_enabled', 0));
            self::assertSame(0, EntityConfig::getUsedValue('dry_run', 0), 'dry_run_global did not become dry_run');
            self::assertSame($calendars_id, EntityConfig::getUsedValue('fallback_calendars_id', 0));

            // No row was written for the child, and it still behaves like the root: that is
            // what makes the upgrade a no-op for every entity below the one that was seeded.
            self::assertSame(
                0,
                countElementsInTable($table, ['entities_id' => $child]),
                'the migration wrote a row for an entity it was not asked about',
            );
            self::assertTrue(EntityConfig::isExecutionEnabled($child));
            self::assertFalse(EntityConfig::isDryRun($child));

            $left = CoreConfig::getConfigurationValues(Config::CONTEXT, $old_keys);
            self::assertSame([], is_array($left) ? $left : [], 'the old global keys were left behind');

            self::assertSame(Version::SCHEMA, Install::getInstalledSchemaVersion());

            // Running the installer again must not undo the seeding or duplicate the row.
            self::assertTrue(Install::install(new Migration(Version::VERSION)));
            EntityConfig::reload();
            self::assertSame(1, countElementsInTable($table, ['entities_id' => 0]));
            self::assertSame(1, EntityConfig::getUsedValue('execution_enabled', 0));
        } finally {
            // The rest of the suite runs on this instance, so it goes back to a whole schema
            // and to the inert baseline every other test assumes.
            CoreConfig::setConfigurationValues(Config::CONTEXT, [Install::SCHEMA_VERSION_KEY => '1.0.0']);
            Config::reload();
            Install::install(new Migration(Version::VERSION));
            $DB->clearSchemaCache();

            CoreConfig::deleteConfigurationValues(Config::CONTEXT, $old_keys);
            Config::reload();
            EntityConfig::reload();
            EntityConfig::setForEntity(0, EntityConfig::ROOT_DEFAULTS);

            (new Entity())->delete(['id' => $child], true);
            (new Calendar())->delete(['id' => $calendars_id], true);
        }
    }

    /**
     * Each supported starting point, with the columns that version did not have yet.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function olderSchemas(): array
    {
        return [
            '1.1.0, before the refusal columns' => ['1.1.0', ['last_error', 'last_error_date']],
            '1.0.0, before start_event as well' => ['1.0.0', ['last_error', 'last_error_date', 'start_event']],
        ];
    }
    /**
     * A failed upgrade must not leave the instance claiming a version it did not reach, and
     * must be recoverable by running the installer again.
     *
     * Worth being precise about what this protects, because the obvious reading is wrong.
     * Every migration this plugin has applies its own DDL as it goes -- `migrateTo100` with a
     * raw `CREATE TABLE`, the others by calling `migrationOneTable()` -- so today the columns
     * land before the version is recorded either way. The trap is for the migration nobody
     * has written yet: one that only queues through `addField()` and leaves the applying to
     * the installer. With the version recorded first, such a step could fail while the row
     * already claimed the new schema, and the next attempt would skip past it forever.
     *
     * So the installer applies each step before recording it, and this asserts the property
     * that follows: after a failure the recorded version is one that genuinely completed, and
     * running the installer again finishes the job. Re-running is safe because `addField()`
     * checks for the column first, so a step that half happened simply completes.
     */
    public function testAFailedUpgradeStaysRecoverable(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = Rule::getTable();
        $DB->clearSchemaCache();

        try {
            $DB->doQuery('ALTER TABLE ' . $DB->quoteName($table) . ' FORCE');
            foreach (['last_error', 'last_error_date'] as $column) {
                $DB->doQuery('ALTER TABLE ' . $DB->quoteName($table) . ' DROP COLUMN ' . $DB->quoteName($column));
            }
            CoreConfig::setConfigurationValues(Config::CONTEXT, [Install::SCHEMA_VERSION_KEY => '1.1.0']);
            Config::reload();

            $failed = false;
            try {
                Install::install(new class (Version::VERSION) extends Migration {
                    public function executeMigration(): never
                    {
                        throw new RuntimeException('the database went away mid-upgrade');
                    }
                });
            } catch (RuntimeException) {
                $failed = true;
            }

            self::assertTrue($failed, 'the failure was swallowed');
            self::assertNotSame(
                Version::SCHEMA,
                Install::getInstalledSchemaVersion(),
                'the instance claims the new schema after an upgrade that threw',
            );

            // The part a user cares about: try again and it finishes.
            self::assertTrue(Install::install(new Migration(Version::VERSION)));
            $DB->clearSchemaCache();

            self::assertSame(Version::SCHEMA, Install::getInstalledSchemaVersion());
            self::assertSame(
                array_map($this->normaliseSignature(...), self::EXPECTED['rules']),
                $this->signatureOf($table),
                'retrying the upgrade did not produce the installed shape',
            );
        } finally {
            CoreConfig::setConfigurationValues(Config::CONTEXT, [Install::SCHEMA_VERSION_KEY => '1.0.0']);
            Config::reload();
            Install::install(new Migration(Version::VERSION));
            $DB->clearSchemaCache();
        }
    }
}
