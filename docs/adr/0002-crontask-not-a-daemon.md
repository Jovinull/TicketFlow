# ADR-002 — Scheduling is a GLPI Automatic Action

**Status:** accepted (0.1)

## Context

Expiry is a property of time passing. No hook fires when a deadline is crossed, so
something has to poll. The options were a GLPI `CronTask`, a plugin-owned daemon, or a
system cron entry calling a plugin script.

## Decision

Two GLPI automatic actions, registered by `CronTask::register()` on install:

| Task | Frequency | Ships as |
|---|---|---|
| `ProcessRules` | hourly | **disabled** |
| `PurgeLogs` | daily | scheduled |

GLPI dispatches a plugin task as `sprintf('%s::cron%s', $itemtype, $name)`
(`CronTask.php:882`) and discovers namespaced plugin itemtypes (`CronTask.php:426`), so
`GlpiPlugin\Ticketflow\Cron::cronProcessRules()` is found without any extra registration.

`Cron` is an entry point and nothing more: it calls the engine, reports the volume, writes
one summary line. It contains no business logic.

## Consequences

Administrators configure, monitor and trigger TicketFlow exactly the way they already do
for every other GLPI task — run mode, frequency, hour window, last run, exit code,
processed volume, error notification. Nothing new to learn, nothing new to supervise.

`ProcessRules` ships **disabled** so installing the plugin cannot start acting on tickets.

## Alternatives rejected

**A plugin daemon.** Would duplicate locking, run modes, logging and the management UI
that GLPI already provides, and would add a process for the customer's ops team to babysit.

**A raw system cron entry to a plugin script.** Invisible in the GLPI interface, no volume
reporting, no shared lock with the rest of GLPI's scheduling.
