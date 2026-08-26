# Changelog

All notable changes to TicketFlow are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] — 2026-08-26

First public release. The two entries below it are the development history that led here.

Verified on GLPI 11.0.4 against **PHP 8.2 / MariaDB 10.6** and **PHP 8.5 / MariaDB 12.3** —
both ends of the official plugin CI matrix — plus PHP 8.3 in between: full suite, an
in-place upgrade, a real execution through `front/cron.php` with an idempotent second pass,
30,050 pending tickets at ~1,800 candidates/second with flat memory, and an uninstall that
leaves no table, config row, cron task, right or display preference behind. The release
archive itself was installed on a virgin instance and confirmed inert on arrival.

### Added

- **Everything needed to publish on the GLPI plugin catalog**: `ticketflow.xml` manifest
  (en/fr/pt-BR), a `ticketflow.png` logo, and [docs/publishing.md](docs/publishing.md) —
  the requirements, their sources, and an audit of this plugin against each one.
- The official CI is wired up: `.github/workflows/continuous-integration.yml` calls
  `glpi-project/plugin-ci-workflows@v1` across the GLPI 11.0.x matrix, and `release.yml`
  calls the official release workflow.
- Quality configuration matching the official skeleton: `.php-cs-fixer.php`, `phpstan.neon`,
  `psalm.xml`, `.twig_cs.dist.php`, `tools/HEADER` and `.glpi-coverage.json`. All six tools
  run clean against a real GLPI 11.0.4 checkout.
- `GlpiPlugin\Ticketflow\Version` — one source of truth for the version numbers, which
  setup.php still exposes under GLPI's `PLUGIN_*` constant convention.
- `tools/apply-headers.php` keeps the licence header on every file (`--check` in CI-style
  verification).
- **French and English catalogues**, both complete, alongside Brazilian Portuguese — 211
  strings each, verified loading through `Plugin::loadLang()` on a running GLPI.
- `tools/init-locale.php` creates or refreshes a catalogue from the `.pot` without losing
  existing translations; `--identity` produces the English one.
- [docs/i18n.md](docs/i18n.md): how GLPI resolves a plugin's language, the rules the code
  follows, and how to add a language. Includes the fallback nobody expects — a user whose
  language has no catalogue gets the *instance* default, not English.
- `rector.php`, standing on its own rather than on GLPI's `PluginsRector.php`, which 11.0.4
  does not ship.

### Changed

- `phpunit.xml` now runs the whole suite on GLPI's bootstrap, which is what the official CI
  executes; the database-free suite moved to `phpunit-unit.xml` (`composer test`).
- Unsafe standard functions replaced with `thecodingmachine/safe` variants, except at six
  sites where the failure value is handled deliberately — each keeps the native call, a
  stated reason and a targeted PHPStan ignore.
- Messages passed to `Session::addMessageAfterRedirect()` are HTML-escaped, matching core
  and clearing Psalm's taint analysis.
- PHPStan raised from level 6 to **level 8**, clean. Level 9 is not adopted: its
  explicit-`mixed` rules flag every value read out of `$DB->request()`, which buys no safety
  the existing casts do not already provide.
- Rector applied across the codebase: `readonly` value objects, return types, dead code.
  Three rules are skipped with their reasons recorded in `rector.php` — one of them because
  Rector's dead-code analysis does not follow by-reference closure captures and proposed
  deleting the entire `.mo` catalogue writer.
- Documentation and code comments are English throughout; the reference figures drawn from a
  production database are anonymised — the counts stay, the names and identifiers do not.
- `tests/Integration/EntityCalendarTest.php`: "entity calendar" mode across a real entity
  tree — inheritance from a parent, the fallback calendar, the honest report when there is
  no calendar at all, entity scoping in both directions (a rule must not reach sideways into
  a sibling, and a non-recursive rule must not reach down into its own children), and a
  guard against the deprecated entity-config call coming back.
- `tests/Integration/NotificationFlowTest.php`: the "raise a notification" action, asserted
  on GLPI's own outgoing queue. This is the one action that leaves no trace on the ticket
  when it fails, so nothing else would have caught it. Notifications are switched on for the
  test and put back afterwards; a fresh GLPI ships with them off, which is why this had
  never been exercised by accident.
- Six screenshots in `docs/screenshots/`, referenced from `ticketflow.xml` and the README.
  They come from a demo instance seeded on purpose, never from a copy of a real one: these
  screens show ticket titles, group names and user names, and a catalog listing is public.
  `tools/seed-demo.php` builds that instance and `tools/seed-screenshots.py` captures it
  through headless Chromium, so the set can be refreshed the same way.
- `tools/seed-demo.php` is re-runnable: it reuses a record that already carries the name
  instead of failing halfway through and leaving a half-seeded instance behind.

### Fixed

