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

/**
 * Bootstrap for the integration suite.
 *
 * Two ways in, because there are two realistic setups:
 *
 *  1. The plugin sits inside a **GLPI development checkout**, which ships its own
 *     `tests/bootstrap.php`. That is what the official pluginsGLPI skeleton assumes, and
 *     it is used when present so CI behaves exactly like every other GLPI plugin.
 *
 *  2. The plugin sits inside a **plain GLPI installation** — a release tarball, a distro
 *     package, a container. Those carry no `tests/` directory and no PHPUnit, so the
 *     skeleton bootstrap simply is not there. In that case the kernel is booted directly,
 *     which is all these tests actually need: `$DB`, `$CFG_GLPI` and the plugin autoloader.
 *
 * The second path is what makes it possible to run the suite against a restored copy of a
 * production database, which is the most valuable thing one can do with it.
 */

$plugin_dir = dirname(__DIR__);
$plugin_key = basename($plugin_dir);
$glpi_root  = dirname($plugin_dir, 2);

require $plugin_dir . '/vendor/autoload.php';

$glpi_test_bootstrap = $glpi_root . '/tests/bootstrap.php';

if (file_exists($glpi_test_bootstrap)) {
    require $glpi_test_bootstrap;
} else {
    if (!file_exists($glpi_root . '/vendor/autoload.php')) {
        throw new RuntimeException(
            sprintf('GLPI does not look installed at "%s".', $glpi_root),
        );
    }

    require_once $glpi_root . '/vendor/autoload.php';

    (new Glpi\Kernel\Kernel())->boot();

    // Register the plugin autoloaders and run plugin_init_*, exactly as a web request does.
    // The development bootstrap does this itself.
    (new Plugin())->init();
}

/** @var DBmysql|null $DB */
global $DB;
if (!($DB instanceof DBmysql) || !$DB->connected) {
    throw new RuntimeException('GLPI is not connected to a database.');
}

// -----------------------------------------------------------------------------
// The session the suite runs in, established the same way whichever path was taken.
//
// This used to live inside the `else` above, which meant it only ever ran against a plain
// installation. In a development checkout GLPI's own bootstrap took over and the suite ran
// with no cron marker and no rights -- so every test that writes a ticket failed, but only
// on CI, which is the one place nobody was watching.
// -----------------------------------------------------------------------------

// GLPI's cron context, because that is the context the engine runs in for real. It is not a
// shortcut: core gates ticket updates on it. See CommonITILObject::handleTemplateFields(),
// which skips the ticket-template mandatory field restrictions when Session::isCron() is
// true -- without this marker every Ticket::update() is refused and the suite would be
// testing a situation that never happens in production. Session::isCron() additionally
// requires a CLI context, which a PHPUnit run always is.
$_SESSION['glpicronuserrunning'] = 'cron_ticketclock_tests';

// The engine writes followups and solutions; core attributes them to the session user when
// none is given, and logs under this name.
$_SESSION['glpiname']         = 'cli';
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
$_SESSION['glpi_use_mode']    = Session::NORMAL_MODE;

// Rights are granted explicitly rather than inherited from the cron marker. Session::isCron()
// makes haveRight() answer yes to everything, which is convenient right up to the moment a
// test runs somewhere that marker is not set -- and then the failure looks like a bug in the
// engine instead of a missing profile.
$_SESSION['glpiactiveprofile'] = [
    'id'        => 0,
    'name'      => 'ticketclock-tests',
    'interface' => 'central',
] + array_fill_keys(
    ['ticket', 'group', 'calendar', 'entity', 'config', 'profile', 'user', GlpiPlugin\Ticketclock\Rule::$rightname],
    ALLSTANDARDRIGHT | READNOTE | UPDATENOTE | UNLOCK,
);

$_SESSION['glpiactiveentities']        = [0];
$_SESSION['glpiactive_entity']         = 0;
$_SESSION['glpiactiveentities_string'] = "'0'";
$_SESSION['glpiactive_entity_recursive'] = true;

if (!Plugin::isPluginActive($plugin_key)) {
    throw new RuntimeException(
        sprintf('Plugin %s is not active in the test database', $plugin_key),
    );
}
