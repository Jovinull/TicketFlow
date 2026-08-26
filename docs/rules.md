# TicketFlow — Rule semantics

The exact meaning of every field, including the cases where an intuitive reading would be
wrong.

---

## Rule types

### `pending_inactivity` — "Pending without answer"

**Situation:** a ticket sits in a pending state and the interaction the team is waiting for
never arrives.

**Reference date:** `glpi_tickets.begin_waiting_date`, which core writes exactly when the
status becomes `WAITING` and clears when it leaves
(`CommonITILObject::post_updateItem()`). It is *not* `date_mod`: `date_mod` moves for
reasons that have nothing to do with an answer — a category change, a priority change,
another plugin touching the row.

A pending ticket with no `begin_waiting_date` is reported as `no_reference_date` and left
alone. TicketFlow refuses to invent a reference date; *Diagnostics* reports how many such
tickets exist.

**Occurrence:** the ticket plus its `begin_waiting_date`. Leaving pending and coming back
produces a new occurrence, so the rule can fire again.

### `pending_approval` — "Approval without answer"

**Situation:** an approval request has been waiting for a decision.

**Reference date:** `glpi_ticketvalidations.submission_date`.

**Unit of work: one approval request.** A ticket with three approvers has three
independent clocks, and the rule fires for each one that stays unanswered. This is the only
reading that stays unambiguous when approvers answer at different times, and it matches the
granularity core's own approval reminder cron uses. See
[ADR-005](adr/0005-approval-semantics.md).

**Occurrence:** the validation row plus its `submission_date`.

Candidates are restricted to approvals whose ticket is still open, using
`Ticket::getOpenCriteria()` — the same guard core uses.

---

## Conditions

### Assigned groups

A rule matches a ticket when **one of** the ticket's assigned groups
(`CommonITILActor::ASSIGN`) is listed on the rule. An empty list means *any group*.

> GLPI has no notion of a "primary" assigned group, so requiring one would be inventing
> semantics. And it would be useless in practice: in the reference production database,
> 2454 tickets have one assigned group, **2675 have two, 317 have three and 12 have four**.

Careful with the actor constants — `ASSIGN` is **2** and `OBSERVER` is **3**, not the other
way round. TicketFlow always reads `CommonITILActor::*`, never a literal.

Group hierarchy is **not** walked in 0.1: a rule targeting a parent group does not match
tickets assigned to a child group. List the groups you mean.

### Ticket status

Matched by id against `Ticket::getAllStatusArray()`, never by translated label.

For `pending_inactivity` this defaults to `WAITING` and should normally stay there — the
reference date only exists for pending tickets. For `pending_approval`, *Any status* is
usually right, since a ticket can be waiting for approval in several statuses.

### Pending reason

`0` means **any pending reason, including none**. Only set it when this installation
actually defines pending reasons and you want to distinguish them.

> The reference production instance has **no** `PendingReason` rows at all — all 191
> pending items carry `pendingreasons_id = 0`. *Diagnostics* shows what your installation
> has.

### Entity scope

A rule belongs to an entity. With *Child entities* set to No it matches only that entity;
with Yes it also matches descendants, resolved through GLPI's own entity tree.

A rule can never affect a ticket outside its scope. If the entity tree cannot be resolved,
the rule refuses to match rather than risking a leak into a sibling entity.

---

## The clock

### The countdown starts at

| Mode | Meaning |
|---|---|
| `pending_start` (default) | the moment the ticket entered the status, from `begin_waiting_date` |
| `last_target_group_message` | the last visible message on the ticket, **and only while that message was written by a member of one of the rule's target groups** |

`last_target_group_message` is the "we answered and nobody came back to us" clock:

* the rule **stops matching entirely** as soon as anybody outside the group replies — the
  last word is no longer yours, so it is not their turn to be chased;
* a newer message from the group starts a new waiting period, and a new occurrence;
* it needs no `begin_waiting_date`, so it works on statuses core never stamps a date on —
  "Approval", for one.

A **message** here means a public `ITILFollowup`. Private notes are excluded: a note the
requester cannot see cannot be what put the ball in their court. TicketFlow's own generated
followups are excluded too, so a rule never restarts its own clock. Tasks and solutions are
not messages in this sense.

Group membership is read from `glpi_groups_users` by id — not by name, and not by whether
the author happens to be an assigned actor on that ticket. When a rule names no group,
"yours" resolves to the ticket's own assigned groups.

See [ADR-009](adr/0009-last-group-message-clock.md).

### A status change always restarts the countdown

In both modes, the reference is `max(the mode's own reference, the last status change)`.
A ticket that has just moved between states is judged fresh rather than acted on with a
stale clock, and because a new reference means a new occurrence key, it can fire again
later on its own merits.

The status-change date comes from GLPI's own history (`glpi_logs`), with
`begin_waiting_date` as the fallback when history has been purged.

### Delay and unit

| Unit | Meaning |
|---|---|
| `business_days` | working days from the calendar |
| `business_hours` | opening hours from the calendar |
| `calendar_days` | plain elapsed days, calendar ignored |
| `hours` | plain elapsed hours, calendar ignored |

Business units are computed by GLPI's `Calendar::computeEndDate()`. Consequences worth
stating, because they surprise people:

