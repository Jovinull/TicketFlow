# ADR-009 — A second clock, measured from the conversation instead of the state

**Status:** accepted (0.2)

## Context

0.1 timed the *state*: the ticket entered a pending status, and the countdown ran from
`begin_waiting_date`. That answers "how long has this been pending?".

It turned out not to be the question the team was asking. Theirs was:

> the ticket is assigned to us, **we sent the last message**, and nobody came back — start
> counting from our message.

The difference is not cosmetic. On the reference production database, of the five pending
tickets assigned to the target group, only **two** had a message from that group as the
last word. On the other three the last message came from someone outside the group. Under
the state clock all five look identical; under the conversation clock three of them are
plainly not the team's problem to chase.

There was a second reason to add it. `begin_waiting_date` only exists for a couple of
statuses — it is `NULL` for "Approval", which is exactly one of the statuses the policy
needed to cover. A clock that hangs off the conversation works on any status, because it
does not need core to have stamped a date on the state.

## Decision

A rule chooses its `start_event`:

* **`pending_start`** — the 0.1 behaviour, unchanged and still the default.
* **`last_target_group_message`** — the rule applies **only while the most recent visible
  message on the ticket was written by a member of one of its target groups**, and the
  countdown runs from that message.

Three consequences follow directly, and are the point of the mode:

1. **Anybody replying from outside the group stops the rule.** Not "resets the clock" —
   stops it. The last word is no longer ours, so it is not their turn to be chased.
2. **A status change restarts the countdown**, in both modes. The reference becomes
   `max(last message, status change)`, and since a new reference means a new occurrence,
   the ticket is judged fresh rather than acted on with a stale clock.
3. **A new message from the group is a new waiting period**, and therefore a new occurrence
   that can fire again later.

"Member of the group" is resolved through `glpi_groups_users` by id. Not by name, not by
whether they happen to be an assigned actor on this ticket — by membership.

When a rule names no group, "ours" resolves to the ticket's own assigned groups. Without
that fallback the question would have no answer at all.

## What counts as a message

Public `ITILFollowup` rows only.

**Private notes are excluded.** A private note is invisible to the requester, so it cannot
be the message that put the ball in their court; letting one start the clock would mean
chasing somebody for an answer to something they never saw.

**TicketFlow's own generated followups are excluded**, by the same marker the rest of the
engine uses — otherwise the plugin's reminder would restart its own clock forever.

Tasks and solutions are not messages in this sense and are not considered.

## Where the status change comes from

`glpi_logs`, GLPI's own history, looked up by the search-option number for the ticket status
field — resolved from `Search::getOptions()` rather than hardcoded, so a core renumbering
degrades to "no status history" instead of to silently wrong dates from another field.

`begin_waiting_date` is the fallback when history has been purged. No plugin-side state is
kept: core already records this, and duplicating it would only create something to drift.

## Consequences

The candidate query had to change with it. The prefilter for this mode is "there is a
visible message older than the threshold **and** no newer one", written as two `EXISTS`
subqueries so both halves use the `(itemtype, items_id)` index rather than aggregating the
followup table. Keeping the old `begin_waiting_date` prefilter here would have silently
dropped every ticket whose pending state is newer than its last message — a false negative,
the worst kind, invisible without an end-to-end test. It was in fact caught by one.

The mode is more conservative than the state clock: it stops matching the moment anyone
answers, so it can only ever act on fewer tickets. That asymmetry is deliberate.

## Alternatives rejected

**Reset the clock on an outside reply instead of stopping.** Would keep chasing a ticket
where the team owes the answer — the opposite of the intent.

**"Last message from the group" regardless of what came after.** Same defect: a requester
who replied yesterday would still be chased because the team wrote something last week.

**Infer the author's role from the ticket actors instead of group membership.** A technician
who answers without being listed as an assignee is still one of us; group membership is the
fact that was actually meant.
