# AI Ingredient Enrichment — Fixes 1–9 Handoff (for third evaluation)

**Date:** 2026-09-02
**Reviewed against:** `main` @ `5d599f22` **plus an uncommitted working tree** carrying fixes 1–9.
**Purpose:** complete record of the nine fixes produced from the adjudication of two independent
evaluations (Sol — OpenAI 5.6 Sol; Codex — GPT-5.6 medium), for a fresh Codex pass that verifies
each fix against source, reproduces the behavioural ones, and probes what is still weak.
**Inputs:** `docs/handoff-ai-ingredient-guidance.md` (second-evaluation handoff),
`docs/ai-enrichment-evaluation-adjudication.md` (my adjudication of Codex's evaluation; contains
the numbered fix list), `.ai/rules/ingredient-enrichment.md` (settled decisions).

> The evaluator must review the **working tree**, not HEAD: all nine fixes are uncommitted on top
> of `5d599f22` (`git status` lists them; `git diff` shows the code delta). The adjudication doc's
> verdict was "Do not ship" until fixes 1–9 landed; this handoff is the record that they have.

---

## 1. Executive summary

Two independent reviews of the enrichment pipeline produced overlapping findings: identity retry
was a deterministic no-op contradicting a documented guarantee; corroboration counted URLs instead
of independent publishers; official-precedence was unenforced; FDA discovery stopped at the first
non-empty candidate batch; the handoff doc contradicted its own queue rule; v9/v10 prose bans were
prompt-only; US declarations leaked marketing qualifiers; and the second review (Codex) missed the
two worst defects — a `formToken()` last-position heuristic that silently matched the wrong
substance, and a renderer rule that deterministically dropped preserved baseline soapmaking use
levels (the "v12" item the handoff had mis-framed as a prompt deferral). The review baselines
themselves were wrong in both directions (test-model drift).

All nine adjudicated fixes are now implemented with regression tests. Full suite: **2880 passed,
0 failed, 25 skipped** (52,340 assertions). Three pre-existing unrelated failures found during the
baseline work were also fixed so "green" means green.

## 2. Mapping: finding → fix

| # | Finding (source) | Fix |
|---|---|---|
| 1 | Test baseline wrong in every prior pass — `.env` model luna leaks into tests, five files pin terra | `phpunit.xml` pins `INGREDIENT_ENRICHMENT_MODEL=gpt-5.6-terra`; suite is hermetic; local `.env` luna still governs real runs |
| 2 | Handoff lines 93/111 call `--timeout=960` "safe" while Decision 11 requires `--timeout=0` or ≥ 2000s | Doc corrected in `docs/handoff-ai-ingredient-guidance.md` |
| 3 | Identity retry is a no-op (Codex P1): identity stages persist `completed`, `retryableFromStage()` returns the first `skipped` guidance stage, `.ai/rules:62` guarantee unimplemented | `IngredientEnrichmentBatchItem::retryableFromStage()` returns `IdentityPreparation` when `failure_code === 'identity_unresolved'` → retry invalidates **all** stages and re-runs live identity |
| 4 | `formToken()` last-token-of-concatenation (missed by Codex, proven during adjudication): "Shea Oil" + INCI "…BUTTER" silently matches the Butter; with only the oil candidate it gates | `IngredientIdentityMatchService::select()` derives the input form from the display name first, INCI only as fallback — never from the concatenation |
| 5 | US declaration leaks editorial qualifiers (Codex, reproduced): `Organic Virgin Marula (Sclerocarya Birrea) Oil` tagged `supported` + 21 CFR 701.3 | `UsIngredientDeclarationService` strips editorial phrases/words (organic, virgin, cold pressed, …) from the display-derived common part; material adjectives (sweet, bitter) preserved |
| 6 | FDA discovery breaks on first non-empty candidate batch (Codex P1) | `OpenFdaSubstanceClient::lookup()` traverses variants until a candidate is **named exactly** by a queried term; sibling phrase-hits no longer end discovery; bounded by the distinct term list |
| 7 | Corroboration counts URLs; official-precedence unfenced (Codex P1s) | New `CorroboratedIngredientIdentifierService` (extracted from the 1,019-line pipeline): independence = distinct www-stripped hosts; value-level official precedence (owner-confirmed); `is_primary=false` secondaries |
| 8 | v12 is a code defect, not a deferral (adjudication): renderer dropped `soapmaking` + `usage` + `fact` baseline claims | `IngredientGuidanceDraftRenderer` allows `usage`+`fact` in Soapmaking when the claim is a verbatim preserved baseline (`fact_paths === ['current.canonical.info_markdown']`), mirroring the formulation_use rule |
| 9 | `config/queue.php` `retry_after` default 960 < 2000s job timeout (latent trap) | Fallback default derived from `INGREDIENT_ENRICHMENT_JOB_TIMEOUT` (2000) + 100 → 2100 |

