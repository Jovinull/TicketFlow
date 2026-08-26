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

use GlpiPlugin\Ticketclock\Menu;
use GlpiPlugin\Ticketclock\Rule;

include __DIR__ . '/../../../inc/includes.php';

Session::checkRight(Rule::$rightname, READ);

$rule = new Rule();

if (isset($_POST['add'])) {
    $rule->check(-1, CREATE, $_POST);
    $newID = $rule->add($_POST);
    Html::redirect($newID > 0 ? Rule::getFormURLWithID($newID) : Rule::getSearchURL());
} elseif (isset($_POST['update'])) {
    $rule->check((int) $_POST['id'], UPDATE);
    $rule->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $rule->check((int) $_POST['id'], PURGE);
    $rule->delete($_POST, true);
    Html::redirect(Rule::getSearchURL());
} elseif (isset($_POST['duplicate'])) {
    $rule->check((int) $_POST['id'], CREATE);
    $newID = $rule->clone(['name' => sprintf(__('%s (copy)', 'ticketclock'), $rule->fields['name'])]);
    if ($newID > 0) {
        Session::addMessageAfterRedirect(htmlescape(__('Rule duplicated. The copy is inactive.', 'ticketclock')), true, INFO);
        Html::redirect(Rule::getFormURLWithID($newID));
    }
    Html::back();
}

$id = (int) ($_GET['id'] ?? -1);

Html::header(
    Rule::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'admin',
    Menu::class,
    'rule',
);

$rule->display(['id' => $id]);

Html::footer();
