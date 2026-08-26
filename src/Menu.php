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
use Session;

/**
 * The "TicketFlow" entry under Administration, with its sub-pages.
 */
class Menu extends CommonGLPI
{
    public static $rightname = 'plugin_ticketflow_rule';

    public static function getTypeName($nb = 0)
    {
        return __('TicketFlow', 'ticketflow');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon(): string
    {
        return 'ti ti-timeline-event-exclamation';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getMenuContent(): array
    {
        $menu = [
            'title' => self::getMenuName(),
            'page'  => '/plugins/ticketflow/front/rule.php',
            'icon'  => self::getIcon(),
            'links' => [
                'search' => '/plugins/ticketflow/front/rule.php',
            ],
        ];

        if (Session::haveRight(self::$rightname, CREATE)) {
            $menu['links']['add'] = '/plugins/ticketflow/front/rule.form.php';
        }

        $menu['options']['rule'] = [
            'title' => Rule::getTypeName(Session::getPluralNumber()),
            'page'  => '/plugins/ticketflow/front/rule.php',
            'icon'  => Rule::getIcon(),
            'links' => $menu['links'],
        ];

        $menu['options']['execution'] = [
            'title' => Execution::getTypeName(Session::getPluralNumber()),
            'page'  => '/plugins/ticketflow/front/execution.php',
            'icon'  => Execution::getIcon(),
            'links' => ['search' => '/plugins/ticketflow/front/execution.php'],
        ];

        if (Session::haveRight(self::$rightname, UPDATE)) {
            $menu['options']['config'] = [
                'title' => __('Setup'),
                'page'  => '/plugins/ticketflow/front/config.form.php',
                'icon'  => 'ti ti-settings',
                'links' => ['search' => '/plugins/ticketflow/front/config.form.php'],
            ];

            $menu['options']['inspect'] = [
                'title' => __('Diagnostics', 'ticketflow'),
                'page'  => '/plugins/ticketflow/front/inspect.php',
                'icon'  => 'ti ti-stethoscope',
                'links' => ['search' => '/plugins/ticketflow/front/inspect.php'],
            ];
        }

        return $menu;
    }
}
