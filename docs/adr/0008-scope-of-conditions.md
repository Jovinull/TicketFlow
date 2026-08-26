# ADR-008 — No general-purpose condition language in 0.1

**Status:** accepted (0.1)

## Context

It is tempting to build a generic engine — arbitrary `field operator value` conditions
combined with AND/OR — so that any future requirement (`priority = high`,
`category = systems`, `requester entity = X`) is configuration rather than code.

GLPI itself already has such an engine: `Rule` / `RuleCriteria` / `RuleAction`, used by
business rules, dictionaries and SLA levels.

## Decision

TicketFlow 0.1 ships a fixed, typed set of conditions: rule type, entity scope, assigned
groups, ticket status, pending reason. Plus the clock, which is where the actual novelty
is.

The *storage* is left open where it is cheap to do so — `rule_type` is a string, actions
are `action_type` + JSON `params` — so new types and actions need no migration.

## Rationale

A generic condition engine solves a problem TicketFlow does not have yet, and would cost
the two things that matter most here: **a readable rule form**, and **matchers that are
pure functions and therefore genuinely testable**.

The value of this plugin is not "conditions". Core already does conditions well. The value
is *time*: business-day deadlines, a reference event that means something, a clock that
only restarts on the interaction you are waiting for, idempotent execution, and an audit
trail. A generic criteria system would dilute that with a second-rate copy of something
GLPI already ships.

The extension points that actually matter are already there: a new rule type is a matcher
plus a candidate query; a new action is one class. Both are described in
`docs/development.md`, and neither requires touching the engine.

## Consequences

Conditions like `priority = high` are not expressible in 0.1. When they are needed, the
path is clear: either add typed columns for the handful that prove useful, or add a
`ruleconditions` table — the matcher interface does not change either way, because it
already receives a `TicketContext` and returns a `MatchResult`.

Warning thresholds ("warn at 3 days, warn again at 4, close at 5") are also not in 0.1, and
were deliberately not designed out: several coordinated rules already express it today
(different delays, different actions, same conditions), and a multi-threshold rule can be
added later since actions are separate rows with a ranking.

## Alternatives rejected

**Reuse GLPI's `RuleCollection`.** Built around evaluating rules on an *input array* at a
specific moment in an object's lifecycle. TicketFlow's question is "which existing tickets
crossed a deadline since the last run?", which that machinery cannot ask.

**A generic criteria table now.** Complexity paid up front against a requirement nobody has
stated, in exchange for a worse form and untestable matchers.