## 3. Fix details and verification

### Fix 1 — `phpunit.xml` (one line)

`<env name="INGREDIENT_ENRICHMENT_MODEL" value="gpt-5.6-terra"/>` added to the test env. The suite
previously inherited the local `.env` luna override (no `.env.testing`), so the five files
asserting the terra request model failed **3 failed / 37 passed**; now **40 passed**. Config
default and `.env.example` (both terra) are consistent; `.env` keeps luna for real calls.

### Fix 2 — `docs/handoff-ai-ingredient-guidance.md`

Lines 93 and 111 now require `--timeout=0` (or ≥ the 2000s job timeout) and state explicitly that
`--timeout=960` would kill the worker mid-call at 960s < 2000s, orphaning the job.

### Fix 3 — `app/Models/IngredientEnrichmentBatchItem.php`

```php
if ($this->failure_code === 'identity_unresolved'
    && ! ($effectiveMode?->isGuidance() ?? false)) {
    return IngredientEnrichmentResearchStage::IdentityPreparation;
}
```

`RetryIngredientEnrichmentFailures` then calls `invalidateFrom(IdentityPreparation)`, which clears
every persisted stage, so the retried job re-runs FDA/CosIng lookups instead of replaying the
cached empty match. Guidance modes are excluded (they never produce `identity_unresolved`).
Tests: new unit-style boundary test in `IngredientEnrichmentPipelineTest` (item with completed
identity + skipped guidance stages → `IdentityPreparation`) and an end-to-end test in
`IngredientEnrichmentRetryTest` (retry clears `research_stages` to `[]`, dispatches
`ResearchIngredientEnrichment`).

### Fix 4 — `app/Services/IngredientEnrichment/IngredientIdentityMatchService.php`

```php
$inputForm = $this->formToken($displayName) ?? $this->formToken($inciName);
```

Reproduced regression (both candidates present, CAS shared): previously selected
`BUTYROSPERMUM PARKII BUTTER` with zero conflicts for display "Shea Oil"; now selects
`BUTYROSPERMUM PARKII OIL` (exact CAS) or gates with a visible material-form conflict when no
identifier arbitrates. Two new tests in `IngredientIdentityMatchServiceTest`. Rule updated:
"material form comes from the catalogue display name first… never from the last form token of a
display+INCI concatenation".

### Fix 5 — `app/Services/IngredientEnrichment/UsIngredientDeclarationService.php`

`EDITORIAL_PHRASES` (extra virgin, cold pressed, cosmetic grade, fair trade, …) and
`EDITORIAL_QUALIFIERS` (organic, virgin, refined, unrefined, raw, pure, natural, premium,
certified, …) are stripped from the display name before it supplies the common part of the
FDA-style label. Reproduction now yields `Marula (Sclerocarya Birrea) Oil`. Regression tests:
qualifier strip; `Organic Sweet Almond Oil` still composes to `Sweet Almond (Prunus Amygdalus
Dulcis) Oil` (material adjectives survive; the FDA canned example is untouched). All existing
declaration tests (coconut, cottonseed, beef tallow) pass unchanged.

