# TicketFlow — Architecture

## 1. The domain in one sentence

> **conditions + a reference event + a delay in business time + a calendar → actions**

Everything else in this document is machinery in service of that sentence.

A **Rule** says *when*. A **Matcher** decides whether a ticket is in that situation and
when its deadline falls. **BusinessTimeCalculator** turns "5 business days" into a
timestamp. **Actions** say *what happens*. **Execution** records that it happened, exactly
once.

## 2. The seam that makes this testable

The single most consequential design decision is that the *reasoning* half of the plugin
knows nothing about GLPI.

```
GLPI-free (unit tested with no database, no GLPI checkout)
    Enum\*                    RuleType, DelayUnit, CalendarMode, ResetEvent, ActionType, ExecutionState
    Engine\RuleDefinition     a rule, as a value object
    Engine\TicketContext      a ticket's state, as a value object
    Engine\ValidationContext  one approval request
    Engine\Matcher\*          "does this rule apply, and when does it expire?"
    Engine\MessageRenderer    {{placeholder}} substitution
    Engine\OccurrenceKey      cycle identity
    Calendar\BusinessTime*    deadline arithmetic

GLPI-bound
    Rule, RuleGroup, RuleAction, Execution     CommonDBTM persistence
    Engine\CandidateFinder                     the SQL that narrows the search
    Engine\TicketContextResolver               the only class that knows GLPI's schema
    Engine\Action\*                            ITILFollowup, ITILSolution, Ticket
    Engine\RuleEngine                          sequencing and safety
    Cron                                       an entry point, nothing more
    Calendar\GlpiCalendarEngine                a thin wrapper over core's Calendar
```

The payoff is concrete: "does a holiday push the deadline?", "does a technician's reply
restart a clock that waits for the requester?", "does a second cron run repeat the
action?" are all answerable by a unit test that runs in milliseconds. Those are exactly
the questions that are otherwise only answered in production, weeks later, by a customer.

## 3. Components

### RuleEngine

Owns the *order* of operations and nothing else. Concretely it guarantees three things:

1. a failure on one ticket never stops the run;
2. nothing destructive happens without a fresh re-read of the ticket;
3. nothing happens twice, because the occurrence is claimed before any action runs.

### CandidateFinder

One indexed, `LIMIT`ed SQL query per batch. Never `SELECT * FROM glpi_tickets`.

For a pending rule it filters on `status`, `begin_waiting_date`, entity scope and — when
the rule names groups — an `EXISTS`-style subquery on `glpi_groups_tickets`. For an
approval rule it starts from `glpi_ticketvalidations` where `status = WAITING`, joined to
open tickets, which is the same shape core's own approval-reminder cron uses.

**The time prefilter.** Candidates are restricted to
`reference <= now - delay_in_seconds`. This is safe for every unit because a delay of N
business days always spans *at least* N calendar days, and N business hours at least N
clock hours — weekends, holidays and closing hours only ever push a deadline later. So the
prefilter is a necessary condition for expiry and cannot hide a ticket that would have
matched. It is not sufficient, which is why the matcher still computes the real deadline.

### TicketContextResolver

The only class that knows how GLPI stores actors, followups, pending state and approvals.
If this installation turns out to model something differently, the change lands here and
the engine is untouched.

Everything is loaded in bulk: resolving 200 tickets costs a fixed handful of queries, not
200 × N. Author classification is done by **id**, from the `CommonITILActor` tables — never
by name, label or free text.

### BusinessTimeCalculator

Delegates business units to `CalendarEngineInterface`. In production that is
`GlpiCalendarEngine`, which forwards to `Calendar::computeEndDate()` and does no arithmetic
of its own — so TicketFlow deadlines have the same semantics as SLA due dates, by
construction rather than by imitation.

`InMemoryCalendarEngine` is a faithful port of the same algorithm used by the unit suite,
and `tests/Integration/CalendarParityTest` asserts that the two agree. If core ever changes
its semantics, that test fails rather than the two silently drifting apart.

### ActionExecutor

Runs a rule's actions in `ranking` order, collects an `ActionResult` for each, and stops at
the first failure.

