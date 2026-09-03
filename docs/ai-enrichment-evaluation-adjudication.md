# AI Ingredient Enrichment — Adjudication of the Codex (GPT-5.6 medium) evaluation

**Date:** 2026-09-02
**Reviewed against:** `main` @ `5d599f22` (clean, 0 ahead)
**Inputs:** `docs/handoff-ai-ingredient-guidance.md`, the Codex evaluation, `.ai/rules/ingredient-enrichment.md`

## Verdict

The Codex evaluation is substantially correct and I confirm seven of its ten findings. I disagree
with its scope on one point and it missed three defects, one of which is more serious than
anything either prior pass reported. **Do not ship.** The blocker is not test instability — it is
that two defects compound into an unrecoverable state: an ingredient can be silently matched to
the wrong substance, and when that fails the other way the retry path cannot recover it.

## Method

Every claim below was checked against source, not accepted from either document. Reproductions
were run through `artisan tinker` against the real service container. Where I could not reproduce
a claim I say so.

## 0. The test baseline is wrong in both prior passes

The handoff claims one failing test (`IngredientEnrichmentResearchPromptTest`). Codex claims 168
passed, 0 failed. Neither is accurate.

```
php artisan test --compact   (5 files that pin gpt-5.6-terra)
Tests: 3 failed, 37 passed (183 assertions)
```

| Failing test | Cause |
|---|---|
| `IngredientEnrichmentResearchPromptTest > it configu…` | asserts config model is `gpt-5.6-terra` |
| `OpenAiIngredientResearchClientTest > it sends a sou…` | asserts outgoing request model is `gpt-5.6-terra` |
| `IngredientGuidanceLocalizationClientTest > it local…` | same |

Root cause: `.env:13` sets `INGREDIENT_ENRICHMENT_MODEL=gpt-5.6-luna`; `.env.example:62` pins
`gpt-5.6-terra`. Five test files hard-code terra. This is environmental, not a code defect — but
"tests are green" is the load-bearing claim behind ship readiness, and it is currently false in
both directions. My broader run: **155 passed, 1 failed (835 assertions, 8.73s)**, consistent with
Codex's magnitude but not its zero.

**Action:** fix the drift or the pins. Until then no pass can be called green.

## 1. Confirmed from Codex

### [P1] Identity retry is a no-op — and it contradicts a documented guarantee

Confirmed, with a worse implication than stated.

- `IngredientEnrichmentPipeline.php:111-129` — on unresolved identity, the five guidance stages are
  persisted `skipped` with reason `identity_unresolved`.
- The identity stages themselves (`UsIdentity`, `EuStructured`, `EuOfficial`, …) are persisted
  **`completed`** — the lookup ran, it just produced no accepted match.
- `IngredientEnrichmentPipeline.php:991` — `runStage()` returns the cached result whenever status is
  `completed`.
- `IngredientEnrichmentBatchItem.php:113-122` — `retryableFromStage()` returns the first stage not
  `completed`, i.e. the first `skipped` guidance stage.

So a retry replays cached identity, re-derives the same empty match, and re-fires the gate. Every
retry is a deterministic no-op that still costs a queue slot.

`.ai/rules/ingredient-enrichment.md:62` states: *"Retrying re-runs identity first."* That
guarantee is not implemented. This is a documented promise the code does not keep, which is worse
than an undocumented gap.

### [P1] Corroboration counts URLs, not independent publishers

Confirmed. `IngredientEnrichmentPipeline.php:512`:

```php
->filter(fn ($group): bool => $group->pluck('source_url')->filter()->unique()->count() >= 2)
```

Two pages on one domain (`supplier.com/cas`, `supplier.com/spec-sheet`) satisfy the two-authority
rule. The rule text says "at least two independent consulted research sources".

### [P1] Official precedence is not enforced

Confirmed. `IngredientEnrichmentPipeline.php:540` skips only an exact `scheme:value` collision:

```php
if (isset($existingKeys[$key])) { continue; }
```

An official CAS and a *different* research CAS coexist in the proposal. Mitigating factor: the
secondary is written with `is_primary => false` (`:548`), so the official value still governs.
But `.ai/rules:68` says such values "may enter the proposal only when at least two independent
consulted research sources explicitly print them" — the operative constraint is that official
records must **lack** the identifier. Shipping a proposal with two conflicting CAS numbers onto a
regulatory surface is not that.

### [P1] FDA discovery can stop before reaching the matchable variant

Confirmed. `OpenFdaSubstanceClient.php:61-63`:

```php
if ($candidates !== []) { break; }
```

