# ADR-007 — Idempotency and concurrency share one row and one nullable unique index

**Status:** accepted (0.1)

## Context

The cron may run every minute. Two workers may run at once. An administrator may press
*Run for real now* while the cron is mid-flight. None of that may close the same ticket
twice or post the same message twice.

But blocking `(rule, ticket)` forever would be wrong too: a ticket that leaves pending and
comes back genuinely deserves a new deadline.

And a simulation must not consume a real execution.

## Decision

**An occurrence key identifies a cycle, not a pair:**

| Rule type | Key |
|---|---|
| `pending_inactivity` | `pi:<tickets_id>:<begin_waiting_date>` |
| `pending_approval` | `pa:<ticketvalidations_id>:<submission_date>` |

The key uses the **cycle start**, not the (possibly reset) reference date — an answer in
the middle of a cycle moves the deadline but must not create a second occurrence.

**Claiming is an insert.** A worker inserts an execution row with
`claim_key = "<rules_id>:<tickets_id>:<occurrence_key>"` under a `UNIQUE` index. A
competing worker's insert fails and it moves on. The claim row *is* the audit row.

**`claim_key` is `NULL` for rows that must not block:** dry runs, and executions that ended
as `skipped`. MySQL and MariaDB allow duplicate `NULL`s in a unique index, which gives a
partial unique constraint with no vendor-specific syntax.

## Rationale

No locks are held. Nothing needs releasing on the happy path. There is no lock table, no
lease, no expiry clock to tune — the database's own uniqueness guarantee does the work, at
the exact moment work begins.

Embedding the cycle timestamp in the key means "can this fire again?" is answered by the
data itself, with no extra bookkeeping.

## Consequences

Running the cron every minute is harmless.

A crashed run leaves a `processing` row that keeps blocking its occurrence **forever**.
That is the chosen failure mode: the alternative — expiring stale claims automatically —
risks repeating a destructive action after a crash that may have happened *after* the
solution was added. Stuck rows are visible in the execution log and an administrator
deletes the row to release the occurrence. This is documented in the README's
troubleshooting section rather than hidden.

`failed` also keeps its claim, for the same reason (see ADR-004).

Because the claim is taken *before* the actions and the state can change in between, the
engine re-reads the ticket and re-evaluates the rule immediately after claiming. If the
conditions no longer hold, the execution is closed as `skipped: state_changed`, which
releases the claim.

## Alternatives rejected

**`SELECT … FOR UPDATE` / a lock table.** Holds locks across API calls that raise
notifications; a slow notification would block other workers.

**`(rules_id, tickets_id)` unique.** Would permanently prevent a ticket from ever firing
again for that rule.

**A `processed` flag on the ticket.** Requires writing to a core table, which TicketFlow
does not do, and cannot represent multiple rules or repeated cycles.
