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

use CommonDBChild;
use Dropdown;

/**
 * Links a rule to the assigned groups it targets.
 *
 * A separate table rather than a column because a ticket can carry several assigned
 * groups (verified on the production data: most tickets have two or more), so a rule that
 * could only name one group would be unusable. No row at all means "any group".
 */
class RuleGroup extends CommonDBChild
{
    public static $rightname = 'plugin_ticketclock_rule';

    public static $itemtype = Rule::class;
    public static $items_id = 'plugin_ticketclock_rules_id';

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Target group', 'Target groups', $nb, 'ticketclock');
    }

    /**
     * @return list<int>
     */
    public static function getGroupIdsForRule(int $rules_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($rules_id <= 0) {
            return [];
        }

        $out = [];
        $iterator = $DB->request([
            'SELECT' => 'groups_id',
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_ticketclock_rules_id' => $rules_id],
            'ORDER'  => 'groups_id',
        ]);

        foreach ($iterator as $row) {
            $out[] = (int) $row['groups_id'];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function getGroupNamesForRule(int $rules_id): array
    {
        $names = [];
        foreach (self::getGroupIdsForRule($rules_id) as $groups_id) {
            $names[] = Dropdown::getDropdownName('glpi_groups', $groups_id);
        }

        return $names;
    }

    /**
     * Replace the rule's group list with the given ids.
     *
     * @param array<int|string> $groups_id
     */
    public static function setGroupsForRule(int $rules_id, array $groups_id): void
    {
        if ($rules_id <= 0) {
            return;
        }

        $wanted = [];
        foreach ($groups_id as $value) {
            $id = (int) $value;
            if ($id > 0 && !in_array($id, $wanted, true)) {
                $wanted[] = $id;
            }
        }

        $current = self::getGroupIdsForRule($rules_id);
        $link    = new self();

        foreach (array_diff($current, $wanted) as $obsolete) {
            $link->deleteByCriteria([
                'plugin_ticketclock_rules_id' => $rules_id,
                'groups_id'                  => $obsolete,
            ]);
        }

        foreach (array_diff($wanted, $current) as $missing) {
            (new self())->add([
                'plugin_ticketclock_rules_id' => $rules_id,
                'groups_id'                  => $missing,
            ]);
        }
    }
}
