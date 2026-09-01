# ADR-010 — Manual execution is an operator context, not cron in a browser

**Status:** accepted (1.1.0 development)

## Context

The engine has two callers with fundamentally different authority:

* the GLPI automatic action is administrator-configured automation, has no interactive
  profile, and writes as the configured system user;
* the manual **Run for real** screen is a logged-in person changing tickets now.

`CommonDBTM::add()` and `update()` do not provide a controller authorization boundary. A
plugin-only right therefore allowed a configuration operator to perform actions on tickets
they could not perform through GLPI's own interface. Applying GLPI item `check()` calls
inside action handlers looked attractive but also applied a profile status-transition check
to cron. Cron has no profile and would then be denied every solution transition.

## Decision

`RuleEngine::forOperator()` creates a distinct engine context:

1. candidates are constrained by the session's active entities as well as the rule scope;
2. generated followups/solutions are attributed to `Session::getLoginUserID()`;
3. `OperatorAuthorization` asks the ticket capability required for each action immediately
   before dispatch; the scheduled engine receives no such policy;
4. a real manual run needs both the ticket UPDATE coarse gate and the rule's direct-entity
   administration policy;
5. simulation and real execution are POST-only; simulation remains dry and writes no plugin
   audit or rule bookkeeping.

A denied first action is represented as `ActionResult::refused` and completes as `skipped`,
which releases the occurrence claim for cron. If an earlier action already ran, the refusal
is a partial failure and keeps its claim: releasing it would let cron repeat the earlier
side effect.

## Consequences

Manual behavior is intentionally not identical to cron behavior. A person can see a rule
but still be unable to run it for real, and can preview a rule only in their active entity
scope. This is a security property, not an inconvenience to bypass.

The per-action authorization map must be extended whenever an `ActionType` is added. Tests
cover denied and allowed manual capabilities, status-transition denials, actor attribution,
entity scope and the two claim-release paths.

## Alternatives rejected

**One broad plugin right.** It does not express followup, solution, ticket or status rights.

**Model-level checks inside actions for every caller.** Correct for a web user but breaks
cron because it has no active profile/status matrix.

**Release every refused claim.** Unsafe after an earlier action succeeded; cron would replay
the batch and duplicate effects.
