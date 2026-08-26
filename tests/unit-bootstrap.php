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
 * Bootstrap for the unit suite.
 *
 * The whole point of the suite is that it runs with nothing but PHP: no database, no web
 * server, no GLPI checkout. The classes it covers (the deadline arithmetic, the matchers,
 * the placeholder renderer, the occurrence keys) were designed not to depend on GLPI, and
 * the only glue they still need is GLPI's translation functions.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('__')) {
    /**
     * Stand-in for GLPI's gettext wrapper.
     *
     * @param string $str
     * @param string $domain
     */
    function __($str, $domain = 'glpi'): string
    {
        return (string) $str;
    }
}

if (!function_exists('_n')) {
    function _n($sing, $plural, $nb, $domain = 'glpi'): string
    {
        return (string) ($nb > 1 ? $plural : $sing);
    }
}

if (!function_exists('_x')) {
    function _x($ctx, $str, $domain = 'glpi'): string
    {
        return (string) $str;
    }
}

if (!function_exists('_sx')) {
    function _sx($ctx, $str, $domain = 'glpi'): string
    {
        return (string) $str;
    }
}

if (!defined('DAY_TIMESTAMP')) {
    define('DAY_TIMESTAMP', 86400);
}

if (!defined('HOUR_TIMESTAMP')) {
    define('HOUR_TIMESTAMP', 3600);
}
