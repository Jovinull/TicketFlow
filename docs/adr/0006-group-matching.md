# ADR-006 — A rule matches when its group is *one of* the assigned groups

**Status:** accepted (0.1)

## Context

"Tickets assigned to the Development team" needs a precise meaning.

GLPI stores group actors in `glpi_groups_tickets(tickets_id, groups_id, type)` with
`CommonITILActor::REQUESTER = 1`, **`ASSIGN = 2`**, `OBSERVER = 3`. (The ordering is a
classic bug source — `OBSERVER` is not 2.)

There is **no "primary assigned group"** column, flag or ordering in the schema.

And multi-assignment is not an edge case. In the reference production database, of the
tickets with at least one assigned group:

| assigned groups | tickets |
|---|---|
| 1 | 2454 |
| 2 | **2675** |
| 3 | 317 |
| 4 | 12 |

More than half carry two or more.

## Decision

A rule matches a ticket when **at least one** of the ticket's `ASSIGN` groups appears in
the rule's group list. An empty list means *any group*.

A rule may list several groups; they are OR-ed.

In SQL this is a subquery on `glpi_groups_tickets`, not a join, so a ticket with several
matching groups still yields one candidate row.

Group hierarchy is **not** walked in 0.1: a rule targeting a parent group does not match
tickets assigned to a child group.

## Rationale

Requiring "the" assigned group would mean inventing a concept the schema does not have —
and picking the lowest `id`, or the first inserted, would be an arbitrary rule that silently
excluded more than half the tickets in a real installation.

## Consequences

A ticket assigned to both Development and Support matches a rule for either. If two rules
match, both run — each with its own occurrence, its own log entry, its own actions.
`ranking` controls evaluation order; combining a message-only rule with a destructive one
on the same tickets is a configuration to make deliberately, and the dry run shows it.

Not walking the group tree is a real limitation for deeply nested structures. It is a
deliberate 0.1 scope choice: implicit hierarchy expansion in a rule that can *close
tickets* is exactly the kind of surprise worth avoiding until it is asked for. Listing the
groups is explicit and auditable.

*Administration > TicketFlow > Diagnostics* reports the group-assignment distribution of
the installation, so this decision can be re-examined against local data rather than
against ours.
