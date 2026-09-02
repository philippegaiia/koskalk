# AI Ingredient Enrichment — Full Session Handoff (for second evaluation)

**Date:** 2026-09-02
**Branch:** `main` — HEAD `863600ef`, clean, pushed (0 ahead of origin)
**Purpose:** complete record of the ingredient-guidance enrichment overhaul for an independent second evaluation (OpenAI 5.6 Sol). Everything the reviewer needs to judge the work, reproduce it, and probe its weak spots.

---

## 1. Executive summary

The AI ingredient enrichment pipeline was failing on **every run** after an Aug 30 feature series. Over the session we (a) merged the divergent feature branch that contained the original bug's fix, (b) converted the strict fail-fast authoring validator into a **drop-and-warn** renderer, (c) iterated the authoring prompt **v5 → v11** and research prompt **v3 → v6** to produce natural formulator prose, (d) reworked **identity resolution** (discovery variants, substance-form disambiguation, an identity completeness gate, FDA-style US declarations, corroborated technical CAS/EC), and (e) aligned queue/job timeouts that were killing long jobs. The pipeline now completes end-to-end on live ingredients with reviewable warnings. ~200 enrichment tests pass; the only failing test is a pre-existing local `.env` model-name assertion unrelated to the work.

## 2. The problem that started the session

Every enrichment run failed after the Aug 30 guidance series (`bccc0d87`→`c8edee5a`): `RuntimeException "The research provider returned an invalid ingredient result."` from `IngredientGuidanceDraftRenderer` at the `ai_guidance_authoring` stage (24 identical failures; items 40–47 failed). Three stacked causes:

1. **Schema/validator contract mismatch** — the JSON schema declared `usage_application` as an unconditional 3-value enum while the validator required `not_applicable` on every non-usage claim. OpenAI structured outputs cannot express conditionals, so valid model output was rejected (line 119).
2. **Unresolved-question veto** — the renderer rejected evidence-backed claims whose topic matched a research question keyword; accepted evidence became unusable.
3. **Fail-fast validator** — any single claim-level violation (phrasing, evidence-index mismatch) killed a 10-minute run instead of dropping one claim.

The branch `codex/ingredient-guidance-evidence-quarantine` (7 commits, forked at `8b600964`) contained the schema `anyOf` fix. It was merged with conflict resolution.

## 3. Commits (enrichment work only; parallel inventory work from another agent is interleaved in history)

| Commit | What |
|---|---|
| `da38dc64` | **Merge** `codex/ingredient-guidance-evidence-quarantine` (654b614d, 8afc3440, 3c79f355, 59a2484d, 7190eb43, 564be4d0, bd722abd): evidence partitioning, rejection codes, rerun reviewability, `anyOf` usage-metadata schema, prompt constraint. 9 conflicts resolved keeping `c8edee5a`'s hardening + branch fixes |
| `07b4988d` | Test alignment: merged pipeline marks `Warning` when research disabled/evidence rejected; editorial no longer exposes gap research; recompute test decoupled from versions |
| `9802ca95` | **Drop-and-warn renderer**: claim-level violations omit the claim + reviewable warning; hard fails only for draft shape, claim shape, word cap. Unresolved-question veto removed entirely. `composer.json` dev queue listener `--timeout=0` |
| `78758702` | Prompt v5: no supplier/brand/product-code identifiers in prose + renderer code-token leak guard |
| `1b3566fc` | `.ai/rules` — record authoring decisions |
| `ccc447ec` | First handoff doc |
| `3439da75` | **Codex revision (uncommitted when reviewed)**: research v5 (practical relevance, 5-search cap `max_tool_calls`, patents blocked, hobbyist = `specialist_reference`), authoring v6 (natural formulator voice, baseline preservation via `current.canonical.info_markdown` fact claims, no forced attribution phrases), renderer matching (baseline containment check, usage-text relaxation, 2000-char ceiling), config (240 words / 2000 chars) |
| `f1f4d608` | Prompt v7: ban taxonomy statements ("classified as… within the … category") + renderer guard |
| `53daed0d` | Prompt v8: overview must state identity + physical form from facts; baseline sentences verbatim only |
| `acbf6446` | Prompt v9: natural material terms (fixed oil/vegetable oil/butter…) instead of lab phrasing; plain grade qualifiers ("the refined grade") |
| `6e088075` | Prompt v10: ban the full evidence-layer adjective family (cited/documented/specified/referenced/supplied/reported/listed/verified) |
| `e2a8f164` | Research v6: soapmaking usage evidence shape (`usage`/`soapmaking`/`soap_oils`/`formulation_recommendation`); job timeout default 2000s; `DB_QUEUE_RETRY_AFTER` 2100 (was 360 < 900) |
| `5643d356` | `.env.example` alignment |
| `ebbb95ff` | Identity slice 1a: search variants (parenthetical strip, kernel→seed) shared by CosIng + FDA; substance-form-aware matching (oil input never matches acid/extract/ester/fraction records) |
| `2062ed1d` | Identity slice 1b: kernel/seed + parenthetical **name equivalence** in the matcher (`exact_inci_variant`) |
| `9cad51aa` | Identity slice 2: **completeness gate** — no registry candidate confirmed → all guidance stages persisted `skipped` (reason `identity_unresolved`), item `Failed`/`identity_unresolved` with conflict reasons; zero tokens spent on unverified identity |
| `985ee936` | Identity slice 3: **US declarations "Common (Plant Name) Form"** (Coconut (Cocos Nucifera) Oil; botanical from verified INCI, product-part words stripped; FDA sweet-almond example override) |
| `a643a168` | `.ai/rules` — identity rules |
| `71c3d5ee` | Identity slice 4: **corroborated technical CAS/EC** — research reports identifiers only when a source prints them; promoted only when ≥2 independent consulted sources agree; new `approved_secondary` source tier + `source_confirmed` provenance; never overrides official |
| `ca9494bd` | Prompt v11: baseline `Typical use level` sentences are preserve-unless-superseded |
| `863600ef` | **jsonb fix**: PostgreSQL `jsonb` canonicalizes key order → strict comparison of persisted research stage failed on cache reuse; now compared order-insensitively + regression test |

