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

use CommonDBChild;
use GlpiPlugin\Ticketflow\Engine\ActionDefinition;
use GlpiPlugin\Ticketflow\Enum\ActionType;

use function Safe\json_encode;

/**
 * One action of a rule, stored generically (`action_type` + JSON `params`).
 *
 * The storage is deliberately open-ended so new action types need no migration, while the
 * 0.1 rule form only exposes the combinations that are actually useful: an optional
 * message, one terminal action, and an optional notification. Ranking keeps the order
 * meaningful — the message is written before the ticket is solved, never after.
 */
class RuleAction extends CommonDBChild
{
    public static $rightname = 'plugin_ticketflow_rule';

    public static $itemtype = Rule::class;
    public static $items_id = 'plugin_ticketflow_rules_id';

    public $dohistory = true;

    /** Ranks chosen so a message always lands before a terminal action. */
    private const RANK_FOLLOWUP     = 10;
    private const RANK_FINAL        = 20;
    private const RANK_NOTIFICATION = 30;

    public static function getTypeName($nb = 0)
    {
        return _n('Action', 'Actions', $nb, 'ticketflow');
    }

    /**
     * @return list<ActionDefinition>
     */
    public static function getDefinitionsForRule(int $rules_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($rules_id <= 0) {
            return [];
        }

        $out = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_ticketflow_rules_id' => $rules_id],
            'ORDER' => ['ranking ASC', 'id ASC'],
        ]);

        foreach ($iterator as $row) {
            $definition = ActionDefinition::fromRow($row);
            if ($definition !== null) {
                $out[] = $definition;
            }
        }

        return $out;
    }

    /**
     * Row set collapsed into the shape the rule form uses.
     *
     * @return array{
     *     add_followup: array{enabled: bool, content: string, is_private: bool},
     *     final: array{type: string, content: string, solutiontypes_id: int, status: int},
     *     send_notification: array{enabled: bool, event: string}
     * }
     */
    public static function getFormValues(int $rules_id): array
    {
        $values = [
            'add_followup'      => ['enabled' => false, 'content' => '', 'is_private' => false],
            'final'             => ['type' => 'none', 'content' => '', 'solutiontypes_id' => 0, 'status' => 0],
            'send_notification' => ['enabled' => false, 'event' => 'update'],
        ];

        foreach (self::getDefinitionsForRule($rules_id) as $definition) {
            switch ($definition->type) {
                case ActionType::AddFollowup:
                    $values['add_followup'] = [
                        'enabled'    => true,
                        'content'    => $definition->stringParam('content'),
                        'is_private' => $definition->boolParam('is_private'),
                    ];
                    break;

                case ActionType::AddSolution:
                    $values['final'] = [
                        'type'             => ActionType::AddSolution->value,
                        'content'          => $definition->stringParam('content'),
                        'solutiontypes_id' => $definition->intParam('solutiontypes_id'),
                        'status'           => 0,
                    ];
                    break;

                case ActionType::ChangeStatus:
                    $values['final'] = [
                        'type'             => ActionType::ChangeStatus->value,
                        'content'          => '',
                        'solutiontypes_id' => 0,
                        'status'           => $definition->intParam('status'),
                    ];
                    break;

                case ActionType::CloseTicket:
                    $values['final'] = [
                        'type'             => ActionType::CloseTicket->value,
                        'content'          => '',
                        'solutiontypes_id' => 0,
                        'status'           => 0,
                    ];
                    break;

                case ActionType::SendNotification:
                    $values['send_notification'] = [
                        'enabled' => true,
                        'event'   => $definition->stringParam('event', 'update'),
                    ];
                    break;
            }
        }

        return $values;
    }

    /**
     * Rewrite a rule's action rows from the form payload.
     *
     * The set is small and always replaced as a whole, so a plain delete-then-insert is
     * both simpler and less error-prone than diffing.
     *
     * @param array<string, mixed> $payload
     */
    public static function setActionsForRule(int $rules_id, array $payload): void
    {
        if ($rules_id <= 0) {
            return;
        }

        (new self())->deleteByCriteria(['plugin_ticketflow_rules_id' => $rules_id]);

        $followup = (array) ($payload['add_followup'] ?? []);
        if (!empty($followup['enabled'])) {
            self::insert($rules_id, ActionType::AddFollowup, self::RANK_FOLLOWUP, [
                'content'    => (string) ($followup['content'] ?? ''),
                'is_private' => !empty($followup['is_private']),
            ]);
        }

        $final = (array) ($payload['final'] ?? []);
        $final_type = ActionType::tryFromString(isset($final['type']) ? (string) $final['type'] : null);
        if ($final_type !== null) {
            $params = match ($final_type) {
                ActionType::AddSolution => [
                    'content'          => (string) ($final['content'] ?? ''),
                    'solutiontypes_id' => (int) ($final['solutiontypes_id'] ?? 0),
                ],
                ActionType::ChangeStatus => ['status' => (int) ($final['status'] ?? 0)],
                default                  => [],
            };
            self::insert($rules_id, $final_type, self::RANK_FINAL, $params);
        }

        $notification = (array) ($payload['send_notification'] ?? []);
        if (!empty($notification['enabled'])) {
            self::insert($rules_id, ActionType::SendNotification, self::RANK_NOTIFICATION, [
                'event' => (string) ($notification['event'] ?? 'update'),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function insert(int $rules_id, ActionType $type, int $ranking, array $params): void
    {
        (new self())->add([
            'plugin_ticketflow_rules_id' => $rules_id,
            'action_type'                => $type->value,
            'ranking'                    => $ranking,
            'params'                     => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
