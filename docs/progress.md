# TicketFlow — Working log

A record of what was found, what was decided, and why — kept during development so the
reasoning is auditable after the fact.

---

> **On the figures in this document.** They come from a restored copy of a real
> production database, used offline in a disposable container. Group names, user logins
> and ticket identifiers have been removed; the counts and distributions are unchanged,
> because those are what the design decisions rest on.

## 1. Discovery

No GLPI source was present in the repository; the only input was a 42 MB production dump.
So the environment was established from the dump itself rather than assumed.

* `glpi_configs` → `('core','version','11.0.4')`. **GLPI 11**, not 10 — which changes the
  plugin architecture, the approval model and the routing story.
* Dump header → MariaDB 10.11, `utf8mb4_unicode_ci`, `ROW_FORMAT=DYNAMIC`.
* 454 core tables; other plugins present (later confirmed against the restored
  database: `fields`, `gantt` and `news` active, five others registered but not installed).
* GLPI 11.0.4 source was fetched and read directly; every API claim in `discovery.md`
  carries a file and line reference.

### What the dump actually says

These numbers changed design decisions, so they are recorded rather than summarised:

| Fact | Value | Consequence |
|---|---|---|
| Tickets | 7146 | |
| Currently `WAITING` | 74 | the pending rule has real work to do |
| `WAITING` without `begin_waiting_date` | **0** | the reference date is reliable → ADR-003 |
| Tickets with 1 assigned group | 2454 | |
| Tickets with 2 / 3 / 4 assigned groups | **2675 / 317 / 12** | multi-assignment is the norm → ADR-006 |
| `glpi_pendingreasons` rows | **0** | this installation uses pending *without* a reason |
| `glpi_pendingreasons_items` rows | 191, all with `pendingreasons_id = 0` | `0` must mean "any" |
| Approval requests | 30, of which 8 waiting | targets are both `User` and `Group` → ADR-005 |
| Calendars | 1 (`Default`, Mon–Fri 08:00–20:00) | |
| Holidays | **0** | supported and tested anyway |
| Groups | 16, none named "Desenvolvimento" | closest are two engineering groups → nothing hardcoded |

A read-only **Diagnostics** screen was built so any installation can answer these same
questions about itself instead of inheriting ours.

### Findings that changed the implementation

**`Calendar::computeEndDate($start, $delay, 0, true)` is the canonical "N business days".**
Read line by line (`src/Calendar.php:412`) rather than picked by name — it is what
`LevelAgreement::computeDate()` and `PendingReason_Item::getAutoResolvedate()` both use.
Notably, **the start day is not counted**, and the result is clamped to the last working
hour.

**`begin_waiting_date` is written by core exactly on the transition into `WAITING`** and
cleared on the way out (`CommonITILObject.php:2742-2755`). That gives a correct reference
date *and* a free occurrence boundary, with no plugin-side state.

**`CommonITILActor::ASSIGN` is 2 and `OBSERVER` is 3** — not the other way round. A literal
`2` meaning "observer" would have silently produced a rule that matched nothing.

**Adding a followup reopens a pending ticket.**
`Glpi\Features\ParentStatus::updateParentStatus()` calls `needReopen()` and moves a
`WAITING` ticket back to `ASSIGNED`. Since that clears `begin_waiting_date`, posting the
warning message would have destroyed the very clock being acted on. Generated followups
pass `_no_reopen` and `_do_not_compute_status`. This was the single most valuable finding of
the whole investigation.

**Adding an `ITILSolution` is what solves a ticket** — core sets the status itself
(`ITILSolution::post_addItem()`), respecting the entity's `autoclose_delay`. So "solve"
is not a status write.

**GLPI 11 already ships `PendingReasonCron` and `CommonITILValidationCron`.** Both were read
before writing anything, to make sure TicketFlow complements them rather than reimplementing
them worse. The approval reminder only notifies; the pending cron has no notion of *who*
should answer, no group filter, no dry run and no audit trail.

**GLPI 11 validates *and consumes* the CSRF token in a kernel listener**
(`CheckCsrfListener`) for every non-AJAX POST. The explicit `Session::checkCSRF($_POST)`
calls that a GLPI 10 habit would suggest were removed: they would have failed on a token
already spent.

**Namespaced plugin crontasks are supported** (`CronTask.php:426`) and dispatched as
`sprintf('%s::cron%s', $itemtype, $name)`, so `GlpiPlugin\Ticketflow\Cron::cronProcessRules`
is found with no extra wiring.

