# ADR-003 — Reference dates come from core; activity is judged by author id

**Status:** accepted (0.1)

## Context

Two questions decide whether a rule is correct: *when did the wait start?* and *what counts
as the wait ending?*

The tempting answers are both wrong.

`glpi_tickets.date_mod` is not "when did the wait start" — it moves when anyone changes a
category, a priority, or when another plugin touches the row.

"The last message in the timeline" is not "the requester answered" — the last message is
very often a technician chasing the requester, and treating it as activity would silently
grant another full deadline every time somebody follows up.

## Decision

**Reference dates come from core fields that mean what we need:**

| Rule type | Reference | Maintained by |
|---|---|---|
| `pending_inactivity` | `glpi_tickets.begin_waiting_date` | core, on the status transition into/out of `WAITING` (`CommonITILObject::post_updateItem()`) |
| `pending_approval` | `glpi_ticketvalidations.submission_date` | core, when the request is created |

A pending ticket without `begin_waiting_date` is reported as `no_reference_date` and left
alone. TicketFlow does not invent a reference date.

**Activity is classified by author identity, from the actor tables:**

* `requester_followup` — the author is a `REQUESTER` actor, or a member of a requester
  group;
* `assignee_followup` — the author is an `ASSIGN` actor, or a member of an assigned group;
* `any_followup`, `solution_added`, `validation_answered` for the coarser cases.

All by id, from `glpi_tickets_users` / `glpi_groups_tickets` / `glpi_groups_users`. Never
by name, label or message content.

A rule lists which of these restart its clock. The reference becomes
`max(cycle start, latest listed event)`.

## Consequences

The plugin can express "waiting for the requester" precisely: a technician's reply does
*not* buy the ticket another five days, unless the rule says it should.

No duplicated state. `begin_waiting_date` is maintained by core on the exact transition we
care about, so there is no plugin-side bookkeeping to get out of sync, and no hooks needed
to maintain it.

It also gives occurrence identity for free: the field resets on every entry into pending,
so a ticket that leaves and returns is a genuinely new cycle.

Verified on production data: **74 of 74** currently-pending tickets have a non-null
`begin_waiting_date`.

## Consequence to watch

Followups TicketFlow writes must never be read back as activity. They carry the marker
`<!-- ticketflow-generated -->`, filtered out of every followup query, and their ids are
recorded on the execution row.

Related trap, found in core: `Glpi\Features\ParentStatus::updateParentStatus()` **reopens a
WAITING ticket when a followup is added**, which would clear `begin_waiting_date` and
destroy the clock being acted on. Generated followups pass `_no_reopen` and
`_do_not_compute_status`.
