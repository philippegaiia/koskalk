---
paths:
  - 'app/Services/IngredientEnrichment/**'
  - 'app/Services/IngredientIntake/**'
  - 'app/Actions/IngredientIntake/**'
---

# Ingredient Enrichment

## Deterministic facts precede AI editorial work
Normal ingredient enrichment must obtain identity, identifiers, declarations, and COSING functions from deterministic source stages before any model call. The editorial client receives those facts and must not use web-search tools. Web search is allowed only through the explicitly enabled gap-research path; COSMILE Europe can support cited editorial guidance only, never legal declarations, identifiers, or COSING assignments.

## Source-backed synonyms and guidance evidence
Ingredient synonyms are deterministic identity data: collect only exact authoritative-source names, exclude canonical/display/market-name duplicates, and merge without deleting reviewed aliases. Practical guidance may use the separate restricted editorial-evidence pass; the editorial writer receives paraphrased facts and must never use web tools or invent identity data.

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
