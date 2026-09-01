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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Ticketclock\Execution;
use GlpiPlugin\Ticketclock\Menu;
use GlpiPlugin\Ticketclock\Profile;
use GlpiPlugin\Ticketclock\Rule;
use GlpiPlugin\Ticketclock\Version;

// This file is included *before* the plugin's PSR-4 autoloader exists. GLPI's
// Plugin::load() calls loadPluginSetupFile() first and registerPluginAutoloader() only
// afterwards, and plugin discovery (Plugin::getInformationsFromDirectory()) includes this
// file without registering any autoloader at all — which is exactly the path taken on an
// instance where TicketFlow has never been activated. Anything setup.php touches at
// include time therefore has to be required by hand.
require_once __DIR__ . '/src/Version.php';

// The names and the literals are both dictated by the ecosystem, not by taste.
//
// `pluginsGLPI/example` defines exactly these names, and the official release workflow
// greps this file for `PLUGIN_<KEY>_MIN_GLPI` / `_MAX_GLPI` with a *quoted* value to put
// the supported GLPI range on the GitHub release page. A constant reference here reads
// better but greps to nothing, and the release quietly loses that line.
//
// GlpiPlugin\Ticketclock\Version stays the source of truth for code -- the unit suite uses
// it without loading this file at all -- and a test asserts the two never drift apart.
define('PLUGIN_TICKETCLOCK_VERSION', '1.1.0');
define('PLUGIN_TICKETCLOCK_MIN_GLPI', '11.0.0');
define('PLUGIN_TICKETCLOCK_MAX_GLPI', '11.0.99');
define('PLUGIN_TICKETCLOCK_SCHEMA_VERSION', '1.2.0');

/**
 * Init hooks of the plugin.
 * REQUIRED
 */
function plugin_init_ticketclock(): void
{
    /** @var array<string, mixed> $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    // Note: Hooks::CSRF_COMPLIANT is deprecated since GLPI 11.0 and read by nothing in
    // core — CSRF is enforced globally by CheckCsrfListener for every non-AJAX POST.

    Plugin::registerClass(Profile::class, ['addtabon' => ['Profile']]);
    Plugin::registerClass(Rule::class);
    Plugin::registerClass(Execution::class);

    // Refresh the plugin rights when the user switches profile.
    $PLUGIN_HOOKS[Hooks::CHANGE_PROFILE]['ticketclock'] = Profile::onChangeProfile(...);

    if (!Plugin::isPluginActive('ticketclock')) {
        return;
    }

    if (Session::haveRight(Rule::$rightname, READ)) {
        $PLUGIN_HOOKS['menu_toadd']['ticketclock'] = ['admin' => Menu::class];
    }

    if (Session::haveRight(Rule::$rightname, UPDATE)) {
        $PLUGIN_HOOKS['config_page']['ticketclock'] = 'front/config.form.php';
    }

    // Keep plugin data consistent when referenced core objects are purged.
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['ticketclock'] = [
        'Ticket'   => Execution::onCoreItemPurged(...),
        'Group'    => Rule::onCoreItemPurged(...),
        'Calendar' => Rule::onCoreItemPurged(...),
    ];
}

/**
 * Get the name and the version of the plugin.
 * REQUIRED
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{glpi: array{min: string, max: string}}
 * }
 */
function plugin_version_ticketclock(): array
{
    return [
        'name'         => 'TicketFlow',
        'version'      => Version::VERSION,
        'author'       => 'Felipe Jovino',
        'license'      => 'MIT',
        'homepage'     => 'https://github.com/Jovinull/ticketclock',
        'requirements' => [
            'glpi' => [
                'min' => Version::MIN_GLPI,
                'max' => Version::MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install.
 */
function plugin_ticketclock_check_prerequisites(): bool
{
    return true;
}

/**
 * Check configuration process.
 *
 * @param bool $verbose Whether to display a message on failure.
 */
function plugin_ticketclock_check_config(bool $verbose = false): bool
{
    return true;
}
