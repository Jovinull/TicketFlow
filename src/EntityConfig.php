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

use Calendar;
use CommonDBTM;
use CommonGLPI;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Ticketclock\Support\EntityScope;
use Session;

/**
 * The settings that mean something per entity, stored one row per entity.
 *
 * TicketFlow scopes its rules by entity, and since the security review its diagnostics and
 * its group assignment too. Configuration was the last place that ignored the tree: a single
 * global row decided whether the engine was allowed to act, whether it was in simulation, and
 * which calendar it fell back on -- for every branch at once.
 *
 * Only the settings whose scope has an observable consequence live here. Batch size, the
 * per-run ceiling and log retention describe one cron process running once for the instance,
 * so they stay in {@see Config}: "per entity" would not mean anything for them.
 *
 * Inheritance follows core rather than inventing a second convention: {@see Entity::CONFIG_PARENT}
 * (-2) stored in a field means "ask the parent", resolved by walking up until a concrete value
 * is found, which is what `Entity::getUsedConfig()` does. The root entity always holds concrete
 * values -- the install seeds it -- so the walk terminates.
 */
class EntityConfig extends CommonDBTM
{
    /**
     * Editing an entity's policy needs the same right as editing its rules.
     *
     * Deliberately not core's `config`: this is not instance-wide setup, it is the policy of
     * one branch, and the person who owns that branch's rules is the person who owns whether
     * they are allowed to run. The instance-wide settings stay behind core's `config` on
     * TicketFlow's own configuration screen.
     */
    public static $rightname = 'plugin_ticketclock_rule';

    /** Fields that carry a value or the inherit sentinel. */
    public const INHERITABLE = [
        'execution_enabled',
        'dry_run',
        'fallback_calendars_id',
    ];

    /**
     * What the root entity is seeded with.
     *
     * A fresh install is inert on purpose -- execution off, simulation on -- so installing the
     * plugin can never start closing tickets before anybody configured anything. Children
     * inherit, so the whole tree is inert until somebody says otherwise, once.
     *
     * @var array<string, int>
     */
    public const ROOT_DEFAULTS = [
        'execution_enabled'     => 0,
        'dry_run'               => 1,
        'fallback_calendars_id' => 0,
    ];

    /** @var array<string, array<int, int>> field => entity => resolved value */
    private static array $resolved = [];

    /** @var array<int, array<string, int>>|null entity => stored row, null until loaded */
    private static ?array $rows = null;

    /** @var array<int, int> entity => fallback calendar id, after the visibility check */
    private static array $checked_calendars = [];

    public static function getTypeName($nb = 0)
    {
        return __('TicketFlow', 'ticketclock');
    }

    public static function getIcon(): string
    {
        return 'ti ti-clock-cog';
    }

    // -----------------------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------------------

    /**
     * The value in force for an entity, following inheritance up the tree.
     *
     * An entity with no row of its own inherits, which is what an entity created after the
     * plugin was configured does: nothing has to be written for a new branch to behave like
     * its parent.
     */
    public static function getUsedValue(string $field, int $entities_id): int
    {
        if (!in_array($field, self::INHERITABLE, true)) {
            return 0;
        }

        if (isset(self::$resolved[$field][$entities_id])) {
            return self::$resolved[$field][$entities_id];
        }

        $rows    = self::rows();
        $current = $entities_id;
        $value   = self::ROOT_DEFAULTS[$field];

        // Bounded by the depth of the entity tree. `seen` guards against a cycle in
        // glpi_entities rather than trusting the table: a loop here would hang the cron.
        $seen = [];
        while ($current >= 0 && !isset($seen[$current])) {
            $seen[$current] = true;

            $stored = $rows[$current][$field] ?? Entity::CONFIG_PARENT;
            if ($stored !== Entity::CONFIG_PARENT) {
                $value = $stored;
                break;
            }

            if ($current === 0) {
                break;
            }

            $current = self::parentOf($current);
        }

        return self::$resolved[$field][$entities_id] = $value;
    }

    public static function isExecutionEnabled(int $entities_id): bool
    {
        return self::getUsedValue('execution_enabled', $entities_id) === 1;
    }

    public static function isDryRun(int $entities_id): bool
    {
        return self::getUsedValue('dry_run', $entities_id) === 1;
    }

    /**
     * The fallback calendar for an entity, 0 when none applies.
     *
     * Validated on the way out, not only on the way in. The stored id can stop being valid
     * without anybody touching this plugin -- the calendar is deleted, or moved to another
     * entity -- and a calendar that belongs somewhere else must not compute this entity's
     * deadlines. Falling back to 0 puts the rule on plain elapsed time, which is recorded on
     * every execution row, instead of on somebody else's business hours, which is not.
     */
    public static function getFallbackCalendarId(int $entities_id): int
    {
        // Memoised because the engine asks this once per ticket: without it a cron pass over a
        // backlog would load the same calendar row thousands of times.
        if (isset(self::$checked_calendars[$entities_id])) {
            return self::$checked_calendars[$entities_id];
        }

        $calendars_id = self::getUsedValue('fallback_calendars_id', $entities_id);
        if ($calendars_id <= 0) {
            return self::$checked_calendars[$entities_id] = 0;
        }

        $calendar = new Calendar();
        if (!$calendar->getFromDB($calendars_id)) {
            return self::$checked_calendars[$entities_id] = 0;
        }

        return self::$checked_calendars[$entities_id] = EntityScope::itemIsVisibleIn($calendar, $entities_id)
            ? $calendars_id
            : 0;
    }

