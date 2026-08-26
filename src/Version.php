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

/**
 * The plugin's version numbers, in one place.
 *
 * GLPI's convention is a `PLUGIN_<NAME>_VERSION` constant, and setup.php still defines it
 * so anything expecting that convention keeps working. But a runtime `define()` is
 * invisible to static analysis and to any code loaded before setup.php, so the values live
 * here and setup.php reads them — one source of truth rather than two that can drift.
 */
final class Version
{
    /** Plugin version, as published in the catalog manifest. */
    public const VERSION = '1.0.0';

    /** Minimal GLPI version, inclusive. */
    public const MIN_GLPI = '11.0.0';

    /** Maximum GLPI version, exclusive. */
    public const MAX_GLPI = '11.0.99';

    /**
     * Schema version handled by this code base.
     *
     * Deliberately independent of the plugin version: not every release changes the
     * schema, and {@see Install} compares this against what the database reports to decide
     * which migrations are missing.
     */
    public const SCHEMA = '1.1.0';

    private function __construct() {}
}
