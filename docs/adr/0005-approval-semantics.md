# ADR-005 — An approval rule works on one approval *request*

**Status:** accepted (0.1)

## Context

GLPI 11 models approvals in three layers:

```
glpi_validationsteps        a named step template, with a required approval percentage
glpi_itils_validationsteps  one step attached to one ticket
glpi_ticketvalidations      one request, targeting a User or a Group
```

A ticket can have several steps; a step can have several requests; a step is satisfied when
the accepted share reaches `minimal_required_validation_percent`
(`ITIL_ValidationStep::getStatus()`); `glpi_tickets.global_validation` aggregates the whole
ticket.

So "the approval has been waiting for two business days" is ambiguous. Three approvers, two
answered, one silent — which clock is running, and since when?

Reference production data: 30 validation rows, 8 waiting, 26 steps, all requiring 100%,
targeting both `User` and `Group`.

## Decision

**The unit is one approval request** — one `glpi_ticketvalidations` row with
`status = WAITING`.

* Reference date: that row's `submission_date`.
* Occurrence key: `pa:<validations_id>:<submission_date>`.
* A ticket with three unanswered approvers has three independent clocks and can produce
  three executions.

Candidates are restricted to approvals on open tickets, via `Ticket::getOpenCriteria()`.

## Rationale

It is the only reading that stays unambiguous when approvers answer at different times.
Every alternative needs an arbitrary tie-break the moment the answers are staggered.

It is also the granularity core itself chose: `CommonITILValidationCron::cronApprovalReminder()`
selects work per `glpi_ticketvalidations` row with `status = WAITING`, and stamps
`last_reminder_date` per row. TicketFlow deliberately reuses that query shape.

And it composes: an administrator who wants "act once per ticket" configures a single
approval request, or handles the aggregate with a status-based rule.

## Consequences

A ticket with several unanswered approvers can receive several messages. That is correct —
each approver is separately late — but it is worth knowing before writing a noisy rule, so
it is stated in `docs/rules.md`.

Steps are *not* interpreted in 0.1: TicketFlow does not compute whether a step reached its
required percentage. A step-level rule type can be added later without changing anything
here, because the occurrence key is already namespaced (`pa:`).

Core's approval reminder only notifies; it never acts on the ticket. TicketFlow complements
it rather than replacing it, and the two can be used together.

## Alternatives rejected

**One clock per ticket, from the earliest waiting request.** Silently ignores later
requests: an approval added yesterday would inherit a deadline from one submitted last
week.

**One clock per step.** Needs a rule for "what does an unanswered *step* mean when it is
partly accepted?", which core answers with a percentage that has no time dimension.

**Use `glpi_tickets.global_validation`.** Aggregated, carries no date, and cannot tell
which request is late.
