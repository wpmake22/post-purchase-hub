# Editions — Free / Pro Architecture & Release Pipeline

One repository. One source tree. `composer build` produces two zips:

| Artifact | Contents | Destination |
| --- | --- | --- |
| `post-purchase-hub-{version}.zip` | Core + free-only upsell UI | WordPress.org SVN |
| `post-purchase-hub-pro-{version}.zip` | Core + all pro features + licensing | Freemius / EDD / in-house store |

Pro is a **standalone superset**, not an add-on. A customer installs one plugin, not two.

---

## The decision that matters

There are two ways to build free/pro from one tree, and only one of them survives contact with a real codebase.

**Inline marker stripping** — `//#if__PREMIUM` blocks scattered through shared classes, removed at build time. This is what Freemius' own tooling encourages, and it is a trap. The free artifact becomes a *different program* from the one you developed, tested and statically analysed. You get bugs that exist only in the free zip, occasionally including syntax errors, and they surface after release. Debugging them means mentally running the stripper.

**Directory separation** — all pro code in `pro/`, all free-only code in `free/`, build deletes one directory. The free build is a strict subset of source that was never rewritten. Both editions are testable as-is. No syntax risk. The cost is discipline: **core may never reference edition code**, so pro must integrate purely through extension points.

This project uses directory separation, with **zero inline markers**. If you find yourself wanting one, the real answer is a missing extension point in core.

That constraint is load-bearing rather than aesthetic. `docs/SPEC.md` already requires a documented filter for every default a merchant might disagree with, an `ActionRegistry`, a `TrackingAdapterInterface` and a theme-overridable template layer. Pro consumes exactly those. If Pro can't be built on core's public surface, core's public surface is wrong — and that's a bug worth finding before third-party developers hit it.

---

## Layout

```
post-purchase-hub/
├── post-purchase-hub.php     Shared bootstrap. Header + PPH_EDITION rewritten at build.
├── composer.json
├── readme.txt                Free/WP.org only. Stripped from the pro zip.
├── README.md                 Pro only. Stripped from the free zip.
├── src/                      PostPurchaseHub\          — shared core, ships in BOTH
├── free/
│   ├── src/                  PostPurchaseHub\Free\     — upsell, teasers, locked-field UI
│   ├── templates/
│   └── tests/
├── pro/
│   ├── bootstrap.php         Entry point, loaded only if the directory exists
│   ├── src/                  PostPurchaseHub\Pro\      — returns, rules engine, analytics, licensing
│   ├── templates/
│   ├── assets/src → build/
│   └── tests/
├── templates/                Shared, theme-overridable
├── assets/src → build/       Shared
├── bin/
│   ├── build.php
│   └── verify-build.php
└── dist/                     Build output, gitignored
```

Both zips contain a folder named `post-purchase-hub/`. **The folder name must be identical**, or installing Pro leaves the free plugin sitting beside it and the customer runs two copies.

### composer.json

```json
{
  "autoload": {
    "psr-4": {
      "PostPurchaseHub\\": "src/",
      "PostPurchaseHub\\Free\\": "free/src/",
      "PostPurchaseHub\\Pro\\": "pro/src/"
    }
  },
  "scripts": {
    "build":        "php bin/build.php",
    "build:free":   "php bin/build.php --edition=free",
    "build:pro":    "php bin/build.php --edition=pro",
    "verify":       "php bin/verify-build.php",
    "release":      ["@composer build", "@composer verify"]
  }
}
```

The build rewrites `composer.json` inside staging to drop the stripped namespace before running `composer install --classmap-authoritative`. Skipping that leaves an optimized classmap pointing at files that no longer exist.

---

## Hard rules

These are enforced in CI. Violating one fails the build, not code review.

