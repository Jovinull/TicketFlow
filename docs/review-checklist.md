# TicketFlow — Change and Release Checklist

Use this checklist for every pull request. It is intentionally opinionated: automation that
changes tickets deserves more scrutiny than an ordinary configuration screen.

## 1. Triage before implementation

- [ ] Is the request a bug fix, security fix, operational improvement or feature?
- [ ] Which user-visible behavior and which invariant change?
- [ ] Does an existing ADR already decide this? If it conflicts, write a superseding ADR.
- [ ] Is there a smaller change that solves the proven requirement?
- [ ] Does the change cross a GLPI version, entity, profile, cron, database or template
      boundary?

## 2. Architecture and domain

- [ ] Pure matching/time logic remains independent of GLPI and gets a unit test.
- [ ] SQL/schema knowledge stays in finder, resolver, persistence or install code.
- [ ] Delivery code (`front/`, cron, Twig) does not gain business decisions.
- [ ] A new rule type has a matcher, bounded candidate query, occurrence key and test matrix.
- [ ] A new action has a handler, a result, dry-run behavior and a manual authorization rule.
- [ ] Ordering and partial-failure behavior are written down when more than one action runs.
- [ ] New abstraction has at least two real uses or removes a demonstrated coupling.

## 3. Security and entities

- [ ] Every affected `front/` or `ajax/` endpoint checks the minimum appropriate right before
      an action or expensive scan.
- [ ] Item/entity-scoped operations use item-level and explicit entity policy where GLPI's
      behavior differs across supported patch versions.
- [ ] Manual candidate scope is the intersection of the rule scope and active session entities.
- [ ] Cron keeps the configured rule scope and does not acquire session/profile assumptions.
- [ ] GET is read-only; POST is required for simulation, writes, configuration and destructive
      actions, with GLPI CSRF protection.
- [ ] A manual action is authorized using the same capability that GLPI's UI uses, not only a
      broad plugin right.
- [ ] Followup/solution/status writes carry the correct actor for manual versus cron context.
- [ ] Twig uses normal escaping; any `|raw` has a documented, tested sanitization boundary.
- [ ] Query values use GLPI criteria/binding. Dynamic table/field names have a closed source and
      are quoted where needed.

## 4. Idempotency, failure and audit

- [ ] The occurrence key represents one business cycle, including a new pending/approval cycle.
- [ ] Claim happens before destructive work, then a fresh read and re-match occur before action.
- [ ] Dry run neither consumes nor writes a real occurrence/audit/rule state.
- [ ] A refusal before action releases the claim; one after a side effect remains failed and
      retains it.
- [ ] A failed/crashed partial batch cannot be retried automatically into duplicate effects.
- [ ] Invalid action JSON or unknown action type refuses the entire rule and is visible on the
      rule, not only in a server log.
- [ ] Logs and UI give operators a useful reason without leaking ticket content, sessions or
      stack traces.

## 5. Schema and portability

- [ ] The migration is additive and versioned; no released migration was edited.
- [ ] DDL is applied before its installed version is recorded.
- [ ] Clean install and all supported upgrade starting points reach the same schema contract.
- [ ] Tests cover column order, type, nullability, default and indexes as reported by both
      MariaDB and MySQL.
- [ ] Install, activate, upgrade and uninstall were exercised on a disposable database.

## 6. Evidence required with the pull request

- [ ] Regression test first, or a written reason why a test cannot be automated.
- [ ] Unit suite and relevant integration tests pass.
- [ ] PHPStan, Psalm, Rector, CS Fixer, TwigCS, headers and syntax checks pass.
- [ ] Database/PHP matrix CI passes.
- [ ] Browser smoke test for a changed form/template/CSRF behavior.
- [ ] `CHANGELOG.md`, documentation and translations updated when user-visible behavior changes.
- [ ] Commit message names the intent; body explains a non-obvious safety or compatibility
      decision in a few lines.

## 7. Release gate

- [ ] The release artifact, not only the working tree, installs and activates on a fresh GLPI.
- [ ] It begins inert: execution disabled, global dry run enabled, no active rules and cron task
      disabled unless the release intentionally changes that contract.
- [ ] Manifest version, compatibility, download URL and archive layout match the tag.
- [ ] Release notes identify security fixes, behavior changes, migration actions and operator
      actions needed after upgrade.
- [ ] No unresolved high/critical security finding or known data-corruption path remains.