* **The start day is not counted.** Monday 10:00 + 5 business days = *next* Monday 10:00.
* Non-working days and holidays are skipped, pushing the deadline out.
* If the clock starts on a non-working day, it restarts at the next opening time.
* The result is clamped to the last working hour of the day it lands on: Monday 19:00 +
  1 business day, on an 08:00–18:00 calendar, is Tuesday **18:00**.
* A deadline that falls exactly on `now` counts as expired.

### Calendar mode

| Mode | Behaviour |
|---|---|
| `entity` (default) | the ticket entity's calendar, resolved through the entity tree, then the plugin's fallback calendar |
| `specific` | always the calendar named on the rule |
| `none` | no calendar; the delay is plain elapsed time |

If a business unit is requested but no usable calendar is found — none configured, or one
with no working day at all — the delay falls back to plain elapsed time. This mirrors
core's own SLA behaviour and is **never silent**: `used_elapsed_fallback` is recorded on
every execution row and flagged in the dry-run table.

### Restart the clock on

This is where "who was supposed to answer?" is expressed.

| Event | Counts when |
|---|---|
| `requester_followup` | the followup's author is a ticket requester, or belongs to a requester group |
| `assignee_followup` | the author is an assigned user, or belongs to an assigned group |
| `any_followup` | any human followup |
| `solution_added` | a solution was added |
| `validation_answered` | an approval attached to the ticket was answered |

The reference date becomes `max(cycle start, latest selected event)`.

**A rule that lists only `requester_followup` is not restarted by a technician's reply.**
That is the entire point: a technician chasing the requester should not silently buy the
ticket another five days.

Authors are resolved from `glpi_tickets_users` and `glpi_groups_tickets` by id. TicketFlow
never infers an author's role from a name or from the text of a message.

**TicketFlow's own followups never count.** They carry an HTML marker that the resolver
filters out, so a rule cannot restart its own clock or trigger itself in a loop.

Note that resetting the clock does **not** create a new occurrence — the occurrence key is
tied to the cycle start. An answer moves the deadline; it does not start a second cycle.

---

## Actions

Actions run in a fixed order: the message first, then the terminal action, then the
notification. A message is written *before* the ticket is solved, never after.

| Action | What it does |
|---|---|
| `add_followup` | adds an `ITILFollowup`, carrying the TicketFlow marker |
| `add_solution` | adds an `ITILSolution` — **this is how a ticket is solved in GLPI** |
| `change_status` | `Ticket::update()` with a new status |
| `close_ticket` | sets the status straight to `CLOSED` |
| `send_notification` | raises a GLPI notification event on the ticket |

### Solving versus closing

**Prefer `add_solution`.** In GLPI, adding a solution is what makes a ticket solved: core
moves the status to `SOLVED` (or straight to `CLOSED` when the entity's `autoclose_delay`
is 0), records what was done, and keeps solution approval and reopening working. This is
also exactly what core's own `PendingReasonCron` does when it auto-resolves.

`close_ticket` bypasses that flow: no solution is recorded, and the requester never gets a
chance to reject it. It exists because some processes genuinely want it, and the rule form
says so.

`add_solution` requires an acting user. If neither TicketFlow's `system_users_id` nor
GLPI's `system_user` is set, the action fails with an explicit error rather than
attributing a solution to nobody.

### Who the actions run as

From the automatic action, they run as the **system**: `Session::isCron()` is true, and core
skips the ticket-template restrictions that would otherwise reject an update
(`CommonITILObject::handleTemplateFields()`).

From the *Run for real now* button they run as **you**. `Session::isCron()` requires a CLI
context or `/front/cron.php`, so a web request never qualifies, and an operator without the
update right on tickets can see actions refused there that the scheduler performs without
trouble. The simulation screen warns when that is the case.

### Partial failure

If an action fails, the remaining actions of that occurrence do not run and the execution
is recorded as `failed` with every result kept. Partial execution is visible, not hidden —
see [ADR-004](adr/0004-no-transaction-around-actions.md).

---

## Changing a rule that is already live

**Current configuration always wins.** A rule changed from 5 days to 3 immediately affects
every ticket currently matching it — deadlines are recomputed on every run, never
versioned.

This is the behaviour an administrator expects when they shorten a deadline, and the
alternative (freezing configuration per occurrence) would mean a wrong rule keeps doing the
wrong thing until every open cycle drains.

The consequence to keep in mind: **lengthening** a delay can un-expire tickets that were
about to fire, and **shortening** it can make a batch expire at once. Simulate after
changing a delay — that is what the dry run is for.

Already-executed occurrences are never revisited, whatever the new configuration says.

---

## Execution states

| State | Meaning | Blocks the occurrence? |
|---|---|---|
| `processing` | claimed, actions not finished | yes |
| `executed` | all actions succeeded | yes |
| `failed` | an action failed; the rest were aborted | yes |
| `skipped` | conditions no longer held at re-validation | no |
| `dry_run` | simulated; nothing was modified | no |

`failed` blocks on purpose. A rule that adds a solution and then fails to change a status
has already modified the ticket; retrying it automatically would compound the problem. The
error is in the log, for a human to decide.
