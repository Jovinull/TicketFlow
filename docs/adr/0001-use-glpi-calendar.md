# ADR-001 — Business time comes from GLPI's `Calendar`, not from our own arithmetic

**Status:** accepted (0.1)

## Context

"5 business days" has to become a timestamp. The obvious shortcuts are all wrong:
`now - 5 * 86400` ignores weekends; "Monday to Friday" ignores holidays and non-standard
schedules; a hand-rolled calculator ignores whatever the customer configured in GLPI.

GLPI already answers this question for SLA and OLA due dates
(`LevelAgreement::computeDate()`) and for pending auto-solve
(`PendingReason_Item::getAutoResolvedate()`). Both call
`Calendar::computeEndDate($start, $delay, 0, $work_in_days)`.

There is also history here: time calculations in GLPI calendars have had edge cases, so
picking a method by the sound of its name would be a mistake. `computeEndDate()` was read
line by line (`src/Calendar.php:412`) before being adopted.

## Decision

Production deadlines are produced by `Calendar::computeEndDate()`, through a thin wrapper
(`GlpiCalendarEngine`) that performs no arithmetic of its own.

The semantics we inherit, and document, are core's:

* the start day is not counted;
* non-working days and holidays are skipped rather than consumed;
* a clock starting outside opening hours starts at the next opening;
* the result is clamped to the last working hour of the day it lands on.

When no usable calendar can be resolved, the delay is applied as plain elapsed time —
matching `LevelAgreement::computeDate()`'s own no-calendar branch — and the fallback is
recorded on every execution row and flagged in the dry run.

## Consequences

TicketFlow deadlines and SLA deadlines agree by construction. A customer who fixes their
calendar fixes TicketFlow at the same time. Nothing to maintain when core evolves.

The cost is that `Calendar` needs a database, so it cannot be unit-tested. That is why
`InMemoryCalendarEngine` exists: a faithful port of the same algorithm, used by the unit
suite so weekend and holiday behaviour is actually covered. The duplication is deliberate
and guarded — `tests/Integration/CalendarParityTest` asserts the port and core agree, and
fails if they ever diverge.

## Alternatives rejected

**Our own calculator.** Would drift from SLA, ignore customer configuration, and require
re-deriving holiday and opening-hour semantics that core already has.

**`getActiveTimeBetween()` instead.** It measures elapsed active time between two known
dates; we need the inverse — a future date from a delay. Using it would mean re-deriving
the deadline by search.
