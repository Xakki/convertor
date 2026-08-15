# Epic orchestration plan

> **For Hermes:** execute one EPIC at a time; preserve its ordered child-card lifecycle and return only verified handoffs.

**Goal:** Run the Convertor Kanban through a long-lived Sol teamlead, Sol EPIC leads, Terra implementation workers, and narrowly scoped Luna utility workers.

**Architecture:** `teamlead` owns portfolio order and user decisions. It launches one `epic-lead` for the active EPIC. The EPIC lead uses Terra for implementation and may launch Luna only as an isolated process for fully specified, low-risk utility work. This keeps Hermes delegation depth at two while retaining model control by profile.

## Profiles

| Profile | Model | Responsibility | Reasoning policy |
|---|---|---|---|
| `teamlead` | Sol | EPIC order, dependencies, user decisions, handoff acceptance | High only for architecture/security; medium for routing |
| `epic-lead` | Sol | One EPIC, reuse/compact/fresh-executor decision, reviewed handoff | Medium by default; high for auth, migrations, SSRF, queue and sandbox |
| `implementer-terra` | Terra | One atomic backend/frontend/worker/infra card plus scoped tests | Medium; escalate ambiguous design to EPIC lead |
| `utility-luna` | Luna | Inventory, fixture, mechanical edit, one focused command or report | Low; never decides architecture or lifecycle |

## Delegation limits

- `teamlead`: `max_spawn_depth=2`, `max_concurrent_children=1`, delegated EPIC leads use Sol.
- `epic-lead`: `max_spawn_depth=2`, `max_concurrent_children=2`, delegated implementation children use Terra.
- Do not make Terra a nested orchestrator in normal operation. Luna is launched as a separate profile process only when its task is fully specified and independent; this avoids a third delegation level.

## Execution order

Run EPIC cards numerically and do not start a higher EPIC until the active EPIC is in `ready` and the teamlead has accepted its handoff:

1. EPIC-001 through EPIC-007: foundations, settings/API/browser backend.
2. EPIC-008 through EPIC-011: worker implementations.
3. EPIC-012 through EPIC-015: browser infrastructure/runtime/frontend and public API frontend.
4. EPIC-016 documentation, then EPIC-017 contract QA.

Before starting an EPIC, verify the ordered child cards and their explicit dependencies; a card with a predecessor not yet `ready` stays blocked.

## EPIC lead routine

1. Read the EPIC card and every child card once; create a short decision digest in the EPIC Execution Log.
2. Assign the first unblocked child to Terra.
3. Reuse the current Terra executor only for an adjacent card in the same ownership zone and same contract.
4. Compact or replace Terra at a zone boundary, after a parked task, or when prior investigation is no longer relevant.
5. Launch Luna only for a read-only inventory, deterministic fixture, mechanical transformation, or a single scoped verification command. Luna may not edit shared contracts, run lifecycle moves, commit, or handle secrets.
6. Run independent Terra review for auth, ownership, migrations, queue, proxy/SSRF, sandbox, or cross-layer contract changes.
7. Return to teamlead: card stage, changed paths, scoped checks, review verdict, dependency status, and parked decisions.

## Launch

Start the durable coordinator manually in a terminal:

```bash
cd /home/xakki/convertor
hermes --profile teamlead --tui
```

Give it this initial instruction:

```text
Act as the long-lived Convertor portfolio teamlead. Read .claude/kanban/todo/EPIC-001-backend-gateway.md and its child cards. Execute only one EPIC at a time. Launch an epic-lead profile for the active EPIC, require a short decision digest and verified handoff, and do not start the next EPIC before the current one is ready. Use Terra for atomic implementation and Luna only through a separate utility-luna process for fully specified low-risk tasks. Never push or move cards to done without user approval.
```

The teamlead may launch an EPIC lead as an isolated process when model/profile selection matters:

```bash
hermes --profile epic-lead --worktree chat -q "Execute EPIC-001 in /home/xakki/convertor. Follow the Kanban card order, delegate atomic implementation to Terra, and return only verified results."
```

Use `utility-luna` only for one-shot, non-interactive tasks, preferably in a disposable worktree or read-only mode:

```bash
hermes --profile utility-luna chat -q "In /home/xakki/convertor, list only the test files covering <symbol>; do not edit files. Return paths and one-line relevance."
```

## Verification and stop rules

- Before each EPIC: `git status --short`, Kanban card prerequisites, and current branch.
- Before each child handoff: scoped test/lint/build and independent review where required.
- Before an EPIC handoff: integration tests stated by the EPIC card; record unavailable gates honestly.
- Never run destructive production cleanup without the explicit card gate, current-state check, backup and user approval.
- Never use `utility-luna` for auth, security, migration, queue routing, browser sandbox/SSRF, lifecycle moves, commits, or merge decisions.
