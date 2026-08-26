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

namespace GlpiPlugin\Ticketflow\Engine;

use CommonITILActor;
use CommonITILValidation;
use Entity;
use Group_Ticket;
use Group_User;
use ITILFollowup;
use ITILSolution;
use Log;
use PendingReason_Item;
use Search;
use Ticket;
use Ticket_User;
use TicketValidation;
use GlpiPlugin\Ticketflow\Engine\Action\AddFollowupAction;
use GlpiPlugin\Ticketflow\Enum\ResetEvent;

use function Safe\strtotime;

/**
 * Turns ticket ids into {@see TicketContext} objects.
 *
 * This is the only class that knows how GLPI stores actors, followups, pending state and
 * approvals — which is exactly the point: if this installation turns out to model
 * something differently, the change lands here and the engine is untouched.
 *
 * Everything is loaded in bulk for a whole batch. Resolving 200 tickets costs a fixed
 * handful of queries, not 200 × N.
 *
 * Author classification never guesses. A followup counts as "from the requester" when its
 * `users_id` is a REQUESTER actor of that ticket, or belongs to a requester group — ids
 * all the way down, no names, no labels, no free text.
 */
final class TicketContextResolver
{
    /** @var array<int, int> entities_id => calendars_id */
    private array $entity_calendar_cache = [];

    /** null once resolved to "not found"; false while still unresolved. */
    private int|false|null $status_search_option = false;

    /**
     * @param list<Candidate> $candidates
     * @return array<string, TicketContext> keyed by "<tickets_id>:<validations_id>"
     */
    public function resolveBatch(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $ticket_ids = [];
        foreach ($candidates as $candidate) {
            $ticket_ids[$candidate->tickets_id] = $candidate->tickets_id;
        }
        $ticket_ids = array_values($ticket_ids);

        $tickets     = $this->loadTickets($ticket_ids);
        $groups      = $this->loadGroupActors($ticket_ids);
        $users       = $this->loadUserActors($ticket_ids);
        $pending     = $this->loadPendingReasons($ticket_ids);
        $followups   = $this->loadFollowups($ticket_ids);
        $solutions   = $this->loadLastSolutionDates($ticket_ids);
        $validations = $this->loadValidations($ticket_ids);
        $messages    = $this->loadLastMessages($ticket_ids);
        $status_dates = $this->loadStatusChangeDates($ticket_ids);

        // Only the authors we actually saw need their group memberships resolved.
        $author_ids = [];
        foreach ($followups as $rows) {
            foreach ($rows as $row) {
                $author_ids[(int) $row['users_id']] = (int) $row['users_id'];
            }
        }
        foreach ($messages as $row) {
            $author_ids[(int) $row['users_id']] = (int) $row['users_id'];
        }
        $memberships = $this->loadGroupMemberships(array_values($author_ids));

        $out = [];
        foreach ($candidates as $candidate) {
            $ticket = $tickets[$candidate->tickets_id] ?? null;
            if ($ticket === null) {
                continue;
            }

            $context = $this->buildContext(
                $ticket,
                $groups[$candidate->tickets_id] ?? [],
                $users[$candidate->tickets_id] ?? [],
                $pending[$candidate->tickets_id] ?? 0,
                $followups[$candidate->tickets_id] ?? [],
                $solutions[$candidate->tickets_id] ?? null,
                $validations[$candidate->tickets_id] ?? [],
                $memberships,
                $candidate->validations_id,
                $messages[$candidate->tickets_id] ?? null,
                $status_dates[$candidate->tickets_id] ?? null,
            );

            $out[$this->key($candidate->tickets_id, $candidate->validations_id)] = $context;
        }

        return $out;
    }

    /**
     * Reload a single context straight from the database.
     *
     * Used for the mandatory re-validation right before actions run: the batch context may
     * be seconds or minutes old, and in that window a requester may well have answered.
     */
    public function resolveOne(int $tickets_id, int $validations_id = 0): ?TicketContext
    {
        $contexts = $this->resolveBatch([new Candidate($tickets_id, $validations_id)]);

        return $contexts[$this->key($tickets_id, $validations_id)] ?? null;
    }

