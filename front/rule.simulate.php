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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Ticketclock\Config;
use GlpiPlugin\Ticketclock\Engine\RuleEngine;
use GlpiPlugin\Ticketclock\Menu;
use GlpiPlugin\Ticketclock\Rule;

include __DIR__ . '/../../../inc/includes.php';

Session::checkRight(Rule::$rightname, READ);

$rules_id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$rule = new Rule();
if ($rules_id <= 0 || !$rule->getFromDB($rules_id)) {
    Html::displayErrorAndDie(__('Rule not found.', 'ticketclock'));
}

// Entity check: a rule the current profile cannot see must not be simulated either.
$rule->check($rules_id, READ);

// Running a rule for real is a POST; GLPI's CheckCsrfListener has already validated and
// consumed the token by the time this file runs, so only the right is checked here.
$run_real = isset($_POST['run_real']);
$simulate = isset($_POST['simulate']);
$ran      = $run_real || $simulate;
if ($run_real) {
    Session::checkRight(Rule::$rightname, UPDATE);
    // On this rule, not merely somewhere: the item-level test, which also applies whatever
    // entity rules core applies to editing anything.
    $rule->check($rules_id, UPDATE);
    // And the plugin's own policy on top, because the line above does not answer the same
    // question on every supported GLPI: `canUpdateItem()` accepted an ancestor entity up to
    // 11.0.4 and stopped at 11.0.5. See the method.
    Rule::checkOperatorAdministersRule($rule);
    // ... and the rights the run will actually exercise on tickets. See the method.
    Rule::checkOperatorMayActOnTickets();
}

// A manual run acts as the logged-in operator, not as the cron. That matters: core relaxes
// the ticket-template restrictions only when Session::isCron() is true, and isCron()
// requires a CLI context or /front/cron.php — a web request can never qualify. So an
// operator without UPDATE on tickets may see actions refused here that succeed from the
// scheduler. Surfaced rather than left to be discovered in the execution log.
$missing_ticket_right = !Session::haveRight(Ticket::$rightname, UPDATE);

Html::header(
    __('Simulate a rule', 'ticketclock'),
    $_SERVER['PHP_SELF'],
    'admin',
    Menu::class,
    'rule',
);

$definition = $rule->toDefinition();

$report = null;
if ($ran) {
    $engine = RuleEngine::forOperator();

    // Both evaluation modes are POST-only. A preview can create non-blocking audit rows when
    // dry-run logging is enabled, and a bare GET must never scan the instance or write them.
    $report = $engine->runRule(
        $definition,
        force_dry_run: !$run_real,
        preview_limit: Config::getInt('max_tickets_per_run', 1000),
    );
}

TemplateRenderer::getInstance()->display('@ticketclock/simulation.html.twig', [
    'rule'        => $rule,
    'definition'  => $definition,
    'report'      => $report,
    'counters'    => $report?->counters() ?? [],
    'was_real'    => $run_real,
    'ran'         => $ran,
    'description' => $rule->getHumanDescription(),
    'warnings'    => Config::getHealthWarnings((int) $rule->fields['entities_id']),
    'can_run'     => Session::haveRight(Rule::$rightname, UPDATE),
    'missing_ticket_right' => $missing_ticket_right,
    'statuses'    => Ticket::getAllStatusArray(),
]);

Html::footer();
