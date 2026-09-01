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
use GlpiPlugin\Ticketclock\Config;
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
     * The four tables the plugin owns, and no others. A table added by a migration and not by
     * the installer, or the other way round, is the same drift seen from outside.
     */
    public function testThePluginOwnsExactlyTheseTables(): void
    {
        $declared = array_map(
            static fn(string $class): string => $class::getTable(),
            [Rule::class, RuleGroup::class, RuleAction::class, Execution::class],
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
