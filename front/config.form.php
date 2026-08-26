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

use GlpiPlugin\Ticketclock\Config;
use GlpiPlugin\Ticketclock\Menu;
use GlpiPlugin\Ticketclock\Rule;

include __DIR__ . '/../../../inc/includes.php';

Session::checkRight(Rule::$rightname, UPDATE);

if (isset($_POST['update'])) {
    // No explicit CSRF check here: GLPI 11 validates *and consumes* the token for every
    // non-AJAX POST in CheckCsrfListener, so checking again would fail on a token that has
    // already been spent. Declaring Hooks::CSRF_COMPLIANT in setup.php is what enrols the
    // plugin's forms in that protection.
    Config::set([
        'execution_enabled'     => empty($_POST['execution_enabled']) ? 0 : 1,
        'dry_run_global'        => empty($_POST['dry_run_global']) ? 0 : 1,
        'log_dry_runs'          => empty($_POST['log_dry_runs']) ? 0 : 1,
        'batch_size'            => max(1, (int) ($_POST['batch_size'] ?? 200)),
        'max_tickets_per_run'   => max(1, (int) ($_POST['max_tickets_per_run'] ?? 1000)),
        'log_retention_days'    => max(0, (int) ($_POST['log_retention_days'] ?? 90)),
        'fallback_calendars_id' => max(0, (int) ($_POST['fallback_calendars_id'] ?? 0)),
        'system_users_id'       => max(0, (int) ($_POST['system_users_id'] ?? 0)),
    ]);

    Session::addMessageAfterRedirect(htmlescape(__('Configuration saved.', 'ticketclock')), true, INFO);
    Html::back();
}

Html::header(
    __('TicketFlow', 'ticketclock'),
    $_SERVER['PHP_SELF'],
    'admin',
    Menu::class,
    'config',
);

Config::showForm();

Html::footer();
