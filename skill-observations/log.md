# Skill Observation Log

Observations captured during task-oriented work.

**Status key:** OPEN = not yet actioned | ACTIONED (YYYY-MM-DD) = skill updated/created | DECLINED (YYYY-MM-DD) = user decided not to pursue — resolved statuses always carry their resolution date

---


## 2026-08-03

### Observation 1: Kanban side-find leftovers — fix-now vs grooming conflict with reviewer advice

**Status:** OPEN
**Date:** 2026-08-03
**Session context:** CNV-55 Alpine double init; reviewer recommended grooming for oos leftovers
**Skill:** ai-agents-skills:kanban
**Type:** open-source
**Phase/Area:** Side-found / user-suggested fixes triage

**Issue:** Reviewer digest said "file grooming for leftovers" while kanban triage says fix-now when cplx ≤ 3 OR standard-subagent-sized. Team-lead followed triage (fixed leftovers in-branch) and skipped grooming — correct per skill, but reviewers aren't instructed to apply the same triage table, so advice drifts.

**Suggested improvement:** In kanban reviewer / hand-off prompts (or reference.md wrap-up), add one line: before recommending a new grooming card, apply the fix-now vs grooming triage (cplx ≤3 / in-scope / subagent-sized → fix-now, not groom).

**Principle:** Review recommendations for "file a follow-up card" must reuse the same side-find triage as implementers, or the board accumulates low-cplx cards that should have been fixed en-route.

### Observation 2: Nested Grok→composer delegation works for admin split

**Status:** OPEN
**Date:** 2026-08-03
**Session context:** CNV-61 admin page split; team-lead delegates to Grok agents that may spawn composer for chores
**Skill:** teamlead / lean-delegation
**Type:** internal
**Phase/Area:** model routing / nested delegation

**Issue:** User explicitly required Grok for team roles and composer for nested simple subtasks. Layout and pages agents completed independently with clean digests; layout-dev self-corrected a bad commit that accidentally staged kanban files.

**Suggested improvement:** In teamlead or lean-delegation, document optional nested-delegation pattern: parent agent (standard/judgment) may spawn chore/composer for inventory and mechanical scaffolds; parent must still own commit staging hygiene (only zone files).

**Principle:** When the user pins a two-tier model chain (team model → chore model), bake the nested spawn rule into each teammate prompt and remind implementers to unstage out-of-zone files before commit.

### Observation 3: QA agent OK despite phpstan OOM retry

**Status:** OPEN
**Date:** 2026-08-03
**Session context:** CNV-61 QA gate via make phpstan/cs-check
**Skill:** qa-check / teamlead make-batch silent-OK
**Type:** internal
**Phase/Area:** verification / docker memory

**Issue:** First phpstan run exited 137 (OOM) under 512MB php container; QA agent retried and still returned clean OK without dumping logs to team-lead.

**Suggested improvement:** Document in convertor qa notes or Makefile ## help that phpstan may need a retry/higher memory on constrained hosts; silent-OK agents should treat 137 as retryable once before escalating.

**Principle:** Treat OOM/137 on static analysis as a transient infra failure for one automatic retry, not as an immediate product defect escalation.

### Observation 4: Viewer gated by category breaks AI TTS audio

**Status:** OPEN
**Date:** 2026-08-03
**Session context:** text→wav no audio player; fixed resolveViewerType by output ext/MIME
**Skill:** New skill candidate: convertor-result-viewer / or note in frontend skill
**Type:** internal
**Phase/Area:** conversion result preview

**Issue:** Player visibility used matrix pair category; AI TTS is document+ai with audio output, so UI hid the existing audio player.

**Suggested improvement:** Document in a small project note/skill: result viewers must key off output MIME/extension (and result_mime), never source-pair category alone.

**Principle:** Preview/player gates should follow the artifact type the user receives, not the routing category used to process the job.