**E1. Core never references edition code.** No `PostPurchaseHub\Pro\` or `PostPurchaseHub\Free\` anywhere in `src/`, including in strings, docblocks and `class_exists()` checks. Checked by `bin/build.php` preflight and by the CI grep gate.

**E2. Core never asks whether Pro is active** to decide behaviour. No `if ( pph_is_pro() )` in `src/`. Core registers extension points; Pro fills them. `pph_is_pro()` exists only for display purposes in `free/` and for the licensing layer in `pro/`.

**E3. Pro extends only through core's public API** — documented filters, actions, the `ActionRegistry`, interfaces, and template overrides. No reaching into core internals, no reflection, no reopening private state.

**E4. Free must be complete and coherent alone.** No dead buttons, no half-features, no error paths that only make sense with Pro installed. Free is a product, not a demo.

**E5. Every core test runs against both editions.** CI runs the suite twice: `src/` alone, and `src/` + `pro/`. Pro must not change core behaviour except where a documented filter says it does.

**E6. The free zip makes no outbound HTTP requests.** Spec hard rule 7, verified against the actual artifact by `verify-build.php`. No licensing, no telemetry, no update pings.

**E7. Version numbers are shared.** One version across both editions, sourced from the plugin header. The release workflow fails if the git tag, the plugin header and `readme.txt`'s `Stable tag` disagree.

**E8. The Pro build carries an `Update URI` header.** Pro shares its slug with a WP.org-hosted plugin. Without `Update URI`, WordPress will "update" a paying customer's Pro install down to the free version. This is the most expensive packaging bug available in this architecture and it is silent until a customer reports lost features.

---

## How Pro hooks in

The main plugin file loads Pro only if the directory survived the build:

```php
// post-purchase-hub.php — after the core bootstrap.
if ( is_readable( __DIR__ . '/pro/bootstrap.php' ) ) {
    require_once __DIR__ . '/pro/bootstrap.php';
}
```

`pro/bootstrap.php` waits for core to be ready, then registers:

```php
add_action( 'pph_loaded', static function ( $plugin ) {
    ( new PostPurchaseHub\Pro\Bootstrap( $plugin ) )->register();
} );
```

Core must therefore expose enough surface for Pro to add a whole feature. At minimum, and all of these are already implied by the spec:

| Extension point | What Pro does with it |
| --- | --- |
| `pph_loaded` action | Bootstrap entry |
| `ActionRegistry::register()` | Adds the Return action |
| `pph_request_types` filter | Adds `return` as a first-class request type |
| `pph_action_eligibility` filter | Rules engine overrides |
| `pph_settings_tabs` / `pph_settings_fields` | Pro settings, replacing free's locked teasers |
| `pph_request_list_columns` / `_actions` | Bulk actions, saved views |
| `pph_locate_template` | Pro template overrides |
| `pph_timeline_stages` | Return-in-progress branch states |
| `pph_registered_emails` | Return lifecycle emails |
| `TrackingAdapterInterface` | Additional adapters |

Free's counterpart lives in `free/src/` and does one job: render locked teasers where Pro features would be, so the settings screen shows the same shape in both editions. Keep it small. It is the only place in the codebase where marketing lives.

---

## Two decisions to make before M01A

### Licensing layer

`docs/SPEC.md` Phase 1 recommends in-house licensing over Freemius — the revenue share and the data-collection prompts are hard to justify when WPEverest already has updater infrastructure. That recommendation stands, and this build pipeline is deliberately licensing-agnostic: whatever you choose lives in `pro/src/License/` behind an interface and never touches the free zip.

There is one real trade-off worth deciding consciously. Freemius' free-tier value — opt-in analytics, trials, in-dashboard upgrade flow — only works if the **Freemius SDK ships in the free plugin**. That directly conflicts with spec hard rule 7 (no outbound HTTP in free) and with `verify-build.php`'s free-edition checks. You can have the analytics or the rule, not both.

- **Keep the rule** (recommended): Freemius or EDD serves the Pro zip only; free stays inert and the WP.org review is trivial.
- **Take the analytics**: move the SDK into `src/`, relax E6 and the corresponding verifier checks, and document the opt-in prompt in `readme.txt`. Do this deliberately, in one commit, with the spec amended — not by quietly deleting a failing check.

### Translations

Both editions share the text domain `post-purchase-hub`. Free strings get community translations from translate.wordpress.org; Pro-only strings never will, because WP.org has no visibility into the Pro build. Ship Pro's `.mo` files in `languages/` and accept that Pro strings are yours to translate. Do not invent a second text domain to work around it — that fragments the merchant's translation setup for no gain.

---

## Runbook

**Local build**

```bash
npm run build                 # assets first; the build refuses to run without assets/build
composer build                # both editions → dist/
composer verify               # inspects the actual zips
```

Or one edition: `composer build:free`, `composer build:pro`.

**Cutting a release**

1. Bump the version in `post-purchase-hub.php` and `readme.txt` (`Stable tag`). Update the changelog.
2. Commit and push to the release branch.
3. `composer release` locally and install both zips on a scratch site. CI is a safety net, not a substitute for looking at it once.
4. Tag and publish a GitHub release. The tag may be `1.2.0` or `v1.2.0`; the workflow strips the `v`.
5. `.github/workflows/release.yml` runs on **publish** — draft releases don't trigger it, so you can stage notes safely.

**What the workflow does**

`build` → version-consistency check, lint, PHPStan, unit tests, asset build, both zips, artifact verification.
`smoke` → installs and activates the *built free zip* in wp-env and asserts `PPH_EDITION === 'free'` with no fatals. This catches the failure static analysis cannot: a core file referencing a stripped class.
`publish` → attaches both zips to the GitHub release.
`deploy-wporg` → pushes free to SVN. Gated behind a `wporg` environment; add a required reviewer so a stray tag can't ship.
`deploy-pro` → placeholder until the licensing layer is chosen.

Pre-releases skip both deploy jobs. `workflow_dispatch` builds and verifies without publishing — use it to test pipeline changes.

**Required secrets** — `SVN_USERNAME`, `SVN_PASSWORD` in the `wporg` environment. Store secrets go in the `store` environment once chosen.

---

# New milestone

Insert after M01. Everything downstream depends on the edition boundary existing before there is code to put on the wrong side of it.

## M01A — Edition Architecture & Release Pipeline

```
Implement MILESTONE 01A — Edition Architecture and Release Pipeline. Read docs/EDITIONS.md in full first.

