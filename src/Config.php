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
     * Defaults. A fresh install is deliberately inert: execution off, global dry run on,
     * so installing the plugin can never start closing tickets before anybody configured
     * anything.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'execution_enabled'     => '0',
        'dry_run_global'        => '1',
        'batch_size'            => '200',
        'max_tickets_per_run'   => '1000',
        'fallback_calendars_id' => '0',
        'system_users_id'       => '0',
        'log_dry_runs'          => '1',
        'log_retention_days'    => '90',
    ];

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
        }

        if ($clean !== []) {
            CoreConfig::setConfigurationValues(self::CONTEXT, $clean);
            self::$cache = null;
        }
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
     * @return list<string>
     */
    public static function getHealthWarnings(): array
    {
        $warnings = [];

        if (!self::getBool('execution_enabled')) {
            $warnings[] = __('Execution is disabled: rules are evaluated and logged, but no ticket is modified.', 'ticketclock');
        }

        if (self::getBool('dry_run_global')) {
            $warnings[] = __('Global dry run is enabled: every rule behaves as a simulation.', 'ticketclock');
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