---

## 2. Decisions

Recorded as ADRs, with the alternatives that were rejected:

| ADR | Decision |
|---|---|
| 001 | Business time comes from GLPI's `Calendar`, not from our own arithmetic |
| 002 | Scheduling is a GLPI Automatic Action, not a daemon |
| 003 | Reference dates come from core; activity is judged by author id |
| 004 | Actions are not wrapped in a database transaction |
| 005 | An approval rule works on one approval *request* |
| 006 | A rule matches when its group is *one of* the assigned groups |
| 007 | Idempotency and concurrency share one row and one nullable unique index |
| 008 | No general-purpose condition language in 0.1 |
| 009 | A second clock, measured from the conversation instead of the state |

The load-bearing one is **007**: `claim_key` is unique but nullable, so blocking rows
(processing / executed / failed) reserve an occurrence while dry runs and skips do not —
a partial unique index without vendor-specific syntax.

---

## 3. Built

Implemented in dependency order: schema → domain → calendar → matchers → actions →
audit → engine → cron → UI → tests → docs.

* **Domain (GLPI-free, unit tested):** six enums, `RuleDefinition`, `TicketContext`,
  `ValidationContext`, two matchers, `MessageRenderer`, `OccurrenceKey`,
  `BusinessTimeCalculator`, `CalendarDefinition`, `InMemoryCalendarEngine`.
* **Persistence:** `Rule`, `RuleGroup`, `RuleAction`, `Execution`, versioned `Install`.
* **GLPI-bound engine:** `CandidateFinder`, `TicketContextResolver`, five actions,
  `ActionExecutor`, `RuleEngine`, `GlpiCalendarEngine`, `Cron`.
* **UI:** rule list and five-block form, execution logs, dry run / manual run, plugin
  configuration, diagnostics, profile rights, menu.
* **Tooling:** dependency-free locale extraction (PHP + Twig) and `.mo` compilation.

---

## 4. Problems hit, and what was done

**Followups reopening pending tickets.** Found by reading `ParentStatus`, not by testing.
Fixed with `_no_reopen` / `_do_not_compute_status`, documented in the action's docblock and
covered by an integration test.

**`OFFSET` pagination silently skipping tickets.** The first implementation paged with
`START`/`LIMIT`. But solving a ticket removes it from the candidate set, the rows behind it
shift up, and the next page skips exactly the tickets that most needed processing. Replaced
with keyset pagination (`id > after_id`), which is anchored to a value that does not move.
Covered by `CandidateTest`.

**CSRF double-consumption.** See above: the explicit checks were removed after reading
`CheckCsrfListener`.

**A unique index cannot express "dry runs do not block".** Solved by making `claim_key`
nullable — MySQL and MariaDB allow duplicate `NULL`s in a unique index. An integration test
asserts the index really is `UNIQUE`, because the whole idempotency story rests on it.

**Testing calendars without a database.** `Calendar` needs a DB, and the weekend/holiday
behaviour is exactly what must be tested. Resolved with a faithful port used by the unit
suite plus a parity test asserting the port and core agree — so the duplication is guarded
rather than hoped about.

**`clone()` is not on `CommonDBTM`.** It comes from `Glpi\Features\Clonable`, which requires
`getCloneRelations()`. Added, so duplicating a rule brings its groups and actions along
instead of producing a copy that targets everything and does nothing.

---

## 5. Verified against a real GLPI

A disposable Docker stack was built to test properly rather than by inspection: MariaDB
10.11 (matching production) with the **production dump restored**, plus GLPI 11.0.4 from the
official release tarball and the plugin mounted into `plugins/ticketflow`. Nothing was run
against the live instance.

Restoring the dump also corrected two things this document had inferred from tables alone:
the plugins actually **active** are `fields`, `gantt` and `news`, and `moreticket` is
registered but *not installed* — so pending really is native. And the single calendar is not
attached to any entity, so `Entity's calendar` mode resolves to nothing on this
installation; the rules used `Specific calendar → Default` instead.

### Three problems that only running could find

**`getTabNameForItem()` declared static.** `CommonGLPI` declares it as an *instance* method
(only `displayTabContentForItem()` is static). PHP raised a compile error the moment GLPI
loaded the class, and plugin installation aborted. Lint could not see it; the official
`example` plugin gets it right, which is how the correct signature was confirmed.

