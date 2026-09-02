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

use Entity as CoreEntity;
use GlpiPlugin\Ticketclock\EntityConfig;

include __DIR__ . '/../../../inc/includes.php';

Session::checkRight(EntityConfig::$rightname, UPDATE);

$entities_id = (int) ($_POST['entities_id'] ?? -1);

// The entity has to be one this session can actually reach. Without this, the form's own
// hidden field would decide which entity's policy is rewritten, and an operator confined to
// one branch could pause -- or un-pause -- another one.
if ($entities_id < 0 || !Session::haveAccessToEntity($entities_id)) {
    Html::displayRightError();
}

if (isset($_POST['update'])) {
    // No explicit CSRF check here: GLPI 11 validates *and consumes* the token for every
    // non-AJAX POST in CheckCsrfListener, so checking again would fail on a token that has
    // already been spent.
    $saved = EntityConfig::setForEntity($entities_id, [
        'execution_enabled'     => (int) ($_POST['execution_enabled'] ?? CoreEntity::CONFIG_PARENT),
        'dry_run'               => (int) ($_POST['dry_run'] ?? CoreEntity::CONFIG_PARENT),
        'fallback_calendars_id' => (int) ($_POST['fallback_calendars_id'] ?? CoreEntity::CONFIG_PARENT),
    ]);

    if ($saved) {
        Session::addMessageAfterRedirect(htmlescape(__('Configuration saved.', 'ticketclock')), true, INFO);
    }
}

Html::back();
