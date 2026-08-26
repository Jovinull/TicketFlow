# TicketFlow — Technical discovery

> Working document produced **before** implementation, as required by the project brief (§70).
> Every statement below was verified against the target GLPI source tree and against the
> production database dump provided with this repository. File/line references point to
> the GLPI **11.0.4** source.

---

> **On the figures in this document.** They come from a restored copy of a real
> production database, used offline in a disposable container. Group names, user logins
> and ticket identifiers have been removed; the counts and distributions are unchanged,
> because those are what the design decisions rest on.

## 1. Environment

| Item | Value | How it was determined |
|---|---|---|
| GLPI version | **11.0.4** | `glpi_configs` row `('core','version','11.0.4')` in the production dump |
| Database | MariaDB 10.11.14 | dump header |
| DB charset | `utf8mb4_unicode_ci`, `ROW_FORMAT=DYNAMIC` | `CREATE TABLE` statements in the dump |
| PHP (dev host) | 8.4.23 | `php -v` |
| PHP (plugin floor) | **8.2** | GLPI 11 `composer.json` requires `php >= 8.2`; official `pluginsGLPI/empty` skeleton targets the same |
| Core tables in production | 454 | `grep -c '^CREATE TABLE' dump` |
| Other plugins **active** | `fields`, `gantt`, `news` | `glpi_plugins.state = 1`, read back from the restored database |
| Other plugins registered but not installed | `behaviors`, `escalade`, `moreticket`, `tasklists`, `treeview` | `glpi_plugins.state = 2` — notably `moreticket`, so pending state is native, not plugin-provided |

Target declared by TicketFlow: **GLPI >= 11.0.0, < 11.0.99**, PHP >= 8.2 — matching the
official `pluginsGLPI/empty` skeleton for GLPI 11.

## 2. Plugin architecture selected

GLPI 11 registers a **PSR-4 autoloader per plugin**:

```php
// glpi/src/Plugin.php::registerPluginAutoloader()
$psr4_dir = $plugin_directory . '/src/';
$psr4_autoloader->addPsr4(NS_PLUG . ucfirst($plugin_key) . '\\', $psr4_dir);
```