**Every `Ticket::update()` refused in the test harness.** Traced to
`CommonITILObject::handleTemplateFields()`, which enforces ticket-template restrictions
unless `Session::isCron()` is true. Not a plugin bug — the engine's production context *is*
the cron — but it made the integration suite test a situation that never occurs. The
bootstrap now sets the cron marker, and the reason is written next to it.

It did surface a genuine finding, though: `Session::isCron()` requires a CLI context or
`/front/cron.php`, so the **manual "run for real" button can never be cron**. An operator
without the update right on tickets would see actions refused there that the scheduler
performs fine. The simulation screen now warns about exactly that.

**A wrong assertion of mine.** The "two approvals are independent" test created the second
request with today's date and then asserted both were candidates — but the time prefilter
correctly excluded the fresh one. The test was fixed, and a second test was added that
asserts precisely that behaviour instead of accidentally contradicting it.

### Results

Integration suite, against GLPI 11.0.4 with the production database restored:

```
Tests: 29, Assertions: 88  — OK
```

One run out of fifteen failed with a single assertion short, on the run started immediately
after an `rsync` into the mounted plugin directory. It did not reproduce in the fourteen
runs since. The likeliest explanation is environmental rather than a defect: OPcache is
enabled in the test image and revalidates timestamps on a two-second window, so a file
replaced moments before the run can be served stale. Recorded here rather than quietly
ignored — if it recurs without an rsync alongside it, it needs a real investigation.

* **Calendar parity 7/7**: the in-memory port and core's `Calendar` return identical dates
  for every scenario, including business hours spanning a weekend and a mid-week holiday.
* Installation: tables, naming convention, `claim_key` really `UNIQUE`, both automatic
  actions registered under the names GLPI dispatches, processing task disabled, fresh
  install inert.
* Pending flow: expired ticket gets a message and a solution **exactly once**; a second run
  changes nothing; a requester answer keeps the ticket open; a dry run changes nothing and
  does not consume the occurrence; a generated followup does not count as an answer; a rule
  for another group does nothing.
* Approval flow: fires once per request; answered requests left alone; fresh requests left
  alone; two overdue requests on one ticket produce two independent occurrences.

### Dry run over the real production data

The two rules from the brief were created and simulated against the restored database:

| Rule | Analysed | Match | Overdue | Would act |
|---|---|---|---|---|
| Desenvolvimento (a second engineering group + the target group), 5 business days | 6 | 6 | 6 | 6 |
| Approval unanswered, 2 business days | 1 | 1 | 1 | 1 |
| *(diagnostic)* any group, 5 business days | 74 | 74 | 74 | 74 |

After all three simulations: **0 followups, 0 solutions, 74 tickets still pending**, 81
execution rows logged and **0 of them blocking**. The dry run does exactly what it claims.

Two details worth recording, both visible in the output:

* Only 1 of the 8 waiting approvals was picked up — the other 7 are attached to **closed**
  tickets and are correctly excluded by `Ticket::getOpenCriteria()`, the same guard core's
  own approval reminder uses.
* Deadlines landing after closing time were clamped to 20:00, the last working hour of the
  `Default` calendar.

A real execution was then run in the container, on the six Development tickets: all six were
solved, six marked followups appeared in their timelines authored by the system user, six
solutions were recorded, and a second pass reported `analyzed 0`.

The audit rows show the reset logic working on real data: two of the tickets carry an
`occurrence_key` anchored to `begin_waiting_date` but a noticeably later `reference_date` —
a requester answer moved the deadline forward without creating a second occurrence.

## 6. Verified (static)

* **78 unit tests, 141 assertions**, no database required — deadlines (weekends, holidays,
  exact boundary, opening-hour clamping, fallbacks, business hours across a weekend), both
  matchers, placeholders, action ordering and failure containment, occurrence keys,
  pagination cursors.
* Every PHP file lints clean.
* Every GLPI class, method, function and constant referenced was checked against the
  11.0.4 source.
* The generated `pt_BR.mo` was decoded and verified against a standard MO reader.
* 202 translatable strings extracted; **202 translated** to pt-BR.

---

## 7. Revision after the first review (0.2.0)

The state clock turned out to answer the wrong question. What was actually wanted:

> the ticket is assigned to the target group, **we sent the last message**, and nobody
> came back — count 5 business days for pending, 2 for approval, and reset on a status
> change.

That is a clock on the *conversation*, not on the state. Added as a second `start_event`
rather than replacing the first (see [ADR-009](adr/0009-last-group-message-clock.md)),
because both readings are legitimate and existing rules must keep working.