- **The official release workflow could not read the supported GLPI range from
  `setup.php`.** It greps for `PLUGIN_<KEY>_MIN_GLPI` / `_MAX_GLPI` with a *quoted* value,
  to put "Compatible GLPI: x -- y" on the GitHub release page. This plugin used
  `PLUGIN_TICKETFLOW_MIN_GLPI_VERSION` — a name the pattern does not match — holding a
  class constant rather than a literal, so the grep found nothing and the release quietly
  lost that line. The constants now use the names `pluginsGLPI/example` uses, spelled out
  as literals; `Version` stays the source of truth for code, and a test asserts the two
  never drift.
- **Memory grew with the length of a run, for a reader that did not exist.** Every examined
  ticket built a preview row and kept it, whether or not anybody would read it — a
  scheduled pass never does. Measured at 30k candidates: about 34 MB of preview held for
  the whole run. Collection is now opt-in and bounded; the simulation screen, the only
  reader, asks for it and says how many matches it is not listing. Memory across a 30x
  longer run is now flat, and the run is about 50% faster for not building rows nobody
  wanted.
- **A zero batch size or run ceiling could be stored, and silently stopped the engine.**
  The form declares `min="1"`, but that is a hint to the browser: a hand-made POST, an
  import or a call from code got past it, and zero does not mean "no limit" here — it means
  the engine examines nothing and reports a successful, empty run. Both settings are now
  clamped where they are written and again where they are read, so a row written before
  this guard existed cannot brick a run either.
- **The entity calendar was read through a deprecated core API.**
  `Entity::getUsedConfig('calendars_id', …)` still answers correctly in GLPI 11, but core
  rewrites it internally and `trigger_error()`s a deprecation notice every time. That call
  runs once per entity per cron pass, so it meant a log full of notices — and worse on an
  instance configured to escalate them. It now asks for `calendars_strategy` with
  `calendars_id` as the value field, which is the form core expects.
- **Saving a rule silently cleared its "Restart the clock on" setting.** The reset-events
  field was declared as `_reset_events[]`, but `Dropdown::showFromArray()` appends `[]`
  itself when `multiple` is set, so the browser posted `_reset_events[][]`. PHP parsed that
  one array level deeper than `prepareInput()` reads it, the selection never arrived, and
  the `_reset_events_defined` marker — which exists precisely so an emptied list can be
  told apart from an absent field — made the code store an empty list in good faith. The
  deadline then stopped resetting on a requester answer, which is the whole point of the
  setting. The field posts under the correct name now, and a test asserts that no field on
  the form posts under a doubly-bracketed name.
- **Every ticket status in the rule form pointed at the wrong status.** The form prepended
  an "any status" entry with Twig's `merge` filter, which is `array_merge()` and therefore
  renumbers integer keys. `Ticket::getAllStatusArray()` is keyed by status id and those ids
  are neither sequential nor sorted — `10` (Approval) sits between `1` and `2` — so the
  whole list shifted: a rule saved as Pending (`4`) came back on screen as
  "Processing (planned)", Approval was offered as `2`, and Closed as `7`, which is not a
  status at all. Opening a rule and saving it silently rewrote its condition. Both status
  dropdowns are now built in PHP with the keys preserved, and a test asserts every option
  carries its own id.
- **The plugin could not be installed on an instance that had never activated it.**
  `setup.php` read `Version::VERSION` at include time and relied on the plugin's PSR-4
  autoloader, which does not exist yet at that point: GLPI's `Plugin::load()` includes
  `setup.php` *before* calling `registerPluginAutoloader()`, and plugin discovery
  (`Plugin::getInformationsFromDirectory()`) includes it without registering any autoloader
  at all. Every earlier test passed only because the plugin was already activated in those
  databases, so `bootPlugins()` had registered the autoloader earlier in the request. On a
  fresh GLPI the very first page load died with
  `Attempted to load class "Version" from namespace "GlpiPlugin\Ticketflow"`. `setup.php`
  now requires `src/Version.php` explicitly.

### Removed

- The `Hooks::CSRF_COMPLIANT` declaration: deprecated in GLPI 11.0 and read by nothing in
  core, which enforces CSRF globally in `CheckCsrfListener`.


## [0.2.0] — 2026-08-25

Adds a second way to measure the wait, driven by how the work is actually chased: not "how
long has this been pending?" but "we answered, and nobody came back to us".

### Added

- **`start_event` on a rule.** `pending_start` keeps the 0.1 behaviour and stays the
  default; `last_target_group_message` runs the countdown from the last visible message on
  the ticket, and only while that message was written by a member of one of the rule's
  target groups.
- Any reply from outside the group **stops** such a rule rather than merely resetting it.
- A **status change now restarts the countdown** in both modes, and starts a new occurrence,
  read from GLPI's own history (`glpi_logs`) with `begin_waiting_date` as fallback.
- Because the conversation clock does not need `begin_waiting_date`, rules can now target
  statuses core never stamps a date on — "Approval" among them.
- The same group gate is available on approval rules.
- 13 unit tests and 6 integration tests for the new semantics; [ADR-009](docs/adr/0009-last-group-message-clock.md).

### Fixed

