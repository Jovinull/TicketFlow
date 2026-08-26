# TicketFlow — Development

## Layout

```
ticketclock/
├── setup.php                 plugin_init / plugin_version / prerequisites
├── hook.php                  install / uninstall entry points
├── src/
│   ├── Rule.php              rule persistence + form + search options
│   ├── RuleGroup.php         rule → assigned groups
│   ├── RuleAction.php        rule → actions (generic rows, simple form)
│   ├── Execution.php         audit trail + occurrence claim
│   ├── Config.php            plugin settings (stored in glpi_configs)
│   ├── Profile.php           the right, in the profile matrix
│   ├── Menu.php              Administration > TicketFlow
│   ├── Install.php           versioned schema migrations
│   ├── Inspector.php         read-only installation diagnostics
│   ├── Cron.php              the two automatic actions
│   ├── Calendar/             deadline arithmetic (GLPI-free + GLPI wrapper)
│   └── Engine/               matchers, resolver, actions, orchestration
├── front/                    legacy-router pages
├── templates/                Twig, reachable as @ticketclock/*
├── locales/                  .pot / .po / .mo
├── tools/                    locale extraction and compilation
├── tests/Unit/               no database, no GLPI
├── tests/Integration/        needs a GLPI test install
└── docs/
```

`GlpiPlugin\Ticketclock\Foo\Bar` maps to `src/Foo/Bar.php`: GLPI registers a PSR-4
autoloader per plugin over `<plugin>/src/`, using `ucfirst(<folder name>)` as the
namespace segment (`Plugin::registerPluginAutoloader()`). The folder therefore **must** be
`ticketclock`.

## Environment used to build 0.1

| | |
|---|---|
| GLPI | 11.0.4 |
| PHP | 8.4 (floor 8.2) |
| Database | MariaDB 10.11 |

Everything asserted about GLPI in these documents was checked against the 11.0.4 source and
against a real production dump; see [discovery.md](discovery.md) for the file and line
references.

## Setting up

```bash
git clone https://github.com/Jovinull/ticketclock.git /var/www/glpi/plugins/ticketclock
cd /var/www/glpi/plugins/ticketclock
composer install
```

Then install and activate the plugin (interface, or `bin/console plugin:install ticketclock`
followed by `plugin:activate`).

## Tests

### Unit suite — the one that must always run

```bash
composer test          # or: vendor/bin/phpunit --testsuite unit
```

No database, no web server, no GLPI checkout. It covers the parts that are easy to get
subtly wrong and expensive to get wrong in production:

* deadline arithmetic — weekends, holidays, exact-deadline boundaries, opening-hour
  clamping, the no-calendar fallback, business hours spanning a weekend;
* the pending matcher — inside/at/after the deadline, requester answer restarting the
  clock, technician answer *not* restarting it, status/group/entity/pending-reason
  mismatches, missing reference dates, occurrence identity across cycles;
* the approval matcher — unanswered past the deadline, answered before, per-request
  occurrence keys, group conditions;
* placeholder rendering — substitution, escaping, unknown placeholders, no expression
  evaluation;
* action execution — ranking order, stop-on-failure, thrown exceptions contained,
  dry-run propagation.

### Integration suite — needs a real GLPI

```bash
vendor/bin/phpunit -c phpunit-integration.xml
```

