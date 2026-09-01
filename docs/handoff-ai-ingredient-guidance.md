# AI Ingredient Enrichment — Session Handoff

**Date:** 2026-09-01
**Branch:** `main` (local HEAD; 1 commit ahead of origin at handoff)
**Session scope:** ingredient guidance enrichment — diagnosis, merge, authoring resilience, prompt hygiene

## Opening request for the next discussion

Use this document as the source of truth. The enrichment pipeline is now operational, but the failed items below still need retrying/resetting, and item 50 is mid-run. Verify the current batch/item state before touching anything. Do not reintroduce fail-fast claim validation, and do not add an unresolved-question veto — both were deliberately removed (see "Settled decisions" and `.ai/rules/ingredient-enrichment.md`).

## Problem that started the session

Ingredient AI enrichment failed on every run after the Aug 30 guidance-feature series (`bccc0d87` → `c8edee5a`): `RuntimeException "The research provider returned an invalid ingredient result."` from `IngredientGuidanceDraftRenderer` at the `ai_guidance_authoring` stage. 24 identical failures in `storage/logs/laravel.log`; items 40–47 failed.

Root causes, in order of discovery:
1. **Schema/validator contract mismatch** — `usage_application` was a loose 3-value enum in the JSON schema while the validator required `not_applicable` on every non-usage claim; OpenAI structured outputs cannot express that conditional, so the model's valid output was rejected (line 119).
2. **Unresolved-question veto** — the renderer rejected any claim whose type matched a research question keyword (e.g. "…dispersion/solubility…soapmaking…" questions vetoed evidence-backed usage/soapmaking claims). Accepted evidence became unusable; the pipeline contradicted itself.
3. **Fail-fast validator** — every claim-level violation (phrasing miss, wrong evidence index, missing "product grade" phrase) killed the whole 10-minute run instead of dropping one claim.

## What was done

### Phase 1 — Diagnosis (no code)
- Traced the log/DB failures, identified the three causes above, verified the branch `codex/ingredient-guidance-evidence-quarantine` contained the schema `anyOf` fix (valid per official OpenAI docs; smoke-tested live once credits were restored).

