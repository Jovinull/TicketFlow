# TicketFlow — Engineering Playbook

This document is the working agreement for changing TicketFlow. It complements
[architecture.md](architecture.md), the ADRs, and the test suite; it does not replace
them. A change is good when it makes a real requirement safer, clearer, or easier to
operate — not merely when it adds another abstraction.

## 1. Product constraints that shape every design

TicketFlow is not a generic PHP application. It is an extension inside a running GLPI
instance, where users, entities, profiles, calendars, ITIL history, other plugins and the
automatic-action scheduler are shared infrastructure.

That gives the project four non-negotiable constraints:

1. **GLPI owns the host semantics.** Use its item APIs, rights, calendars, query builder,
   CSRF lifecycle and scheduler instead of recreating them. GLPI 11 makes framework queries
   and updates mandatory for Marketplace plugins.
2. **Entities are a security boundary.** A rule's configured scope is the ceiling for cron;
   a manual caller's active entities form an additional, lower ceiling. A preview is data
   disclosure just as much as a real action is a write.
3. **Time-based automation is distributed work.** The same occurrence can be observed by
   multiple cron processes and a manual run. Claims, re-validation and explicit failure
   states matter more than a superficially simple loop.
4. **GLPI APIs have external effects.** Followups, solutions and ticket changes cause
   history, notifications and hooks. A database transaction cannot honestly undo all of
   those effects.

## 2. Architecture: functional core, GLPI shell

The preferred shape is a functional core with an imperative shell:

| Area | Examples | Rules |
|---|---|---|
| Domain | `RuleDefinition`, `TicketContext`, `Deadline`, enums, matchers, occurrence keys | Deterministic values and pure decisions; unit-test without GLPI. |
| Application | `RuleEngine`, `ActionExecutor`, `RunReport` | Orchestrates ordering, retries and state transitions; owns no SQL shape. |
| GLPI adapters | `CandidateFinder`, `TicketContextResolver`, `GlpiCalendarEngine`, `Execution`, actions | Isolate schema, session and core APIs. Keep GLPI-specific facts here. |
| Delivery | `Cron`, `front/*.php`, Twig | Authenticate/authorize, validate request intent, call an application service and render. No business rules. |

This is a boundary, not a folder-counting exercise. A class may cross it only when the
alternative would duplicate GLPI semantics or make an invariant impossible to enforce.

### Patterns already justified by the domain

| Pattern | Existing seam | Why it earns its cost |
|---|---|---|
| Strategy | `MatcherInterface`, `ActionInterface`, `CalendarEngineInterface` | Rule types, actions and calendar implementations vary independently. |
| Command | `ActionDefinition` plus ordered action handlers | An action has typed intent, parameters, rank and an auditable result. |
| Adapter | `GlpiCalendarEngine` | Imports GLPI calendar semantics without leaking `Calendar` through pure matching code. |
| Policy | `OperatorAuthorization` | Manual permission decisions differ from cron because cron has no user profile. |
| Result | `MatchResult`, `ActionResult`, `RunReport` | Expected outcomes remain data, not invisible booleans or swallowed exceptions. |
| ADR | `docs/adr/` | Preserves the reason behind choices that look counter-intuitive later. |

Do **not** add factories, repositories, service locators, event buses or a generic rules DSL
until there is a second concrete use that cannot be served by an existing seam. The current
`RuleAction` rows are intentionally generic enough for actions; a universal condition engine
is intentionally absent (ADR-008).

## 3. SOLID and clean-code rules, applied rather than recited

| Principle | TicketFlow interpretation | Warning sign |
|---|---|---|
| Single responsibility | `CandidateFinder` selects, resolver loads, matcher decides, executor dispatches, action mutates. | A controller reads tables or a matcher performs a write. |
| Open/closed | Add a rule type via matcher + candidate query + occurrence key; add an action via handler + authorization policy + tests. | Editing a long `if` chain in several unrelated classes for one new type. |
| Liskov substitution | Every matcher and action must honour its interface contract, especially dry run and result shape. | A special caller needs `instanceof` to make one implementation safe. |
| Interface segregation | Interfaces represent one capability: match, execute, calendar lookup. | A handler implements methods it cannot sensibly support. |
| Dependency inversion | Pure code depends on value objects and interfaces; GLPI integrations sit at construction boundaries. | A unit test needs a database because a time or matching decision imports GLPI. |