**There is deliberately no transaction around the batch.** The actions call core APIs that
fire notifications, write history entries and trigger other plugins' hooks. Rolling a
database transaction back would undo the rows but not those side effects, producing a state
*less* consistent than an honestly recorded partial run. So partial execution is never
hidden: the audit row shows one success, one failure, and the actions that never ran.

## 4. The cron flow

```
Cron::cronProcessRules
  └─ RuleEngine::runAll
       for each active rule (by ranking):
         while candidates remain and the ceiling is not reached:
           CandidateFinder::find(rule, now, batch, after_id)    ← indexed SQL + LIMIT
           TicketContextResolver::resolveBatch(candidates)      ← bulk load
           for each candidate:
             Matcher::evaluate(rule, context, now)
               ├─ conditions fail        → skipped
               ├─ deadline not reached   → preview row, nothing else
               └─ expired ─────────────────────────────────────┐
                                                               │
             dry run?  ── yes → simulate, log, preview row      │
                     └─ no ↓                                    │
             Execution::claim(...)                              │
               ├─ already claimed → already_processed           │
               └─ claimed ↓                                     │
             TicketContextResolver::resolveOne(...)   ← FRESH READ
             Matcher::evaluate(rule, fresh, now)
               └─ no longer expired → Execution::complete(skipped: state_changed)
             ActionExecutor::run(...)
             Execution::complete(executed | failed, results)
         Rule.last_execution_date = now
  └─ CronTask::addVolume(executed + failed + simulated)
```

Batching is bounded twice: `batch_size` per query, and `max_tickets_per_run` for the whole
run, so a backlog can never turn into one very long execution.

Pages are taken with a **cursor** (`id > after_id`), not `OFFSET`. Acting on a candidate can
remove it from the result set — solving a ticket drops it out of a pending query — and with
`OFFSET` the rows behind it shift up, so the next page would silently skip exactly the
tickets that most needed processing.

## 5. Data model

```
glpi_plugin_ticketclock_rules
    id, entities_id, is_recursive, name, comment, is_active, ranking,
    rule_type, target_status, pendingreasons_id,
    delay_value, delay_unit, calendar_mode, calendars_id,
    reset_events, is_dry_run,
    last_execution_date, date_creation, date_mod
        KEY active_type (is_active, rule_type)   ← the loop that runs every cron tick

glpi_plugin_ticketclock_rulegroups
    plugin_ticketclock_rules_id, groups_id
        UNIQUE (rules_id, groups_id)

glpi_plugin_ticketclock_ruleactions
    plugin_ticketclock_rules_id, action_type, ranking, params (JSON)
        KEY rule_ranking (rules_id, ranking)

glpi_plugin_ticketclock_executions
    plugin_ticketclock_rules_id, tickets_id, entities_id,
    occurrence_key, claim_key, state,
    reference_date, deadline_date, calendars_id, calendar_name,
    delay_value, delay_unit, used_elapsed_fallback, itilvalidations_id,
    triggered_at, completed_at, actions_result (JSON), error, date_creation
        UNIQUE claim_key                          ← idempotency + mutual exclusion
        KEY occurrence (rules_id, tickets_id, occurrence_key)
```

Why a separate `rulegroups` table rather than a column: in the reference production
database, **2675 tickets carry two assigned groups, 317 carry three and 12 carry four**.
A rule that could only name one group would be unusable there.

Why generic `ruleactions` rows rather than columns on the rule: new action types then need
no migration. The 0.1 form only exposes the useful combinations (a message, one terminal
action, a notification) and maps them onto those rows — the storage is open-ended, the UI
is not.

### Indexes

Every index exists because a query in the hot path uses it:

| Index | Used by |
|---|---|
| `rules.active_type` | the per-tick "which rules are live?" query |
| `rules.entities_id` | entity restriction on the rule list |
| `executions.claim_key` (UNIQUE) | the idempotency check and the claim itself |
| `executions.occurrence` | the per-rule history tab |
| `executions.date_creation` | the retention purge |
| `rulegroups.unicity` | the group subquery, and preventing duplicates |
| `ruleactions.rule_ranking` | loading a rule's actions in order |

Candidate selection relies on core's own indexes on `glpi_tickets`,
`glpi_groups_tickets` and `glpi_ticketvalidations`. TicketFlow adds none to core tables.