The loop breaks on the first query returning *any* candidate, without testing whether that
candidate matches the requested material. Search variants (parenthetical strip, kernel→seed) run
in order, so an earlier sibling-form hit suppresses the later, correct variant.

This is the same failure class as the Cottonseed case that motivated form-aware matching — but
form-aware matching runs in `IngredientIdentityMatchService`, *after* discovery. If discovery
never offers the right record, the matcher cannot save it.

### [P1] The handoff contradicts itself on the worker timeout

Confirmed by reading. `handoff:61` (Decision 11) requires `--timeout=0` or ≥ the 2000s job
timeout. `handoff:93` calls `--timeout=960` "safe", and `handoff:111` recommends it. 960 < 2000 is
exactly the failure Decision 11 warns about. `composer run dev` correctly uses `--timeout=0`.

### [P2 — downgraded from P1] Queue `retry_after` default

Partially confirmed. `config/queue.php:43` defaults to 960 vs a 2000s job timeout, so the shipped
default *is* unsafe. But `.env:45` and `.env.example:53` both pin `DB_QUEUE_RETRY_AFTER=2100`.
Any deployment following `.env.example` is fine. This is a latent trap, not an active production
defect — hence P2. Still worth hardening (derive the default from the job timeout, or assert it).

### [P2] The v9/v10 bans are prompt-only

Confirmed in substance, with one correction. Codex says the renderer "allows qualifiers such as
reported, product grade, and cited" at `IngredientGuidanceDraftRenderer.php:336`. That is
imprecise: `hasBoundedEvidenceQualifier()` is an **allowlist requirement**, applied only when
`evidence_kind === experimental_observation` (`:310-312`). It does not permit those words
generally.

The substance holds though: there is no code ban anywhere in the renderer for the v10 vocabulary
(`documented`, `specified`, `supplied`, `referenced`, `listed`, `verified`) or for v9's material
terms. Six prompt iterations are protected only by the prompt. A model regression ships silently.

### [P1 — upgraded from Codex's "additional problem"] US declaration leaks editorial qualifiers

Confirmed and reproduced exactly:

```
UsIngredientDeclarationService::propose(
    ['common_name' => 'SCLEROCARYA BIRREA SEED OIL', 'inci_names' => ['SCLEROCARYA BIRREA SEED OIL']],
    false, 'SCLEROCARYA BIRREA SEED OIL', 'Organic Virgin Marula Oil')
=> 'Organic Virgin Marula (Sclerocarya Birrea) Oil'   confidence: supported
```

`UsIngredientDeclarationService.php:96-100` uses the catalogue display name **wholesale** as the
common component whenever the registry common name is itself latin. Marketing qualifiers
("Organic", "Virgin") survive into a US declaration that is then tagged `confidence: supported`
with FDA 21 CFR 701.3 cited as its evidence row (`:57-66`).

I rank this P1 because it is not a wrong intermediate — it emits a wrong **artifact** with
official provenance attached. Canned examples still behave (`Coconut (Cocos Nucifera) Oil`,
`Beef (Adeps Bovis) Tallow`), which is why the existing tests pass.

### [P3] Mutation by reference inside `map()`

Confirmed: `IngredientIdentityMatchService.php:46-52`, `&$formExcluded` mutated inside a
transformation closure. Style only; behaviour is correct today because `map()` is eager here.

### [P3, architectural] Pipeline owns four concerns

Fair. `mergeCorroboratedIdentifiers()` (`:478-585`) builds identifiers, evidence rows, provenance
and confidence inside a 1019-line orchestrator. A dedicated corroborated-identifier service would
give the two-authority and official-precedence invariants one enforceable boundary — which is
exactly where findings 2 and 3 live.

## 2. What the Codex evaluation got wrong or missed

### [P1] "v12" is a code defect, not a deferral

Codex states: *"The explicitly deferred v12 baseline preservation … was not counted as new
defects."* That accepts the handoff's framing. It is wrong.

`IngredientGuidanceDraftRenderer.php:158-162`:

```php
if ($section === 'soapmaking'
    && $supportType === 'fact'
    && $claimType !== 'soapmaking') {
    return null;
}
```

A preserved baseline use level is modelled as `claim_type=usage` + `support_type=fact` +
`fact_paths=['current.canonical.info_markdown']`. Line 146 admits `usage`; line 158 then drops it.
**The soapmaking baseline use level is discarded deterministically.** No v12 prompt can fix this,
so the deferral as written is unactionable.

`tests/Unit/Services/IngredientGuidanceDraftRendererTest.php:150` exercises soapmaking usage only
with `support_type=evidence`. The failing combination is untested.

