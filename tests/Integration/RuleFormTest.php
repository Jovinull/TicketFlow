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

namespace GlpiPlugin\Ticketclock\Tests\Integration;

use Group;
use GlpiPlugin\Ticketclock\Config;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use GlpiPlugin\Ticketclock\Enum\StartEvent;
use GlpiPlugin\Ticketclock\Rule;
use GlpiPlugin\Ticketclock\RuleAction;
use GlpiPlugin\Ticketclock\RuleGroup;
use PHPUnit\Framework\TestCase;
use Session;
use Ticket;
use Throwable;

/**
 * The rule form has to actually render.
 *
 * This suite exists because it did not. Two of GLPI's dropdown APIs disagree about what a
 * "multiple" selection looks like — `Dropdown::show()` wants an array in `value`,
 * `Dropdown::showFromArray()` wants a scalar there and the selection in `values` — and
 * getting either wrong throws inside the template. Nothing else in the project renders
 * Twig, so the whole form was broken while every other test stayed green.
 *
 * Asserting on posted field names rather than on markup keeps this about the contract: if
 * a field stops being submitted, the rule silently loses that setting on the next save.
 */
final class RuleFormTest extends TestCase
{
    /** Every input the form must post back for a rule to be fully configurable. */
    private const REQUIRED_FIELDS = [
        'name', 'is_active', 'is_recursive', 'ranking', 'comment',
        'rule_type', '_groups_id[]', '__groups_id_defined', 'target_status', 'pendingreasons_id',
        'start_event', 'delay_value', 'delay_unit', 'calendar_mode', 'calendars_id',
        '_reset_events[]', '_reset_events_defined',
        '_actions[add_followup][enabled]', '_actions[add_followup][content]',
        '_actions[add_followup][is_private]',
        '_actions[final][type]', '_actions[final][content]',
        '_actions[final][solutiontypes_id]', '_actions[final][status]',
        '_actions[send_notification][enabled]', '_actions[send_notification][event]',
        'is_dry_run',
    ];

    private ?string $previous_cron_marker = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The form is rendered for a person, not for the scheduler: give the session a
        // profile with the plugin right so the fields are editable rather than read-only.
        $this->previous_cron_marker = $_SESSION['glpicronuserrunning'] ?? null;
        unset($_SESSION['glpicronuserrunning']);

