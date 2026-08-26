# TicketFlow — Roadmap

A direction, not a promise. Reality gets a vote.

## 0.1 — shipped

The engine and the two situations that justified building it.

Rules with entity/group/status/pending-reason conditions · business-day and business-hour
deadlines through GLPI's calendars · pending-inactivity and pending-approval types ·
followup / solution / status / close / notification actions · occurrence-based idempotency
and concurrency · audit trail · dry run · manual run · diagnostics · automatic actions ·
unit and integration tests · pt-BR.

## 0.2 — warn before acting

The most frequent request a plugin like this gets: *tell me before you close it.*

- **Warning thresholds.** "Warn at 3 days, warn again at 4, close at 5." Expressible today
  with three coordinated rules; the goal is to express it in one, which means letting a
  rule carry several thresholds each with its own actions. Nothing in the current model
  prevents it — actions are already separate rows with a ranking, and occurrence keys can
  carry a threshold discriminator.
- **More reset events.** Document added, task added, actor changed.
- **More rule types**, each a matcher plus a candidate query: ticket without a technician,
  ticket without any update, SLA about to breach.
- **Message templates** reusable across rules, instead of one body per rule.
- **Exclusions**: a ticket, a category or a group a rule must never touch.

## 0.3 — escalate and observe

- **Escalation actions**: reassign to another group, raise the priority, add an actor.
- **Group hierarchy** as an explicit, opt-in rule option (see ADR-006 for why it is not
  implicit).
- **Approval steps** as a first-class rule type, on top of the per-request semantics of
  ADR-005.
- **Dashboard cards**: what fired this week, which rules are noisy, which never fire.
- **Statistics** on the execution log: average overdue time, distribution per group.

## Under consideration

- A `ruleconditions` table, if and only if a real requirement outgrows the fixed condition
  set (ADR-008 explains why it is not there yet).
- Event-driven pre-filtering via hooks to shrink the candidate set on very large
  installations — only if measurement shows the cron is actually the bottleneck.
- Applying the same engine to Changes and Problems: the resolver and the matchers are
  already written against a generic ticket context, but the reference dates and the actions
  would need review before promising it.

## Explicitly out of scope

- A second scheduler.
- A generic rule engine competing with GLPI's own `RuleCollection`.
- Anything that writes to core tables or modifies core files.
