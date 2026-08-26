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
 * @link      https://github.com/Jovinull/ticketflow
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Ticketflow;

use CommonGLPI;
use Html;
use ProfileRight;
use Session;

/**
 * Exposes TicketFlow's right in the standard profile matrix.
 *
 * One right (`plugin_ticketflow_rule`) with the usual READ/UPDATE/CREATE/PURGE bits is
 * enough for 0.1: reading covers the rule list and the logs, UPDATE covers editing and
 * the plugin configuration, and manual execution is gated on UPDATE too. Splitting
 * "execute" out would be complexity without a demand behind it yet.
 */
class Profile extends \Profile
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && $item->getField('id')) {
            return self::createTabEntry(__('TicketFlow', 'ticketflow'));
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof \Profile) {
            (new self())->showRightsForm((int) $item->getID());
        }

        return true;
    }

    public function showRightsForm(int $profiles_id): void
    {
        if (!self::canView()) {
            return;
        }

        $can_edit = Session::haveRight(self::$rightname, UPDATE);

        echo "<div class='spaced'>";
        if ($can_edit) {
            echo "<form method='post' action='" . htmlescape(\Profile::getFormURL()) . "'>";
        }

        $this->displayRightsChoiceMatrix(
            [
                [
                    'itemtype' => Rule::class,
                    'label'    => Rule::getTypeName(Session::getPluralNumber()),
                    'field'    => Rule::$rightname,
                ],
            ],
            [
                'canedit' => $can_edit,
                'title'   => __('TicketFlow', 'ticketflow'),
            ],
        );

        if ($can_edit) {
            echo "<div class='text-center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';
    }

    /**
     * Load the plugin right into the session after a profile switch.
     *
     * Without this, the menu entry stays hidden until the next login.
     */
    public static function onChangeProfile(): void
    {
        if (!isset($_SESSION['glpiactiveprofile']['id'])) {
            return;
        }

        $rights = ProfileRight::getProfileRights(
            (int) $_SESSION['glpiactiveprofile']['id'],
            [Rule::$rightname],
        );

        $_SESSION['glpiactiveprofile'][Rule::$rightname] = $rights[Rule::$rightname] ?? 0;
    }

    /**
     * Grant the full right to the profiles that already administer GLPI.
     *
     * Must be idempotent: the plugin's install routine runs again on every upgrade, and
     * `ProfileRight::addProfileRights()` inserts unconditionally — calling it a second time
     * throws on the unicity index and takes the whole upgrade down with it.
     */
    public static function installRights(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (countElementsInTable(ProfileRight::getTable(), ['name' => Rule::$rightname]) === 0) {
            ProfileRight::addProfileRights([Rule::$rightname]);
        }

        // Any profile that can already configure GLPI gets the plugin right, so an
        // administrator is not locked out of their own plugin right after installing it.
        $iterator = $DB->request([
            'SELECT' => 'profiles_id',
            'FROM'   => ProfileRight::getTable(),
            'WHERE'  => ['name' => 'config', 'rights' => ['>', 0]],
        ]);

        foreach ($iterator as $row) {
            $DB->update(
                ProfileRight::getTable(),
                ['rights' => ALLSTANDARDRIGHT],
                ['profiles_id' => $row['profiles_id'], 'name' => Rule::$rightname],
            );

            if ((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) === (int) $row['profiles_id']) {
                $_SESSION['glpiactiveprofile'][Rule::$rightname] = ALLSTANDARDRIGHT;
            }
        }
    }

    public static function uninstallRights(): void
    {
        ProfileRight::deleteProfileRights([Rule::$rightname]);
        unset($_SESSION['glpiactiveprofile'][Rule::$rightname]);
    }
}
