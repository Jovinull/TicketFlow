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

namespace GlpiPlugin\Ticketclock\Support;

use CommonDBTM;

/**
 * Whether an entity-scoped object is usable from a given entity.
 *
 * GLPI's own answer, from `DbUtils::getEntitiesRestrictCriteria()`: an object is available in
 * entity E when it belongs to E, or when it is recursive and belongs to an ancestor of E.
 * Reproduced here rather than called because core's version builds SQL criteria for a query,
 * and the checks that need this already hold the object.
 *
 * It exists as its own class because two different things need the same answer -- the group a
 * rule hands a ticket to, and the calendar an entity falls back on -- and a second copy of the
 * rule is a second place for it to drift from core.
 */
final class EntityScope
{
    private function __construct() {}

    /** Convenience for a loaded item: reads `entities_id` and `is_recursive` off its fields. */
    public static function itemIsVisibleIn(CommonDBTM $item, int $entities_id): bool
    {
        return self::isVisibleIn(
            (int) ($item->fields['entities_id'] ?? 0),
            (bool) ($item->fields['is_recursive'] ?? false),
            $entities_id,
        );
    }

    /**
     * @param int  $item_entity   the entity the object belongs to
     * @param bool $is_recursive  whether the object is published to child entities
     * @param int  $entities_id   the entity the object would be used from
     */
    public static function isVisibleIn(int $item_entity, bool $is_recursive, int $entities_id): bool
    {
        if ($item_entity === $entities_id) {
            return true;
        }

        if (!$is_recursive) {
            return false;
        }

        $ancestors = array_map(intval(...), getAncestorsOf('glpi_entities', $entities_id));

        return in_array($item_entity, $ancestors, true);
    }
}
