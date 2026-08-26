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

/**
 * Bootstrap for Rector.
 *
 * Loads GLPI so its classes resolve, then unregisters GLPI's legacy plugin autoloader.
 * That autoloader fires for any `GlpiPlugin\…` symbol and calls `Plugin::isPluginLoaded()`,
 * which is not reachable from inside Rector's scoped runtime — the result is
 * "Class Plugin not found" on every file of ours that mentions a GLPI class. Rector parses
 * our sources rather than loading them, so dropping that autoloader costs nothing.
 */

$glpi_root = dirname(__DIR__, 3);

require_once $glpi_root . '/vendor/autoload.php';

$constants = $glpi_root . '/stubs/glpi_constants.php';
if (file_exists($constants)) {
    require_once $constants;
}

foreach (spl_autoload_functions() ?: [] as $autoloader) {
    if ($autoloader === 'glpi_autoload') {
        spl_autoload_unregister($autoloader);
    }
}
