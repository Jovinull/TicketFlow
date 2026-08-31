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

use CommonDBTM;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Features\Clonable;
use GlpiPlugin\Ticketclock\Engine\RuleDefinition;
use GlpiPlugin\Ticketclock\Enum\ActionType;
use GlpiPlugin\Ticketclock\Enum\CalendarMode;
use GlpiPlugin\Ticketclock\Enum\DelayUnit;
use GlpiPlugin\Ticketclock\Enum\ResetEvent;
use GlpiPlugin\Ticketclock\Enum\RuleType;
use GlpiPlugin\Ticketclock\Enum\StartEvent;
use Session;
use Ticket;
use Glpi\Exception\Http\AccessDeniedHttpException;

/**
 * A TicketFlow rule: conditions + clock + actions.
 *
 * The row is the persistence shape; {@see self::toDefinition()} converts it into the
 * GLPI-free {@see RuleDefinition} the engine actually reasons about. Keeping those two
 * apart is what lets the matchers be unit-tested without a database.
 */
class Rule extends CommonDBTM
{
    /** @use Clonable<Rule> */
    use Clonable;

    public static $rightname = 'plugin_ticketclock_rule';

    public $dohistory = true;

    /** @var array<string, mixed>|null lazily built form payload */
    private ?array $form_actions = null;

    /**
     * Duplicating a rule must bring its groups and its actions along, otherwise the copy
     * silently targets everything and does nothing.
     *
     * @return array<class-string<CommonDBTM>>
     */
    public function getCloneRelations(): array
    {
        return [
            RuleGroup::class,
            RuleAction::class,
        ];
    }

    public static function getTypeName($nb = 0)
    {
        return _n('TicketFlow rule', 'TicketFlow rules', $nb, 'ticketclock');
    }

    public static function getIcon(): string
    {
        return 'ti ti-timeline-event-exclamation';
    }

    /**
     * What an operator must hold before the manual "Run for real" button may touch a ticket.
     *
     * `CommonDBTM::add()` and `update()` authorize nothing -- in GLPI authorization lives in
     * the controller, not the model. So holding this plugin's own UPDATE right was enough to
     * reach code that adds followups, writes solutions and closes tickets, and
     * `Profile::installRights()` hands that right to every profile that can configure GLPI.
     * An operator with no ticket rights at all could act on tickets through this screen.
     *
     * Deliberately a right check rather than `Ticket::check()` or `ITILSolution::check()`.
     * Those also apply the interface's status transition matrix, and
     * `Ticket::isAllowedStatus(WAITING, SOLVED)` is false: GLPI's UI does not offer a solve
     * button on a pending ticket. Since a pending ticket is the only kind this plugin ever
     * acts on, routing the engine through those checks would authorize nothing extra and
     * would stop the plugin from doing the one thing it exists to do.
     *
     * The cron path is untouched on purpose. It has no session to check, runs as the
     * configured acting user, and is reached only by an administrator who enabled the
     * automatic action.
     */
    /**
     * Records why the engine would not run this rule, where somebody will find it.
     *
     * A refusal happens before any ticket is chosen, so there is no execution to attach the
     * reason to, and inventing one would put a row carrying no ticket into a log that is
     * otherwise strictly one row per ticket. The problem belongs to the rule: it is the rule
     * that is unusable, on every ticket, until somebody edits it. So it is kept on the rule
     * and shown on the rule's own screen and in its list.
     *
     * Written straight to the table rather than through update(): this runs from cron, where
     * a history entry and a notification for every pass would be noise, and the value is
     * bookkeeping about the rule rather than a change somebody made to it.
     */
    public static function recordRefusal(int $rules_id, string $reason): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($rules_id <= 0) {
            return;
        }