    public function key(int $tickets_id, int $validations_id): string
    {
        return $tickets_id . ':' . $validations_id;
    }

    // -----------------------------------------------------------------------------
    // Loading
    // -----------------------------------------------------------------------------

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function loadTickets(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'entities_id', 'status', 'is_deleted', 'begin_waiting_date', 'date_mod'],
            'FROM'   => Ticket::getTable(),
            'WHERE'  => ['id' => $ids],
        ]);

        foreach ($iterator as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<int, list<int>>> tickets_id => actor type => group ids
     */
    private function loadGroupActors(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['tickets_id', 'groups_id', 'type'],
            'FROM'   => Group_Ticket::getTable(),
            'WHERE'  => ['tickets_id' => $ids],
        ]);

        foreach ($iterator as $row) {
            $out[(int) $row['tickets_id']][(int) $row['type']][] = (int) $row['groups_id'];
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<int, list<int>>> tickets_id => actor type => user ids
     */
    private function loadUserActors(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['tickets_id', 'users_id', 'type'],
            'FROM'   => Ticket_User::getTable(),
            'WHERE'  => ['tickets_id' => $ids],
        ]);

        foreach ($iterator as $row) {
            $out[(int) $row['tickets_id']][(int) $row['type']][] = (int) $row['users_id'];
        }

        return $out;
    }

    /**
     * Current pending reason of each ticket.
     *
     * GLPI 11 stores this polymorphically in glpi_pendingreasons_items, with rows for both
     * the ticket and the followup that triggered the pending state; only the ticket row
     * describes the ticket's current state.
     *
     * @param list<int> $ids
     * @return array<int, int> tickets_id => pendingreasons_id
     */
    private function loadPendingReasons(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['items_id', 'pendingreasons_id'],
            'FROM'   => PendingReason_Item::getTable(),
            'WHERE'  => ['itemtype' => Ticket::class, 'items_id' => $ids],
            'ORDER'  => 'id ASC',
        ]);

        foreach ($iterator as $row) {
            $out[(int) $row['items_id']] = (int) $row['pendingreasons_id'];
        }

        return $out;
    }

    /**
     * Human followups only: anything TicketFlow generated is filtered out here, which is
     * what stops a rule from resetting its own clock.
     *
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function loadFollowups(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['items_id', 'users_id', 'date'],
            'FROM'   => ITILFollowup::getTable(),
            'WHERE'  => [
                'itemtype' => Ticket::class,
                'items_id' => $ids,
                ['NOT' => ['content' => ['LIKE', '%' . AddFollowupAction::MARKER . '%']]],
            ],
            'ORDER'  => 'date ASC',
        ]);

        foreach ($iterator as $row) {
            $out[(int) $row['items_id']][] = $row;
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadLastSolutionDates(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['items_id', 'date_creation'],
            'FROM'   => ITILSolution::getTable(),
            'WHERE'  => ['itemtype' => Ticket::class, 'items_id' => $ids],
            'ORDER'  => 'date_creation ASC',
        ]);

        foreach ($iterator as $row) {
            if ($row['date_creation'] !== null) {
                $out[(int) $row['items_id']] = (string) $row['date_creation'];
            }
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function loadValidations(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => [
                'id', 'tickets_id', 'status', 'submission_date', 'validation_date',
                'itemtype_target', 'items_id_target', 'itils_validationsteps_id',
            ],
            'FROM'   => TicketValidation::getTable(),
            'WHERE'  => ['tickets_id' => $ids],
            'ORDER'  => 'id ASC',
        ]);

        foreach ($iterator as $row) {
            $out[(int) $row['tickets_id']][] = $row;
        }

        return $out;
    }

    /**
     * The most recent *public* human followup per ticket.
     *
     * Public only: a private note is invisible to the requester, so it cannot be the
     * message that put the ball in their court. TicketFlow's own generated followups are
     * excluded here too, exactly as in loadFollowups().
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function loadLastMessages(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'items_id', 'users_id', 'date'],
            'FROM'   => ITILFollowup::getTable(),
            'WHERE'  => [
                'itemtype'   => Ticket::class,
                'items_id'   => $ids,
                'is_private' => 0,
                ['NOT' => ['content' => ['LIKE', '%' . AddFollowupAction::MARKER . '%']]],
            ],
            'ORDER'  => ['items_id ASC', 'date ASC', 'id ASC'],
        ]);

        // Ordered ascending, so the last row written for a ticket is its latest message.
        foreach ($iterator as $row) {
            $out[(int) $row['items_id']] = $row;
        }

        return $out;
    }

    /**
     * When each ticket last changed status.
     *
     * Read from GLPI's own history rather than kept as plugin state: core already records
     * every status change there, and `begin_waiting_date` only exists for a couple of
     * statuses (it is null for "Approval", for instance), so it cannot answer this on
     * its own.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function loadStatusChangeDates(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $option = $this->getStatusSearchOption();
        if ($option === null) {
            return [];
        }

        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['items_id', 'date_mod'],
            'FROM'   => Log::getTable(),
            'WHERE'  => [
                'itemtype'         => Ticket::class,
                'items_id'         => $ids,
                'id_search_option' => $option,
            ],
            'ORDER'  => ['items_id ASC', 'date_mod ASC', 'id ASC'],
        ]);

        foreach ($iterator as $row) {
            if ($row['date_mod'] !== null) {
                $out[(int) $row['items_id']] = (string) $row['date_mod'];
            }
        }

        return $out;
    }

    /**
     * The search-option number GLPI logs ticket status changes under.
     *
     * Resolved from the search options rather than hardcoded, so a core renumbering shows
     * up as "no status history" instead of as silently wrong dates from another field.
     */
    private function getStatusSearchOption(): ?int
    {
        if ($this->status_search_option !== false) {
            return $this->status_search_option;
        }

        $this->status_search_option = null;

        foreach (Search::getOptions(Ticket::class) as $num => $option) {
            if (
                is_array($option)
                && ($option['field'] ?? null) === 'status'
                && ($option['table'] ?? null) === Ticket::getTable()
            ) {
                $this->status_search_option = (int) $num;
                break;
            }
        }

        return $this->status_search_option;
    }

    /**
     * @param list<int> $user_ids
     * @return array<int, list<int>> users_id => groups_id
     */
    private function loadGroupMemberships(array $user_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $user_ids = array_values(array_filter($user_ids, static fn(int $id): bool => $id > 0));
        if ($user_ids === []) {
            return [];
        }

        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['users_id', 'groups_id'],
            'FROM'   => Group_User::getTable(),
            'WHERE'  => ['users_id' => $user_ids],
        ]);

        foreach ($iterator as $row) {
            $out[(int) $row['users_id']][] = (int) $row['groups_id'];
        }

        return $out;
    }

    // -----------------------------------------------------------------------------
    // Assembly
    // -----------------------------------------------------------------------------

    /**
     * @param array<string, mixed>              $ticket
     * @param array<int, list<int>>             $group_actors
     * @param array<int, list<int>>             $user_actors
     * @param list<array<string, mixed>>        $followups
     * @param list<array<string, mixed>>        $validations
     * @param array<int, list<int>>             $memberships
     * @param array<string, mixed>|null         $last_message_row
     */
    private function buildContext(
        array $ticket,
        array $group_actors,
        array $user_actors,
        int $pendingreasons_id,
        array $followups,
        ?string $last_solution_date,
        array $validations,
        array $memberships,
        int $validations_id,
        ?array $last_message_row,
        ?string $status_changed_at,
    ): TicketContext {
        $assigned_groups  = $group_actors[CommonITILActor::ASSIGN] ?? [];
        $requester_groups = $group_actors[CommonITILActor::REQUESTER] ?? [];
        $assigned_users   = $user_actors[CommonITILActor::ASSIGN] ?? [];
        $requester_users  = $user_actors[CommonITILActor::REQUESTER] ?? [];

        $last_events = [
            ResetEvent::AnyFollowup->value        => null,
            ResetEvent::RequesterFollowup->value  => null,
            ResetEvent::AssigneeFollowup->value   => null,
            ResetEvent::SolutionAdded->value      => $last_solution_date,
            ResetEvent::ValidationAnswered->value => null,
        ];

        foreach ($followups as $followup) {
            $date = isset($followup['date']) ? (string) $followup['date'] : '';
            if ($date === '') {
                continue;
            }

            $author = (int) $followup['users_id'];
            $author_groups = $memberships[$author] ?? [];

            $this->keepLatest($last_events, ResetEvent::AnyFollowup, $date);

            if (
                in_array($author, $requester_users, true)
                || array_intersect($author_groups, $requester_groups) !== []
            ) {
                $this->keepLatest($last_events, ResetEvent::RequesterFollowup, $date);
            }

            if (
                in_array($author, $assigned_users, true)
                || array_intersect($author_groups, $assigned_groups) !== []
            ) {
                $this->keepLatest($last_events, ResetEvent::AssigneeFollowup, $date);
            }
        }

        $selected_validation = null;
        foreach ($validations as $row) {
            if ((int) $row['status'] !== CommonITILValidation::WAITING && $row['validation_date'] !== null) {
                $this->keepLatest($last_events, ResetEvent::ValidationAnswered, (string) $row['validation_date']);
            }

            if ($validations_id > 0 && (int) $row['id'] === $validations_id) {
                $selected_validation = ValidationContext::fromRow($row);
            }
        }

        $entities_id = (int) $ticket['entities_id'];

        $last_message = null;
        if ($last_message_row !== null && ($last_message_row['date'] ?? null) !== null) {
            $author = (int) $last_message_row['users_id'];
            $last_message = new MessageContext(
                (int) $last_message_row['id'],
                (string) $last_message_row['date'],
                $author,
                array_values(array_unique($memberships[$author] ?? [])),
            );
        }

        // Falling back to begin_waiting_date keeps the clock sane on installations whose
        // history has been purged; without either, the matcher simply has no reset anchor.
        $status_changed_at ??= $ticket['begin_waiting_date'] !== null
            ? (string) $ticket['begin_waiting_date']
            : null;

        return new TicketContext(
            (int) $ticket['id'],
            (string) ($ticket['name'] ?? ''),
            $entities_id,
            (int) $ticket['status'],
            (bool) $ticket['is_deleted'],
            $ticket['begin_waiting_date'] !== null ? (string) $ticket['begin_waiting_date'] : null,
            $ticket['date_mod'] !== null ? (string) $ticket['date_mod'] : null,
            array_values(array_unique($assigned_groups)),
            array_values(array_unique($requester_users)),
            array_values(array_unique($requester_groups)),
            array_values(array_unique($assigned_users)),
            $pendingreasons_id,
            $last_events,
            $this->getEntityCalendar($entities_id),
            $selected_validation,
            $last_message,
            $status_changed_at,
        );
    }

    /**
     * @param array<string, string|null> $events
     */
    private function keepLatest(array &$events, ResetEvent $event, string $date): void
    {
        $current = $events[$event->value] ?? null;
        if ($current === null || strtotime($date) > strtotime($current)) {
            $events[$event->value] = $date;
        }
    }

    /**
     * Calendar inherited from the entity tree, resolved by core so the inheritance rules
     * stay identical to the rest of GLPI.
     */
    private function getEntityCalendar(int $entities_id): int
    {
        if (!array_key_exists($entities_id, $this->entity_calendar_cache)) {
            // Asked for by its *strategy* field, which is what GLPI 11 expects. Passing
            // 'calendars_id' as the reference still works -- core rewrites it -- but it
            // trigger_error()s a deprecation on every single call, and this one runs once per
            // entity per cron pass.
            $value = Entity::getUsedConfig('calendars_strategy', $entities_id, 'calendars_id', 0);
            $this->entity_calendar_cache[$entities_id] = is_numeric($value) ? (int) $value : 0;
        }

        return $this->entity_calendar_cache[$entities_id];
    }
}
