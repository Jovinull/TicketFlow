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
use Group;
use GlpiPlugin\Ticketclock\Engine\Action\AssignGroupAction;
use GlpiPlugin\Ticketclock\Enum\CalendarMode;
use GlpiPlugin\Ticketclock\Enum\DelayUnit;
use GlpiPlugin\Ticketclock\Enum\ResetEvent;
use GlpiPlugin\Ticketclock\Enum\ReferenceField;
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

    /**
     * Whether the operator administers this rule, rather than merely inheriting it.
     *
     * A real run writes to the rule row -- it records why the rule was refused, or clears an
     * earlier record -- so it needs more than being able to see the rule. A recursive rule
     * stored on a parent entity is visible from every child by design; that is what
     * `is_recursive` is for. It is not a rule those children own.
     *
     * Stated here rather than left to `CommonDBTM::check($id, UPDATE)`, and that is the whole
     * reason this method exists. Core changed the answer inside the range this plugin
     * supports:
     *
     *     GLPI 11.0.0 - 11.0.4   canUpdateItem() -> checkEntity(true)   ancestor accepted
     *     GLPI 11.0.5 and later  canUpdateItem() -> checkEntity()       ancestor refused
     *
     * So through 11.0.4 the item check alone lets a child-entity operator run and stamp
     * metadata onto a parent's rule, and from 11.0.5 it does not. A guarantee that flips with
     * the host's patch level is not a guarantee. The plugin states its own policy, and the
     * test asserts that policy rather than which core method happens to be called.
     *
     * `haveAccessToEntity()` without the recursive flag is the direct-access test: the rule's
     * entity has to be one of the session's own, not an ancestor of one.
     */
    public static function checkOperatorAdministersRule(self $rule): void
    {
        if (!Session::haveAccessToEntity((int) ($rule->fields['entities_id'] ?? -1))) {
            throw new AccessDeniedHttpException(
                'A real run writes to the rule, and this rule belongs to an entity outside your own.',
            );
        }
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
     * This is the coarse gate: the right to touch tickets at all. What each action needs
     * beyond it is asked per action and per ticket by `Engine\OperatorAuthorization`, which
     * is handed only to the manual caller. The split is not tidiness. Those per-action checks
     * go through the ticket's own capability methods, and `Ticket::isAllowedStatus()` answers
     * from the session profile's `ticket_status` matrix, and the matrix stores only the
     * transitions a profile is *denied*. A standard central profile therefore decodes to an
     * empty matrix and every transition is allowed; it answers false only for a
     * helpdesk-interface profile or an explicit denial. A cron run is the case that matters
     * here: it carries no profile at all, the key is absent, and the method returns false for
     * everything. Sharing one check between the two callers would stop the scheduled
     * run solving anything, on every installation.
     */
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

        $type            = RuleType::tryFromString((string) ($fields['rule_type'] ?? '')) ?? RuleType::PendingInactivity;
        $start_event     = StartEvent::tryFromString((string) ($fields['start_event'] ?? '')) ?? StartEvent::PendingStart;
        $reference_field = ReferenceField::tryFromString(isset($fields['reference_field']) ? (string) $fields['reference_field'] : null);

        // Fails closed, and refuses the whole rule rather than one ticket. A rule told to
        // count from a column that is not on the list is not the rule anybody configured --
        // running it from some other date would be a wrong answer on every ticket it touches,
        // quietly. The stored value is never trusted: it reaches the engine only through
        // `tryFrom()`, so a hand-written row cannot put a column name into a query.
        if ($start_event === StartEvent::TicketDateField && $reference_field === null) {
            $problems[] = sprintf(
                __('its reference field "%s" is not one this version can read', 'ticketclock'),
                (string) ($fields['reference_field'] ?? ''),
            );
        }

        // The row is the other door into this. A rule type that has no implementation for the
        // event must not run at all rather than run from a date nobody chose -- silently
        // timing an approval from its submission while the screen says "time to resolve" is
        // exactly the quiet wrongness this plugin refuses elsewhere.
        if ($start_event === StartEvent::TicketDateField && $type !== RuleType::PendingInactivity) {
            $problems[] = __('it counts from a ticket date, which only inactivity rules support', 'ticketclock');
        }

        return new RuleDefinition(
            (int) ($fields['id'] ?? 0),
            (string) ($fields['name'] ?? ''),
            $type,
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
            $start_event,
            $reference_field,
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

        if (!$this->referenceFieldIsUsable($input)) {
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
            $actions = (array) $input['_actions'];

            if (!$this->assignedGroupIsInScope($actions, (int) ($input['entities_id'] ?? $this->fields['entities_id'] ?? 0))) {
                return false;
            }

            $this->form_actions['_actions'] = $actions;
            unset($input['_actions']);
        }

        return $input;
    }

    /**
     * The reference field has to be one of ours, and it has to be there when it is used.
     *
     * Two refusals, not one. An unknown value is either a stale rule from a version that
     * offered more fields or a hand-made POST, and neither should reach the column list. An
     * empty one on a rule that counts from a ticket date is a rule that cannot be timed at
     * all: better refused at the form than saved and skipped on every ticket forever.
     *
     * Checked here as well as when the rule is read, because these are different doors. The
     * form is the one a person uses; `toDefinition()` guards the row itself, which a restore,
     * an import or a direct UPDATE can change without passing through here.
     *
     * @param array<string, mixed> $input
     */
    private function referenceFieldIsUsable(array $input): bool
    {
        $raw = array_key_exists('reference_field', $input)
            ? (string) $input['reference_field']
            : (string) ($this->fields['reference_field'] ?? '');

        if ($raw !== '' && ReferenceField::tryFromString($raw) === null) {
            Session::addMessageAfterRedirect(
                htmlescape(__('Unknown reference field.', 'ticketclock')),
                false,
                ERROR,
            );
            return false;
        }

        $start_event = StartEvent::tryFromString(
            (string) ($input['start_event'] ?? $this->fields['start_event'] ?? ''),
        ) ?? StartEvent::PendingStart;

        // Only the inactivity matcher implements this event. An approval rule times the
        // approval request, and its prefilter and matcher both read the validation's own
        // submission date -- so accepting the combination would store a setting the engine
        // then ignores, and the rule would say one thing on screen and do another.
        $rule_type = RuleType::tryFromString(
            (string) ($input['rule_type'] ?? $this->fields['rule_type'] ?? ''),
        ) ?? RuleType::PendingInactivity;

        if ($start_event === StartEvent::TicketDateField && $rule_type !== RuleType::PendingInactivity) {
            Session::addMessageAfterRedirect(
                htmlescape(__('Counting from a date on the ticket is only available for inactivity rules. An approval rule is timed from the approval request.', 'ticketclock')),
                false,
                ERROR,
            );
            return false;
        }

        if ($start_event === StartEvent::TicketDateField && $raw === '') {
            Session::addMessageAfterRedirect(
                htmlescape(__('Counting from a date on the ticket needs a field to count from.', 'ticketclock')),
                false,
                ERROR,
            );
            return false;
        }

        return true;
    }

    /**
     * Refuse a rule that would hand tickets to a group outside its entity.
     *
     * The form's dropdown only offers groups in scope, so reaching this is either a crafted
     * POST or a group moved between entities since. Checked here so the administrator is told
     * at the moment they save, rather than discovering it in an execution log; the action
     * checks again per ticket at run time, which is the check that actually protects the
     * tickets. Both are needed: this one cannot see the entity of a ticket that does not exist
     * yet, and a recursive rule reaches entities this one does not look at.
     *
     * @param array<string, mixed> $actions
     */
    private function assignedGroupIsInScope(array $actions, int $entities_id): bool
    {
        $assign = (array) ($actions['assign_group'] ?? []);
        $groups_id = (int) ($assign['groups_id'] ?? 0);

        if (empty($assign['enabled']) || $groups_id <= 0) {
            return true;
        }

        $group = new Group();
        if (!$group->getFromDB($groups_id)) {
            Session::addMessageAfterRedirect(
                htmlescape(__('The group to assign no longer exists.', 'ticketclock')),
                false,
                ERROR,
            );
            return false;
        }

        if (!AssignGroupAction::groupIsVisibleIn($group, $entities_id)) {
            Session::addMessageAfterRedirect(
                htmlescape(__('The group to assign does not belong to this rule\'s entity.', 'ticketclock')),
                false,
                ERROR,
            );
            return false;
        }

        return true;
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

        $trigger = match (true) {
            $definition->type === RuleType::PendingInactivity
                && $definition->start_event === StartEvent::TicketDateField => sprintf(
                    __('When %1$d %2$s go by %3$s after the "%4$s" of a ticket assigned to %5$s%6$s', 'ticketclock'),
                    $definition->delay_value,
                    $definition->delay_unit->label(),
                    $calendar_text,
                    $definition->reference_field?->label() ?? __('(no field chosen)', 'ticketclock'),
                    $group_text,
                    $status_text,
                ),
            $definition->type === RuleType::PendingInactivity => $definition->start_event === StartEvent::LastTargetGroupMessage
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
            default => sprintf(
                __('When an approval request on a ticket assigned to %1$s stays unanswered for %2$d %3$s %4$s', 'ticketclock'),
                $group_text,
                $definition->delay_value,
                $definition->delay_unit->label(),
                $calendar_text,
            ),
        };

        if ($definition->start_event === StartEvent::LastTargetGroupMessage) {
            $trigger .= __(' (any reply from outside the group stops the rule, and a status change restarts the countdown)', 'ticketclock');
        } elseif ($definition->start_event === StartEvent::TicketDateField) {
            // Said out loud because the field is on the form right next to the reset events,
            // and somebody will otherwise expect a reply to push this clock the way it pushes
            // the other two.
            $trigger .= __(' (a date on the ticket does not restart, so replies do not move this clock)', 'ticketclock');
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
            // The empty option is what every rule that counts from something else stores, and
            // it has to be selectable or the form could not express those rules.
            'reference_fields' => ['' => __('None', 'ticketclock')] + ReferenceField::options(),
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
