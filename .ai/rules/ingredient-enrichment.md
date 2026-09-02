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
Ingredient guidance must turn source facts into material-specific formulation consequences or omit them from prose. Preserve useful sentences from the current reviewed guidance as the editorial baseline when they remain supported, and enrich that baseline selectively instead of replacing it with narrower research details. Never mechanically define COSING labels, assume grade-specific processing, copy generic SDS language, repeat storage advice, or describe a saponified oil using the raw oil's emollient properties.

## Match source authority to the claim
Guidance research optimizes for practical relevance, not institutional prestige. Manufacturer and supplier technical material may support grade-specific handling and use levels; professional and specialist formulation or soapmaking references may support practical behaviour; scientific or institutional sources support guidance only when they directly establish a useful material property or consequence. An ingredient-specific, technically coherent reference from an experienced hobbyist may be classified as `specialist_reference`. Patents are not guidance evidence, and isolated narrow studies remain bounded observations only when their tested conditions map directly to a useful formulation decision. Anonymous community posts, marketplaces, generic lifestyle blogs, AI-generated pages, SEO summaries, and unsourced marketing remain unsuitable.

## Localize guidance as native editorial copy
Every ingredient-guidance locale is an independent localized rewrite, never a sentence-by-sentence copy or an English grammatical template. Preserve supported facts, cautions, omissions, and section order while using native cosmetic-formulation terminology, idiom, syntax, register, and rhetorical flow equally in every language.

## Reuse trusted chemistry and persisted guidance research
Expose SAP and fatty-acid data to enrichment prose only when Ingredient::canDriveSoapSaponification() is true, and use it qualitatively without printing exact chemistry values. Keep AI guidance research in its own persisted stage before editorial generation so an editorial timeout/retry does not repeat research.

## Keep catalogue aliases manually curated
Do not spend AI or source-research effort proposing catalogue aliases. Existing aliases may be used internally as identity search terms, and deterministic source names may help match the correct record, but enrichment proposals must leave aliases empty so reviewed/manual aliases remain untouched.

## Treat guidance metadata revalidation as reviewable
Guidance plans treat stale-locale revalidation and changed evidence as reviewable metadata changes even when prose is identical. If English is reviewer-edited, unedited generated localizations are not applied as current; only reviewer-edited locales may receive the new canonical fingerprint. Guidance retries resume completed stages according to persisted batch mode.

## Render guidance claims by fidelity, not by failing the run
The authoring renderer (IngredientGuidanceDraftRenderer) is drop-and-warn, not fail-fast: a claim that violates a claim-level rule is omitted and a reviewable warning is added, and the rest of the draft still renders. This covers wrong or missing evidence citations (claim type mismatch, out-of-range index, multiple combined usage rows), usage phrasing that drops the exact bounds, direction, or percentage basis, generic water or universal-emulsifier claims, fact claims citing paths outside the trusted catalogue, and section/application mismatches. Source kind, evidence scope, and application remain structured metadata and are not required phrases in catalogue prose. Hard failures remain only for draft shape, claim shape, and the configured rendered length caps. Never reintroduce hard rejection for individual claims: real model output trips at least one rule on a large fraction of runs, and failing the run discards otherwise good guidance.

## Evidence authority chain: research proposes, policy partitions, renderer checks fidelity, human reviews
Accepted guidance-evidence rows are the authority for claims; the renderer never judges whether evidence is true. The research model proposes candidate rows, IngredientGuidanceEvidencePolicy partitions them (vocabulary enums, usage metadata, consulted-URL requirement, blocked domains), and the human reviewer approves or rejects at review time. There is no unresolved-question veto: research questions are informational and flow into the item's unresolved_questions; they must not block claims backed by accepted evidence or trusted catalogue facts. Usage evidence (use levels) may come only from recommendation-capable source kinds (manufacturer_technical, supplier_technical, professional_reference, specialist_reference) — official/scientific sources never advise on formulation, and the policy rejects such rows.

## Keep suppliers, brands, and product codes out of guidance prose
Guidance claims keep suppliers, manufacturers, brands, product codes, source kinds, and evidence-schema labels in the evidence record rather than catalogue prose. A grade description appears only when the distinction itself materially changes safe or correct use; `product_grade` is never a required visible phrase. The renderer drops any claim whose sentence contains an uppercase-alphanumeric code token found in the cited evidence source_name (e.g. PA3019). Bump `guidance_prompt_version` whenever the authoring prompt text changes so stored guidance invalidates on refresh, and bump the guidance-research prompt version whenever its instructions change.