It expects the plugin installed and active in a GLPI **test** database, following the
official `pluginsGLPI/empty` convention (`tests/bootstrap.php` chains into GLPI's own).

```bash
php bin/console database:install --db-name=glpi_test --no-interaction
php bin/console plugin:install ticketclock
php bin/console plugin:activate ticketclock
```

It creates its own group, calendar, users and tickets. **Never point it at production.**

What it covers that the unit suite cannot:

* the schema is installed, tables follow the GLPI naming convention, `claim_key` really is
  a `UNIQUE` index, both automatic actions are registered under the names GLPI will call,
  and a fresh install is inert;
* a pending ticket past its deadline gets a followup and a solution, exactly once, and a
  second run changes nothing;
* a requester answer keeps the ticket open;
* a dry run leaves the database untouched and does not consume the occurrence;
* a TicketFlow-generated followup does not count as an answer;
* approvals fire once per request, and answered ones are left alone;
* **calendar parity**: the in-memory port and core's `Calendar` agree on the same inputs.

### Adding a scenario

Prefer a unit test. If it needs GLPI, ask what it really needs — the answer is usually a
value object, in which case `tests/Unit/DomainFactory.php` builds one with named arguments.

## Conventions

* `declare(strict_types=1)` everywhere; typed properties, typed returns.
* Code and identifiers in English; user-facing strings via `__('…', 'ticketclock')`.
* Never a numeric literal where GLPI has a constant. `CommonITILActor::ASSIGN`, not `2`.
* Never compare a translated label. Ids and constants only.
* Every ticket mutation goes through `ITILFollowup`, `ITILSolution` or `Ticket` — never raw
  SQL — so history, notifications, hooks and other plugins keep working.
* Comments explain *why*, and are worth writing where a reader would otherwise assume a
  mistake (the `_no_reopen` flag, the nullable unique index, the absent transaction).

## Translations

```bash
php tools/extract-locales.php    # sources  -> locales/ticketclock.pot
# translate locales/<lang>.po
php tools/compile-locales.php    # *.po     -> *.mo
```

`extract-locales.php` scans PHP **and** Twig for the `ticketclock` domain, because
`xgettext` does not understand Twig. `compile-locales.php` writes the binary `.mo` GLPI
loads, so building never depends on `msgfmt` being installed.

Both are plain PHP with no dependencies.

## Adding a rule type

1. Add a case to `Enum\RuleType`.
2. Write a matcher implementing `Engine\Matcher\MatcherInterface` (extend
   `AbstractMatcher` for the shared entity/group/active checks). It must be pure.
3. Add a branch to `Engine\CandidateFinder` returning candidates through indexed SQL.
4. Add an `OccurrenceKey` factory that identifies the cycle.
5. Register the matcher in `Engine\RuleEngine`.
6. Unit-test the matcher.

No schema change is needed: `rule_type` is a string column.

## Adding an action

1. Add a case to `Enum\ActionType`, with the right `isDestructive()`.
2. Implement `Engine\Action\ActionInterface`. Honour `ActionContext::$dry_run` by returning
   `ActionResult::simulated()` and touching nothing.
3. Register it in `Engine\RuleEngine`'s executor.
4. Expose it in `templates/rule_form.html.twig` and map it in
   `RuleAction::setActionsForRule()` / `getFormValues()`.

No schema change: actions are stored as `action_type` + JSON `params`.

## Changing the schema

Never edit an existing migration. Add a new one:

```php
private const MIGRATIONS = [
    '1.0.0' => 'migrateTo100',
    '1.1.0' => 'migrateTo110',   // new
];
```

`Install::install()` reads the stored schema version and applies only what is missing, so
the same entry point serves a fresh install and an upgrade.

## Quality

```bash
composer validate
php -l <file>                       # syntax
vendor/bin/phpunit --testsuite unit
```

PHPStan, Psalm, PHP-CS-Fixer and Rector are the tools the official GLPI plugin skeleton
uses; adding them is a natural next step, and their configuration should be copied from
`pluginsGLPI/empty` for the target GLPI version rather than invented here. They are not
vendored in 0.1 to keep the dependency surface at exactly one dev package (PHPUnit).

## Debugging

* `files/_log/ticketclock.log` — one summary line per cron run, plus errors.
* *Administration > TicketFlow > Execution logs* — per-ticket outcomes with the reference
  date, deadline and calendar that were actually used.
* *Administration > TicketFlow > Diagnostics* — what this installation looks like:
  calendars, resolved entity calendars, pending shape, approvals, group assignment
  distribution, cron state.
* The rule's **Simulate** button — the fastest way to see what a rule would do.