    private static function parentOf(int $entities_id): int
    {
        $entity = new Entity();

        return $entity->getFromDB($entities_id) ? (int) $entity->fields['entities_id'] : -1;
    }

    /**
     * Every stored row, read once per request.
     *
     * One query rather than one per level: the resolver is called for every rule in a cron
     * pass, and the table has one row per configured entity at most.
     *
     * @return array<int, array<string, int>>
     */
    private static function rows(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        /** @var \DBmysql $DB */
        global $DB;

        self::$rows = [];
        if (!$DB->tableExists(self::getTable())) {
            return self::$rows;
        }

        foreach ($DB->request(['FROM' => self::getTable()]) as $row) {
            $entity = (int) $row['entities_id'];
            foreach (self::INHERITABLE as $field) {
                self::$rows[$entity][$field] = (int) $row[$field];
            }
        }

        return self::$rows;
    }

    /**
     * Drop the caches.
     *
     * Needed whenever the rows change behind this class's back -- another process, the
     * install, or a test writing straight to the table.
     */
    public static function reload(): void
    {
        self::$rows              = null;
        self::$resolved          = [];
        self::$checked_calendars = [];
    }

    // -----------------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------------

    /**
     * Store an entity's policy, creating the row if it has none.
     *
     * @param array<string, int> $values
     */
    public static function setForEntity(int $entities_id, array $values): bool
    {
        $clean = [];
        foreach (self::INHERITABLE as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }

            $value = (int) $values[$field];

            // The root has nothing to inherit from, so "inherit" there would leave the walk
            // with no concrete value to find. Refused rather than silently rewritten.
            if ($value === Entity::CONFIG_PARENT && $entities_id === 0) {
                Session::addMessageAfterRedirect(
                    htmlescape(__('The root entity cannot inherit: it has no parent.', 'ticketclock')),
                    true,
                    ERROR,
                );
                return false;
            }

            if ($field === 'fallback_calendars_id' && !self::calendarIsAcceptable($value, $entities_id)) {
                return false;
            }

            $clean[$field] = $value;
        }

        if ($clean === []) {
            return true;
        }

        $config = new self();
        $existing = $config->find(['entities_id' => $entities_id], [], 1);
        $written = $existing !== []
            ? $config->update(['id' => (int) array_key_first($existing)] + $clean)
            : $config->add(['entities_id' => $entities_id] + $clean);

        self::reload();

        return (bool) $written;
    }

    /**
     * The dropdown restricts a browser, not the database.
     *
     * The same reasoning as the group on an assignment action: a hand-made POST can carry any
     * calendar id, and this one ends up deciding the deadline of every ticket in the entity.
     * A calendar from another branch would compute this branch's business hours from someone
     * else's opening times, which is wrong quietly -- nothing fails, the dates are just not
     * the ones anybody agreed to.
     */
    private static function calendarIsAcceptable(int $calendars_id, int $entities_id): bool
    {
        if ($calendars_id === Entity::CONFIG_PARENT || $calendars_id === 0) {
            return true;
        }

        $calendar = new Calendar();
        if (!$calendar->getFromDB($calendars_id)) {
            Session::addMessageAfterRedirect(
                htmlescape(__('That calendar no longer exists.', 'ticketclock')),
                true,
                ERROR,
            );
            return false;
        }

        if (!EntityScope::itemIsVisibleIn($calendar, $entities_id)) {
            Session::addMessageAfterRedirect(
                htmlescape(__('That calendar does not belong to this entity.', 'ticketclock')),
                true,
                ERROR,
            );
            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------------------
    // UI
    // -----------------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Entity || $item->isNewItem() || !Session::haveRight(self::$rightname, READ)) {
            return '';
        }

        return self::getTypeName();
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Entity) {
            self::showForEntity((int) $item->getID());
        }

        return true;
    }

    public static function showForEntity(int $entities_id): void
    {
        if (!Session::haveRight(self::$rightname, READ)) {
            return;
        }

        $stored = self::rows()[$entities_id] ?? [];
        $values = [];
        foreach (self::INHERITABLE as $field) {
            $values[$field] = $stored[$field] ?? ($entities_id === 0
                ? self::ROOT_DEFAULTS[$field]
                : Entity::CONFIG_PARENT);
        }

        // The root has nothing above it, so "inherit" is not offered there -- the walk would
        // have no concrete value to land on.
        $choices = [0 => __('No'), 1 => __('Yes')];
        if ($entities_id !== 0) {
            $choices = [Entity::CONFIG_PARENT => __('Inheritance of the parent entity')] + $choices;
        }

        TemplateRenderer::getInstance()->display('@ticketclock/entity_config.html.twig', [
            'entities_id' => $entities_id,
            'is_root'     => $entities_id === 0,
            'can_edit'    => Session::haveRight(self::$rightname, UPDATE),
            'choices'     => $choices,
            'values'      => $values,
            'in_force'    => [
                'execution_enabled'     => self::getUsedValue('execution_enabled', $entities_id),
                'dry_run'               => self::getUsedValue('dry_run', $entities_id),
                'fallback_calendars_id' => self::getFallbackCalendarId($entities_id),
            ],
        ]);
    }
}
