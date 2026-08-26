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

use GlpiPlugin\Ticketflow\Install;
use GlpiPlugin\Ticketflow\Version;

/**
 * Plugin install / upgrade process.
 *
 * GLPI calls this both for a first install and for an upgrade; Install::install() reads
 * the stored schema version and applies whatever migrations are missing.
 */
function plugin_ticketflow_install(): bool
{
    $migration = new Migration(Version::VERSION);

    return Install::install($migration);
}

/**
 * Plugin uninstall process.
 *
 * Drops the plugin's own tables, its automatic actions, its right and its configuration.
 * Core data is never touched: TicketFlow only ever read from it, and the followups and
 * solutions it created belong to the tickets now, not to the plugin.
 */
function plugin_ticketflow_uninstall(): bool
{
    return Install::uninstall();
}
