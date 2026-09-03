# Review of fixes 1–9 (third pass)

**Date:** 2026-09-02
**Reviewed:** `main` @ `5d599f22` **plus the uncommitted working tree** (as the handoff instructs)
**Inputs:** `docs/handoff-ai-enrichment-fixes-1-9.md`, `docs/ai-enrichment-evaluation-adjudication.md`

## Verdict

**Ship-worthy, with two probes to run first.** All nine fixes do what the handoff says, the suite
number is exact, and the behavioural reproductions all hold. The earlier "do not ship" blockers are
genuinely closed. I found three residual gaps — two of them are the handoff's own open questions
#5, now confirmed rather than hypothetical, and one is an interaction between fix 6 and the
ambiguity gate that is not in the deferred list at all.

## Verification performed

| Claim | Result |
|---|---|
| Suite: 2880 passed / 0 failed / 25 skipped / 52,340 assertions | ✅ exact (144.98s) |
| Fix 1 — previously 3 failed / 37 passed, now 40 passed | ✅ consistent with the 3 failures I measured before the fix |
| Fix 3 — `retryableFromStage()` → `IdentityPreparation` | ✅ and precisely scoped (below) |
| Fix 4 — Shea: CAS arbitrates → `BUTYROSPERMUM PARKII OIL` | ✅ |
| Fix 5 — `Organic Virgin Marula Oil` → `Marula (Sclerocarya Birrea) Oil` | ✅ |
| Fix 6 — exact-name traversal predicate | ✅ implemented as described |
| Fix 7 — host independence (same host rejected, two hosts accepted, `www.` normalised) | ✅ |
| Fix 8 — baseline soapmaking use level renders, non-baseline still dropped | ✅ |
| Fix 9 — derived `retry_after` = job timeout + 100 | ✅ |

`retryableFromStage()` scoping was checked in both directions, because a fix here that over-fires
would silently discard completed work on every failure:

| Item state | Returns |
|---|---|
| `failure_code=identity_unresolved`, all identity stages completed | `identity_preparation` ✅ |
| `failure_code=runtimeexception`, same stages | `ai_guidance_research` (unchanged) ✅ |
| no `failure_code`, same stages | `ai_guidance_research` ✅ |
| `identity_unresolved` + guidance batch mode | `ai_guidance_research` (excluded) ✅ |

**Answer to question #1 — fix 3 is complete.** `retryableFromStage()` has exactly one caller
(`RetryIngredientEnrichmentFailures:40`). The only other dispatch site
(`IngredientEnrichmentBatchService:285`) creates new items with empty stage maps. Intake items are
marked failed but re-promotion creates fresh items. No path replays cached identity.

## Residual gaps

### [P2] Fix 5 — punctuation defeats the qualifier strip

```
"Organic Virgin Marula Oil"   => 'Marula (Sclerocarya Birrea) Oil'      ✅ documented case
"Organic, Virgin Marula Oil"  => 'Organic, Marula (Sclerocarya Birrea) Oil'   ❌
```

`stripEditorialQualifiers()` splits on whitespace and compares `mb_strtolower($word)` against
`EDITORIAL_QUALIFIERS`. The token `"Organic,"` is not the entry `"organic"`, so it survives — while
the unpunctuated `"Virgin"` is stripped. A single comma re-introduces exactly the leak fix 5 was
written to close, and additionally leaves a stray comma plus a dangling word in a regulatory label
that is then tagged `confidence: supported`.

This confirms the handoff's own question #5. Fix: trim punctuation from each token before the
membership test (or strip `[^\p{L}\p{N}\s-]` from the name before tokenising).

Over-stripping was checked and is safe: an all-qualifier display name falls back to the registry
common name (`A3` → `SCLEROCARYA BIRREA SEED OIL`) because `composeBotanicalLabel()` returns null
on fewer than two words.

### [P2] Fix 7 — subdomains count as independent authorities

| Sources | Result |
|---|---|
| `supplier.example/a` + `supplier.example/b` | rejected ✅ |
| `supplier-a.example` + `supplier-b.example` | accepted ✅ |
| `supplier.example` + `www.supplier.example` | rejected ✅ |
| `supplier.example` + `m.supplier.example` + `uk.supplier.example` | **accepted** ❌ |

`authorityHosts()` strips only a leading `www.`. One publisher with a mobile and a regional
subdomain therefore self-corroborates — precisely the failure the host-level rule exists to
prevent. Also confirms question #5.