- **The rule form never rendered.** GLPI's two dropdown APIs disagree about what a
  "multiple" selection looks like — `Dropdown::show()` wants an array in `value`,
  `Dropdown::showFromArray()` wants a scalar there and the selection in `values` — and
  either mistake throws inside the template. Both were wrong, so the entire configuration
  form was broken while every other test stayed green. Fixed, and covered by
  `tests/Integration/RuleFormTest.php`, which asserts that all 27 inputs post back.
- **Upgrades aborted halfway.** `Profile::installRights()` called
  `ProfileRight::addProfileRights()` unconditionally; on the second run that throws on the
  unicity index and takes the whole upgrade down with it. Now idempotent.
- `Execution::getTabNameForItem()` was declared `static`, which `CommonGLPI` does not allow
  — a compile error that aborted installation.

### Changed

- Schema 1.1.0 adds `glpi_plugin_ticketflow_rules.start_event`. Existing rules keep their
  behaviour: the migration defaults them to `pending_start`.


## [0.1.0] — 2026-08-25

First release. Targets GLPI 11.0.

### Added

**Rule engine**

- `Rule` domain object with entity scope, assigned-group targeting, ticket status, pending
  reason, delay, calendar mode and reset events.
- Two rule types: `pending_inactivity` (a ticket pending without the expected answer) and
  `pending_approval` (an approval request without a decision).
- Pure, unit-testable matchers behind `MatcherInterface`; adding a rule type does not touch
  the engine.
- Human-readable rule preview on the form and in the simulation screen.

**Business time**

- `BusinessTimeCalculator`, delegating to GLPI's `Calendar::computeEndDate()` so deadlines
  have the same semantics as SLA due dates, holidays and opening hours included.
- Delay units: business days, business hours, calendar days, hours.
- Calendar modes: entity (with inheritance), specific, none — plus an explicit,
  always-recorded fallback to elapsed time when no usable calendar exists.

**Actions**

- `add_followup`, `add_solution`, `change_status`, `close_ticket`, `send_notification`,
  behind an `ActionInterface`.
- Safe `{{placeholder}}` templating with a fixed whitelist, HTML escaping, and no
  expression evaluation.
- Generated followups carry a marker so the plugin can never restart its own clock, and
  pass `_no_reopen` so adding a message does not reopen a pending ticket.

**Execution, idempotency, audit**

- Occurrence keys tied to the cycle (`begin_waiting_date` / `submission_date`), so a ticket
  that goes pending again can fire again.
- Lock-free mutual exclusion through a nullable `UNIQUE` claim key.
- Mandatory re-validation against a fresh read immediately before acting.
- Full audit row per occurrence: reference date, deadline, calendar used, delay,
  per-action results, errors.
- Retention purge as a second automatic action.

**Automatic actions**

- `ProcessRules` (hourly, ships disabled) and `PurgeLogs` (daily), registered as standard
  GLPI automatic actions and runnable by the CLI scheduler.
- Batching by `batch_size` and a hard ceiling per run.

**Interface**

- Administration > TicketFlow: rule list, rule form in five blocks, execution logs.
- Dry run with counters and a per-ticket table (reference date, deadline, overdue,
  calendar, actions), and a confirmed "run for real now".
- Diagnostics screen reporting what the installation actually looks like: calendars,
  resolved entity calendars, pending shape, approvals, group-assignment distribution and
  cron state.
- Rules that can solve, close or change a status are flagged as destructive.

**Safety**

- `execution_enabled` off and `dry_run_global` on at install; the processing task disabled;
  rules always created inactive.

**Security**

- Dedicated `plugin_ticketflow_rule` right in the standard profile matrix, CSRF-compliant
  forms, server-side validation of every enum, entity scoping on rules and logs.

**Quality**

- 75 unit tests covering deadlines, matchers, placeholders, occurrence keys and action
  execution — no database required.
- Integration suite covering installation, the acceptance scenarios, and parity between the
  in-memory calendar port and core's `Calendar`.
- Portuguese (Brazil) translation, with dependency-free extraction and `.mo` compilation
  tools.
- `docs/discovery.md`, `docs/architecture.md`, `docs/rules.md`, `docs/development.md` and
  eight ADRs.

### Known limitations

- Group hierarchy is not walked: a rule targeting a parent group does not match tickets
  assigned to a child group.
- Approval *steps* are not interpreted; the unit is the individual approval request.
- No warning thresholds inside a single rule yet (several coordinated rules express it).
- Conditions are the fixed set a temporal rule needs; there is no generic criteria language
  (see ADR-008).
- A crashed run leaves a `processing` row that keeps blocking its occurrence until an
  administrator deletes it — deliberately, since the alternative risks repeating a
  destructive action.

[Unreleased]: https://github.com/Jovinull/ticketflow/compare/1.0.0...HEAD
[1.0.0]: https://github.com/Jovinull/ticketflow/releases/tag/1.0.0
[0.2.0]: https://github.com/Jovinull/ticketflow/releases/tag/0.2.0
[0.1.0]: https://github.com/Jovinull/ticketflow/releases/tag/0.1.0