Context: one repository produces two zips. Free ships to WordPress.org, Pro is a standalone superset for a commercial store. Directory separation, zero inline markers.

Build:
1. Directory scaffolding: free/src, free/templates, free/tests, pro/src, pro/templates, pro/assets/src, pro/tests, pro/bootstrap.php. Add the three PSR-4 mappings to composer.json.
2. In post-purchase-hub.php: define PPH_EDITION as 'free' in source (the build rewrites it), a pph_is_pro() helper reading that constant, and a conditional require of pro/bootstrap.php guarded by is_readable().
3. Fire a pph_loaded action at the end of core bootstrap, passing the container. This is Pro's only entry point.
4. pro/bootstrap.php with a Pro\Bootstrap class that registers on pph_loaded. Leave it a no-op stub with a single log line — features arrive in later milestones.
5. free/src/ with a Free\Bootstrap stub for upsell UI, registering on the same hook.
6. bin/build.php and bin/verify-build.php exactly as supplied in docs/EDITIONS.md. Do not rewrite them; if you believe one is wrong, say what and why and wait.
7. composer scripts: build, build:free, build:pro, verify, release.
8. .github/workflows/release.yml as supplied.
9. CI additions to the main workflow: run PHPUnit and PHPStan twice, once core-only and once with pro/ present. Add grep gates failing the build on PostPurchaseHub\Pro or PostPurchaseHub\Free appearing anywhere under src/.
10. .gitignore dist/.

Acceptance:
- composer build produces both zips with a post-purchase-hub/ root folder in each.
- composer verify passes every check on both artifacts.
- The free zip contains no pro/ directory, no Pro namespace anywhere including the composer classmap, and no outbound HTTP calls.
- The pro zip carries an Update URI header; the free zip does not.
- Both zips report the correct PPH_EDITION and a Version header matching the argument.
- Installing the free zip on a clean site activates without fatals.
- Removing pro/ from the working tree leaves a plugin that still boots and passes the core test suite.

Tests:
- A build smoke test asserting both zips exist and their entry lists satisfy the E1-E8 rules.
- An integration test asserting core never calls pph_is_pro() — grep src/ and fail on any hit.
- An integration test asserting pph_loaded fires exactly once with the container.

Do NOT implement any Pro feature in this milestone. The goal is the boundary and the pipeline, verified empty. A pipeline proven on a stub is worth more than one debugged later with three features already on the wrong side of the line.

Workflow per CLAUDE.md. Report, then STOP.
```

---

# Amendments to existing milestones

Apply these when you reach each one.

**M09 — Admin Request Queue.** Add: *"Expose `pph_request_list_columns`, `pph_request_list_actions` and `pph_request_bulk_actions` filters so Pro can add item-level columns, bulk approve and saved views without modifying this class. Register the request-type filter dropdown from `pph_request_types` rather than a hardcoded list."*

**M14 — Settings & Onboarding.** Add: *"Settings tabs and fields are registered through `pph_settings_tabs` and `pph_settings_fields`, not hardcoded. Where a Pro feature would appear, `free/src/` registers a locked teaser field with the same key, so the two editions render the same shape. Core must not know which is which."*

**M07 — Action Engine.** Add: *"`ActionRegistry` must be able to accept an action registered from outside `src/` with no change to core. Prove it with a test that registers a fake action from the test suite and asserts it renders and executes."*

**M17 — Release.** Replace the `.distignore` task with: *"Verify the exclusion lists in `bin/build.php` against the built zips, and report each artifact's file count and size."* Add: *"Confirm the version-consistency gate fails as designed by deliberately mismatching `readme.txt`'s Stable tag in a scratch commit."*

**M16 — Testing.** Add: *"Run the full compatibility matrix against the built free zip, not the source tree. Add one Pro-edition pass covering activation, licensing stub and Pro settings registration."*