`NS_PLUG` is `GlpiPlugin\` (`src/autoload/constants.php:63`). With the plugin directory
named `ticketclock`, the namespace is therefore exactly **`GlpiPlugin\Ticketclock\`** mapped to
`plugins/ticketclock/src/`. No custom autoloader and no Composer autoload file is needed —
this is the same mechanism the official `pluginsGLPI/example` plugin relies on.

Twig templates are picked up automatically:

```php
// glpi/src/Glpi/Application/View/TemplateRenderer.php:82
$loader->addPath(Plugin::getPhpDir($plugin_key . '/templates'), $plugin_key);
```

so `plugins/ticketclock/templates/foo.html.twig` is addressable as `@ticketclock/foo.html.twig`.

Front controllers use the classic legacy router (`front/*.php` + `include('.../inc/includes.php')`),
which is still the supported and dominant pattern for plugins in GLPI 11 — the official
`example` plugin for 11.0 uses it throughout. GLPI 11 *Controllers* (`Glpi\Controller`) exist
but plugin route loading (`Glpi\Routing\PluginRoutesLoader`) is aimed at attribute-routed
controllers and is not used by the reference plugins; TicketFlow stays on the documented,
widely-tested path and renders its views with Twig.

## 3. Core classes TicketFlow will use

| Need | Class / API | Notes |
|---|---|---|
| Persisted objects | `CommonDBTM` | rules, rule groups, rule actions, executions |
| Schema install/upgrade | `Migration` (`glpi/src/Migration.php`) | `addField`, `addKey`, `migrationOneTable`, `executeMigration` |
| Scheduled processing | `CronTask::register()` / `cron<Name>()` | dispatch is `sprintf('%s::cron%s', itemtype, name)` (`CronTask.php:882`) |
| Plugin cron discovery | namespaced itemtypes are supported | `CronTask.php:426,434` matches `GlpiPlugin\<Plugin>\%` |
| Business time | `Calendar::computeEndDate()` | see §5 |
| Working-day test | `Calendar::isAWorkingDay()`, `Calendar::isHoliday()` | |
| Ticket read/update | `Ticket` (`CommonITILObject`) | statuses `INCOMING 1 / ASSIGNED 2 / PLANNED 3 / WAITING 4 / SOLVED 5 / CLOSED 6 / APPROVAL 10` (`CommonITILObject.php:116-124`) |
| Timeline message | `ITILFollowup` (`itemtype`/`items_id` polymorphic) | |
| Solving a ticket | `ITILSolution` | adding a solution is what moves the ticket to `SOLVED`; this is exactly what core's `PendingReasonCron` does |
| Pending state | `PendingReason`, `PendingReason_Item` | |
| Approvals | `TicketValidation` / `CommonITILValidation`, `ITIL_ValidationStep` | statuses `NONE 1 / WAITING 2 / ACCEPTED 3 / REFUSED 4` (`CommonITILValidation.php:62-65`) |
| Rights | `ProfileRight::addProfileRights()` / `deleteProfileRights()` | |
| Global config | `Config::setConfigurationValues('plugin:ticketclock', …)` | no bespoke config table needed |
| Views | `Glpi\Application\View\TemplateRenderer` + `templates/components/form/fields_macros.html.twig` | |
| Listing/search | `Search::show()` + `rawSearchOptions()` | gives filtering, sorting, CSV export, entity restriction for free |
| Notifications | `NotificationEvent::raiseEvent()` | optional action |

## 4. Core tables — read only

TicketFlow **never** writes to core tables directly and **never** adds columns to them.
It reads from:

`glpi_tickets`, `glpi_groups_tickets`, `glpi_tickets_users`, `glpi_itilfollowups`,
`glpi_itilsolutions`, `glpi_ticketvalidations`, `glpi_itils_validationsteps`,
`glpi_pendingreasons`, `glpi_pendingreasons_items`, `glpi_calendars`,
`glpi_calendarsegments`, `glpi_holidays`, `glpi_calendars_holidays`, `glpi_entities`,
`glpi_groups`.

All *writes* to core objects go through `ITILFollowup`, `ITILSolution` and `Ticket`
objects so that history, notifications, hooks and other plugins keep working.

## 5. Business-time strategy

### What core does

`Calendar::computeEndDate($start, $delay_seconds, $additional_delay, $work_in_days, $end_of_working_day)`
(`glpi/src/Calendar.php:412`). With `$work_in_days = true` the algorithm is:

1. If the start date is a holiday or a non-working weekday, move forward to the first
   working day and snap to its **first** working hour.
2. Then repeatedly add one calendar day; each time the new day is a working day,
   subtract `DAY_TIMESTAMP` from the remaining delay.
3. When the remainder goes negative, the leftover seconds are subtracted from the time.
4. Finally, if the resulting time is later than the **last** working hour of that weekday,
   it is clamped to that last working hour.

Consequences worth stating explicitly:

* The **start day is never counted**. `Mon 10:00 + 5 working days` on a Mon–Fri calendar is
  `next Mon 10:00`.
* Weekends and holidays are skipped by *not* decrementing, so they extend the deadline.
* Opening hours only clamp the final instant; they do not shorten the count.

This is exactly how core computes SLA/OLA due dates (`LevelAgreement::computeDate()`,
`LevelAgreement.php:723-750`) and how `PendingReason_Item::getNextFollowupDate()` /
`getAutoResolvedate()` compute pending auto-bump and auto-solve dates
(`PendingReason_Item.php:184-252`).

### What TicketFlow does

`BusinessTimeCalculator` is the single entry point. It delegates the `business_days`
and `business_hours` units to `Calendar::computeEndDate()` through a
`CalendarEngineInterface` seam, so the production behaviour is *core behaviour*, not a
re-implementation.

Calendar resolution order for a rule (`CalendarResolver`):

1. `calendar_mode = specific` → the rule's `calendars_id`.
2. `calendar_mode = entity` (default) → the calendar of the ticket's entity
   (`glpi_entities.calendars_id`, walking up the entity tree the same way GLPI does), then
   the plugin's configured fallback calendar.
3. Nothing resolvable, or the resolved calendar has no working day → **calendar-less mode**:
   the delay is applied as plain elapsed time (`+N × 24h`). This mirrors
   `LevelAgreement::computeDate()`'s own no-calendar branch, is logged on every execution
   row (`calendars_id = 0`) and is surfaced in the rule preview so it is never silent.

**Production reality check** (from the dump): a single calendar exists, `Default` (id 1),
entity 0, recursive, Monday–Friday `08:00–20:00`, `cache_duration = [0,43200,43200,43200,43200,43200,0]`,
and **no holidays are configured**. Holidays are supported and tested regardless.

> **Operational note, verified on a restored copy of this database.** The calendar exists,
> but no entity *points* at it: `Entity::getUsedConfig('calendars_id', 0)` returns `0`. A
> rule left in the default `entity` calendar mode would therefore fall back to plain
> elapsed time on this installation — correctly recorded, but not what anyone intends by
> "5 business days". Either set the calendar on the root entity, or set the rule to
> `Specific calendar → Default`. The **Diagnostics** screen shows the resolved calendar
> per entity precisely so this is visible before a rule is armed.

## 6. "Pending" strategy

### Reference date

`glpi_tickets.begin_waiting_date` is written by core precisely when a ticket's `status`
changes to `WAITING` (or to a solved status) and reset to `NULL` when it leaves that state
(`CommonITILObject.php:2742-2755`). It is therefore the correct, core-maintained
"the pending started at" timestamp — no plugin-side state duplication is needed.

Verified on production data: **74 tickets** are currently in `WAITING`, and **every one of
them has a non-null `begin_waiting_date`**.

Because the field is reset on every status transition into `WAITING`, it doubles as a natural
**occurrence boundary**: a ticket that leaves pending and comes back gets a new
`begin_waiting_date`, hence a new TicketFlow occurrence (see §9).

### Pending reason

GLPI 11 stores the pending reason **polymorphically**, not on the ticket:

```
glpi_pendingreasons_items(pendingreasons_id, itemtype, items_id,
                          followup_frequency, followups_before_resolution,
                          bump_count, last_bump_date, previous_status)
```

Rows exist both for `Ticket` (the current pending state) and for `ITILFollowup`
(the followup that triggered it).

**Production reality check**: `glpi_pendingreasons` is **empty** and all 191
`glpi_pendingreasons_items` rows carry `pendingreasons_id = 0`. This installation uses
"pending without a reason". TicketFlow therefore treats `pendingreasons_id = 0` on a rule as
*"any pending reason, including none"*, and only filters when a reason is explicitly chosen.
`TODO business mapping` — if this installation later adopts named pending reasons, no code
change is required, only rule configuration.

### Relationship to the native feature

Core already ships `PendingReasonCron` (`pendingreason_autobump_autosolve`) which bumps and
auto-solves pending tickets. TicketFlow does **not** replace it. The differences that justify
this plugin:

| | native `PendingReasonCron` | TicketFlow |
|---|---|---|
| Scope | every pending item with `followup_frequency > 0` | rules filtered by entity, assigned group, status, pending reason |
| Requires a `PendingReason` object | yes (and a followup + solution template) | no |
| Clock reset on a *specific kind* of interaction | no | yes (requester answer vs. technician answer vs. any) |
| Approvals | not covered | covered |
| Dry run / simulation | no | yes |
| Per-ticket audit trail | no | yes |
| Idempotency across cron runs | implicit via `bump_count` | explicit occurrence keys + unique constraint |

## 7. Approval strategy

GLPI 11 model:

```
glpi_validationsteps            (named step template, minimal_required_validation_percent)
glpi_itils_validationsteps      (itemtype, items_id, validationsteps_id, minimal_required_validation_percent)
glpi_ticketvalidations          (tickets_id, itils_validationsteps_id, itemtype_target, items_id_target,
                                 status, submission_date, validation_date, last_reminder_date, …)
```

* One ticket can have several **steps**; each step can have several **validation requests**.
* A request targets a `User` **or** a `Group` (`itemtype_target` / `items_id_target`).
  Production data contains both.
* `status` uses `CommonITILValidation::WAITING = 2`, `ACCEPTED = 3`, `REFUSED = 4`.
* A step is satisfied when the accepted share reaches
  `minimal_required_validation_percent` (`ITIL_ValidationStep::getStatus()`).
* `glpi_tickets.global_validation` aggregates the whole ticket.

**Production reality check**: 30 validation rows, 8 currently `WAITING`, 26 validation steps,
all with `minimal_required_validation_percent = 100`.

**V1 semantics (decided, documented, testable)**: the unit of the `pending_approval` rule is
**one individual validation request**. Reference date = that row's `submission_date`;
occurrence key = that row's id. A ticket with three approvers therefore yields up to three
independent clocks, and the rule fires for the ones that stay unanswered. This is the only
semantics that is unambiguous when approvers answer at different times, and it matches how
core's own `CommonITILValidationCron` (approval reminder, `CommonITILValidationCron.php:64`)
selects work: per `glpi_ticketvalidations` row with `status = WAITING`.

Core's approval reminder only *notifies*; it never acts on the ticket. TicketFlow complements
it — and deliberately reuses the same candidate query shape.

## 8. Group semantics

`glpi_groups_tickets(tickets_id, groups_id, type)` and `glpi_tickets_users(tickets_id, users_id, type)`
share the `CommonITILActor` constants (`CommonITILActor.php:47-49`):

```
REQUESTER = 1
ASSIGN    = 2     <-- note: ASSIGN is 2, OBSERVER is 3
OBSERVER  = 3
```

This ordering is a classic source of bugs (`OBSERVER` is *not* 2). TicketFlow always reads the
constants, never numeric literals.

Production actor counts confirm the reading: `glpi_groups_tickets` has 17 requester, **8803
assigned**, 8 observer rows; `glpi_tickets_users` has 3596 requester, 9215 assigned, 75
observer rows.

**Production reality check**: multi-group assignment is the norm, not an exception —
2454 tickets have 1 assigned group, **2675 have 2, 317 have 3, 12 have 4**.

**Decision**: a rule that targets group *G* matches a ticket when *G* is **one of** the
ticket's assigned groups. There is no "primary group" concept in the GLPI schema, so
requiring one would be inventing semantics. A rule may list several groups (OR); an empty
group list means "any group". Documented in `docs/rules.md` and in ADR-006.

No group is hardcoded. The production instance has no group named "Desenvolvimento" — the
closest are `the target group` (id 9) and `a second engineering group` (id 8) — which is precisely why the
brief's example rule is shipped as *documentation*, not as seeded data.

## 9. Idempotency and concurrency

Each candidate produces an **occurrence key** that identifies the current cycle:

| rule type | occurrence key |
|---|---|
| `pending_inactivity` | `pi:<tickets_id>:<begin_waiting_date>` |
| `pending_approval` | `pa:<ticketvalidations_id>:<submission_date>` |

`glpi_plugin_ticketclock_executions` carries
`UNIQUE KEY (plugin_ticketclock_rules_id, tickets_id, occurrence_key)`.

The engine **claims** an occurrence by inserting a row with `state = processing`. A duplicate
key error means another worker/cron already owns it, and the candidate is skipped. This gives
lock-free mutual exclusion with no held locks and no `SELECT … FOR UPDATE`.

Because the key contains the cycle timestamp, a ticket that leaves pending and returns gets a
brand-new key and *can* be processed again — idempotency is per occurrence, not per
`(rule, ticket)` forever.

## 10. Which interaction stops the clock

The engine never treats "the timeline moved" as activity, and never uses `date_mod`.
`TicketContextResolver` classifies events by **author identity**, resolved from
`glpi_tickets_users` / `glpi_groups_tickets` actor types:

* `requester_followup` — `glpi_itilfollowups.users_id` is a ticket requester
  (`CommonITILActor::REQUESTER`) or belongs to a requester group.
* `assignee_followup` — author is an assigned user/group member (`CommonITILActor::ASSIGN`).
* `any_followup` — any human followup.
* `solution_added` — an `ITILSolution` row.
* `validation_answered` — a validation row left `WAITING`.

TicketFlow's own generated followups are excluded from all of these: every generated followup
is prefixed with the marker `<!-- ticketclock-generated -->` **and** its id is recorded on the
execution row. This is what prevents the plugin from resetting its own clock (§18 of the brief).

## 11. Hooks

Deliberately minimal. The temporal expiry *cannot* be detected by hooks, only by the cron,
and `begin_waiting_date` / `submission_date` already give reliable reference dates maintained
by core. Registering broad `item_update` hooks on every `CommonDBTM` would cost far more than
it buys.

TicketFlow registers only:

* `Hooks::PRE_ITEM_PURGE` on `Ticket` / `Group` / `Calendar` — clean up rule references and
  execution rows so nothing dangles.
* `change_profile` — refresh the plugin right in the session.

## 12. Actions and the solve/close workflow

Adding an `ITILSolution` is what moves a ticket to `SOLVED` in GLPI — this is how
`PendingReasonCron` auto-solves. TicketFlow therefore implements:

* `add_followup` → `ITILFollowup::add()`
* `add_solution` → `ITILSolution::add()` (ticket becomes `SOLVED`; solution approval and
  automatic closing keep working normally)
* `change_status` → `Ticket::update(['status' => …])`
* `close_ticket` → `Ticket::update(['status' => Ticket::CLOSED])`, flagged in the UI as
  bypassing the normal solve→approve→close flow
* `send_notification` → `NotificationEvent::raiseEvent()`

Actions run in `ranking` order; each returns an `ActionResult`; a failure aborts the
remaining actions of that occurrence and the execution row is marked `failed` with the
partial results kept. Partial execution is never hidden.

## 13. Acting user

Destructive actions need an author. TicketFlow resolves it as:

1. plugin config `system_users_id`, else
2. core config `system_user` (the same one `PendingReasonCron` requires), else
3. no user → followups are still possible, but `add_solution` is refused with an explicit
   error, and the configuration screen shows a health warning.

## 14. Risks identified

| Risk | Mitigation |
|---|---|
| First install silently closing tickets | `execution_enabled = 0` and `dry_run_global = 1` at install; rules are created inactive; destructive rules are flagged in the UI |
| A user answers between match and action | mandatory re-validation immediately before acting (`skip: state_changed`) |
| Two crons processing the same ticket | unique occurrence key claimed with `state = processing` |
| Plugin's own followup resetting the clock | marker + recorded followup ids, excluded from reset events |
| Large installations | candidate SQL filters on indexed core columns, `LIMIT`-based batching, per-rule and global caps |
| Calendar edge cases | delegation to core `Calendar`, plus a faithful in-memory port covered by weekend/holiday unit tests |
| This installation's pending reasons are unmapped | `pendingreasons_id = 0` treated as "any"; isolated in `TicketContextResolver`, not spread through the engine |
| Log table growth | only `executed` / `failed` / `dry_run` rows are persisted by default; `skipped` only in verbose mode; retention purge task |

## 15. Open items — `TODO business mapping`

* Which pending reason (once this installation defines any) means "waiting for the requester".
* Whether the `the target group` / `a second engineering group` groups are the intended target of the
  "Desenvolvimento" rule from the brief.
* Whether closing should be `add_solution` (recommended, keeps the normal workflow) or
  `close_ticket` (hard close).

None of these block the implementation: all three are rule configuration, not code.
