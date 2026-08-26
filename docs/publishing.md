# Publishing TicketFlow

What GLPI actually requires to publish a plugin, where each requirement is written down,
and where TicketFlow stands against it.

Sources are primary throughout: the GLPI developer documentation, the official
`pluginsGLPI/empty` skeleton, and `glpi-project/plugin-ci-workflows` — the reusable
workflow that *is* the CI every official plugin runs.

---

## 1. The two destinations

They are separate, and the second depends on the first.

| | **Plugins catalog** | **Marketplace** |
|---|---|---|
| What | the public plugin directory | in-product install and update |
| How | submit the raw XML manifest URL at [plugins.glpi-project.org/#/submit](https://plugins.glpi-project.org/#/submit) (registration required) | email `glpi@teclib.com` **after** the catalog listing exists |
| Who decides | Teclib' is notified and reviews before activating the listing | the GLPI team reviews key technical aspects; on-premise and Cloud have separate bars, Cloud stricter |
| Can be revoked | — | yes, at any time, for a major bug or a security issue |

> Nothing is automatic. Both steps are human review.

## 2. Hard requirements, and where we stand

### 2.1 Repository and licence

| Requirement | Source | Status |
|---|---|---|
| Publicly accessible git repository | tutorial · *Publishing your plugin* | ⚠️ **you must create it** — nothing else blocks |
| Open source licence | same | ✅ MIT, `LICENSE` at the root |
| Directory name alphanumeric only, never changes | guidelines · *Directories structure* | ✅ `ticketclock` |
| `setup.php` and `hook.php` at the root | requirements | ✅ |
| `plugin_version_ticketclock()` and `plugin_init_ticketclock()` | requirements | ✅ |
| `README.md` and `LICENSE` | guidelines | ✅ |

### 2.2 The XML manifest

The catalog reads one file. Ours is [`ticketclock.xml`](../ticketclock.xml).

| Requirement | Status |
|---|---|
| Well-formed XML | ✅ verified with `DOMDocument::load()` |
| `<key>` matches the directory name exactly — lowercase, no spaces, no accents | ✅ `ticketclock`, asserted against the directory name |
| `<name>`, `<state>`, `<logo>`, `<description><short>/<long>`, `<homepage>`, `<download>`, `<issues>`, `<readme>`, `<authors>`, `<versions>`, `<langs>`, `<license>` | ✅ all present |
| `<compatibility>` is a **Composer** version constraint | ✅ `~11.0.0` |
| `<download_url>` per version, pointing at a downloadable archive | ✅ declared; the release must exist before submitting |
| Descriptions in several languages, screenshots recommended | ✅ en / fr / pt-BR · ✅ six screenshots |
| A logo image | ✅ `ticketclock.png`, 128×128 RGBA |

Two things only you can do:

1. **Submit the *raw* URL**, not the repository page. `https://github.com/…/blob/main/ticketclock.xml` is rejected; `https://raw.githubusercontent.com/…/refs/heads/main/ticketclock.xml` is right.
2. **Every URL in the manifest must resolve.** The CI checks this on any PR that touches the
   manifest, and a `<download_url>` for a release that does not exist yet is the one
   tolerated exception.

### 2.3 Archive layout

> The archive must contain a directory named after the plugin's technical name, with all
> files inside it.

✅ The repository ships the plugin as `ticketclock/`, so `tar czf glpi-ticketclock-1.0.0.tar.bz2 ticketclock/` produces exactly the required shape.

### 2.4 Technical requirements for the Marketplace

These are the ones stated as **mandatory**.

| Requirement | Status | Evidence |
|---|---|---|
| Complies with GLPI coding standards; no errors from tools like phpcs | ✅ | PHP-CS-Fixer `@PER-CS` clean on 80 files |
| **No raw SQL — mandatory from GLPI 11.** Use the framework's query builder | ✅ | every read and write goes through `$DB->request/insert/update/delete`. The only raw statements are `CREATE TABLE` / `DROP TABLE` in `Install`, which is the same thing the official `example` plugin does (`example/hook.php`) — there is no framework API for DDL |
| Twig for templating | ✅ | all five screens are Twig under `templates/`, rendered by `TemplateRenderer` |
| Permissions enforced in **every** `front/*` and `ajax/*` file | ✅ | all six front controllers open with `Session::checkRight()`; there are no `ajax/` files |
| No backdoors or obvious security flaws | ✅ | Psalm taint analysis clean; no `eval`, no dynamic includes, no shell execution; placeholders are a fixed whitelist, not an expression language |
| Compatible with a maintained GLPI version | ✅ | GLPI 11.0.x, PHP 8.2+ |

## 3. What the official CI runs, and what it would do to us

`glpi-project/plugin-ci-workflows@v1` runs each tool **only when its config file exists**, so
an absent config is a silently skipped check. Every one below was executed locally against a
real GLPI 11.0.4 development checkout, using GLPI's own vendor binaries — the same ones CI
uses.

| Step | Config that enables it | Present | Result |
|---|---|---|---|
| PHP Parallel Lint | always runs | — | ✅ 81 files, no syntax error |
| PHP CodeSniffer | `.phpcs.xml` | ❌ | skipped — superseded by PHP-CS-Fixer, as in the official skeleton |
| PHP-CS-Fixer | `.php-cs-fixer.php` | ✅ | ✅ clean |
| PHPStan | `phpstan.neon` | ✅ | ✅ **no errors at level 8**, with the official `phpstan-glpi` extension, deprecation rules and the Safe rule |
| Psalm | `psalm.xml` | ✅ | ✅ **no errors**, taint analysis on |
| Rector | `rector.php` | ✅ | ✅ **clean**. The skeleton's config delegates to GLPI's `PluginsRector.php`, which 11.0.4 does not ship, so this one stands alone: PHP 8.2 plus the dead-code, code-quality and type-declaration sets |
| ESLint / Stylelint | `eslint.config.*` / `.stylelintrc.js` | ❌ | not applicable — no JavaScript, no CSS |
| Licence header check | `tools/HEADER` | ✅ | ✅ all 84 PHP and Twig files carry the header |
| Install + activate plugin | always runs | — | ✅ verified repeatedly against GLPI 11.0.4 |
| PHPUnit | `phpunit.xml` | ✅ | ✅ **146 tests, 512 assertions** (95 unit + 51 integration), green on **both** matrix halves and on 8.3 |
| Jest / Vitest | `jest.config.js` / `vitest.config.*` | ❌ | not applicable |
| TwigCS | `.twig_cs.dist.php` | ✅ | ✅ no violation |
| **Uninstall cleanliness** | always runs | — | ✅ no leftover table, config row, cron task, profile right or display preference |
| CHANGELOG updated on PRs | `CHANGELOG.md` present | ✅ | ✅ present and maintained |
| Manifest URL validity | manifest touched in a PR | ✅ | ⚠️ passes once the repository is public |
| Common dependencies with core | `composer.json` | ✅ | ✅ **zero production dependencies**; the two dev ones (`phpunit`, `thecodingmachine/safe`) are exempt from that check, which only compares production requirements |

The CI matrix for GLPI 11.0.x is **PHP 8.2 / MariaDB 10.6** and **PHP 8.5 / MariaDB 12.3**.
Our declared floor is PHP 8.2, and the code uses no syntax newer than that.

Both halves were run locally rather than assumed, on their own containers: **PHP 8.2.33 /
MariaDB 10.6.28** and **PHP 8.5.9 / MariaDB 12.3.3**, plus 8.3 in between. Each got a fresh
GLPI install, the plugin installed and activated, all 146 tests, a real execution through
`front/cron.php` with an idempotent second pass, and an uninstall that left nothing behind.
The floor was also measured under load: 30,050 pending tickets at ~1,800 candidates/second,
with per-ticket cost flat between 1k and 30k.

### Screenshots

`ticketclock.xml` carries six, in `docs/screenshots/`: the rule form, a dry run, the
execution log, diagnostics, the rule list and the configuration screen.

They were captured on a **demo instance seeded on purpose** — a fresh database, an invented
group, two invented users, six invented tickets — driven through headless Chromium over the
DevTools protocol. That detour is not fussiness: these screens show ticket titles, group
names and user names, and on this project's reference database those are real customer
records. A catalog listing is public. **Never re-shoot them against a copy of production.**

`tools/seed-demo.php` and `tools/seed-screenshots.py` reproduce the instance and the
capture, so a later version can refresh the set the same way.

### Coverage

`.glpi-coverage.json` ships with `"enabled": false`, the skeleton's default. Enabling it
turns on pcov and a report upload that could not be exercised here; flipping it to `true` is
a one-line change once CI has run green at least once.

## 4. What is left, and it is not code

1. **Create the public repository** at `github.com/Jovinull/ticketclock` and push. Every URL
   in the manifest, the headers and the docs already points there.
2. **Cut the `1.0.0` tag.** `.github/workflows/release.yml` calls the official release
   workflow, which builds and attaches the archive.
3. **Check the `<download_url>`** resolves to that archive.
4. **Submit the raw manifest URL** at plugins.glpi-project.org/#/submit and wait for
   Teclib'.
5. **Then, and only then**, email `glpi@teclib.com` for the Marketplace.

### What the release workflow reads out of `setup.php`

`glpi-project/plugin-release-workflows@v1` does two greps on this one file before it builds
anything, and both were worth checking rather than assuming:

| It greps for | To get | Ours |
|---|---|---|
| `function plugin_version_<key>` | the plugin key, which becomes the archive name | `ticketclock` |
| `PLUGIN_<KEY>_MIN_GLPI` / `_MAX_GLPI`, **quoted literal** | the "Compatible GLPI" line on the release page | `11.0.0` / `11.0.99` |

The second one did not match at first: the constants were named `…_MIN_GLPI_VERSION` and
held class constants, so the grep returned nothing and the step degraded silently — the
release would simply have shipped without that line. Fixed, and pinned by a test.

The archive it produces is `glpi-<key>-<tag>.tar.bz2`, so tag `1.0.0` yields
`glpi-ticketclock-1.0.0.tar.bz2` — exactly what `<download_url>` in the manifest already
points at.

### The archive, verified

`glpi-ticketclock-1.0.0.tar.bz2` was built and installed on a *fourth* throwaway instance —
a virgin GLPI 11.0.4, the archive unpacked into `plugins/`, nothing from the working tree.
It installs, activates, and lands inert exactly as designed:

| | |
|---|---|
| `execution_enabled` | `0` |
| `dry_run_global` | `1` |
| `ProcessRules` task | disabled |
| Rules | none |
| A forced cron pass | 0 executions, 0 followups, 0 solutions |

That is the safety promise from the original brief, checked against the artefact people
actually download rather than against the source tree.

## 5. Honest gaps

* **PHPStan runs at level 8, not `max`.** Level 9 adds the explicit-`mixed` rules, which
  produce 200-odd `cast.int` / `cast.string` findings — every one of them on a value read
  out of `$DB->request()`, which GLPI types as `mixed`. Silencing those would mean either a
  baseline that hides them or an assertion at every row access; neither buys any safety the
  casts do not already provide. Level 8 is clean and meaningful; `max` would be clean only
  on paper.
* **Three Rector rules are skipped, each with its reason in `rector.php`.** One of them
  matters: Rector's dead-code analysis does not follow variables captured by reference into
  a closure, and it proposed deleting the entire body of the `.mo` catalogue flush in
  `tools/compile-locales.php`. Applied blindly that would have silently broken every
  translation. Skipped for that file, verified against Rector's own dry-run.
* **Six defects got through a green suite**, none of them findable from inside it: a
  `setup.php` that needed the plugin autoloader before it existed (caught by installing on
  a clean instance), a status dropdown shifted by one because Twig's `merge` renumbers
  integer keys (caught by looking at a screenshot), a multi-select posting under
  `_reset_events[][]` that made Save wipe the field (caught by pressing Save in a browser
  and diffing the database), and a deprecated entity-config call writing a notice on every
  cron pass (caught by exercising a real entity tree). All four are fixed and covered. The
  general point stands: rendering assertions prove a field is on the page, not that the page
  does the right thing when you use it — and a test suite only covers the paths somebody
  thought to walk down.
* **The manifest URLs are unverifiable until the repository is public** — the CI step that
  checks them will be the first real test.
* **The CI has never run.** Everything above was reproduced locally with the same tools and
  the same GLPI version, but GitHub Actions itself has not executed once.

---

## Reproducing this audit

```bash
# a GLPI development checkout, with dev dependencies (that is where the tools live)
git clone --branch 11.0.4 https://github.com/glpi-project/glpi.git
cd glpi && composer install
cp -r /path/to/ticketclock plugins/ticketclock
cd plugins/ticketclock

../../vendor/bin/parallel-lint --exclude ./vendor/ --no-progress .
PHP_CS_FIXER_IGNORE_ENV=1 php ../../vendor/bin/php-cs-fixer check --config=.php-cs-fixer.php
php ../../vendor/bin/phpstan analyze --memory-limit=2G --no-progress
php ../../vendor/bin/psalm --no-progress
php ../../vendor/bin/twigcs
php ../../vendor/bin/licence-headers-check --no-interaction --header-file=tools/HEADER -d .
php ../../vendor/bin/rector process --dry-run
```

The unit suite needs none of that: `composer test` runs it anywhere PHP does.

## Sources

* [Publishing your plugin — GLPI Developer Documentation, plugin tutorial](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/tutorial.html)
* [Plugin requirements](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/requirements.html)
* [Plugin guidelines](https://glpi-developer-documentation.readthedocs.io/en/master/plugins/guidelines.html)
* [glpi-project/plugin-ci-workflows](https://github.com/glpi-project/plugin-ci-workflows)
* [pluginsGLPI/empty](https://github.com/pluginsGLPI/empty) — the official skeleton
* [GLPI plugins catalog](https://plugins.glpi-project.org/)
