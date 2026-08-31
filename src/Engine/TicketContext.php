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

namespace GlpiPlugin\Ticketclock\Engine;

use GlpiPlugin\Ticketclock\Enum\ResetEvent;
use GlpiPlugin\Ticketclock\Support\Time;

/**
 * The state of one ticket, as far as the rule engine is concerned.
 *
 * Built by TicketContextResolver (which is the only piece that talks to the database)
 * and consumed by the matchers, which stay pure and therefore testable.
 */
final readonly class TicketContext
{
    /**
     * @param list<int>                    $assigned_groups
     * @param list<int>                    $requester_users
     * @param list<int>                    $requester_groups
     * @param list<int>                    $assigned_users
     * @param array<string, string|null>   $last_events keyed by ResetEvent::value
     */
    public function __construct(
        public int $tickets_id,
        public string $name,
        public int $entities_id,
        public int $status,
        public bool $is_deleted,
        public ?string $begin_waiting_date,
        public ?string $date_mod,
        public array $assigned_groups,
        public array $requester_users,
        public array $requester_groups,
        public array $assigned_users,
        public int $pendingreasons_id,
        public array $last_events,
        /** Calendar of the ticket's entity (0 when the entity tree defines none). */
        public int $entity_calendars_id = 0,
        public ?ValidationContext $validation = null,
        /** The most recent human message, whoever wrote it; null when there is none. */
        public ?MessageContext $last_message = null,
        /** When the ticket last changed status; null when that cannot be established. */
        public ?string $status_changed_at = null,
    ) {}

    public function lastEventDate(ResetEvent $event): ?string
    {
        $value = $this->last_events[$event->value] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The most recent date among the rule's reset events, or null when none happened.
     *
     * @param list<ResetEvent> $events
     */
    public function latestResetDate(array $events): ?string
    {
        $latest = null;
        foreach ($events as $event) {
            $date = $this->lastEventDate($event);
            if ($date === null) {
                continue;
            }
            if ($latest === null || Time::stamp($date) > Time::stamp($latest)) {
                $latest = $date;
            }
        }

        return $latest;
    }

    /** Same ticket, but pointing at another approval request. */
    public function withValidation(?ValidationContext $validation): self
    {
        return new self(
            $this->tickets_id,
            $this->name,
            $this->entities_id,
            $this->status,
            $this->is_deleted,
            $this->begin_waiting_date,
            $this->date_mod,
            $this->assigned_groups,
            $this->requester_users,
            $this->requester_groups,
            $this->assigned_users,
            $this->pendingreasons_id,
            $this->last_events,
            $this->entity_calendars_id,
            $validation,
            $this->last_message,
            $this->status_changed_at,
        );
    }
}