Measured on the pristine production data before writing any code, which is what made the
change worth the effort:

| | |
|---|---|
| Open tickets assigned to the target group | 12 |
| ...with status Pendente | 5 |
| ...**whose last message came from a member of the target group** | **2** |
| ...with status Approval | 1 (last message from outside) |
| ...with a waiting validation | 1 (last message from outside) |

The gate cuts 5 candidates to 2, and names the other three: on a ticket and a ticket the last word
was a member of a different group, on a ticket it was a user outside the group (a second engineering group). None of those is Software
Engineering's to chase.

The status reset is visible in the same output: a ticket's last message is `2026-08-10 21:23`
but its reference is `2026-08-11 11:55`, because the status changed after the message.

### The configuration UI had never been rendered

Every rule so far had been created from a script, which meant the whole interface layer was
unexercised. Driving it over HTTP as a browser would — log in, open the list, open the form,
POST a new rule, reopen it, change every field, save, simulate — found that **the rule form
threw before rendering a single field**.

The cause is an asymmetry in GLPI's own API. With `multiple` set, `Dropdown::show()` (the
itemtype dropdown, used for groups) requires an **array** in `value` and feeds it to
`array_diff()`; `Dropdown::showFromArray()` (the array dropdown, used for reset events)
requires a **scalar** there, with the selection in `values`, and feeds `value` to
`strlen()`. Both were wrong in the template, and each throws a different exception.

Nothing else in the project renders Twig, so the entire configuration screen was broken
while 91 unit tests and 35 integration tests stayed green. `tests/Integration/RuleFormTest`
now renders the form and asserts that all 27 inputs post back — on field *names* rather than
markup, because a field that stops being submitted silently drops that setting on the next
save.

The round trip was then verified end to end over HTTP: a rule created through the form,
reopened, edited across every block (target status, start event, delay, a second target
group, reset events, terminal action swapped from *solve* to *change status*, simulation
flag) and saved — with the database reflecting each change.

### Two more bugs, both found by running

**Upgrades aborted halfway.** `Profile::installRights()` called
`ProfileRight::addProfileRights()` unconditionally. Core inserts without checking, so the
second run throws on the unicity index — and since `install()` runs on every upgrade, the
schema migration landed but the plugin was left stuck in "to update". Only a real upgrade
finds this; a fresh install never will.

**A false negative in the candidate query.** The new mode measures from the conversation,
but `CandidateFinder` still prefiltered on `begin_waiting_date` — so a ticket whose pending
state was newer than its last message was dropped before the matcher ever saw it, silently.
The prefilter now branches on the mode: "there is a visible message older than the threshold
and no newer one", as two `EXISTS` subqueries so both halves use the `(itemtype, items_id)`
index. This is the worst kind of defect — no error, no log line, just tickets quietly never
processed — and no amount of reading would have found it.

### Results

* Unit: **91 tests, 169 assertions**.
* Integration against GLPI 11.0.4 with the production database restored: **35 tests, 107
  assertions**.
* A full **uninstall → fresh install** cycle was exercised for the first time: uninstall
  leaves zero tables, zero automatic actions, zero rights, zero config rows and zero display
  preferences, and the fresh install produces the intended `varchar(50)` column rather than
  the widened one an `addField('string')` would have created.

---

## 7b. Publication pass

Everything the GLPI catalog and the Marketplace ask for, short of the parts that need a
public repository. Audit and sources: [publishing.md](publishing.md).

**Two bugs surfaced, both only visible outside the environment they were built in.**

*The plugin could not be installed on a GLPI that had never activated it.* `setup.php` read
`Version::VERSION` at include time, relying on the plugin's PSR-4 autoloader — which does
not exist yet at that point. `Plugin::load()` includes `setup.php` **before** calling
`registerPluginAutoloader()`, and plugin discovery includes it with no autoloader at all.
Every earlier install passed because the plugin was already activated in those databases,
so `bootPlugins()` had registered the autoloader earlier in the request. The first page load
of a fresh instance died outright. `setup.php` now requires `src/Version.php` by hand.

The lesson generalises: **`setup.php` runs before the plugin exists as far as PHP is
concerned.** Nothing it touches at include time may depend on the autoloader.

