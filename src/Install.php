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

use Config as CoreConfig;
use CronTask;
use DisplayPreference;
use GlpiPlugin\Ticketclock\Enum\StartEvent;
use Migration;

/**
 * Schema lifecycle: install, upgrade, uninstall.
 *
 * Versioned from the very first release. `plugin_ticketclock_install()` is not a
 * throwaway script — it reads the schema version stored in the plugin's configuration
 * context and applies the migrations that are missing, so an existing installation can
 * always move forward without anybody hand-editing tables.
 */
final class Install
{
    /**
     * Where the installed schema version is kept.
     *
     * Public because it is a stable identifier rather than behaviour, and the schema tests
     * need to name it to build an older instance on purpose. The setter stays private: tests
     * are allowed to say which version is recorded, not to bypass the migration chain.
     */
    public const SCHEMA_VERSION_KEY = 'schema_version';

    /**
     * Ordered list of migrations. Each key is the version it produces.
     *
     * @var array<string, string> version => method name
     */
    private const MIGRATIONS = [
        '1.0.0' => 'migrateTo100',
        '1.1.0' => 'migrateTo110',
        '1.2.0' => 'migrateTo120',
    ];

    public static function install(Migration $migration): bool
    {
        $from = self::getInstalledSchemaVersion();

        foreach (self::MIGRATIONS as $version => $method) {
            if ($from !== null && version_compare($from, $version, '>=')) {
                continue;
            }

            // Applied, then recorded. The migration methods only queue their DDL; nothing
            // reaches the database until executeMigration() runs. Recording the version first
            // -- which is what this loop used to do, with a single execute after it -- meant
            // that a failure while applying left the instance claiming a schema it did not
            // have, and the next upgrade attempt would skip straight past the step that never
            // happened. Now a failure stops at the last version that genuinely landed, and
            // running the install again resumes from there.
            //
            // Executing per step is safe: executeMigration() empties its queues each time.
            self::$method($migration);
            $migration->executeMigration();
            self::setInstalledSchemaVersion($version);
        }

        Config::registerDefaults();
        Profile::installRights();
        Cron::register();

        return true;
    }