Clean code here means:

- names explain domain intent (`occurrence_key`, `reference_date`, `limit_entities`), not
  implementation accidents;
- enums and GLPI constants replace magic strings and numbers;
- comments explain *why a surprising rule exists*, not what the next line does;
- methods may be long when they make a safety sequence readable. Do not fragment the claim →
  fresh read → re-check → action sequence merely to meet a line count;
- an exception is for an exceptional or infrastructure failure; expected domain outcomes use
  `MatchResult`/`ActionResult`;
- external input and stored JSON are parsed once at the boundary into typed values.

## 4. Safety invariants

These are release blockers. Any change touching their path must state which invariant it
preserves and add or update the relevant test.

| Invariant | Enforcement point | Required evidence |
|---|---|---|
| A manual user can affect only tickets they may affect by hand. | `Rule::checkOperatorMayActOnTickets`, `OperatorAuthorization`, action policy. | Integration test for the denied capability and the allowed capability. |
| A manual run cannot escape its active entities. | `RuleEngine::forOperator` → `CandidateFinder`. | Two-branch entity test, including preview. |
| Cron obeys the configured rule scope, not a web session or profile. | `new RuleEngine()` from `Cron`. | Cron integration path stays independent of operator policy. |
| A GET never scans for candidates or writes operational/audit state. | `front/rule.simulate.php`. | Controller/feature test or explicit code-path review. |
| Dry run changes neither tickets nor plugin audit/rule state. | `RuleEngine::isGloballyInert`, simulation path. | Test both creating and clearing state are absent. |
| Every real action follows a fresh read and a new match. | `RuleEngine::processCandidate`. | Regression test for state changing after selection. |
| One occurrence is executed at most once automatically. | `Execution::claim`, unique `claim_key`. | Concurrent/second-run integration test. |
| A refusal before any action releases the claim; a refusal after a side effect retains it. | `ActionExecutor`, `Execution::complete`. | Both paths tested; no duplicate followup on cron. |
| Failed or crashed partial work is never retried automatically. | `ExecutionState::blocksNewClaim`. | State/audit assertion. |
| Ticket writes use `ITILFollowup`, `ITILSolution` or `Ticket`, never direct SQL. | Action classes. | Static review plus integration history assertion. |
| Rendering escapes untrusted ticket values. | `MessageRenderer`, Twig autoescaping. | Unit test for placeholders and review of every `raw`. |

## 5. Extension protocols

### A new rule type

1. Write the acceptance examples first: eligible, ineligible, exact deadline, new cycle,
   entity and group edge cases.
2. Add a typed `RuleType` case and a pure matcher.
3. Add an indexed and bounded candidate query. It may over-select, but it must never
   under-select a genuinely expired candidate.
4. Define an occurrence key representing the business cycle, not merely `(rule, ticket)`.
5. Add a resolver field only if core does not already expose the information in the existing
   `TicketContext`.
6. Add unit tests for semantics and integration tests for the GLPI data shape.
7. Write an ADR if the reference event, idempotency meaning or failure policy is non-obvious.

### A new action

1. Add an `ActionType` and decide whether it is destructive.
2. Implement `ActionInterface`; dry-run must return a simulated result and write nothing.
3. Extend `OperatorAuthorization` with the same capability that GLPI's interface requires
   for a person performing that action.
4. Choose the actor deliberately: manual runs use the session user; cron uses the configured
   automation user.
5. Define what happens after a partial batch. Never introduce automatic retry without proving
   it cannot duplicate an external effect.
6. Test authorization, audit result, cron/manual distinction and idempotency.

### A schema change

1. Never rewrite a released migration; add a versioned step.
2. Apply each step before recording its schema version.
3. Install clean and upgrade from every supported historical version on MariaDB and MySQL.
4. Keep the schema contract in column order, type, nullability and defaults; engine-specific
   metadata spelling belongs in its normalizer, not in product DDL.
5. State rollback/repair behaviour. The normal answer is forward repair, not rollback.

## 6. Security and operational design

### Authorization and request intent

- Every `front/` entry point must check rights before any action; item-level checks are needed
  where the object is entity-scoped.