        $DB->update(self::getTable(), [
            'last_error'      => $reason,
            'last_error_date' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ], ['id' => $rules_id]);
    }

    /**
     * Clears a recorded refusal once the rule runs again.
     *
     * Guarded on the column already being set, so the normal case is an UPDATE matching no
     * rows rather than a write per rule per pass. A stale error is worse than none: it sends
     * somebody looking for a problem that was fixed.
     */
    public static function clearRefusal(int $rules_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($rules_id <= 0) {
            return;
        }

        $DB->update(self::getTable(), [
            'last_error'      => null,
            'last_error_date' => null,
        ], ['id' => $rules_id, ['NOT' => ['last_error' => null]]]);
    }

    public static function checkOperatorMayActOnTickets(): void
    {
        // haveRight() plus an explicit throw rather than Session::checkRight(), which also
        // runs checkValidSessionId(). The caller has already validated the session one line
        // earlier, and re-validating it here would make the guard untestable outside a real
        // HTTP session for no gain.
        if (!Session::haveRight(Ticket::$rightname, UPDATE)) {
            throw new AccessDeniedHttpException(
                'A real run writes to tickets, and this profile is missing the UPDATE right on them.',
            );
        }
    }

    public static function getMenuName()
    {
        return __('TicketFlow', 'ticketclock');
    }

    // -----------------------------------------------------------------------------
    // Domain conversion
    // -----------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $overrides used by the simulation form to preview
     *                                             unsaved changes without touching the row
     */
    public function toDefinition(?array $overrides = null): RuleDefinition
    {
        $fields = $this->fields;
        if ($overrides !== null) {
            $fields = array_replace($fields, $overrides);
        }

        $problems = [];

        return new RuleDefinition(
            (int) ($fields['id'] ?? 0),
            (string) ($fields['name'] ?? ''),
            RuleType::tryFromString((string) ($fields['rule_type'] ?? '')) ?? RuleType::PendingInactivity,
            (int) ($fields['entities_id'] ?? 0),
            (bool) ($fields['is_recursive'] ?? false),
            (bool) ($fields['is_active'] ?? false),
            (int) ($fields['ranking'] ?? 0),
            RuleGroup::getGroupIdsForRule((int) ($fields['id'] ?? 0)),
            (int) ($fields['target_status'] ?? 0),
            (int) ($fields['pendingreasons_id'] ?? 0),
            (int) ($fields['delay_value'] ?? 0),
            DelayUnit::tryFromString((string) ($fields['delay_unit'] ?? '')) ?? DelayUnit::BusinessDays,
            CalendarMode::tryFromString((string) ($fields['calendar_mode'] ?? '')) ?? CalendarMode::Entity,
            (int) ($fields['calendars_id'] ?? 0),
            ResetEvent::decodeList(isset($fields['reset_events']) ? (string) $fields['reset_events'] : null),
            RuleAction::getDefinitionsForRule((int) ($fields['id'] ?? 0), $problems),
            (bool) ($fields['is_dry_run'] ?? false),
            StartEvent::tryFromString((string) ($fields['start_event'] ?? '')) ?? StartEvent::PendingStart,
            $problems ?? [],
        );
    }

    /**
     * Active rules, ordered the way the engine must evaluate them.
     *
     * @return list<RuleDefinition>
     */
    public static function getActiveDefinitions(?RuleType $type = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = ['is_active' => 1];
        if ($type !== null) {
            $where['rule_type'] = $type->value;
        }

        $out = [];
        $iterator = $DB->request([
            'FROM'   => self::getTable(),
            'WHERE'  => $where,
            'ORDER'  => ['ranking ASC', 'id ASC'],
        ]);

        foreach ($iterator as $row) {
            $rule = new self();
            $rule->fields = $row;
            $out[] = $rule->toDefinition();
        }

        return $out;
    }

    // -----------------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------------

    public function prepareInputForAdd($input): false|array
    {
        $input = $this->prepareCommonInput($input);
        if ($input === false) {
            return false;
        }

        // New rules never start armed: an administrator has to turn them on knowingly.
        $input['is_active'] = 0;

        return $input;
    }

    public function prepareInputForUpdate($input): array|false
    {
        return $this->prepareCommonInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function prepareCommonInput(array $input): array|false
    {
        if (isset($input['name']) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(htmlescape(__('A rule needs a name.', 'ticketclock')), false, ERROR);
            return false;
        }

        if (isset($input['rule_type']) && RuleType::tryFromString((string) $input['rule_type']) === null) {
            Session::addMessageAfterRedirect(htmlescape(__('Unknown rule type.', 'ticketclock')), false, ERROR);
            return false;
        }

        if (isset($input['delay_unit']) && DelayUnit::tryFromString((string) $input['delay_unit']) === null) {
            Session::addMessageAfterRedirect(htmlescape(__('Unknown delay unit.', 'ticketclock')), false, ERROR);
            return false;
        }

        if (isset($input['start_event']) && StartEvent::tryFromString((string) $input['start_event']) === null) {
            Session::addMessageAfterRedirect(htmlescape(__('Unknown start event.', 'ticketclock')), false, ERROR);
            return false;
        }

        if (isset($input['calendar_mode']) && CalendarMode::tryFromString((string) $input['calendar_mode']) === null) {
            Session::addMessageAfterRedirect(htmlescape(__('Unknown calendar mode.', 'ticketclock')), false, ERROR);
            return false;
        }

        if (isset($input['delay_value'])) {
            $delay = (int) $input['delay_value'];
            if ($delay < 1) {
                Session::addMessageAfterRedirect(htmlescape(__('The delay must be at least 1.', 'ticketclock')), false, ERROR);
                return false;
            }
            $input['delay_value'] = $delay;
        }

        $this->form_actions ??= [];

        // The form ships a "_defined" marker next to every multi-select, because an empty
        // multi-select sends nothing at all. Without the marker there is no way to tell
        // "the user cleared the list" from "this form did not contain the field".
        if (isset($input['_reset_events_defined'])) {
            $input['reset_events'] = ResetEvent::encodeList((array) ($input['_reset_events'] ?? []));
        }
        unset($input['_reset_events'], $input['_reset_events_defined']);

        if (isset($input['__groups_id_defined'])) {
            $this->form_actions['_groups_id'] = (array) ($input['_groups_id'] ?? []);
        }
        unset($input['_groups_id'], $input['__groups_id_defined']);

        // Keep the sub-object payload out of the row; the post_* hooks consume it.
        if (array_key_exists('_actions', $input)) {
            $this->form_actions['_actions'] = (array) $input['_actions'];
            unset($input['_actions']);
        }

        return $input;
    }

    public function post_addItem(): void
    {
        $this->syncChildren();
        parent::post_addItem();
    }

    public function post_updateItem($history = true): void
    {
        $this->syncChildren();
        parent::post_updateItem($history);
    }

    private function syncChildren(): void
    {
        if ($this->form_actions === null) {
            return;
        }

        if (array_key_exists('_groups_id', $this->form_actions)) {
            RuleGroup::setGroupsForRule($this->getID(), (array) $this->form_actions['_groups_id']);
        }

        if (array_key_exists('_actions', $this->form_actions)) {
            RuleAction::setActionsForRule($this->getID(), (array) $this->form_actions['_actions']);
        }

        $this->form_actions = null;
    }

    public function cleanDBonPurge(): void
    {
        (new RuleGroup())->deleteByCriteria(['plugin_ticketclock_rules_id' => $this->getID()]);
        (new RuleAction())->deleteByCriteria(['plugin_ticketclock_rules_id' => $this->getID()]);
        (new Execution())->deleteByCriteria(['plugin_ticketclock_rules_id' => $this->getID()]);
    }

    /**
     * Drop dangling references when a Group or a Calendar is purged from the core.
     *
     * Registered on Hooks::ITEM_PURGE in setup.php.
     */
    public static function onCoreItemPurged(CommonDBTM $item): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($item instanceof \Group) {
            (new RuleGroup())->deleteByCriteria(['groups_id' => $item->getID()]);
            return;
        }

        if ($item instanceof \Calendar) {
            $DB->update(
                self::getTable(),
                ['calendars_id' => 0, 'calendar_mode' => CalendarMode::Entity->value],
                ['calendars_id' => $item->getID()],
            );
        }
    }

    // -----------------------------------------------------------------------------
    // Human readable preview
    // -----------------------------------------------------------------------------

    /**
     * One sentence describing the rule, shown on the form and in the list.
     *
     * Configuration mistakes in a temporal rule are expensive and invisible until the cron
     * runs, so the form states in plain language what the administrator just built.
     */
    public function getHumanDescription(): string
    {
        $definition = $this->toDefinition();

        $groups = RuleGroup::getGroupNamesForRule($this->getID());
        $group_text = $groups === []
            ? __('any group', 'ticketclock')
            : sprintf(__('the group(s) %s', 'ticketclock'), implode(', ', $groups));

        $status_text = '';
        if ($definition->target_status > 0) {
            $labels = Ticket::getAllStatusArray();
            $status_text = sprintf(
                __(' and whose status is %s', 'ticketclock'),
                $labels[$definition->target_status] ?? (string) $definition->target_status,
            );
        }

        $calendar_text = match ($definition->calendar_mode) {
            CalendarMode::None     => __('ignoring calendars', 'ticketclock'),
            CalendarMode::Specific => sprintf(
                __('using the calendar "%s"', 'ticketclock'),
                \Dropdown::getDropdownName('glpi_calendars', $definition->calendars_id),
            ),
            CalendarMode::Entity   => __("using each entity's calendar", 'ticketclock'),
        };

        $trigger = match ($definition->type) {
            RuleType::PendingInactivity => $definition->start_event === StartEvent::LastTargetGroupMessage
                ? sprintf(
                    __('When the last message on a ticket assigned to %1$s was written by that group%2$s, and %3$d %4$s go by %5$s without anybody answering', 'ticketclock'),
                    $group_text,
                    $status_text,
                    $definition->delay_value,
                    $definition->delay_unit->label(),
                    $calendar_text,
                )
                : sprintf(
                    __('When a ticket assigned to %1$s%2$s stays pending for %3$d %4$s %5$s', 'ticketclock'),
                    $group_text,
                    $status_text,
                    $definition->delay_value,
                    $definition->delay_unit->label(),
                    $calendar_text,
                ),
            RuleType::PendingApproval => sprintf(
                __('When an approval request on a ticket assigned to %1$s stays unanswered for %2$d %3$s %4$s', 'ticketclock'),
                $group_text,
                $definition->delay_value,
                $definition->delay_unit->label(),
                $calendar_text,
            ),
        };

        if ($definition->start_event === StartEvent::LastTargetGroupMessage) {
            $trigger .= __(' (any reply from outside the group stops the rule, and a status change restarts the countdown)', 'ticketclock');
        } elseif ($definition->reset_events !== []) {
            $labels = array_map(static fn(ResetEvent $e): string => $e->label(), $definition->reset_events);
            $trigger .= sprintf(__(', with the clock restarting on: %s', 'ticketclock'), implode(', ', $labels));
        }

        $actions = [];
        foreach ($definition->orderedActions() as $action) {
            $actions[] = $action->type->label();
        }

        if ($actions === []) {
            return $trigger . __(', nothing happens (no action is configured).', 'ticketclock');
        }

        return $trigger . sprintf(__(', then: %s.', 'ticketclock'), implode(' + ', $actions));
    }

    // -----------------------------------------------------------------------------
    // UI
    // -----------------------------------------------------------------------------

    /**
     * @param array{withtemplate?: int} $options
     * @return array<string, string|bool> tab identifier => tab label, plus the `no_all_tab`
     *                                    flag core may set, which is a boolean
     */
    public function defineTabs($options = []): array
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(Execution::class, $tabs, $options);
        $this->addStandardTab(\Log::class, $tabs, $options);

        return $tabs;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@ticketclock/rule_form.html.twig', [
            'item'            => $this,
            'params'          => $options,
            'rule_types'      => RuleType::options(),
            'delay_units'     => DelayUnit::options(),
            'calendar_modes'  => CalendarMode::options(),
            'start_events'    => StartEvent::options(),
            'start_event_helpers' => array_combine(
                array_map(static fn(StartEvent $e): string => $e->value, StartEvent::cases()),
                array_map(static fn(StartEvent $e): string => $e->helper(), StartEvent::cases()),
            ),
            'reset_events'    => ResetEvent::options(),
            'action_types'    => ActionType::options(),
            'selected_resets' => array_map(
                static fn(ResetEvent $e): string => $e->value,
                ResetEvent::decodeList($this->fields['reset_events'] ?? null),
            ),
            'selected_groups' => RuleGroup::getGroupIdsForRule((int) $ID),
            'actions'         => RuleAction::getFormValues((int) $ID),
            // Prepended in PHP, not with Twig's `merge`: that filter is array_merge(),
            // which renumbers integer keys. Ticket::getAllStatusArray() is keyed by status
            // id and those ids are neither sequential nor sorted (10 sits between 1 and 2),
            // so merging silently shifts every option onto the wrong value.
            'statuses'         => [0 => __('Any status')] + Ticket::getAllStatusArray(),
            'target_statuses'  => [0 => __('Not applicable', 'ticketclock')] + Ticket::getAllStatusArray(),
            'description'     => $ID > 0 ? $this->getHumanDescription() : '',
            'is_destructive'  => $ID > 0 && $this->toDefinition()->isDestructive(),
            'last_error'      => (string) ($this->fields['last_error'] ?? ''),
            'last_error_date' => (string) ($this->fields['last_error_date'] ?? ''),
        ]);

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'            => '2',
            'table'         => self::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'massiveaction' => false,
            'datatype'      => 'number',
        ];

        $tab[] = [
            'id'       => '3',
            'table'    => self::getTable(),
            'field'    => 'rule_type',
            'name'     => __('Rule type', 'ticketclock'),
            'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];

        $tab[] = [
            'id'       => '4',
            'table'    => self::getTable(),
            'field'    => 'delay_value',
            'name'     => __('Delay', 'ticketclock'),
            'datatype' => 'number',
        ];

        $tab[] = [
            'id'       => '5',
            'table'    => self::getTable(),
            'field'    => 'delay_unit',
            'name'     => __('Delay unit', 'ticketclock'),
            'datatype' => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];

        $tab[] = [
            'id'       => '6',
            'table'    => self::getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => '7',
            'table'    => self::getTable(),
            'field'    => 'last_execution_date',
            'name'     => __('Last run', 'ticketclock'),
            'datatype' => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '8',
            'table'    => \Group::getTable(),
            'field'    => 'completename',
            'name'     => __('Assigned group'),
            'datatype' => 'dropdown',
            'forcegroupby' => true,
            'joinparams'   => [
                'beforejoin' => [
                    'table'      => RuleGroup::getTable(),
                    'joinparams' => ['jointype' => 'child'],
                ],
            ],
        ];

        $tab[] = [
            'id'       => '9',
            'table'    => self::getTable(),
            'field'    => 'is_dry_run',
            'name'     => __('Simulation only', 'ticketclock'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'       => '16',
            'table'    => self::getTable(),
            'field'    => 'comment',
            'name'     => __('Comments'),
            'datatype' => 'text',
        ];

        // Searchable so an administrator can list every rule the engine is refusing, rather
        // than opening them one by one. `id` 17 and 18 continue the plugin's own block; 80 is
        // core's conventional slot for the entity and stays last.
        $tab[] = [
            'id'            => '17',
            'table'         => self::getTable(),
            'field'         => 'last_error',
            'name'          => __('Why it is not running', 'ticketclock'),
            'datatype'      => 'text',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => '18',
            'table'         => self::getTable(),
            'field'         => 'last_error_date',
            'name'          => __('Refused at', 'ticketclock'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '80',
            'table'    => \Entity::getTable(),
            'field'    => 'completename',
            'name'     => \Entity::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        return $tab;
    }

    /**
     * @param array<string, mixed>|string $values
     * @param array<string, mixed> $options
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        return match ($field) {
            'rule_type'  => RuleType::options()[$values[$field]] ?? (string) $values[$field],
            'delay_unit' => DelayUnit::options()[$values[$field]] ?? (string) $values[$field],
            default      => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }

    /**
     * @param array<string, mixed>|string $values
     * @param array<string, mixed> $options
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        $choices = match ($field) {
            'rule_type'  => RuleType::options(),
            'delay_unit' => DelayUnit::options(),
            default      => null,
        };

        if ($choices === null) {
            return parent::getSpecificValueToSelect($field, $name, $values, $options);
        }

        $options['display'] = false;

        return (string) \Dropdown::showFromArray($name, $choices, $options + ['value' => $values[$field]]);
    }
}