Fix: strip a wider prefix set, or reduce to the registrable domain. If the project has a PSL
dependency available, prefer that; otherwise a curated prefix list plus a two-label heuristic is a
materially better approximation than `www.` alone.

### [P2] Fix 6 × ambiguity gate — a new interaction, not in the deferred list

Fix 6 deliberately stops suppressing candidates: discovery now accumulates across variants instead
of breaking on the first non-empty batch. The matcher then gates when
`$best['score'] - $runnerUp['score'] < 10`.

Two registry records sharing one INCI name under different UNIIs both score 100 (`exact_inci`),
so the margin is 0:

```
candidates: ARGANIA SPINOSA KERNEL OIL (UNII AAA), ARGANIA SPINOSA KERNEL OIL (UNII BBB)
=> GATED: "Identity candidates remain ambiguous and require human review."
```

`OpenFdaSubstanceClient` keys accumulated candidates by `unii ?: common_name`, so distinct-UNII
duplicates both survive. Before fix 6 an early break often returned only one of them and the
ingredient matched. **Fix 6 can therefore convert a previously-successful match into an
`identity_unresolved` gate.**

This is not a reason to revert fix 6 — the gate is the safe outcome when two records genuinely
tie, and fix 3 now makes that state recoverable by retry. But the gate rate should be measured
before/after on real ingredients, and it is not mentioned in the deferred list.

## Notes (not defects)

- **Baseline repairs are legitimate but out of scope.** `MediaAssetFoundationTest` (pin updated to
  match `composer.json`, which deliberately uses `--timeout=0`) and
  `ProductionBenchProductionCalendarTest` (clock frozen to the fixture month in a `try/finally` —
  correct handling of genuine month-boundary rot). Both are real pre-existing failures, honestly
  described. **Recommend splitting them into a separate commit** so the enrichment delta stays
  reviewable.
- **Project memory compaction dropped a convention.** `.workbuddy-ai/memory/MEMORY.md` went
  290 → 121 lines; the PostgreSQL `jsonb` key-order gotcha no longer appears. `.ai/rules` still
  carries it, so the shared team record is intact. No action needed beyond noting it.
