---
paths:
  - 'app/Services/IngredientEnrichment/**'
  - 'app/Services/IngredientIntake/**'
  - 'app/Actions/IngredientIntake/**'
---

# Ingredient Enrichment

## Deterministic facts precede AI editorial work
Normal ingredient enrichment must obtain identity, identifiers, declarations, and COSING functions from deterministic source stages before any model call. The editorial client receives those facts and must not use web-search tools. Web search is allowed only through the explicitly enabled gap-research path; COSMILE Europe can support cited editorial guidance only, never legal declarations, identifiers, or COSING assignments.

## Keep evidence, confidence, provenance, and review independent
Store source tier (official, official mirror, approved secondary, reviewer supplied), field confidence (verified, supported, conflicting, unresolved), value provenance, and reviewer decision as separate dimensions. Conflicting evidence remains distinct from a missing value. Human approval accepts a catalogue value but never upgrades its source tier or field confidence.

## Verify or visibly propose soap INCI salts independently
For lipid enrichment, first discover sodium and potassium soap entries from deterministic CosIng records that explicitly relate each salt to the base material, then independently verify each exact name in the official EUR-Lex glossary. When a deterministic value remains unresolved, AI may propose each NaOH or KOH declaration independently using the verified base identity and available naming evidence. Mark it as AI-proposed, never as CosIng/official/source-confirmed, retain its reasoning and links, and require explicit human approval before apply.

## Normalize localized guidance headings
AI may translate guidance prose, but the three fixed Markdown section headings are normalized deterministically per catalogue locale before validation. Validation and completeness checks require the configured localized headings in order.

## Editorial guidance filters low-value source facts
Ingredient guidance must turn source facts into material-specific formulation consequences or omit them from prose. Never mechanically define COSING labels, assume grade-specific processing, copy generic SDS language, repeat storage advice, or describe a saponified oil using the raw oil's emollient properties.

## Localize guidance as native editorial copy
Every ingredient-guidance locale is an independent localized rewrite, never a sentence-by-sentence copy or an English grammatical template. Preserve supported facts, cautions, omissions, and section order while using native cosmetic-formulation terminology, idiom, syntax, register, and rhetorical flow equally in every language.

## Reuse trusted chemistry and persisted guidance research
Expose SAP and fatty-acid data to enrichment prose only when Ingredient::canDriveSoapSaponification() is true, and use it qualitatively without printing exact chemistry values. Keep AI guidance research in its own persisted stage before editorial generation so an editorial timeout/retry does not repeat research.

## Keep catalogue aliases manually curated
Do not spend AI or source-research effort proposing catalogue aliases. Existing aliases may be used internally as identity search terms, and deterministic source names may help match the correct record, but enrichment proposals must leave aliases empty so reviewed/manual aliases remain untouched.

## Treat guidance metadata revalidation as reviewable
Guidance plans treat stale-locale revalidation and changed evidence as reviewable metadata changes even when prose is identical. If English is reviewer-edited, unedited generated localizations are not applied as current; only reviewer-edited locales may receive the new canonical fingerprint. Guidance retries resume completed stages according to persisted batch mode.

## Render guidance claims by fidelity, not by failing the run
The authoring renderer (IngredientGuidanceDraftRenderer) is drop-and-warn, not fail-fast: a claim that violates a claim-level rule is omitted and a reviewable warning is added, and the rest of the draft still renders. This covers wrong or missing evidence citations (claim type mismatch, out-of-range index, multiple combined usage rows), usage phrasing that drops the required attribution/grade/basis/application, generic water or universal-emulsifier claims, fact claims citing paths outside the trusted catalogue, and section/application mismatches. Hard failures remain only for draft shape, claim shape, and the rendered 160-word cap. Never reintroduce hard rejection for individual claims: real model output trips at least one rule on a large fraction of runs, and failing the run discards otherwise good guidance.

## Evidence authority chain: research proposes, policy partitions, renderer checks fidelity, human reviews
Accepted guidance-evidence rows are the authority for claims; the renderer never judges whether evidence is true. The research model proposes candidate rows, IngredientGuidanceEvidencePolicy partitions them (vocabulary enums, usage metadata, consulted-URL requirement, blocked domains), and the human reviewer approves or rejects at review time. There is no unresolved-question veto: research questions are informational and flow into the item's unresolved_questions; they must not block claims backed by accepted evidence or trusted catalogue facts. Usage evidence (use levels) may come only from recommendation-capable source kinds (manufacturer_technical, supplier_technical, professional_reference, specialist_reference) — official/scientific sources never advise on formulation, and the policy rejects such rows.

## Keep suppliers, brands, and product codes out of guidance prose
Guidance claims must never name suppliers, manufacturers, brands, or alphanumeric product/batch codes. Grade-specific statements qualify with generic descriptors only ("this product grade", "the virgin grade", "a refined grade"). The authoring prompt forbids identifiers (prompt version v5+), and the renderer additionally drops any claim whose sentence contains an uppercase-alphanumeric code token found in the cited evidence source_name (e.g. PA3019). Bump guidance_prompt_version whenever the authoring prompt text changes so stored guidance invalidates on refresh.

## Full-enrichment stages resume without version checks; refresh invalidates
IngredientEnrichmentPipeline re-runs only stages that are not completed: it does not compare prompt versions, so editing the prompt does not regenerate guidance on a plain re-run. The intended guidance-only re-run is the GuidanceRefresh batch mode (IngredientGuidanceStageRunner), which invalidates authoring/localization caches when the effective provider configuration changes. To regenerate guidance in place from a full-enrichment item, clear the ai_guidance_authoring, ai_guidance_localization, and validation stage entries (research and editorial are reused).

## Queue workers must not kill enrichment jobs
Enrichment jobs run up to their own `direct_ai.job_timeout_seconds` (default 900s, enforced via pcntl with failOnTimeout) and each provider call up to `openai.timeout_seconds` (600s). A `queue:listen`/`queue:work` started without `--timeout` kills the worker process after the default 60s mid-call, orphaning the job (item stuck in `researching`, stale reservation and WithoutOverlapping cache lock). Run the listener with `--timeout=0` (the job's own timeout then governs) or at least above the job timeout; the `composer run dev` script uses `--timeout=0`. If an item is orphaned this way, reset it to failed, delete the stale `cache_locks` rows for `ResearchIngredientEnrichment:<id>`, and requeue or run the pipeline directly.