## Full-enrichment stages resume without version checks; refresh invalidates
IngredientEnrichmentPipeline re-runs only stages that are not completed: it does not compare prompt versions, so editing the prompt does not regenerate guidance on a plain re-run. The intended guidance-only re-run is the GuidanceRefresh batch mode (IngredientGuidanceStageRunner), which invalidates authoring/localization caches when the effective provider configuration changes. To regenerate guidance in place from a full-enrichment item, clear the ai_guidance_authoring, ai_guidance_localization, and validation stage entries (research and editorial are reused).

## Queue workers must not kill enrichment jobs
Enrichment jobs run up to their own `direct_ai.job_timeout_seconds` (default 2000s, env `INGREDIENT_ENRICHMENT_JOB_TIMEOUT`, enforced via pcntl with failOnTimeout) and each provider call up to `openai.timeout_seconds` (600s). A `queue:listen`/`queue:work` started without `--timeout` kills the worker process after the default 60s mid-call, orphaning the job (item stuck in `researching`, stale reservation and WithoutOverlapping cache lock). Run the listener with `--timeout=0` (the job's own timeout then governs) or at least above the job timeout; the `composer run dev` script uses `--timeout=0`. If an item is orphaned this way, reset it to failed, delete the stale `cache_locks` rows for `ResearchIngredientEnrichment:<id>`, and requeue or run the pipeline directly.

## Identity search expands variants; the matched registry record stays authoritative
Identity lookups (CosIng, FDA GSRS) expand search terms only: parenthetical qualifiers are removed and `kernel` converts to `seed` (registries index the seed-oil form). The matcher treats kernel/seed and parenthetical names as the same material when the names otherwise agree, and never fuzzy-matches material modifiers (hydrogenated, unsaponifiables, ...). The matched registry record remains the authority; variants are never stored. FDA GSRS discovery keeps traversing its query variants until a candidate is named exactly by a queried term — a sibling-form record that merely contains the search phrase inside a longer synonym must not end discovery (traversal is bounded by the distinct term list).

## Identity matching is substance-form aware
An oil/butter/tallow/fat input must never match a sibling-form record sharing its stem — COTTONSEED OIL is not COTTONSEED ACID, nor an ester derivative, extract, or olein/stearin fraction. Candidates whose primary name carries a different product form are excluded and surface "Identity candidate material form does not match the ingredient." plus the material difference. Form-less registry names (ADEPS BOVIS) stay matchable through identifiers. The owner's rule: for the lipids category, acceptable forms are oil, butter, tallow, and fat. The input's material form comes from the catalogue display name first and only falls back to the INCI name when the display carries no form token — never from the last form token of a display+INCI concatenation (a trailing INCI sibling form such as BUTTER must not override a display OIL, and vice versa).

## Identity must be confirmed before guidance runs
The enrichment pipeline gates after the deterministic identity stages: when neither FDA GSRS nor CosIng confirms a candidate, the guidance research, editorial, authoring, localization, and validation stages are persisted as `skipped` (reason `identity_unresolved`) and the item ends `Failed` with `failure_code=identity_unresolved` — no model tokens are spent on an unverified material. The item reads as unresolved, never as unchanged. Retrying re-runs identity first.

## US declarations use the Common (Plant Name) Form convention
The US declaration follows the owner's FDA-label convention: English common name with the plant name in parentheses — `Coconut (Cocos Nucifera) Oil`, `Beef (Adeps Bovis) Tallow`, `Apricot Kernel (Prunus Armeniaca) Oil`. The parenthetical is the proper botanical name taken from the verified INCI with product-part words (oil, seed, kernel, fruit, ...) stripped; the known FDA sweet-almond label example remains the override. When the registry common name is itself the latin name, the catalogue display name supplies the common part with editorial grade/marketing qualifiers stripped (organic, virgin, refined, cold pressed, ...) — product claims never leak onto the regulatory surface, while material-distinguishing adjectives (sweet, bitter, high oleic) are preserved.

## Corroborated technical CAS/EC identifiers are separately governed
Official identifiers always win. A substance may legitimately carry several accepted CAS or EC numbers, so corroborated values coexist with official ones as non-primary rows; only an exact value the official record already carries is skipped (value-level precedence, owner-confirmed). CAS or EC numbers that official records lack may enter the proposal only when at least two independent consulted research sources explicitly print them — independence is measured by distinct consulted hosts (www-stripped), never by URL count, so two pages on one publisher's domain are a single authority. Entries are tagged with source tier `approved_secondary` and `source_confirmed` provenance listing every corroborating URL. The research model reports identifiers only when a consulted source prints them, never guessing or converting. Stage data read back from PostgreSQL is compared order-insensitively (jsonb canonicalizes object key order); never reintroduce strict array equality on persisted research stage data.
