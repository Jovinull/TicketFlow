# ADR-004 — Actions are not wrapped in a database transaction

**Status:** accepted (0.1)

## Context

A rule can run several actions: add a message, then add a solution, then raise a
notification. If the second fails, what should happen to the first?

The reflex is a transaction: `BEGIN`, run everything, `ROLLBACK` on failure.

## Decision

No transaction around the batch. Actions run in `ranking` order, each produces an
`ActionResult`, the first failure stops the rest, and the execution is recorded as `failed`
with every result kept.

## Rationale

The actions do not merely write rows. `ITILFollowup::add()` and `ITILSolution::add()` raise
notifications, write history entries, update the parent ticket and fire hooks that other
plugins listen to. A `ROLLBACK` undoes the rows and **none** of that.

The result would be a ticket whose timeline shows nothing, whose history shows nothing, but
whose requester already received an email saying the ticket was solved — a state strictly
*less* consistent than "the message was added, the solution failed, here is the record".

So the honest option wins: record precisely what happened and stop.

## Consequences

Partial execution is possible and visible. The execution log shows one success, one
failure, and the actions that never ran; `error` carries the first failure message.

`failed` **blocks** the occurrence — a `failed` row keeps its `claim_key`, so the next cron
run will not retry. A rule that added a solution and then failed to change a status has
already modified the ticket; retrying automatically would compound the damage. A human
decides.

Ordering is therefore load-bearing, and fixed: the message (rank 10) always precedes the
terminal action (rank 20), which precedes the notification (rank 30). A message written
after the ticket was solved would read as nonsense.

## Alternatives rejected

**Transaction with rollback.** Cannot undo notifications, history or other plugins' hook
side effects. Creates a worse inconsistency than it prevents.

**Continue after a failure.** Would let a rule close a ticket whose explanatory message
failed to post — the exact outcome that makes automated closure feel arbitrary to a user.

**Automatic retry.** Cannot distinguish "failed before doing anything" from "failed
halfway", and the second case is where retrying does real harm.
