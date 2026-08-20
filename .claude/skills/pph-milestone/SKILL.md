---
name: pph-milestone
description: Run one development milestone of the Post-Purchase Hub WooCommerce plugin end to end — confirm scope, inspect, plan, implement, test, review, report, stop. Use when the user says "implement milestone NN", "run milestone NN", "next milestone", or names a milestone from docs/MILESTONE-PROMPTS.md. Also use when the user asks to review, audit, or hand off milestone work on this plugin.
---

# Post-Purchase Hub — Milestone Runner

Executes a single milestone of a commercial WooCommerce plugin under a fixed six-step loop, then stops for human approval.

## Required reading before any action

1. `CLAUDE.md` — the 15 hard rules, architecture, standards, escalation triggers. Non-negotiable.
2. `docs/SPEC.md` — authoritative scope, DB design, security design, acceptance criteria.
3. `docs/MILESTONE-PROMPTS.md` — the specific milestone block.
4. `docs/HANDOFF.md` if it exists — prior session state.

If the requested milestone's dependencies are not complete, say so and stop. Dependency order is in `docs/MILESTONE-PROMPTS.md`.

## The loop

### Step 0 — Confirm scope
Restate the milestone's acceptance criteria verbatim. Then flag anything invalidated by work already in the repo. This step exists because the likeliest failure on a heavily planned project is faithfully building a milestone that stopped making sense three milestones ago. If something is invalidated, stop and ask.

### Step 1 — Inspect
Read every file the milestone will touch. Read the relevant WooCommerce core source and name it. Never assume file contents; never assume an earlier milestone delivered what it promised — verify.

### Step 2 — Plan
State the change file by file before editing anything. Include: new files, modified files, new hooks, new filters, new tests. If the plan requires anything on the escalation list in `CLAUDE.md` (new dependency, table, cron event, capability, REST route, or anything touching refunds, gateways, uploads or sessions), stop and ask.

### Step 3 — Implement
Only what the milestone requires. No opportunistic refactors. No unrelated file edits. No "while I'm here."

### Step 4 — Test
```
composer lint && composer analyse && composer test:unit
composer test:int              # run with HPOS=1 and HPOS=0
npm run build
```
Plus the milestone's named tests. Use Playwright MCP for browser tests, targeting `data-pph-*` attributes only — never theme selectors.

### Step 5 — Review
Re-read the diff against all 15 hard rules, answering each explicitly. Then against the spec's Phase 8 threat table for anything touching order data, requests, tokens or REST.

### Step 6 — Report, then stop
```
## Completed
## Files Changed          (path — one line why)
## Tests                  (added, passing, coverage delta per namespace)
## Deviations             (anything differing from docs/SPEC.md, and why — MANDATORY)
## Issues Found           (bugs, spec gaps, ambiguities discovered)
## Technical Debt         (what was deliberately left)
## Next Milestone         (name it; do not start it)
```
Then stop. Do not begin the next milestone under any circumstances, including if the user's original message listed several.

## Milestone-specific cautions

- **M04 (Rendering)** and **M11 (Guest lookup)** are the two highest-risk milestones. On both, run the pre-flight investigation prompt from `docs/MILESTONE-PROMPTS.md` first and get approval on the plan before implementing.
- **M06 (Security layer)** everything downstream depends on. Prefer asking over guessing; a wrong abstraction here becomes an IDOR later.
- **M09 (Admin queue)** must never reach a refund API. If the implementation trends that way, stop.
- **M15 (Audit)** is adversarial toward this repo's own earlier work. Assume M08 and M11 contain mistakes and go looking.

## Failure modes to avoid

- Reporting a milestone complete with `Deviations: none` when the implementation differs from the spec.
- Passing tests written to match the implementation rather than the acceptance criteria.
- Hiding an eligibility or ownership check in the UI layer only.
- Direct `postmeta` access because CRUD was inconvenient.
- Continuing past Step 6 because the next milestone seemed obvious.