### Fix 6 — `app/Services/IngredientEnrichment/Sources/OpenFdaSubstanceClient.php`

Discovery now accumulates candidate batches and stops only when `batchNamesTermExactly()` finds a
candidate whose whole name equals the queried term (case-insensitive). GSRS name searches match
phrases inside longer synonyms, so a sibling record (`HYDROGENATED ARGANIA SPINOSA KERNEL OIL`
returned for the phrase `ARGANIA SPINOSA KERNEL OIL`) no longer suppresses the later variant
holding the exact record; the matcher still performs the authoritative form-aware selection.
Traversal is bounded by the distinct term list (original term, parenthetical-stripped,
kernel→seed, then identifier values, then display variants). Regression test drives a three-call
sequence (sibling phrase-hit → non-matching seed variant → exact `ARGAN OIL` display hit) and
asserts both candidates reach the result with the exact-name hit terminating discovery.

### Fix 7 — `app/Services/IngredientEnrichment/CorroboratedIngredientIdentifierService.php` (new)

Extracted verbatim-behaviour-plus-fixes from the pipeline's `mergeCorroboratedIdentifiers()`
(removed; constructor-injected; unused enum imports cleaned):

- **Host-level independence**: a value enters only when ≥2 distinct consulted hosts print it
  (`authorityHosts()` — www-stripped, lowercase, parseable only). Two pages on one domain are one
  authority; URLs without a host never count.