        $_SESSION['glpiactiveprofile'] = [
            'id'                     => 0,
            'name'                   => 'ticketclock-tests',
            'interface'              => 'central',
            Rule::$rightname         => ALLSTANDARDRIGHT,
            'ticket'                 => ALLSTANDARDRIGHT,
            'group'                  => ALLSTANDARDRIGHT,
            'calendar'               => ALLSTANDARDRIGHT,
        ];
        Session::changeActiveEntities(0, true);
    }

    protected function tearDown(): void
    {
        if ($this->previous_cron_marker !== null) {
            $_SESSION['glpicronuserrunning'] = $this->previous_cron_marker;
        }

        parent::tearDown();
    }

    private function render(int $id): string
    {
        $rule = new Rule();

        ob_start();
        try {
            $rule->showForm($id);
        } catch (Throwable $e) {
            ob_end_clean();
            self::fail('showForm() threw: ' . $e->getMessage());
        }

        return (string) ob_get_clean();
    }

    /**
     * @param list<string> $fields
     */
    private function assertPostsBack(string $html, array $fields): void
    {
        foreach ($fields as $field) {
            self::assertMatchesRegularExpression(
                '/name=["\']' . preg_quote($field, '/') . '["\']/',
                $html,
                sprintf('the form does not post back "%s"', $field),
            );
        }
    }

    public function testTheNewRuleFormRendersEveryField(): void
    {
        $html = $this->render(-1);

        self::assertStringNotContainsString('Fatal error', $html);
        $this->assertPostsBack($html, self::REQUIRED_FIELDS);
    }

    public function testAnExistingRuleFormRendersEveryFieldAndItsSavedValues(): void
    {
        $groups_id = (int) (new Group())->add([
            'name'        => 'TicketFlow form group ' . uniqid(),
            'entities_id' => 0,
            'is_assign'   => 1,
        ]);

        $rule = new Rule();
        $rules_id = (int) $rule->add([
            'name'          => 'TicketFlow form rule',
            'entities_id'   => 0,
            'rule_type'     => 'pending_inactivity',
            'target_status' => Ticket::WAITING,
            'start_event'   => StartEvent::LastTargetGroupMessage->value,
            'delay_value'   => 7,
            'delay_unit'    => 'business_days',
            'reset_events'  => 'requester_followup',
        ]);
        RuleGroup::setGroupsForRule($rules_id, [$groups_id]);
        RuleAction::setActionsForRule($rules_id, [
            'add_followup' => ['enabled' => 1, 'content' => 'a message'],
            'final'        => ['type' => ActionType::AddSolution->value, 'content' => 'a solution'],
        ]);

        $html = $this->render($rules_id);

        $this->assertPostsBack($html, self::REQUIRED_FIELDS);

        // The saved configuration has to come back selected, or editing a rule silently
        // resets it.
        self::assertStringContainsString(StartEvent::LastTargetGroupMessage->value, $html);
        self::assertStringContainsString('a message', $html);
        self::assertStringContainsString((string) $groups_id, $html);
        self::assertStringContainsString('rule.simulate.php', $html, 'a saved rule must offer the simulation');

        // A rule that can solve tickets must say so on its own form.
        self::assertTrue($rule->getFromDB($rules_id));
        self::assertTrue($rule->toDefinition()->isDestructive());
    }

    /**
     * No field may post under a doubly-bracketed name.
     *
     * Dropdown::showFromArray() appends "[]" to the name itself when 'multiple' is set, so
     * passing a name that already ends in "[]" produces "[][]". PHP then parses the value
     * one level deeper than the code reads it: the selection never arrives, and because the
     * form also posts the "_defined" marker, saving a rule silently *cleared* its reset
     * events. The screen looked right the whole time -- the chips were rendered from the
     * 'values' option, not from the select's own state.
     *
     * Asserting the name is present is not enough: showFromArray() also emits the original
     * name elsewhere in its markup, so the old assertion kept passing.
     */
    public function testNoFieldPostsUnderADoublyBracketedName(): void
    {
        foreach ([-1, $this->aSavedRuleId()] as $id) {
            preg_match_all(
                '/<(?:select|input)[^>]*\bname=["\']([^"\']+)["\']/i',
                $this->render($id),
                $matches,
            );
            self::assertNotEmpty($matches[1], 'the form rendered no named field at all');

            foreach ($matches[1] as $field) {
                self::assertStringNotContainsString(
                    '[][]',
                    $field,
                    sprintf('"%s" posts one array level deeper than the code reads it', $field),
                );
            }
        }
    }

    /**
     * A rule with every multi-value field populated, for tests that need one.
     */
    private function aSavedRuleId(): int
    {
        $groups_id = (int) (new Group())->add([
            'name'        => 'TicketFlow bracket group ' . uniqid(),
            'entities_id' => 0,
            'is_assign'   => 1,
        ]);

        $rules_id = (int) (new Rule())->add([
            'name'          => 'TicketFlow bracket rule',
            'entities_id'   => 0,
            'rule_type'     => 'pending_inactivity',
            'target_status' => Ticket::WAITING,
            'start_event'   => StartEvent::LastTargetGroupMessage->value,
            'delay_value'   => 3,
            'delay_unit'    => 'business_days',
            'reset_events'  => 'requester_followup',
        ]);
        RuleGroup::setGroupsForRule($rules_id, [$groups_id]);

        return $rules_id;
    }

    /**
     * Every status option must carry its own status id.
     *
     * Ticket::getAllStatusArray() is keyed by status id, and those ids are neither
     * sequential nor sorted -- 10 (Approval) sits between 1 and 2. Prepending the
     * "any status" entry with Twig's `merge` filter therefore renumbered the whole list,
     * so a rule saved as WAITING (4) came back on screen as "Processing (planned)" and
     * saving the form again wrote the wrong status. The form is the only place where that
     * shows, which is why this asserts on the rendered option, not on the array.
     */
    public function testEveryTicketStatusOptionKeepsItsOwnId(): void
    {
        $html = $this->render(-1);

        foreach (Ticket::getAllStatusArray() as $status_id => $label) {
            // GLPI quotes attributes with single quotes here, core templates use double
            // ones: match either rather than depending on which renderer produced the tag.
            self::assertMatchesRegularExpression(
                '/<option[^>]*value=[\'"]' . $status_id . '[\'"][^>]*>\s*'
                    . preg_quote((string) $label, '/') . '\s*</',
                $html,
                sprintf('status %d must be offered as "%s"', $status_id, $label),
            );
        }
    }

    public function testTheConfigurationScreenRenders(): void
    {
        ob_start();
        try {
            Config::showForm();
        } catch (Throwable $e) {
            ob_end_clean();
            self::fail('Config::showForm() threw: ' . $e->getMessage());
        }
        $html = (string) ob_get_clean();

        $this->assertPostsBack($html, [
            'execution_enabled', 'dry_run_global', 'log_dry_runs',
            'batch_size', 'max_tickets_per_run', 'log_retention_days',
            'fallback_calendars_id', 'system_users_id',
        ]);
    }

    public function testTheDiagnosticsScreenRenders(): void
    {
        ob_start();
        try {
            \Glpi\Application\View\TemplateRenderer::getInstance()->display(
                '@ticketclock/inspect.html.twig',
                ['report' => \GlpiPlugin\Ticketclock\Inspector::report()],
            );
        } catch (Throwable $e) {
            ob_end_clean();
            self::fail('the diagnostics template threw: ' . $e->getMessage());
        }
        $html = (string) ob_get_clean();

        self::assertNotSame('', trim($html));
        self::assertStringNotContainsString('Fatal error', $html);
    }
}
