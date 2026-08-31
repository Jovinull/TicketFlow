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
if ($run_real) {
    Session::checkRight(Rule::$rightname, UPDATE);
    // On this rule, not merely somewhere. `check()` is the item-level test, and GLPI 11
    // splits the two directions: `canViewItem()` uses `checkEntity(true)`, which accepts an
    // ancestor of the session's entities, so a recursive rule stored on a parent entity is
    // visible from every child; `canUpdateItem()` uses `checkEntity()` without recursion, so
    // it is not editable from there. A real run writes the refusal record onto the rule row,
    // and writing to a rule is not something a reader gets to do.
    //
    // Easy to get backwards, so ManualRunAuthorizationTest pins it rather than leaving the
    // guarantee resting on this comment.
    $rule->check($rules_id, UPDATE);
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

$engine     = RuleEngine::forOperator();
$definition = $rule->toDefinition();

// A manual run still honours the safety switches, the idempotency claim and the
// re-validation step; the only thing the button changes is that it does not force dry run.
// The screen is the only reader of the preview, so it is the only place that asks for it.
// The ceiling matches what a run can examine anyway, so nothing that used to be listed
// stops being listed.
$report = $engine->runRule(
    $definition,
    force_dry_run: !$run_real,
    preview_limit: Config::getInt('max_tickets_per_run', 1000),
);

TemplateRenderer::getInstance()->display('@ticketclock/simulation.html.twig', [
    'rule'        => $rule,
    'definition'  => $definition,
    'report'      => $report,
    'counters'    => $report->counters(),
    'was_real'    => $run_real,
    'description' => $rule->getHumanDescription(),
    'warnings'    => Config::getHealthWarnings(),
    'can_run'     => Session::haveRight(Rule::$rightname, UPDATE),
    'missing_ticket_right' => $missing_ticket_right,
    'statuses'    => Ticket::getAllStatusArray(),
]);

Html::footer();
