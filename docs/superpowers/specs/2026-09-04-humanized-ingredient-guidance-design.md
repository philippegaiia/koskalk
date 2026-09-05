# Humanized Ingredient Guidance Design

**Date:** 2026-09-04

**Status:** Approved

## Goal

Make generated ingredient guidance read like concise advice from an experienced formulator without changing the evidence model, adding facts, or introducing another AI pass.

## Approach

Add a compact, domain-specific natural-writing contract directly to the English guidance-authoring prompt and the guidance-localization prompt.

English authoring receives the stronger editing rules because it creates the canonical prose. Localization receives the language-neutral subset so every translation preserves the reviewed English facts and qualifications while reading naturally in its own language.

Do not add a separate humanization request. The existing Luna calls remain responsible for authoring and localization, so the change adds no provider round trip.

## English Guidance Rules

The authoring prompt will require the model to:

- preserve every supported fact and avoid inventing details;
- use plain, concrete cosmetic-formulation language, simple verbs, and active subjects;
- vary sentence openings and sentence length when natural;
- remove evidence-report narration, vague attribution, sales language, filler, stock AI vocabulary, and generic positive conclusions;
- avoid repeated qualifications and forced contrasts such as "not only X, but Y";
- keep necessary scientific uncertainty once, in the clearest place;
- retain the existing headings, evidence references, claim structure, usage-range rules, word target, and character limit.

The rules are adapted from the humanizer skill rather than copied mechanically. Formatting preferences that do not materially affect catalogue guidance, such as an absolute punctuation ban, are out of scope.

## Localization Rules

The localization prompt will continue treating the approved English guidance as the sole factual source. For each target locale, it will:

- preserve every fact, limitation, warning, omission, and section;
- use natural native cosmetic and soapmaking terminology;
- recast syntax instead of following English sentence structure;
- prefer simple verbs, concrete wording, and natural rhythm;
- avoid literal calques, bureaucratic evidence language, filler, sales language, repetitive openings, and unnecessary qualifiers;
- avoid adding personality, claims, or explanations absent from the English source.

Identity-name localization remains separate and unchanged.

## Versioning and Compatibility

Bump the English guidance prompt version and guidance-localization prompt version. Existing stored and reviewer-owned guidance remains untouched. A new guidance run uses the revised English contract; a later explicit translation run uses the revised localization contract.

## Testing

Add focused prompt-contract coverage proving that:

- English instructions contain the natural-writing invariants while retaining evidence and no-invention constraints;
- localization instructions preserve facts and qualifications while forbidding literal translation and common AI-writing patterns;
- prompt versions change, invalidating the appropriate cached generation stages;
- the change does not add a provider call or alter response schemas.

Run the focused prompt and refresh tests, the wider ingredient-guidance suite, Pint, `git diff --check`, and Graphify. FilaCheck is required only if Filament code changes.

## Out of Scope

- Changing identity resolution, research sources, evidence thresholds, output schemas, or guidance length limits.
- Post-processing generated prose with a second model call.
- Rewriting existing saved or reviewer-owned guidance automatically.