- **Value-level official precedence (owner-confirmed 2026-09-02: "a substance can have a few
  accepted CAS numbers… we keep all and define a primary one")**: an accepted value is skipped
  only when the proposal already carries that exact scheme:value (at merge time those are the
  official records' values). A *different* value of an officially covered scheme may coexist as
  `is_primary => false` with `approved_secondary` tier and `source_confirmed` provenance listing
  every corroborating URL.
- Unit tests (5) cover two-host acceptance, same-host rejection, hostless-URL rejection,
  official-value skip, and official-value + corroborated alternates coexisting with the official
  primary intact. The existing hybrid pipeline test (supplier-a.example / supplier-b.example
  corroborating argan's alternate CAS) passes unchanged.

### Fix 8 — `app/Services/IngredientEnrichment/IngredientGuidanceDraftRenderer.php`

```php
if ($section === 'soapmaking'
    && $supportType === 'fact'
    && $claimType !== 'soapmaking'
    && ! ($claimType === 'usage' && $factPaths === ['current.canonical.info_markdown'])) {
    return null;
}
```

Preserved baseline use-level sentences (usage + fact from the current guidance) now survive under
the Soapmaking heading exactly as they already did under Formulation use; non-baseline usage facts
(soap_chemistry paths) and every other claim shape remain dropped. Regression tests: baseline
sentence renders with no warning; soap_chemistry usage fact still omitted. The v12 deferral in the
previous handoff is closed — it was a renderer rule, not a prompt iteration.

### Fix 9 — `config/queue.php`

```php
'retry_after' => (int) env(
    'DB_QUEUE_RETRY_AFTER',
    (int) env('INGREDIENT_ENRICHMENT_JOB_TIMEOUT', 2000) + 100
),
```

The shipped default is no longer the unsafe 960. `.env`/`.env.example` keep the explicit 2100
pin. The media queue test (`retry_after` > job timeout) passes under both the env pin and the
derived fallback.

### Baseline repairs (pre-existing, out of the adjudicated scope, included for a green suite)

- `IngredientEnrichmentVocabularyTest` — enum-order drift: `IngredientSourceTier` gained
  `approved_secondary` in the corroborated-ID merge (commit `71c3d5ee`); the test now lists it.
- `MediaAssetFoundationTest` — pinned `--timeout=300` on the composer dev listener; composer.json
  deliberately uses `--timeout=0` (per-job timeouts + `failOnTimeout` govern). Pin updated.
- `ProductionBenchProductionCalendarTest` — month-boundary rot: fixture dates are Aug 20–21 but
  the calendar component defaults to the current month; the clock is frozen to the fixture month
  in that test.

### Rules and docs updated

`.ai/rules/ingredient-enrichment.md`: FDA discovery traversal rule; display-first input form
(substance-form-aware rule); US declaration qualifier stripping; corroboration = host-level
independence + value-level precedence; queue-rule job-timeout default corrected 900s → 2000s.
`docs/ai-enrichment-evaluation-adjudication.md`: status note now covers fixes 1–9.

## 4. Decisions taken during the fixes (reviewable)

1. **Value-level official precedence** (owner-confirmed, above). Both external reviews pushed
   scheme-level ("no secondary value when official exists"); the owner's written rule and the
   alternate-CAS reality of the argan case (official 223747-87-3 vs supplier-corroborated
   68956-68-3) argue value-level. Residual: two CAS of one substance coexist on the proposal —
   flagged `is_primary=false`/`approved_secondary`. Reviewer to judge whether the review UI and
   apply path make the non-primary status visible enough.
2. **Exact-name termination predicate for FDA discovery** — the client stops only on a whole-name
   equality; everything else (phrase siblings, identifier-valued terms) keeps traversal going
   within the bounded term list. Identifier-only records (no INCI) traverse all terms — bounded,
   but costs extra calls; probe the cost.
3. **Curated qualifier blocklist** — English editorial vocabulary only; material-distinguishing
   adjectives are not in it. Probe for false positives (a blocklisted word used as a material
   name) and coverage gaps.
4. **Identity-unresolved retry = full live re-run** — matches the documented guarantee and fixes
   the stuck-state compound defect, at the cost of repeating deterministic lookups per retry;
   `attempt_count` exists but no cap is enforced.

## 5. Current state

- Config: `guidance_prompt_version` `ingredient-guidance-v11`; `guidance_research.prompt_version`
  `ingredient-guidance-research-v6`; 240 words / 2000 chars; `maximum_tool_calls` 5;
  `job_timeout_seconds` default 2000 (env `INGREDIENT_ENRICHMENT_JOB_TIMEOUT`); database queue
  `retry_after` fallback derived (2000 + 100); `.env` `INGREDIENT_ENRICHMENT_MODEL=gpt-5.6-luna`,
  `REASONING_EFFORT=xhigh`, `DB_QUEUE_RETRY_AFTER=2100`; phpunit pins terra.
- Suite: **2880 passed, 0 failed, 25 skipped** (52,340 assertions, SQLite).
- DB: housekeeping unchanged — item 44 stuck `researching` from an Aug 30 killed worker (needs
  manual reset); items 40–46 failed from old runs (now genuinely retryable, including any
  `identity_unresolved` items).
- Working tree: fixes are uncommitted on `main` @ `5d599f22` — see `git status --short` for the
  full list (code, tests, rules, docs; two new files: `CorroboratedIngredientIdentifierService.php`
  and its test).

## 6. Deferred items & known trade-offs (probe these)

1. **Two-CAS coexistence on a regulatory surface** — owner-accepted value-level precedence; judge
   whether `is_primary=false` + `approved_secondary` + review gate is sufficient product
   protection, or whether the review UI should surface scheme conflicts explicitly.
2. **Hobbyist corroboration is a rule, not an enforcement** — unchanged: the policy does not
   enforce "two independent specialist sources or consistency with deterministic data" for
   important practical claims (renderer guards contain it today).
3. **Full-pipeline stage reuse has no prompt-version invalidation** — unchanged (only
   `GuidanceRefresh` mode invalidates).
4. **Transport retry arithmetic** — 600s × up to 3 attempts = 1800s worst case vs 2000s job
   timeout: bounded but tight; a killed run wastes a partial attempt, never corrupts.
5. **`max_tool_calls=5` vs reported searches** — the `web_search_calls` counter counts sources,
   one tool call returns several; cap is on tool calls.
6. **Identity gate has no manual-override path** — niche ingredients with no registry record
   always gate; retry now re-runs live lookups but cannot manufacture a record.
7. **Empty section headings can still render** — validators require the localized headings even
   when all claims in a section were dropped.
8. **Renderer/policy interplay driver-dependence** — the jsonb cache-reuse bug escaped because
   tests run on SQLite; only that comparison is known-order-sensitive now. What else could be
   driver-dependent (PostgreSQL vs SQLite)?
9. **Qualifier strip and FDA traversal predicates are English/GSRS-shaped** — localized display
   names and non-GSRS sources are out of scope; confirm that is intended.

## 7. How to run / inspect

```bash
php artisan test --compact                              # full suite (SQLite)
php artisan test --compact tests/Unit/Services/CorroboratedIngredientIdentifierServiceTest.php
php artisan test --compact tests/Unit/Services/IngredientGuidanceDraftRendererTest.php
php artisan test --compact tests/Feature/IngredientIdentityMatchServiceTest.php
php artisan test --compact tests/Feature/IngredientEnrichmentRetryTest.php
php artisan test --compact tests/Feature/OpenFdaSubstanceClientTest.php
php artisan test --compact tests/Feature/UsIngredientDeclarationServiceTest.php
git status --short && git diff                    # the full fixes delta (tracked) + new files
```

Behavioural reproductions (tinker):

```php
// Fix 5 — declaration qualifier strip
app(UsIngredientDeclarationService::class)->propose(
    ['common_name' => 'SCLEROCARYA BIRREA SEED OIL', 'inci_names' => ['SCLEROCARYA BIRREA SEED OIL']],
    verifiedInciName: 'SCLEROCARYA BIRREA SEED OIL', displayName: 'Organic Virgin Marula Oil',
)->data['declaration_name'];   // => 'Marula (Sclerocarya Birrea) Oil'

// Fix 4 — Shea form preference (was: silent BUTTER match)
app(IngredientIdentityMatchService::class)->select([
    ['inci_name' => 'BUTYROSPERMUM PARKII OIL',  'cas' => ['194043-92-0'], 'ec' => []],
    ['inci_name' => 'BUTYROSPERMUM PARKII BUTTER','cas' => ['194043-92-0'], 'ec' => []],
], ['display_name' => 'Shea Oil', 'inci_name' => 'BUTYROSPERMUM PARKII BUTTER',
    'identifiers' => [['scheme' => 'cas', 'value' => '194043-92-0']]])
['candidate']['inci_name'];    // => 'BUTYROSPERMUM PARKII OIL'

// Fix 3 — identity-unresolved retry boundary
$item = IngredientEnrichmentBatchItem::query()->where('failure_code', 'identity_unresolved')->first();
$item?->retryableFromStage();  // => IngredientEnrichmentResearchStage::IdentityPreparation
```

## 8. Questions for the third evaluator

1. Does fix 3 fully restore the documented retry guarantee for every entry path (batch retry
   action, intake items, per-item reset), or are there dispatch paths that still replay cached
   identity?
2. Is the exact-name predicate in fix 6 the right discovery-level stop, or should discovery also
   weigh record-level signals (e.g., INCI org names) before terminating?
3. Value-level vs scheme-level official precedence: is the residual two-CAS case acceptable given
   the owner's explicit decision, and is the non-primary status sufficiently visible downstream
   (result model, review UI, apply)?
4. Fix 8's allowance is narrower than formulation_use's (single path, exact list) — are there
   other baseline-sentence shapes under Soapmaking that still drop deterministically?
5. The qualifier strip and the FDA predicate both encode curated heuristics — which failure modes
   remain untested (case, punctuation, non-ASCII display names, `m.`/regional subdomains)?
6. After nine fixes, is anything left that would justify the earlier "do not ship" verdict — and
   what should be re-measured live (Luna xhigh, PostgreSQL) before a production push?