## 4. Architecture decisions (evaluate each)

1. **Evidence authority chain**: research proposes → `IngredientGuidanceEvidencePolicy` partitions (vocabulary enums, usage metadata, consulted-URL requirement, blocked domains incl. patents) → renderer enforces claim↔evidence fidelity only → human reviews in the admin UI. The renderer never judges whether evidence is true.
2. **Drop-and-warn, not fail-fast**: individual claim violations (wrong/missing citations, phrasing, taxonomy leaks, code leaks, fact paths outside trusted catalogue) omit that claim + a reviewable warning. Hard failures remain only for draft shape, claim shape, and the rendered 240-word / 2000-visible-char caps. Rationale: real model output trips at least one rule on a large share of runs; failing the run discarded otherwise-good guidance.
3. **No unresolved-question veto**: research questions are informational (they flow into the item's `unresolved_questions`); accepted evidence and trusted catalogue facts are the authority.
4. **Baseline preservation**: applied guidance (`current.canonical.info_markdown`) is the editorial baseline; useful sentences preserved verbatim as fact claims (containment check enforces verbatim); use-level sentences preserve-unless-superseded (v11). Research fills gaps; it does not regenerate.
5. **Natural prose**: no evidence-schema terminology in catalogue copy (v7–v10 bans); grade qualification only when it materially changes use.
6. **Usage evidence source kinds**: only `manufacturer_technical | supplier_technical | professional_reference | specialist_reference` may support usage claims (official/scientific sources never advise formulation). Soapmaking charts from specialist/hobbyist sources are valid usage evidence with the exact row contract (`usage`/`soapmaking`/`soap_oils`).
7. **Identity search expands, matching is form-aware**: search-time variants only (parentheticals, kernel→seed); the matched registry record stays authoritative. An oil input never matches an ACID/extract/ester/fraction sibling (cottonseed case). Kernel/seed and parentheticals are name-equivalent; material modifiers (hydrogenated, unsaponifiables) are never fuzzy-matched.
8. **Identity completeness gate**: no FDA GSRS nor CosIng candidate confirmed → stop before guidance (stages `skipped`), item `Failed` with `failure_code=identity_unresolved` + reviewer-visible conflict reasons. Reads as unresolved, never unchanged.
9. **US declaration convention** (owner-specified): `Common (Plant Name) Form` — e.g. `Coconut (Cocos Nucifera) Oil`, `Beef (Adeps Bovis) Tallow`. Plant name from the verified INCI with product-part words (oil/seed/kernel/fruit/…) stripped; display name supplies the common part when the registry common name is itself latin.
10. **Corroborated technical CAS/EC**: identifiers official records lack may enter only with ≥2 independent consulted sources, tagged `approved_secondary`/`source_confirmed` with all corroborating URLs; never overrides an official identifier.
11. **Queue/ops**: jobs run up to their own `job_timeout_seconds` (2000s default; pcntl + failOnTimeout); provider calls up to 600s with 2 retries; `DB_QUEUE_RETRY_AFTER` must exceed the job timeout (2100). `queue:listen` without `--timeout` kills jobs at the 60s default — use `--timeout=0` or ≥ job timeout.

## 5. Prompt evolution

- v5: identifiers out of prose (+ code-token guard)
- v6: natural formulator voice, baseline preservation, no forced attribution phrases (codex revision, reviewed + committed)
- v7: ban classification/taxonomy statements
- v8: guarantee a substantive overview from identity/physical-form facts; verbatim baseline copying
- v9: natural material terms; plain grade qualifiers
- v10: ban the evidence-layer adjective family (cited, documented, specified, referenced, supplied, reported, listed, verified)
- v11: baseline `Typical use level` sentences preserve-unless-superseded
- Research v5→v6: practical relevance over prestige; 5-search hard cap; patents excluded; soapmaking usage row contract explicit.

## 6. Verification evidence

- **Tests**: 143 in the core enrichment/identity/refresh files pass (814 assertions) at last full run; broader enrichment suite ~200 tests green. The single failure is `IngredientEnrichmentResearchPromptTest` asserting config default model `gpt-5.6-terra` while the local `.env` sets `gpt-5.6-luna` (pre-existing, environment-only).
- **Live trials** (all real OpenAI calls, Luna xhigh unless noted):

| Ingredient | Mode | Result | Notes |
|---|---|---|---|
| Buriti Oil | fill_missing | applied | identity OK; guidance iterated to clean v5 prose (identifiers removed) |
| Apricot Oil | guidance_refresh | warning | natural voice benchmark; 5m01s, 61k in / 29k out |
| Beef Tallow | fill_missing | warning | full identity (CAS/EC/UNII), soapmaking evidence gap initially, then fixed |
| Cottonseed Oil | fill_missing | (rejected by owner) | **wrong-substance match** (COTTONSEED ACID) — the motivating case for form-aware matching |
| Marula Oil | fill_missing | applied | kernel→seed identity resolution; use levels found |
| Marula Oil | guidance_refresh | warning | v10: good prose but dropped baseline use levels; v11: cosmetics range preserved, soapmaking range not (deferred v12) |

- **jsonb bug**: reproduced live (cache-reuse LogicException on PostgreSQL; undetectable on SQLite) and fixed with an order-insensitive comparison + regression test simulating jsonb key canonicalization.

## 7. Current state

- Config: `guidance_prompt_version` `ingredient-guidance-v11`; `guidance_research.prompt_version` `ingredient-guidance-research-v6`; `maximum_words` 240; `maximum_characters` 2000; `maximum_tool_calls` 5; job timeout default 2000 (env `INGREDIENT_ENRICHMENT_JOB_TIMEOUT`); local `.env` `MODEL=gpt-5.6-luna`, `REASONING_EFFORT=xhigh`, `DB_QUEUE_RETRY_AFTER=2100`.
- Queue listener: running with `--timeout=960` (safe; job's own pcntl timeout governs).
- DB: batches 45–58 exercised; item 44 stuck `researching` from an Aug 30 killed worker (needs manual reset); items 40–46 failed from old runs (retryable).
- Rules: `.ai/rules/ingredient-enrichment.md` records all settled decisions (drop-and-warn, authority chain, hygiene, stage reuse vs refresh invalidation, queue timeout trap, identity variants/form-awareness/gate, US declaration convention, corroborated CAS/EC, jsonb comparison).

## 8. Deferred items & known trade-offs (probe these)

1. **v12**: every baseline `Typical use level` sentence (cosmetics AND soapmaking) should survive refreshes unless superseded by application-specific evidence. v11 only preserved the cosmetics range on the Marula refresh.
2. **Hobbyist corroboration is a rule, not an enforcement**: the rules require two independent specialist sources or consistency with deterministic data for important practical claims — not implemented in the policy (contained by renderer guards today).
3. **Full-pipeline stage reuse has no prompt-version invalidation** (only `GuidanceRefresh` mode invalidates): regenerating a full run in place requires clearing the guidance stages.
4. **`retry_after` vs transport retry arithmetic**: 600s × up to 3 attempts = 1800s worst case vs 2000s job timeout — bounded but tight; the pipeline is resumable so a killed run wastes a partial attempt rather than corrupting anything.
5. **`max_tool_calls=5` vs reported searches**: the item's `web_search_calls` counter can exceed 5 because one tool call returns several sources; the cap is on tool calls, the counter counts sources.
6. **Identity gate has no "manual override" path** beyond editing the ingredient and re-running; niche ingredients with no registry record will always gate.
7. **Empty section headings** (`## Overview` with no body) can still render when all claims in a section are dropped — validators require the headings.
8. Old failed/stuck items (40–46, 44) are housekeeping, not code issues.

## 9. How to run / inspect

```bash
php artisan queue:listen --queue=media,default --tries=1 --timeout=0   # or --timeout=960
php artisan test --compact tests/Feature/HybridIngredientEnrichmentPipelineTest.php  # pipeline incl. identity
php artisan test --compact tests/Feature/IngredientGuidanceRefreshJobTest.php        # refresh incl. jsonb reuse
# Start a full enrichment or guidance refresh from the admin UI (Ingredient Enrichment Batches);
# identity gate + research + authoring + localization run under the queue.
```

## 10. Questions for the second evaluator

1. Is drop-and-warn the right trade-off vs the original strict validation philosophy, given the review gate?
2. Are the prompt bans (v7–v10) the right way to achieve natural prose, or do they over-constrain?
3. Is baseline preservation + preserve-unless-superseded the correct refresh semantics?
4. Does the corroborated CAS/EC threshold (≥2 consulted sources) match the product's trust bar?
5. Is the identity gate's strictness (no registry candidate → stop) acceptable, or should there be an explicit "unresolved but proceed" mode?
6. Are there remaining failure modes in the renderer/policy interplay that the tests do not cover (the jsonb bug escaped because tests run on SQLite — what else is driver-dependent)?
