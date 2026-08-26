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

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Ticketflow\Calendar\Deadline;
use GlpiPlugin\Ticketflow\Engine\ActionResult;
use GlpiPlugin\Ticketflow\Engine\RuleDefinition;
use GlpiPlugin\Ticketflow\Engine\TicketContext;
use GlpiPlugin\Ticketflow\Enum\ExecutionState;
use RuntimeException;
use Ticket;

use function Safe\json_decode;
use function Safe\json_encode;

/**
 * The audit trail, and the mutual-exclusion mechanism, in one table.
 *
 * Idempotency and concurrency are solved by the same row. A worker *claims* an occurrence
 * by inserting a row whose `claim_key` is unique; a second worker's insert fails on the
 * unique index and it moves on. No locks are held, nothing has to be released on a happy
 * path, and the claim doubles as the log entry.
 *
 * `claim_key` is NULL for rows that must not block anything — dry runs, and executions
 * that ended as `skipped`. MySQL/MariaDB allow duplicate NULLs in a unique index, which
 * gives us a partial unique constraint without vendor-specific syntax.
 */
class Execution extends CommonDBTM
{
    public static $rightname = 'plugin_ticketflow_rule';

    public static function getTypeName($nb = 0)
    {
        return _n('Execution log', 'Execution logs', $nb, 'ticketflow');
    }

    public static function getIcon(): string
    {
        return 'ti ti-history';
    }

    public static function buildClaimKey(int $rules_id, int $tickets_id, string $occurrence_key): string
    {
        return sprintf('%d:%d:%s', $rules_id, $tickets_id, $occurrence_key);
    }