## 6. Idempotency and concurrency

Both are solved by the same row, which is also the log entry.

**Occurrence key** — identifies a *cycle*, not a `(rule, ticket)` pair:

| Rule type | Key |
|---|---|
| `pending_inactivity` | `pi:<tickets_id>:<begin_waiting_date>` |
| `pending_approval` | `pa:<ticketvalidations_id>:<submission_date>` |

`begin_waiting_date` is written by core precisely when a ticket enters `WAITING` and
cleared when it leaves, so a ticket that leaves pending and comes back gets a new key and
*can* fire again. That is the intended behaviour: idempotency is per occurrence, not
forever.

Note that the key uses the **cycle start**, not the (possibly reset) reference date. An
answer in the middle of a cycle moves the deadline but must not create a second
occurrence.

**Claim** — a worker inserts a row with a unique `claim_key`. A competing worker's insert
fails on the unique index and it moves on. No locks are held, nothing needs releasing on
the happy path, and the claim *is* the audit row.

`claim_key` is `NULL` for rows that must not block anything — dry runs, and executions that
ended as `skipped`. MySQL and MariaDB allow duplicate `NULL`s in a unique index, which
gives a partial unique constraint without vendor-specific syntax.

**A crashed run leaves a `processing` row that keeps blocking its occurrence.** This is the
deliberate failure mode: the alternative — auto-expiring the claim — risks repeating a
destructive action. Such rows are visible in the execution log and can be deleted to
release the occurrence.

## 7. Re-validation

Between the candidate query and the action there is a window — usually milliseconds,
occasionally minutes on a large batch — in which a requester can answer or an approver can
decide. Acting on stale state is the one mistake that cannot be taken back.

So after claiming and before acting, the engine re-reads the ticket from the database and
re-evaluates the rule. If the conditions no longer hold, or the occurrence key changed, the
execution is closed as `skipped: state_changed` and nothing is modified.

## 8. Not resetting our own clock

A followup TicketFlow writes must never be read back as "somebody answered".

Two independent mechanisms: generated content carries the marker
`<!-- ticketclock-generated -->`, which `TicketContextResolver` filters out of every
followup query; and the created followup's id is recorded on the execution row, so the
plugin's own writes are traceable after the fact.

There is a second, subtler trap. Core's `Glpi\Features\ParentStatus::updateParentStatus()`
**reopens a WAITING ticket when a followup is added**. Adding the warning message would
therefore clear `begin_waiting_date` and destroy the very clock being acted on. Generated
followups pass `_no_reopen` and `_do_not_compute_status` to prevent that.

## 9. Hooks vs. cron

Hooks cannot detect the passage of time, and the reference dates TicketFlow needs
(`begin_waiting_date`, `submission_date`) are already maintained by core. Registering broad
`item_update` hooks on every `CommonDBTM` would cost far more than it buys — so TicketFlow
does not.

The only hooks registered are `item_purge` on `Ticket`, `Group` and `Calendar` (so nothing
dangles) and `change_profile` (so the menu appears without a re-login).

## 10. Safety

Three independent switches, each solving a different problem:

| Switch | Scope | Purpose |
|---|---|---|
| `execution_enabled` | plugin | master off switch; ships **off** |
| `dry_run_global` | plugin | let rules run but keep them simulated; ships **on** |
| `Rule.is_dry_run` | one rule | watch a new rule for a few days before arming it |

Plus: the `ProcessRules` task ships **disabled**, rules are always **created inactive**,
and the rule form flags a rule as destructive as soon as it can solve, close or change a
status.

Installing TicketFlow cannot modify a single ticket. That takes four deliberate actions.

## 11. What TicketFlow deliberately does not do

* It does not re-implement calendars, SLA, pending reasons or notifications — it uses them.
* It does not write to core tables or add columns to them.
* It does not change ticket state with raw SQL; every mutation goes through
  `ITILFollowup`, `ITILSolution` or `Ticket`, so history, hooks and notifications keep
  working and other plugins keep seeing what they expect.
* It does not run its own scheduler.
* It does not carry a general-purpose condition language. The conditions are the ones a
  temporal rule actually needs; a generic engine would cost far more complexity than it
  would buy today (see `docs/adr/0008-scope-of-conditions.md`).