*Every ticket status in the rule form pointed at the wrong one.* The form prepended its
"any status" entry with Twig's `merge`, which is `array_merge()` and renumbers integer keys.
`Ticket::getAllStatusArray()` is keyed by status id, and those ids are neither sequential
nor sorted — `10` (Approval) sits between `1` and `2`. So the list shifted by one: a rule
stored as Pending (`4`) displayed as "Processing (planned)", and re-saving the form wrote
the wrong condition. Caught by looking at a screenshot, not by a test — 130 tests were green
and the simulation screen, which reads the stored value, described the rule correctly. Both
dropdowns are now built in PHP with keys preserved, and a test asserts each option carries
its own id.

The lesson there: **a green suite says the values round-trip, not that the right one is on
screen.** The assertion that would have caught it is the one nobody writes — that option
`4` is labelled `Pending`.

*Saving a rule cleared its reset events.* Found by the only test that mattered here:
open each rule in a browser, press Save without touching anything, and diff the database.
The field was named `_reset_events[]`, and `Dropdown::showFromArray()` appends `[]` itself
when `multiple` is set — so the browser posted `_reset_events[][]`, one level deeper than
`prepareInput()` reads. The `_defined` marker did the rest: the code concluded the user had
emptied the list and stored nothing. Nothing looked wrong on screen, because the chips are
rendered from the `values` option rather than from the select's own state.

That one is worth remembering as a shape, not as a fact: **the round trip is the test.**
Rendering assertions proved the field was on the page and the status options were right;
neither could see that pressing Save destroyed a setting.

**Proven end to end after the fixes**, on a clean demo instance:

| | |
|---|---|
| Real execution through `front/cron.php` | 6 tickets: followup + solution added, status 4 → 5, history rows written, author `glpi-system` |
| Second pass | nothing added — idempotent |
| Placeholders | `{{delay}}`, `{{delay_unit}}`, `{{deadline}}`, `{{ticket.id}}`, `{{reference}}` all resolved |
| Approval rule | 3 matched; answering one dropped it to 2; execution added followups and left the status alone |
| Form round trip | both rules saved unchanged, every field |
| Uninstall | no table, config row, cron task, right or display preference left behind |

### Closing the four gaps

The previous pass listed four things that had never been exercised. All four now have been.

**Business days against a real calendar.** A calendar with Monday–Friday segments and a
holiday on Wednesday 2026-08-05. Five business days from Monday the 3rd lands on Monday the
10th; adding the holiday moves it to Tuesday the 11th — exactly one working day, which is
the only assertion worth making, since the absolute date would only restate what
`Calendar::computeEndDate()` does. Then the same thing through a rule in "entity calendar"
mode, so the wiring is proven and not just the arithmetic.

*That turned up a defect.* `Entity::getUsedConfig('calendars_id', …)` is deprecated in GLPI
11: core rewrites it to the `calendars_strategy` reference and `trigger_error()`s on the way
through. It answers correctly, so nothing failed — it just wrote a notice on every entity
lookup, once per entity per cron pass. Fixed, and pinned by a test that captures notices
while a rule runs.

**Multi-entity.** A three-level tree. A ticket in a child entity uses the calendar inherited
from its parent. An entity with nothing above it falls back and *says so* — silently
treating calendar days as business days would be the worst outcome available, because the
numbers still look plausible. A rule confined to one branch does not reach sideways into a
sibling, and a non-recursive rule does not reach down into its own children.

**Notifications.** The one action that leaves no trace on the ticket when it fails: a rule
that solves a ticket leaves a solution behind, but a notification that never left produces
nothing at all. So the assertion is on GLPI's own outgoing queue. With mailing enabled and
the shipped "Update Ticket" notification armed, a real row lands there, addressed to the
requester's address with a subject; a dry run queues nothing. A fresh GLPI ships
notifications off, which is exactly why this had never happened by accident.

**The other half of the CI matrix.** GLPI's plugin CI runs PHP 8.2 / MariaDB 10.6 and
PHP 8.5 / MariaDB 12.3. A second stack was built for the upper half — PHP 8.5.9,
MariaDB 12.3.3 — and given the whole treatment: fresh install, plugin install and activate,
141 tests, real execution through `front/cron.php` (6 tickets solved, second pass idempotent),
uninstall leaving nothing behind, reinstall. One build note worth keeping: `opcache` must be
dropped from `docker-php-ext-install` on 8.5, where it is compiled in and asking for it
fails the build.

**Volume.** 30,050 pending tickets, loaded straight into the table, run on the weakest
stack in the matrix (PHP 8.2 / MariaDB 10.6):