    public static function uninstall(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Crontasks first: an orphan task would keep appearing in the automatic actions
        // list and fail on every run.
        CronTask::unregister('ticketclock');

        foreach (self::tables() as $table) {
            if ($DB->tableExists($table)) {
                $DB->doQuery('DROP TABLE ' . $DB->quoteName($table));
            }
        }

        $DB->delete(DisplayPreference::getTable(), [
            'itemtype' => [Rule::class, Execution::class],
        ]);

        Profile::uninstallRights();

        Config::removeAll();
        CoreConfig::deleteConfigurationValues(Config::CONTEXT, [self::SCHEMA_VERSION_KEY]);

        return true;
    }

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        // Children first so a partial drop never leaves a dangling reference.
        return [
            Execution::getTable(),
            RuleAction::getTable(),
            RuleGroup::getTable(),
            Rule::getTable(),
        ];
    }

    public static function getInstalledSchemaVersion(): ?string
    {
        $values = CoreConfig::getConfigurationValues(Config::CONTEXT, [self::SCHEMA_VERSION_KEY]);
        $version = $values[self::SCHEMA_VERSION_KEY] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    private static function setInstalledSchemaVersion(string $version): void
    {
        CoreConfig::setConfigurationValues(Config::CONTEXT, [self::SCHEMA_VERSION_KEY => $version]);
    }

    // -----------------------------------------------------------------------------
    // Migrations
    // -----------------------------------------------------------------------------

    /**
     * Initial schema.
     *
     * Index choices are driven by the two queries that actually run in a loop:
     *  - the candidate query filters tickets by status + begin_waiting_date (core indexes)
     *    and rules by is_active + rule_type;
     *  - the idempotency check looks a claim key up by equality.
     * Everything else is a plain foreign-key index so the list screens and the purge stay
     * cheap. Nothing here is speculative.
     */
    private static function migrateTo100(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Raw CREATE TABLE, deliberately: GLPI's Migration API offers addField(), dropTable()
        // and renameTable() but no table builder, so every plugin writes the statement out.
        // What the query builder would buy is escaping, and there is nothing here to escape:
        // the names come from getTable() on the plugin's own classes, and quoteName() makes
        // that structural rather than incidental. Recorded by GLPI's security review as a
        // checklist note, with no injection path identified.
        $charset   = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';
        $suffix    = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

        $rules = Rule::getTable();
        if (!$DB->tableExists($rules)) {
            $DB->doQuery("
                CREATE TABLE {$DB->quoteName($rules)} (
                    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `entities_id`         INT UNSIGNED NOT NULL DEFAULT 0,
                    `is_recursive`        TINYINT NOT NULL DEFAULT 0,
                    `name`                VARCHAR(255) DEFAULT NULL,
                    `comment`             TEXT DEFAULT NULL,
                    `is_active`           TINYINT NOT NULL DEFAULT 0,
                    `ranking`             INT NOT NULL DEFAULT 0,
                    `rule_type`           VARCHAR(50) NOT NULL DEFAULT 'pending_inactivity',
                    `target_status`       INT NOT NULL DEFAULT 0,
                    `pendingreasons_id`   INT UNSIGNED NOT NULL DEFAULT 0,
                    `start_event`         VARCHAR(50) NOT NULL DEFAULT 'pending_start',
                    `delay_value`         INT NOT NULL DEFAULT 5,
                    `delay_unit`          VARCHAR(20) NOT NULL DEFAULT 'business_days',
                    `calendar_mode`       VARCHAR(20) NOT NULL DEFAULT 'entity',
                    `calendars_id`        INT UNSIGNED NOT NULL DEFAULT 0,
                    `reset_events`        VARCHAR(255) DEFAULT NULL,
                    `is_dry_run`          TINYINT NOT NULL DEFAULT 0,
                    `last_execution_date` TIMESTAMP NULL DEFAULT NULL,
                    `last_error`          TEXT DEFAULT NULL,
                    `last_error_date`     TIMESTAMP NULL DEFAULT NULL,
                    `date_creation`       TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`            TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `name` (`name`),
                    KEY `entities_id` (`entities_id`),
                    KEY `is_recursive` (`is_recursive`),
                    KEY `active_type` (`is_active`, `rule_type`),
                    KEY `ranking` (`ranking`),
                    KEY `calendars_id` (`calendars_id`),
                    KEY `pendingreasons_id` (`pendingreasons_id`),
                    KEY `date_creation` (`date_creation`),
                    KEY `date_mod` (`date_mod`)
                ) {$suffix}
            ");
        }

        $rulegroups = RuleGroup::getTable();
        if (!$DB->tableExists($rulegroups)) {
            $DB->doQuery("
                CREATE TABLE {$DB->quoteName($rulegroups)} (
                    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `plugin_ticketclock_rules_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `groups_id`                  INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unicity` (`plugin_ticketclock_rules_id`, `groups_id`),
                    KEY `groups_id` (`groups_id`)
                ) {$suffix}
            ");
        }

        $ruleactions = RuleAction::getTable();
        if (!$DB->tableExists($ruleactions)) {
            $DB->doQuery("
                CREATE TABLE {$DB->quoteName($ruleactions)} (
                    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `plugin_ticketclock_rules_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `action_type`                VARCHAR(50) NOT NULL DEFAULT '',
                    `ranking`                    INT NOT NULL DEFAULT 0,
                    `params`                     TEXT DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `rule_ranking` (`plugin_ticketclock_rules_id`, `ranking`)
                ) {$suffix}
            ");
        }

        $executions = Execution::getTable();
        if (!$DB->tableExists($executions)) {
            // `claim_key` is nullable and unique: MySQL/MariaDB permit duplicate NULLs, so
            // this behaves as a partial unique index — blocking rows (processing, executed,
            // failed) reserve the occurrence, while dry runs and skipped rows do not.
            $DB->doQuery("
                CREATE TABLE {$DB->quoteName($executions)} (
                    `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `plugin_ticketclock_rules_id` INT UNSIGNED NOT NULL DEFAULT 0,
                    `tickets_id`                 INT UNSIGNED NOT NULL DEFAULT 0,
                    `entities_id`                INT UNSIGNED NOT NULL DEFAULT 0,
                    `occurrence_key`             VARCHAR(100) NOT NULL DEFAULT '',
                    `claim_key`                  VARCHAR(150) DEFAULT NULL,
                    `state`                      VARCHAR(20) NOT NULL DEFAULT 'processing',
                    `reference_date`             TIMESTAMP NULL DEFAULT NULL,
                    `deadline_date`              TIMESTAMP NULL DEFAULT NULL,
                    `calendars_id`               INT UNSIGNED NOT NULL DEFAULT 0,
                    `calendar_name`              VARCHAR(255) DEFAULT NULL,
                    `delay_value`                INT NOT NULL DEFAULT 0,
                    `delay_unit`                 VARCHAR(20) NOT NULL DEFAULT '',
                    `used_elapsed_fallback`      TINYINT NOT NULL DEFAULT 0,
                    `itilvalidations_id`         INT UNSIGNED NOT NULL DEFAULT 0,
                    `triggered_at`               TIMESTAMP NULL DEFAULT NULL,
                    `completed_at`               TIMESTAMP NULL DEFAULT NULL,
                    `actions_result`             LONGTEXT DEFAULT NULL,
                    `error`                      TEXT DEFAULT NULL,
                    `date_creation`              TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `claim_key` (`claim_key`),
                    KEY `rule_ticket` (`plugin_ticketclock_rules_id`, `tickets_id`),
                    KEY `occurrence` (`plugin_ticketclock_rules_id`, `tickets_id`, `occurrence_key`),
                    KEY `tickets_id` (`tickets_id`),
                    KEY `entities_id` (`entities_id`),
                    KEY `state` (`state`),
                    KEY `triggered_at` (`triggered_at`),
                    KEY `date_creation` (`date_creation`)
                ) {$suffix}
            ");
        }

        self::addDefaultDisplayPreferences();
    }

    /**
     * Adds `start_event`: rules can now time the conversation instead of the state.
     *
     * The default preserves the behaviour of every rule that already exists, so an upgrade
     * changes nothing until somebody edits a rule.
     */
    private static function migrateTo110(Migration $migration): void
    {
        $migration->addField(
            Rule::getTable(),
            'start_event',
            // Explicit type rather than 'string': Migration would widen it to varchar(255),
            // which would not match what a fresh install creates.
            "varchar(50) NOT NULL DEFAULT '" . StartEvent::PendingStart->value . "'",
            ['after' => 'pendingreasons_id'],
        );
        $migration->migrationOneTable(Rule::getTable());
    }

    /**
     * Adds `last_error` and `last_error_date`: why a rule stopped running, kept on the rule.
     *
     * The engine refuses a rule whose stored actions cannot all be read, which is correct but
     * used to leave the reason in the server log and nowhere else. A refusal happens before
     * any ticket is chosen, so there is no execution to attach it to and inventing one would
     * put a row with no ticket into a log that is otherwise one row per ticket. The problem
     * belongs to the rule, so it is stored on the rule.
     *
     * Nullable with no default, so every existing rule upgrades as "nothing wrong", which is
     * true until the engine next looks at it.
     */
    private static function migrateTo120(Migration $migration): void
    {
        $migration->addField(
            Rule::getTable(),
            'last_error',
            'text',
            ['after' => 'last_execution_date'],
        );
        $migration->addField(
            Rule::getTable(),
            'last_error_date',
            'timestamp',
            ['after' => 'last_error'],
        );
        $migration->migrationOneTable(Rule::getTable());
    }

    /**
     * Give both list screens usable default columns instead of just the name.
     */
    private static function addDefaultDisplayPreferences(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $defaults = [
            Rule::class      => [80, 3, 8, 4, 6, 7],
            Execution::class => [2, 3, 4, 5, 6, 7],
        ];

        foreach ($defaults as $itemtype => $numbers) {
            foreach ($numbers as $rank => $num) {
                $exists = countElementsInTable(DisplayPreference::getTable(), [
                    'itemtype' => $itemtype,
                    'num'      => $num,
                    'users_id' => 0,
                ]);

                if ($exists === 0) {
                    $DB->insert(DisplayPreference::getTable(), [
                        'itemtype' => $itemtype,
                        'num'      => $num,
                        'rank'     => $rank + 1,
                        'users_id' => 0,
                    ]);
                }
            }
        }
    }
}
