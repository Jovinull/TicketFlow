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
use Glpi\Application\View\TemplateRenderer;
use Session;
use User;

/**
 * Plugin-wide settings, stored in GLPI's own `glpi_configs` under a plugin context.
 *
 * Only settings that genuinely belong to the whole plugin live here — throughput limits,
 * retention, and the two safety switches. Anything that varies per rule (delays,
 * calendars, groups, messages) belongs to the rule, not here.
 */
final class Config
{
    public const CONTEXT = 'plugin:ticketclock';

    /**
     * Defaults for the settings that belong to the instance.
     *
     * What is left here describes one cron process running once for the whole instance:
     * how many tickets it looks at, who it writes as, how much it logs and for how long.
     * "Per entity" would not mean anything for any of them.
     *
     * The two safety switches and the fallback calendar used to live here and now live on
     * {@see EntityConfig}, one row per entity: a branch has to be able to pause its own
     * engine, and a calendar belongs to an entity, so a single global one computed other
     * branches' deadlines from opening times that were not theirs.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'batch_size'            => '200',
        'max_tickets_per_run'   => '1000',
        'system_users_id'       => '0',
        'log_dry_runs'          => '1',
        'log_retention_days'    => '90',
        'ignored_message_marks' => self::DEFAULT_IGNORED_MARKS,
    ];

    /**
     * Text that marks a followup as machinery answering, not a person.
     *
     * An out of office reply arrives through the mail collector as an ordinary public
     * followup signed by the requester, and the engine reads it as an answer. On a rule
     * whose clock runs while the last message came from your own team, that silences the
     * rule: the ticket is never chased, never solved, and nothing is logged, so it rots
     * without anybody finding out.
     *
     * Substrings rather than regular expressions, on purpose. They are matched in SQL before
     * the latest message is picked, which is the only place the exclusion can work; a regex
     * would have to be applied in PHP afterwards, by which point the wrong row has already
     * won. They also cannot be made to backtrack, and an administrator editing this field
     * cannot break the engine with a bad pattern. `AutomaticReplyFilter` escapes LIKE's own
     * wildcards so a mark means the characters it contains and nothing else.
     *
     * Matching ignores case because that is what GLPI's collation on the followup content
     * gives, not because this code enforces it. A test asserts it so the promise stays true.
     *
     * The default covers what the common mail systems put in an automatic reply, in the
     * languages this plugin ships plus German and Spanish. It is a starting point and is
     * meant to be edited: local gateways word these differently. The diagnostics screen carries
     * a recent sample of what each mark matches on this instance, counted with the same escaping
     * the engine uses -- a sample for judging a mark rather than a replay of what the rules
     * excluded; see {@see \GlpiPlugin\Ticketclock\Inspector::MARK_SAMPLE_DAYS}.
     */
    public const DEFAULT_IGNORED_MARKS = "Out of Office\n"
        . "Out of the Office\n"
        . "Automatic reply\n"
        . "Auto-Reply\n"
        . "Autoreply\n"
        . "Absence du bureau\n"
        . "Réponse automatique\n"
        . "Resposta automática\n"
        . "Ausência temporária\n"
        . "Automatische Antwort\n"
        . "Respuesta automática";

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = CoreConfig::getConfigurationValues(self::CONTEXT);
            self::$cache = array_replace(self::DEFAULTS, is_array($stored) ? $stored : []);
        }

        return self::$cache;
    }

    public static function get(string $name, string $default = ''): string
    {
        return (string) (self::all()[$name] ?? $default);
    }

    public static function getInt(string $name, int $default = 0): int
    {
        $value = self::all()[$name] ?? null;
        $int   = is_numeric($value) ? (int) $value : $default;

        // Also clamped on the way out, so a value stored before this guard existed -- or
        // written straight into the table -- cannot silently stop the engine.
        return array_key_exists($name, self::MINIMUMS)
            ? max($int, self::MINIMUMS[$name])
            : $int;
    }

    public static function getBool(string $name, bool $default = false): bool
    {
        $value = self::all()[$name] ?? null;

        return $value === null ? $default : (bool) (int) $value;
    }

    /**
     * Settings that must stay at or above a floor to mean anything.
     *
     * The form declares these minimums too, but `min` on a number input is a hint to the
     * browser and nothing else: a hand-made POST, an import, or a call from code can still
     * store a zero. A zero here does not mean "no limit" -- it means the engine looks at no
     * tickets at all and reports success, which is the worst way for this plugin to fail.
     *
     * @var array<string, int>
     */
    private const MINIMUMS = [
        'batch_size'          => 1,
        'max_tickets_per_run' => 1,
    ];

    /**
     * @param array<string, mixed> $values
     */
    public static function set(array $values): void
    {
        $clean = [];
        foreach ($values as $name => $value) {
            if (!array_key_exists($name, self::DEFAULTS)) {
                continue;
            }
            $clean[$name] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

            if (array_key_exists($name, self::MINIMUMS)) {
                $clean[$name] = (string) max((int) $clean[$name], self::MINIMUMS[$name]);
            }

            // The acting user ends up as the author of followups and solutions on real
            // tickets, so it is the one setting where a typo is written into somebody's
            // ticket history and stays there. An id nobody can resolve is refused rather
            // than stored: the engine would otherwise attribute its work to a user that
            // does not exist, and the audit trail would name nobody.
            if ($name === 'system_users_id' && (int) $clean[$name] > 0 && !self::userExists((int) $clean[$name])) {
                unset($clean[$name]);
            }
        }

        if ($clean !== []) {
            CoreConfig::setConfigurationValues(self::CONTEXT, $clean);
            self::$cache = null;
        }
    }

    /**
     * The marks currently configured, empty ones dropped.
     *
     * @return list<string>
     */
    public static function ignoredMessageMarks(): array
    {
        $raw = self::get('ignored_message_marks');

        // explode on normalised newlines rather than preg_split: the separator is a literal,
        // so there is nothing a regex buys here beyond a function that can fail.
        $marks = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $raw)) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $marks[] = $line;
            }
        }

        return $marks;
    }

    private static function userExists(int $users_id): bool
    {
        $user = new User();

        return $user->getFromDB($users_id) && (int) $user->fields['is_deleted'] === 0;
    }

    /** Write any missing default. Called on install and on every init (cheap, cached). */
    public static function registerDefaults(): void
    {
        $stored = CoreConfig::getConfigurationValues(self::CONTEXT);
        $missing = array_diff_key(self::DEFAULTS, is_array($stored) ? $stored : []);

        if ($missing !== []) {
            CoreConfig::setConfigurationValues(self::CONTEXT, $missing);
            self::$cache = null;
        }
    }

    /**
     * Drop the in-process cache.
     *
     * Needed whenever the rows change behind this class's back -- another process, a
     * console command, or a test writing straight to the table.
     */
    public static function reload(): void
    {
        self::$cache = null;
    }

    public static function removeAll(): void
    {
        CoreConfig::deleteConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));
        self::$cache = null;
    }

    // -----------------------------------------------------------------------------
    // Derived helpers
    // -----------------------------------------------------------------------------

    /**
     * The user generated followups and solutions are attributed to.
     *
     * Falls back to core's `system_user`, the same account GLPI's own PendingReasonCron
     * requires, so most installations need no extra setup.
     */
    public static function getActingUserId(): int
    {
        $configured = self::getInt('system_users_id');
        if ($configured > 0) {
            return $configured;
        }

        $core = CoreConfig::getConfigurationValues('core', ['system_user']);
        $system_user = (int) ($core['system_user'] ?? 0);

        return max($system_user, 0);
    }

    /**
     * Problems an administrator should know about before arming a rule.
     *
     * The two switches are policy of one entity now, so they can only be reported against
     * one. Callers that are looking at a rule pass the rule's entity; a screen that is not
     * about a particular entity passes the one the session is in, which is the entity whose
     * answer that person can act on.
     *
     * @param int|null $entities_id the entity to report the switches for, null to report
     *                              only what belongs to the instance
     * @return list<string>
     */
    public static function getHealthWarnings(?int $entities_id = null): array
    {
        $warnings = [];

        if ($entities_id !== null && !EntityConfig::isExecutionEnabled($entities_id)) {
            $warnings[] = __('Execution is disabled for this entity: rules are evaluated and logged, but no ticket is modified.', 'ticketclock');
        }

        if ($entities_id !== null && EntityConfig::isDryRun($entities_id)) {
            $warnings[] = __('Dry run is enabled for this entity: every rule behaves as a simulation.', 'ticketclock');
        }

        if (self::getActingUserId() <= 0) {
            $warnings[] = __('No acting user is configured (neither TicketFlow nor GLPI\'s "system_user"). Actions that need an author, such as adding a solution, will fail.', 'ticketclock');
        }

        return $warnings;
    }

    // -----------------------------------------------------------------------------
    // UI
    // -----------------------------------------------------------------------------

    public static function showForm(): void
    {
        if (!Session::haveRight(Rule::$rightname, UPDATE)) {
            return;
        }

        TemplateRenderer::getInstance()->display('@ticketclock/config_form.html.twig', [
            'config'   => self::all(),
            'warnings' => self::getHealthWarnings(),
            'acting_user' => self::getActingUserId() > 0
                ? \Dropdown::getDropdownName(User::getTable(), self::getActingUserId())
                : '',
        ]);
    }
}