    /**
     * Try to take ownership of an occurrence.
     *
     * @return int|null the execution id, or null when somebody else already owns it
     */
    public static function claim(
        RuleDefinition $rule,
        TicketContext $ticket,
        Deadline $deadline,
        string $occurrence_key,
    ): ?int {
        /** @var \DBmysql $DB */
        global $DB;

        $claim_key = self::buildClaimKey($rule->id, $ticket->tickets_id, $occurrence_key);

        // Cheap pre-check so the common "already handled" case costs a SELECT, not an
        // exception. The unique index below is what actually makes this safe.
        if (countElementsInTable(self::getTable(), ['claim_key' => $claim_key]) > 0) {
            return null;
        }

        try {
            $DB->insert(self::getTable(), [
                'plugin_ticketflow_rules_id' => $rule->id,
                'tickets_id'                 => $ticket->tickets_id,
                'entities_id'                => $ticket->entities_id,
                'occurrence_key'             => $occurrence_key,
                'claim_key'                  => $claim_key,
                'state'                      => ExecutionState::Processing->value,
                'reference_date'             => $deadline->reference_date,
                'deadline_date'              => $deadline->deadline_date,
                'calendars_id'               => $deadline->calendars_id,
                'calendar_name'              => $deadline->calendar_name,
                'delay_value'                => $deadline->delay_value,
                'delay_unit'                 => $deadline->delay_unit->value,
                'used_elapsed_fallback'      => $deadline->used_elapsed_time_fallback ? 1 : 0,
                'itilvalidations_id'         => $ticket->validation->id ?? 0,
                'triggered_at'               => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                'date_creation'              => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
        } catch (RuntimeException) {
            // Lost the race on the unique index, or a genuine DB error. Either way this
            // occurrence is not ours to process; a real error is still visible in the logs.
            return null;
        }

        return (int) $DB->insertId();
    }

    /**
     * Record a simulated evaluation. Never blocks a later real execution.
     *
     * @param list<ActionResult> $results
     * @return int the execution id
     */
    public static function logDryRun(
        RuleDefinition $rule,
        TicketContext $ticket,
        Deadline $deadline,
        string $occurrence_key,
        array $results,
    ): int {
        return self::insertNonBlocking(
            $rule,
            $ticket,
            $deadline,
            $occurrence_key,
            ExecutionState::DryRun,
            $results,
            null,
        );
    }

    /**
     * @param list<ActionResult> $results
     */
    private static function insertNonBlocking(
        RuleDefinition $rule,
        TicketContext $ticket,
        Deadline $deadline,
        string $occurrence_key,
        ExecutionState $state,
        array $results,
        ?string $error,
    ): int {
        /** @var \DBmysql $DB */
        global $DB;

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $DB->insert(self::getTable(), [
            'plugin_ticketflow_rules_id' => $rule->id,
            'tickets_id'                 => $ticket->tickets_id,
            'entities_id'                => $ticket->entities_id,
            'occurrence_key'             => $occurrence_key,
            'claim_key'                  => null,
            'state'                      => $state->value,
            'reference_date'             => $deadline->reference_date,
            'deadline_date'              => $deadline->deadline_date,
            'calendars_id'               => $deadline->calendars_id,
            'calendar_name'              => $deadline->calendar_name,
            'delay_value'                => $deadline->delay_value,
            'delay_unit'                 => $deadline->delay_unit->value,
            'used_elapsed_fallback'      => $deadline->used_elapsed_time_fallback ? 1 : 0,
            'itilvalidations_id'         => $ticket->validation->id ?? 0,
            'triggered_at'               => $now,
            'completed_at'               => $now,
            'actions_result'             => self::encodeResults($results),
            'error'                      => $error,
            'date_creation'              => $now,
        ]);

        return (int) $DB->insertId();
    }

    /**
     * Close a claimed execution.
     *
     * A `skipped` outcome releases the claim (claim_key -> NULL) so the occurrence can be
     * evaluated again later; `executed` and `failed` keep it, which is what stops a second
     * cron run from repeating the actions.
     *
     * @param list<ActionResult> $results
     */
    public static function complete(int $executions_id, ExecutionState $state, array $results = [], ?string $error = null): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $update = [
            'state'          => $state->value,
            'completed_at'   => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'actions_result' => self::encodeResults($results),
            'error'          => $error,
        ];

        if (!$state->blocksNewClaim()) {
            $update['claim_key'] = null;
        }

        $DB->update(self::getTable(), $update, ['id' => $executions_id]);
    }

    /**
     * @param list<ActionResult> $results
     */
    private static function encodeResults(array $results): string
    {
        return json_encode(
            array_map(static fn(ActionResult $r): array => $r->toArray(), $results),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDecodedResults(): array
    {
        $raw = $this->fields['actions_result'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * Delete rows older than the retention window.
     *
     * @return int number of deleted rows
     */
    public static function purgeOlderThan(int $days): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($days <= 0) {
            return 0;
        }

        // Native \strtotime on purpose: the fallback keeps the purge running even if the
        // interval string were ever malformed.
        // @phpstan-ignore theCodingMachineSafe.function
        $limit = date('Y-m-d H:i:s', \strtotime(sprintf('-%d days', $days)) ?: time());

        $DB->delete(self::getTable(), [
            'date_creation' => ['<', $limit],
            // Never drop a row that still owns an occurrence.
            'state'         => ['<>', ExecutionState::Processing->value],
        ]);

        return $DB->affectedRows();
    }

    /** Remove execution rows of a purged ticket (registered on Hooks::ITEM_PURGE). */
    public static function onCoreItemPurged(CommonDBTM $item): void
    {
        if ($item instanceof Ticket) {
            (new self())->deleteByCriteria(['tickets_id' => $item->getID()]);
        }
    }

    // -----------------------------------------------------------------------------
    // UI
    // -----------------------------------------------------------------------------

    // Non-static on purpose: CommonGLPI declares getTabNameForItem() as an instance method
    // (displayTabContentForItem() is the static one). Declaring it static here is a compile
    // error the moment GLPI loads the class.
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof Rule || $item->isNewItem()) {
            return '';
        }

        $count = countElementsInTable(self::getTable(), ['plugin_ticketflow_rules_id' => $item->getID()]);

        return self::createTabEntry(self::getTypeName(2), $count, $item::class);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Rule) {
            self::showForRule($item);
        }

        return true;
    }

    public static function showForRule(Rule $rule): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_ticketflow_rules_id' => $rule->getID()],
            'ORDER' => 'id DESC',
            'LIMIT' => 100,
        ]);

        foreach ($iterator as $row) {
            // Decode here rather than in Twig: the template stays a template.
            $decoded = json_decode((string) ($row['actions_result'] ?? ''), true);
            $row['actions'] = is_array($decoded) ? $decoded : [];
            $rows[] = $row;
        }

        TemplateRenderer::getInstance()->display('@ticketflow/execution_list.html.twig', [
            'rows'   => $rows,
            'states' => ExecutionState::options(),
            'rule'   => $rule,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rawSearchOptions(): array
    {
        return [['id' => 'common', 'name' => self::getTypeName(2)], [
            'id'            => '1',
            'table'         => self::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ], [
            'id'       => '2',
            'table'    => Rule::getTable(),
            'field'    => 'name',
            'name'     => Rule::getTypeName(1),
            'datatype' => 'dropdown',
        ], [
            'id'       => '3',
            'table'    => self::getTable(),
            'field'    => 'tickets_id',
            'name'     => Ticket::getTypeName(1),
            'datatype' => 'number',
        ], [
            'id'         => '4',
            'table'      => self::getTable(),
            'field'      => 'state',
            'name'       => __('Result', 'ticketflow'),
            'datatype'   => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ], [
            'id'       => '5',
            'table'    => self::getTable(),
            'field'    => 'reference_date',
            'name'     => __('Reference date', 'ticketflow'),
            'datatype' => 'datetime',
        ], [
            'id'       => '6',
            'table'    => self::getTable(),
            'field'    => 'deadline_date',
            'name'     => __('Deadline', 'ticketflow'),
            'datatype' => 'datetime',
        ], [
            'id'       => '7',
            'table'    => self::getTable(),
            'field'    => 'triggered_at',
            'name'     => __('Triggered at', 'ticketflow'),
            'datatype' => 'datetime',
        ], [
            'id'       => '8',
            'table'    => self::getTable(),
            'field'    => 'calendar_name',
            'name'     => __('Calendar'),
            'datatype' => 'string',
        ], [
            'id'       => '9',
            'table'    => self::getTable(),
            'field'    => 'error',
            'name'     => __('Error'),
            'datatype' => 'text',
        ], [
            'id'       => '80',
            'table'    => \Entity::getTable(),
            'field'    => 'completename',
            'name'     => \Entity::getTypeName(1),
            'datatype' => 'dropdown',
        ]];
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

        if ($field === 'state') {
            return ExecutionState::options()[$values[$field]] ?? (string) $values[$field];
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
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

        if ($field === 'state') {
            $options['display'] = false;

            return (string) \Dropdown::showFromArray($name, ExecutionState::options(), $options + ['value' => $values[$field]]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