- **Rules and doc updates match the code.** The 900s → 2000s job-timeout correction, the
  display-first form rule, the FDA traversal rule, the qualifier-stripping rule and the
  value-level precedence rule all read back accurately. `.ai/rules:62` ("Retrying re-runs identity
  first") is now true rather than aspirational — no doc drift left.

## Answers to the remaining questions

**#2 — exact-name predicate.** Defensible: whole-name equality is the right signal for "this
variant actually found the record". See the fix 6 × gate interaction above for its cost.

**#3 — value-level precedence.** Acceptable given the owner's explicit decision and the argan
alternate-CAS reality. The residual two-CAS case still warrants explicit surfacing in the review
UI (your deferred #1) — `is_primary=false` is correct data but invisible unless the UI renders it.

**#4 — other baseline shapes under Soapmaking.** The allowance is exactly
`usage` + `fact` + `fact_paths === ['current.canonical.info_markdown']`. A baseline emitted with
`claim_type='soapmaking'` also passes (pre-existing). `formulation_use` has always admitted only
that one path, so a baseline sentence citing any other trusted path still drops there —
pre-existing behaviour, not introduced here, and worth a look if Marula-like cases recur.

**#5 — untested failure modes.** Punctuation (confirmed broken), subdomains (confirmed broken),
non-ASCII display names (`\b` with `/u` should behave, untested), case (covered).

**#6 — does "do not ship" still hold?** No, not as a blocker. The two confirmed gaps (punctuation,
subdomains) are narrow and both are hardening of fixes that already work for the documented cases;
neither can produce the silent wrong-substance or permanent-stuck outcomes that drove the original
verdict. Before a production push I would: fix those two, measure the fix 6 gate-rate delta, and
re-run once on PostgreSQL (the whole suite is SQLite, and the one bug that escaped before escaped
exactly that way).

## Suggested commit split

1. `fix: adjudicated enrichment findings 1–9` — the nine fixes plus their regression tests
2. `test: repair pre-existing media and calendar baselines` — the two unrelated test repairs
`phpunit.xml` belongs with the first.

---

# Addendum — reconciliation with a second independent review

A second reviewer (Claude Opus 4.6, high) reviewed the same tree. Two of its findings match mine
independently; two are new and I verified both. **My verdict changes.**

## Confirmed by both reviews (raise confidence)

| Finding | This review | Second review | Agreed severity |
|---|---|---|---|
| Subdomain self-corroboration (fix 7) | P2 | P1 | **P1** — it defeats the core anti-gaming guarantee, not a tidy-up. I upgrade mine. |
| Punctuation defeats the qualifier strip (fix 5) | P2 | P1 | **P2** — the declaration is still comprehensible and the value is right; it re-leaks a qualifier, it does not misidentify the material. I hold at P2. |
| v9/v10 bans remain prompt-only | P2 (earlier pass) | P2 | **P2**, unchanged. |

The second review adds two qualifier-strip cases I did not try, both real:
`"Cold‑Pressed"` with a non-ASCII hyphen (U+2011) survives, because `\b` treats it as a word
character; and `"Organic (Virgin) Marula Oil"` → `"(Virgin) Marula (…)"`, because parentheticals
are stripped from the *botanical* side but never from the display-derived common part. Same root
cause as my punctuation finding — the tokeniser trusts the raw token.

## New finding A — [P1] the two-authority audit trail is lost on apply

Verified empirically and by tracing both paths.

```
evidence rows for one corroborated identifier (2 hosts):
  BEFORE edit   2   (supplier-a, supplier-b)
  AFTER  edit   1   (supplier-a only)

provenance source_urls AFTER edit:  [supplier-a, supplier-b]   ← both retained
```

So on the **enrichment item** the trail survives in `value_provenance.source_urls`, and the second
reviewer's caveat ("provenance persists") is correct — *for the item*.

It is **not** correct for the applied ingredient. `value_provenance` appears in exactly two places
outside the enrichment result payload: `EditIngredientEnrichmentProposal:86` (item-level) and
nowhere else. `ApplyPlatformIngredientEnrichment:245` builds only `identifier_evidence`, one entry
per identifier taken from that identifier's own single `source_url`/`source_name`, and
`IngredientDataEntryService:215` is the only consumer. **Provenance is never passed to
`syncCurrentData`.**

Consequence: after apply, an ingredient carries a CAS/EC tagged `approved_secondary` / `supported`
whose stored evidence names **one** supplier — while the rule that admitted it required two
independent authorities. The acceptance basis is not reconstructible from the applied record.

Why P1 despite the value itself being fine: the project's own rule
(`.ai/rules/ingredient-enrichment.md:14`) requires that "source tier, field confidence, value
provenance, and reviewer decision" stay independent and stored. Dropping provenance at the apply
boundary breaks that invariant on a regulatory identifier. Note this is **pre-existing** — neither
`ApplyPlatformIngredientEnrichment` nor `IngredientDataEntryService` was touched by fixes 1–9 — but
fix 7 raised the acceptance bar to host-level independence, so what is now discarded is a stronger
justification than before.

Fix: carry `value_provenance` (or at minimum the corroborating `source_urls`) into
`syncCurrentData`, or have `identifier_evidence` emit one entry per corroborating URL.

## New finding B — [P2] FDA discovery can terminate on a non-authoritative alias

Verified empirically:

```
candidate: inci_name "HYDROGENATED ARGANIA SPINOSA KERNEL OIL",
           names[] ["ARGANIA SPINOSA KERNEL OIL"]     (base name as an ordinary alias)
query term: "ARGANIA SPINOSA KERNEL OIL"

batchNamesTermExactly(...)  => true      → discovery stops here
matcher select(...)         => GATED, "Material difference: hydrogenated."
```

`batchNamesTermExactly()` matches against `common_name` + `inci_names` + `names`, but
`IngredientIdentityMatchService::scoreCandidate()` considers only `inci_name` + `inci_names`. A
derivative carrying the base name as a plain alias therefore satisfies the stop predicate, is then
rejected by the matcher, and suppresses the later variant holding the real record.

This is a **different mechanism** from the tie-gating I reported above, and both are live. Fix:
restrict the termination predicate to the same name set the matcher scores on
(`inci_name` + `inci_names`).

## Revised verdict

**Do not ship the corroborated-CAS/EC path to production yet.** The nine fixes are sound and the
original blockers are closed; the residual concerns are about the *evidence trail around* those
fixes rather than the fixes themselves. Concretely:

- Blocks production: **finding A** (provenance dropped on apply).
- Fix before or immediately after, narrow: subdomain independence, qualifier tokenising
  (punctuation + non-ASCII hyphen + parentheticals), FDA alias termination.
- Measure, do not block: the fix 6 gate-rate delta.
- Still prompt-only, accepted: the v9/v10 prose bans.

Unchanged: re-run once on PostgreSQL before any production push — the whole suite is SQLite, and
the one bug that previously escaped, escaped exactly that way.