The doc's own evidence confirms the mechanism: cosmetics range preserved, soapmaking range lost —
which is precisely the asymmetry this rule creates (`formulation_use` has no equivalent guard).

### [P1] `formToken()` derives the form from the last token of a concatenated string

`IngredientIdentityMatchService.php:27` concatenates display name + INCI name, and `:216-232`
picks the form token with the **greatest offset**. A trailing INCI form word therefore overrides
the more specific display form word. Reproduced:

```
record:  display "Shea Oil", inci "BUTYROSPERMUM PARKII BUTTER"

A) candidates [BUTYROSPERMUM PARKII OIL, BUTYROSPERMUM PARKII BUTTER]
   => chose BUTYROSPERMUM PARKII BUTTER,   conflicts: []
B) candidates [BUTYROSPERMUM PARKII OIL] only
   => NULL, "Identity candidate material form does not match the ingredient."
```

Case A is the Cottonseed bug inverted: form-aware matching now *causes* a silent wrong-substance
match with zero conflicts reported. Case B gates the ingredient — and per finding 1, retry cannot
recover it. Together these two defects produce an ingredient that is either silently wrong or
permanently stuck.

### [P2, process] No design doc exists for any of this

`docs/superpowers/specs/` and `docs/superpowers/plans/` stop at 2026-08-31. The Sep 1–2 identity
series — form-aware matching, the completeness gate, US declarations, corroborated CAS/EC
(`ebbb95ff`, `2062ed1d`, `9cad51aa`, `985ee936`, `71c3d5ee`) — has no governing spec or plan.
Only `.ai/rules` entries, written after the fact, and the handoff.

Per house convention the spec is the authority on intent. This series has no authority to drift
*from*, which is likely why two independent reviewers reached different conclusions about whether
v12 is a defect.

## 3. Recommended fix order

> Status 2026-09-02: fixes 1–9 implemented (phpunit model pin; handoff timeout text corrected;
> identity-unresolved retries invalidate from `IdentityPreparation`; matcher input form prefers the
> display name; US declaration common part strips editorial qualifiers; FDA discovery traverses
> variants until a candidate is named exactly by a queried term; corroborated identifiers extracted
> into `CorroboratedIngredientIdentifierService` with host-level independence and owner-confirmed
> value-level official precedence; renderer keeps baseline soapmaking use levels (v12 closed);
> queue `retry_after` default derives from the job timeout). Full suite: green. Three pre-existing
> unrelated failures fixed along the way (`IngredientEnrichmentVocabularyTest` enum-order drift from
> the corroborated-ID merge, `MediaAssetFoundationTest` stale `--timeout=300` pin,
> `ProductionBenchProductionCalendarTest` month-boundary rot — clock frozen to the fixture month).

| # | Fix | Why first |
|---|---|---|
| 1 | Resolve the `.env`/test model drift | Nothing can be called green until the baseline is real |
| 2 | Correct `handoff:93` and `:111` to match Decision 11 | One-line doc fix; actively dangerous as written |
| 3 | Make identity retryable — invalidate identity stages on `identity_unresolved` | Restores a documented guarantee; unblocks stuck items |
| 4 | Fix `formToken()` — score display and INCI forms separately, prefer the display form | Wrong-substance matches are the worst failure mode here |
| 5 | Strip marketing qualifiers from the US declaration common component | Wrong artifact with official provenance |
| 6 | Continue FDA discovery until a *matching* candidate is found (bounded) | Restores the variant traversal the design intends |
| 7 | Extract a corroborated-identifier service: group by registrable domain, enforce official-precedence | Gives findings 2 and 3 one boundary |
| 8 | Renderer: allow `usage`+`fact` in `soapmaking`, add the regression test | Closes v12, which is a code fix not a prompt fix |
| 9 | Default `retry_after` from `job_timeout_seconds` | Removes the latent trap |

Items 3 and 4 are the compound blocker named in the verdict — they gate every ship decision.
Items 1 and 2 are prerequisites to calling any run green (and 2 is a one-line doc fix). Items 5, 6
and 8 are P1 defect fixes. Item 7 is structural: fold it into the corroboration fix so findings 2
and 3 get their enforceable boundary in the same change. Item 9 is cheap hardening — do it
whenever the queue config is next touched. Nothing in this list is optional-before-ship; the
order above is the dependency order, not a triage.

## 4. On the model choice

Agreed with Codex: do not decide Luna max vs xhigh yet. Every output-quality problem cited in the
handoff (dropped use levels, taxonomy leaks) is traceable to a pipeline or enforcement gap, not to
reasoning capacity. Fix 1–8 and re-measure before spending on reasoning effort.