- A global setting requires GLPI's global `config` right, not merely a plugin rule right.
- State-changing and expensive operations are POST-only and protected by GLPI's CSRF flow.
- Never rely on `CommonDBTM::add()` or `update()` to authorize a caller. Controllers and
  policies own authorization.
- Treat rendered previews as reads of protected ticket data.

### Data and output

- Use GLPI's query builder for DML and query criteria. DDL is isolated in installation code,
  whose identifiers come only from plugin classes and are quoted.
- Parse JSON with explicit failure handling; a corrupt stored action must not silently become
  a different rule.
- Store only what helps operate or audit the plugin. Do not add ticket body, session data,
  credentials or tokens to logs.
- Keep errors actionable to administrators but do not expose stack traces or internals in
  pages.

### Resilience

- A bad ticket, action or rule must not abort the rest of a cron pass.
- A bad rule is refused as a rule-level operational state, visible where its administrator
  will look; it is not a fake ticket execution.
- Measure before optimizing candidate selection. Keep batch and total ceilings; paginate by a
  monotonic cursor, never `OFFSET` after mutations.
- Do not add a lease/expiry to claims without a business-level duplicate-effect analysis.

## 7. Test and review strategy

The project uses a deliberate test portfolio rather than a coverage percentage target:

| Layer | Purpose | Examples |
|---|---|---|
| Unit | Fast, deterministic domain semantics. | deadline boundaries, matcher outcomes, placeholders, action order. |
| Integration | Real GLPI database/API semantics. | entity scope, profile rights, followup attribution, claim uniqueness, migrations. |
| Feature/manual smoke | Browser-visible behavior that assertions alone miss. | CSRF form flow, status dropdown, preview rendering, archive install. |
| CI matrix | Host compatibility. | PHP 8.2–8.5, MariaDB 10.6/12.3, MySQL 8/9. |

Every defect gets a regression test that fails on the faulty behavior. A test should prove an
observable contract, not merely require a newly introduced method to exist.

## 8. Quality gates

Before a pull request is merged, run the applicable gates in a GLPI checkout with its dev
dependencies:

```bash
composer validate --strict
composer test

# From <glpi>/plugins/ticketclock
../../vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G
../../vendor/bin/psalm --config=psalm.xml --no-cache
../../vendor/bin/rector process --dry-run --no-progress-bar
../../vendor/bin/php-cs-fixer check --config=.php-cs-fixer.php --diff --no-interaction
../../vendor/bin/twigcs templates --config=.twig_cs.dist.php
php tools/apply-headers.php --check
../../vendor/bin/phpunit -c phpunit.xml
```

The remote CI is still authoritative because it exercises the supported database/PHP matrix.
A local green run is evidence; a green matrix is the merge gate.

## 9. Reference material

- [Official empty plugin skeleton](https://github.com/pluginsGLPI/empty) — repository layout,
  tooling and test conventions.
- [Official example plugin](https://github.com/pluginsGLPI/example) — supported hooks and
  integration examples; use it as an API reference, not as a reason to copy legacy patterns.
- [Official plugin CI workflows](https://github.com/glpi-project/plugin-ci-workflows) — the
  reusable pipeline and supported PHP/database matrix.
- [GLPI plugin tutorial and Marketplace requirements](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/tutorial.html)
- [GLPI plugin guidelines](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/guidelines.html)
- [GLPI database updates](https://glpi-developer-documentation.readthedocs.io/en/master/devapi/database/dbupdate.html)
- [GLPI controllers and CSRF](https://glpi-developer-documentation.readthedocs.io/en/master/devapi/controllers.html)
- [GLPI automatic actions](https://glpi-developer-documentation.readthedocs.io/en/latest/devapi/crontasks.html)
- [PHP-FIG PSR-4](https://www.php-fig.org/psr/psr-4/) and [PSR-12](https://www.php-fig.org/psr/psr-12/)
- [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/)
- [ADR guidance](https://martinfowler.com/bliki/ArchitectureDecisionRecord.html)
- [Test-pyramid guidance](https://martinfowler.com/bliki/TestPyramid.html)

External references guide decisions; the code, tests and accepted ADRs remain the source of
truth for TicketFlow's behavior.