### Phase 2 — Merge (`da38dc64`, pushed)
- Merged `codex/ingredient-guidance-evidence-quarantine` (7 commits: evidence partitioning, rejection codes, rerun reviewability, `anyOf` schema + prompt constraint). Resolved 9 conflicts, keeping `c8edee5a`'s text-level hardening **and** the branch's fixes.
- Key merge content: `IngredientGuidanceSchema` now uses `anyOf` (non-usage claims → `usage_application: not_applicable`; usage claims → cosmetics/soapmaking), `IngredientGuidanceEvidencePolicy::partitionCandidates` with `REJECTION_CODES`, `rejected_evidence` bookkeeping, `guidance_unresolved_questions` context.
- Prompt versions bumped: `guidance_prompt_version` v3→v4 (then v5 later), `guidance_research.prompt_version` v2→v3 (text changed without bumps on both sides).
- Test alignment commit `07b4988d`: merged pipeline now marks items `Warning` when research is disabled/evidence rejected; editorial facts no longer expose gap research (main's deliberate `c8edee5a` design); recompute test decoupled from concrete versions.

### Phase 3 — Authoring resilience (`9802ca95`, pushed)
- **Drop-and-warn renderer**: claim-level violations now omit the offending claim and add a reviewable warning (`ingredient_enrichment.warnings.guidance_claim_omitted`) instead of failing the run. Covers: wrong/missing evidence citations, usage phrasing misses (attribution/grade/basis/application), generic water & universal-emulsifier claims, fact claims outside trusted paths, section/application mismatches, combined usage rows. Hard failures remain only for draft shape, claim shape, and the 160-word rendered cap.
- **Unresolved-question veto removed entirely** (tried A "veto fact claims" — live run proved fact claims reference trusted deterministic data and must not be vetoed either). Research questions stay informational in `unresolved_questions`.
- `composer.json` dev queue listener `--timeout=300` → `--timeout=0` (enrichment jobs were being SIGKILLed at the default 60s by `queue:listen`; even 300s is too short for full runs).

### Phase 4 — Prose hygiene (`78758702`, pushed)
- **Prompt rule 9**: never name suppliers, manufacturers, brands, or product codes; generic grade descriptors only ("this product grade", "the virgin grade"). `guidance_prompt_version` → **v5**.
- **Renderer code-leak guard**: drops any claim whose sentence contains an uppercase-alphanumeric code token found in the cited evidence `source_name` (PA3019, OILBURITIEXPBR243, …).

### Phase 5 — Rules (`1b3566fc`, NOT pushed at handoff)
- `.ai/rules/ingredient-enrichment.md` gained five sections: drop-and-warn semantics, evidence authority chain, prose hygiene, stage-reuse vs refresh invalidation, queue-worker timeout trap.

### Phase 6 — Operational rescues (no code)
- Item 48 and 49 rescued through killed-worker orphans: reset to `failed`, deleted stale `cache_locks` rows (`laravel-queue-overlap:...ResearchIngredientEnrichment:<id>` and unique lock), ran the pipeline directly via tinker (bypassing the queue race) because the running `queue:listen` had the 60s default timeout.
- Item 49 (Buriti) regenerated with v5: identifiers removed from prose (was "PA3019 is a reddish…", "Farachem virgin product grade"; now "A documented product grade…", "a supplier recommends this product grade"), 156s run. Note: full-pipeline re-runs reuse completed stages regardless of prompt version — the three guidance stages had to be cleared to force re-authoring.

## Files changed (this session)

- `app/Services/IngredientEnrichment/IngredientGuidanceDraftRenderer.php` — drop-and-warn, code-leak guard
- `app/Services/IngredientEnrichment/IngredientGuidancePrompt.php` — rule 9 (no supplier/brand/code identifiers)
- `app/Services/IngredientEnrichment/IngredientGuidanceSchema.php` — `anyOf` usage-metadata constraint
- `app/Services/IngredientEnrichment/IngredientGuidanceEvidencePolicy.php` — `partitionCandidates`, `REJECTION_CODES`, usage requires recommendation-capable source kinds
- `app/Services/IngredientEnrichment/IngredientGuidanceEvidenceValidationResult.php` — new (partition result)
- `app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php`, `IngredientGuidanceStageRunner.php`, `IngredientEnrichmentPipeline.php`, `IngredientEnrichmentEvidenceVerifier.php`, `OpenAiIngredientGapResearchClient.php` — evidence partitioning, rejected-evidence tracking, context wiring
- `config/ingredient-enrichment.php` — `guidance_prompt_version` v5, `guidance_research.prompt_version` v3
- `composer.json` — dev queue listener `--timeout=0`
- `lang/en/ingredient_enrichment.php` — `warnings.guidance_claim_omitted`
- `.ai/rules/ingredient-enrichment.md` — settled decisions
- Tests: `IngredientGuidanceDraftRendererTest`, `IngredientGuidanceEvidencePolicyTest`, `IngredientEnrichmentEvidenceVerifierTest`, `IngredientGuidanceClientTest`, `IngredientEditorialClientTest`, `IngredientGuidanceRefreshJobTest`, `HybridIngredientEnrichmentPipelineTest`, `PlatformIngredientEnrichmentImportTest`, `OpenAiIngredientResearchClientTest`
- `.env` (local only, gitignored): `INGREDIENT_ENRICHMENT_MODEL=gpt-5.6-luna`, `INGREDIENT_ENRICHMENT_REASONING_EFFORT=xhigh` (was `max` — max made calls take ~10 min; xhigh ~2.5 min)

## Settled decisions (recorded in `.ai/rules/ingredient-enrichment.md`)

1. Renderer is **drop-and-warn**, never fail-fast for individual claims.
2. **Evidence authority chain**: research proposes → policy partitions → renderer checks claim↔evidence fidelity → human reviews. No unresolved-question veto.
3. Usage evidence only from `manufacturer_technical | supplier_technical | professional_reference | specialist_reference` (official sources never advise on formulation).
4. Guidance prose never names suppliers/brands/product codes.
5. Full pipeline resumes completed stages without version checks; **GuidanceRefresh** mode invalidates by prompt version.

## Verification

- Enrichment test suite green (renderer, policy, verifier, clients, refresh processor, hybrid pipeline, import): ~95+ tests in affected files; the only failure is the **pre-existing** `IngredientEnrichmentResearchPromptTest` (asserts config default `gpt-5.6-terra`; local `.env` overrides to `gpt-5.6-luna` — align `.env` or the test).
- Live: item 48 → `warning`, item 49 → `applied` (Buriti, v5 prose verified clean), smoke test with `anyOf` schema passed.
- Graphify rebuilt (7192 nodes). Note: `graphify update .` refuses to overwrite when the node count shrinks (safety check in `export.py`); use `to_json(..., force=True)` via the library for such cases.

## Current live state (check before acting — the user is active)

| Item | Batch | Status | Note |
|---|---|---|---|
| 39–43, 45, 46 | 29–33, 35, 36 | failed | 40/41 `ValidationException` (pre-merge), 42/43/46 `RuntimeException`, 45 credit-exhausted (resolved) — retryable |
| 44 | 34 | researching | **stuck** since Aug 30 (killed worker) — needs reset to failed before retry |
| 47 | 37 | applied | retried by user, worked |
| 48 | 38 | warning | rescued (batch 38 now cancelled) |
| 49 | 39 | applied | Buriti; v5 regeneration applied |
| 50 | 40 | researching | Buriti **guidance_refresh** launched by user (v5) — running; verify it completes |

Queue: listener running with `--timeout=960` (safe; job's own 900s pcntl timeout governs, pcntl available). Queue idle besides item 50's job.

## Open items / next steps

1. **Push `1b3566fc`** (rules docs; HEAD is 1 ahead of origin at handoff).
2. Retry failed items **40–43, 45, 46**; reset stuck item **44** (status → failed, clear stale `cache_locks` if any, then retry).
3. Verify **item 50** (Buriti guidance-refresh) completes — it is the first live GuidanceRefresh run with v5.
4. **Production**: deploy requires the supervisor worker config (`queue:work --timeout=0`, `stopwaitsecs=1200`), the same `.env` values (luna/xhigh), and `INGREDIENT_ENRICHMENT_JOB_TIMEOUT=1800` if full runs keep brushing the 900s cap (item 49 took 851s once; xhigh runs are ~2.5 min).
5. The user's parallel codex work (`production-bench-inventory-ux` merge `643918a3` and follow-ups) is interleaved in the history — unrelated to this session, do not touch.

## Related docs

- `.ai/rules/ingredient-enrichment.md` — the authoritative settled decisions
- `docs/superpowers/plans/2026-08-30-broader-ingredient-guidance-research.md` (historical plan)
- `docs/superpowers/plans/2026-08-29-workspace-ingredient-guidance-overrides.md` (historical plan)