| | |
|---|---|
| Throughput | ~1,800 candidates/second |
| Per-ticket cost, 1k vs 30k | 0.65 ms vs 0.56 ms — **0.87x**, so linear at worst |
| Memory across a 30x longer run | flat |
| `max_tickets_per_run` | honoured at every level tested |
| `batch_size` | changes the number of queries, never the population |

The per-ticket figure is the one that matters: keyset pagination is what keeps it flat, and
`OFFSET` is what would have made it climb. It is measured, not assumed.

*That measurement found two more defects.* The first: memory grew about 34 MB over 30k
candidates because every examined ticket built a preview row and kept it — for a reader that
does not exist on the scheduled path. Collection is now opt-in and bounded, which also made
the run roughly 50% faster. The second: `max_tickets_per_run` and `batch_size` could be
stored as zero, and zero does not mean "no limit" — it means the engine examines nothing and
reports a clean, empty run. The form's `min="1"` is a browser hint and nothing more, so both
are clamped on write and on read now.

**Both remaining halves of the matrix.** PHP 8.2 / MariaDB 10.6 — the floor the manifest
promises — got the same treatment as 8.5 did: fresh install, plugin install and activate,
the whole suite, real execution through `front/cron.php` with idempotency on the second
pass, and an uninstall leaving nothing behind. All three PHP versions run the same 146
tests green.

**The upgrade path.** Bumping the version to 1.0.0 immediately made GLPI refuse to load the
plugin on all three stacks — "not active in the test database" — which is the core version
detector doing its job. `plugin:install` then upgraded each in place: the plugin version
moved to 1.0.0, the schema stayed at 1.1.0 (nothing structural changed), and all 146 tests
were green afterwards on every stack. Worth stating plainly because it is the path every
existing user will take, and it had never been exercised.

**The artefact itself.** `glpi-ticketflow-1.0.0.tar.bz2`, built and installed on a fourth
throwaway instance: virgin GLPI, the archive unpacked into `plugins/`, nothing from the
working tree. It installs, activates, and does nothing — execution off, global dry run on,
the processing task disabled, no rules, and a forced cron pass that writes zero rows. That
is the safety promise from the original brief, checked against what people download.

---

**Screenshots.** Six, on a demo instance built for the purpose: fresh database, invented
group, invented users, six invented tickets, driven through headless Chromium over the
DevTools protocol. Not on the restored copy of the reference database — those ticket titles
are real customer records and a catalog listing is public. `tools/seed-demo.php` and
`tools/seed-screenshots.py` reproduce both halves.

**Languages.** English, French and Brazilian Portuguese, complete, verified loading on a
running instance. The fallback chain is worth knowing: user language → *instance default* →
`en_GB` → msgid. A Spanish user on a Brazilian instance sees Portuguese, not English.
Details in [i18n.md](i18n.md).

**Quality gates.** PHPStan raised 6 → 8 and clean; Rector wired and clean; Psalm, PHP-CS-Fixer,
TwigCS, parallel-lint and the licence check all clean; 131 tests / 354 assertions; uninstall
verified to leave no table, config row, cron task, right or display preference behind.

---

## 8. Open items

`TODO business mapping` — three questions that are configuration, not code, and that need
the operator rather than the engineer:

1. Which pending reason means "waiting for the requester", once this installation defines
   any. Today `0` = any, which matches the data.
2. Whether the engineering groups are the intended target of the
   "Desenvolvimento" rule from the brief. No group name is hardcoded anywhere.
3. Whether closing should be `add_solution` (recommended — keeps the normal workflow) or
   `close_ticket` (hard close).
4. What "approval" means here: the ticket **status** `Approval` (10), or a **waiting
   validation request**. Both are expressible today and both currently match zero tickets,
   so the data cannot decide it — only the process can.

---

## 9. Next

1. Decide the calendar question: either attach `Default` to the root entity, or keep the
   rules on `Specific calendar`. Until then, `Entity's calendar` mode silently means
   calendar days on this installation.
2. Install on staging, create the two rules, leave them in *simulation only* for a week and
   compare the dry-run list against what a human would actually have closed. The diagnostic
   rule above says the honest number: **74 pending tickets are already past five business
   days**, most of them in the front-line groups, some by more than eighty days. Arming a
   rule for all groups on day one would close all of them at once.
3. Then arm one rule, for one group, and read the execution log.

Roadmap beyond that: `docs/roadmap.md`.
