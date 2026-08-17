# Ingredient Catalogue Strategy Decision Handoff

**Date:** 2026-08-16  
**Repository:** `/Users/philippe/Herd/koskalk`  
**Branch:** `main`  
**Starting commit:** `99abbe1`  
**Status:** The integrated branch is merged and pushed. The next conversation is for product and architecture decisions, not immediate implementation.

## Opening request for the next conversation

Use this document as the working brief. Inspect the current code and the referenced specifications, then help decide a durable ingredient-catalogue strategy before proposing implementation work.

The current workflow is technically substantial, but its research policy is too rigid in some places and its editorial output is not useful enough. Start with the decisions in this document. Do not begin by adding another source adapter, changing a prompt, or widening an allow-list in isolation.

The human Admin reviewer always has the final decision. Source tier, confidence, provenance, and human approval must remain separate concepts: approving a value accepts it for the Koskalk catalogue but does not turn a secondary source or AI proposal into an official fact.

## Product objective

Build a large, useful platform ingredient catalogue quickly enough for an MVP and continued growth. The catalogue should support soapmaking first while remaining genuinely useful for broader cosmetic formulation.

The intended workflow is:

1. Enter one ingredient or paste/import a batch, often with only a current name and sometimes an INCI name.
2. Research identity, identifiers, names, functions, market declarations, useful formulation facts, and relevant regulatory references.
3. Present a transparent proposal with sources, confidence, warnings, and unresolved fields.
4. Let an Admin reviewer correct any field, add their own knowledge or source, approve, reject, or retry gaps.
5. Promote approved intake rows into the platform catalogue.
6. Publish useful information as non-authoritative guidance; users remain responsible for supplier documents and regulatory checks.

The workflow must work for one ingredient, ten ingredients, or batches of at least seventy. Seventy is a capacity case, not a minimum and not a colourant-only limit.

## Current implementation

### Intake and review

- Admin can create ingredient intake batches from pasted rows or CSV.
- Each row accepts `current_name`, `inci_name`, or both.
- An optional family hint guides research without becoming an approved category.
- Exact duplicates require resolution; similar matches warn without automatically merging.
- Intake rows remain outside the active catalogue until promotion.
- Research runs one queued job per subject, so one failure does not have to stop the batch.
- Proposals can be reviewed and edited before approval.
- Human approval does not mutate the catalogue; **Apply approved** performs the actual update or promotion.
- Category and compatible subcategory remain catalogue requirements at promotion, not intake requirements.

### Stored catalogue concepts

- Canonical/display identity and canonical INCI.
- Multi-valued identifiers, including CAS, EC/EINECS, UNII, CosIng reference, and other supported schemes.
- Identifier evidence stored separately from identifier values.
- Aliases/synonyms with locale and kind.
- EU and US market declarations stored separately from identifiers.
- COSING functions with assignment provenance.
- Saponification name, which is a short translatable material/plant stem rather than an INCI.
- Separate NaOH and KOH soap declaration proposals.
- English guidance plus catalogue translations.
- Category, subcategory, taxonomy provenance, reviewer, and review date.
- Allergen, substance/restriction, IFRA, fatty-acid, SAP, and component areas exist, but most are outside the current general enrichment pass.

### Current research stages

The normal pipeline currently performs:

1. Identity preparation.
2. FDA GSRS/openFDA identity lookup.
3. CosIng Checker structured lookup.
4. EUR-Lex Common Ingredient Names glossary corroboration.
5. US declaration lookup, with a separate FDA colour-additive path.
6. Conflict evaluation and fact assembly.
7. Restricted AI web research for editorial guidance.
8. AI editorial generation and translations.
9. Validation, review, approval, and apply/promotion.

Configured deterministic sources are currently:

- openFDA/GSRS for US substance identity;
- CosIng Checker as a structured mirror of EU ingredient data;
- the EUR-Lex Common Ingredient Names glossary for official name corroboration;
- the FDA permitted-colour-additives page for US colour declarations.

The guidance web pass is restricted to configured domains and produces candidate evidence only for `proposal.info_markdown`.

Important code:

- `app/Services/IngredientEnrichment/IngredientEnrichmentPipeline.php`
- `app/Services/IngredientEnrichment/IngredientEnrichmentFactsBuilder.php`
- `app/Services/IngredientEnrichment/IngredientIdentityMatchService.php`
- `app/Services/IngredientEnrichment/OpenAiIngredientGapResearchClient.php`
- `app/Services/IngredientEnrichment/IngredientEnrichmentEditorialPrompt.php`
- `app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php`
- `app/Services/IngredientEnrichment/Sources/CosingCheckerClient.php`
- `app/Services/IngredientEnrichment/Sources/EurLexGlossaryClient.php`
- `app/Services/IngredientEnrichment/Sources/OpenFdaSubstanceClient.php`
- `app/Services/IngredientEnrichment/Sources/FdaColourAdditiveClient.php`
- `config/ingredient-enrichment.php`

Primary existing specifications:

- `docs/superpowers/specs/2026-08-14-hybrid-ingredient-enrichment-source-pipeline-design.md`
- `docs/superpowers/specs/2026-08-15-ingredient-enrichment-trust-and-bulk-intake-design.md`
- `docs/handoff-ingredient-catalog-localization.md`
- `.ai/rules/ingredient-enrichment.md`

## What works well

- Bulk intake is materially faster than creating fully classified ingredients one by one.
- Intake keeps incomplete rows away from the public catalogue.
- Review and manual editing are essential and should remain central.
- EU INCI and US declaration are correctly treated as different fields.
- CI names are supported for EU colour declarations while the US can use its separate FDA declaration.
- Identifiers are multi-valued and retain evidence instead of collapsing everything into one CAS or EC field.
- Valid COSING functions can be imported and displayed as editable Filament selections.
- The workflow records source calls, web calls, model, token use, warnings, and unresolved questions.
- Batch deletion cleans up its related private artifacts.
- Human review can accept useful values even when optional facts remain unresolved.

## Central problem: the pipeline is safe but not flexible enough

### Concrete example: Bacaba pulp oil

The current enrichment can return no INCI, CAS, or EC for Bacaba pulp oil. A normal targeted web search can surface plausible identity data quickly, but the pipeline cannot use that result for identity fields.

This is not mainly a model-capability failure. It follows from the current contract:

- deterministic adapters are the only stages allowed to establish INCI, CAS, EC, market declarations, and COSING functions;
- AI gap research is explicitly limited to practical guidance evidence;
- the gap-research prompt explicitly forbids establishing identifiers or INCI names;
- the final editorial model cannot research the web and cannot change deterministic facts;
- citations outside the approved research-site policy can invalidate a result rather than preserving valid fields and isolating the disputed one.

When openFDA, CosIng Checker, or the exact EUR-Lex matcher misses a niche ingredient, the system is designed to leave identity unresolved. Increasing model reasoning effort will not fix that policy boundary.

### Why the current restriction existed

The restriction protects against:

- attaching the identifiers of a related derivative, plant part, salt, extract, or trade product;
- treating the first search result as the correct identity;
- using a search snippet, marketplace, supplier marketing page, or copied database as official evidence;
- silently resolving conflicting CAS or EC values;
- presenting AI-inferred nomenclature as verified regulatory data.

Those risks remain real. The next design must add flexibility without erasing identity distinctions or provenance.

### Decision required

Choose how unresolved identity fields may be proposed:

1. **Strict deterministic only:** retain the current boundary and accept many unresolved niche ingredients.
2. **Tiered candidate research:** allow a dedicated identity-gap research stage to propose INCI, CAS, EC, UNII, aliases, and exact source URLs from an approved hierarchy. Values remain candidate/supported or conflicting and require human approval. This is the leading option for discussion.
3. **Open assisted research:** allow broader web results because every value is human-reviewed, while prominently grading source quality and conflicts.

The likely durable distinction is not “AI may” versus “AI may not.” It is:

- what field the source is competent to support;
- whether the exact material identity matches;
- how the source tier and confidence are recorded;
- whether conflicting values remain visible;
- what the reviewer must explicitly accept.

## Identity and naming issues requiring decisions

### Canonical INCI and market declarations

- Canonical INCI currently follows the EU/common-ingredient-name style used by the initial market.
- For botanicals, EU style normally omits the English common name in parentheses.
- US declaration remains a separate market value using the common/usual English naming rule; an international/INCI name may follow parenthetically when appropriate.
- Future Canada, Brazil, and other markets must be new regime-specific declarations rather than reusing `us` as a generic non-EU name.
- Brazil will eventually need both INCI and Portuguese composition representations; it should not be modelled as one declaration string.

### Colourants

- EU colour declaration can use a CI name such as `CI 77491`.
- US colour declarations use FDA names/allowed abbreviations and cannot be generated from the CI number alone.
- A “Colourants” intake hint should route colour research but must not force colourants to be the ingredient's primary catalogue category.
- Titanium dioxide, zinc oxide, and other multi-role substances show why regulatory authorization as a colourant is not the same as primary catalogue taxonomy.

### Identifiers

- CAS and EC values may be multiple and source-dependent.
- A disagreement is `conflicting`, not merely `unresolved`.
- EC/EINECS is important and should be collected when available.
- UNII is a US identity identifier, not the US label declaration.
- A reviewer must be able to enter or correct identifiers and attach their own source or professional judgement without falsifying source tier.

### Aliases and synonyms

- Useful common, botanical, former, and spelling names should support search and review.
- Exact source names should remain separate from translated display names.
- Synonyms must not collapse materially different plant parts, extraction methods, salts, or derivatives.
- Decide whether the new identity-gap stage may propose aliases from strong secondary sources when official sources have none.

### Saponification fields

- `saponification_name` is a short translatable stem such as `Coconut`, not a regulatory identity.
- NaOH and KOH soap names are separate declaration candidates.
- Exact sodium/potassium entries are frequently difficult to locate in official sources even when the conventional name is well known.
- Current rules allow visibly AI-proposed salt names only with explicit human approval; they must never be relabelled as CosIng-confirmed.
- Decide whether trusted reviewer knowledge is enough to save these values and what evidence/provenance the UI should record.

## Guidance quality is below the product standard

### Current problem

The guidance exists, but much of it is thin, repetitive, and too generic to help a user. Earlier examples resembled:

- “This vegetable oil can be used in the oil phase.”
- “Vegetable oils can react with alkali to form soap.”
- cautious summaries of what was or was not researched rather than useful formulation insight.

The current prompt now forbids research-process commentary and asks for 140–280 words under Overview, Formulation use, and optional Soapmaking. That improves tone, but it cannot create useful expertise when the evidence supplied to it contains only a short CosIng description and function names.

More tokens alone will not solve missing evidence. A richer prompt can improve writing, but the system first needs a better guidance fact contract.

### Desired catalogue value

For a lipid, useful concise guidance could cover, when supported:

- what the material is, source organism and plant part;
- fixed oil, butter, wax, essential oil, pigment, mineral, starch, surfactant, preservative, or other relevant material class;
- typical physical form and practical phase placement;
- solubility or dispersion behavior;
- emollience, occlusivity, structure, absorption/skin-feel, cleansing, hardness, lather, trace, colour, or other relevant qualitative contribution;
- oxidation, heat, storage, compatibility, staining, dispersion, pH, or handling cautions;
- whether the material is normally a main functional component, a supporting component, or a specialist additive;
- for soapmaking, a genuinely useful qualitative contribution and handling consideration without inventing SAP, percentages, or performance guarantees.

Not every ingredient needs a Soapmaking section. The content should vary by ingredient family instead of applying an oil-shaped template to every substance.

### Decision required

Choose the guidance architecture:

1. **One Markdown generation pass:** improve sources and prompt but continue storing only prose.
2. **Structured family profile, then prose:** research/store bounded facts such as material class, origin, physical form, phase, solubility, qualitative functions, handling, stability, soap relevance, and cautions; generate concise Markdown from reviewed facts. This is the leading option for discussion.
3. **Reviewer-authored editorial:** AI drafts lightly, but the Admin writes most guidance manually.

Also decide:

- which source tiers may support practical formulation guidance;
- whether supplier technical documents can support handling/use for their exact material;
- whether CIR, PubMed, COSMILE, ECHA, manufacturer technical documents, and specialist soap references have different permitted fields;
- which claims require a citation in the review UI;
- whether guidance should be approved by section or as one field;
- whether translations are regenerated after an English edit or maintained independently.

## Official datasets and the regulatory boundary

The Commission datasets can supply more than identity:

- Annex IV: permitted colourants and use conditions;
- Annex V: permitted preservatives and maximum concentrations/conditions;
- Annex VI: permitted UV filters and maximum concentrations/conditions.

FDA colour data will also be supplied separately.

These datasets contain many chemical/regulatory entries that should not automatically become user-facing platform ingredients. They also use chemical names or formulas where an approachable display name may be absent.

The preferred distinction to evaluate is:

- **Platform ingredient catalogue:** curated materials users can actually select and understand.
- **Regulatory reference catalogue:** imported source records, restrictions, permitted uses, maximum concentrations, conditions, annex references, and version dates.
- **Links between them:** one platform ingredient can link to zero, one, or several regulatory records for a market and purpose.

This would allow useful warnings and insights without importing all of CosIng or every annex row as an active ingredient.

Koskalk is not intended to certify compliance. It provides point-in-time information and warnings. Users must cross-check authoritative sources and supplier documentation. The product can still give valuable professional-grade insights if source, date, market, use, and limits remain visible.

### Decisions required

- Import official EU datasets into local versioned reference tables, query them remotely, or use a hybrid cache/snapshot approach?
- Which official files are in MVP scope: inventory/glossary, Annex IV, V, VI, and/or other restricted-substance annexes?
- How are source version, effective date, row identity, amendments, and superseded records tracked?
- Which restrictions become structured calculation warnings and which remain informational text?
- How does a regulatory function such as colourant or UV filter coexist with the ingredient's primary catalogue category?
- How are entries that are too chemical or irrelevant to the target audience kept out of the selectable catalogue while remaining available for substance matching?

## Intake needs richer optional context without becoming complicated

Name-only and INCI-only intake must remain quick. Many similar ingredients make automatic matching risky, so optional context can improve research without becoming compulsory.

Evaluate optional intake columns or batch defaults for:

- known CAS or EC;
- CI number;
- plant part;
- material form or process;
- supplier/trade name;
- family hint;
- reviewer source URL;
- short reviewer note.

The simple paste format must still work with one name per line or the two identity columns. Extra fields should be optional and discoverable rather than turning intake into a regulatory spreadsheet mapper.

## Review and validation issues

- Valid partial fields should survive when one citation or field is unacceptable.
- A non-approved source should downgrade or isolate that proposed field rather than necessarily failing the entire proposal.
- Review must show current, proposed, source, tier, confidence, conflicts, and reviewer decision together.
- Reviewers need a straightforward way to add their own value and source.
- A reviewer edit should retain the original AI/source proposal in the audit.
- Retrying should support unresolved stages/fields without rerunning successful expensive work.
- It should be obvious whether **Approve** accepts a proposal and **Apply approved** mutates/promotes catalogue data.
- Category and subcategory proposals must be visible and editable for intake rows before promotion.
- Human approval may accept an unresolved or secondary-source candidate, but it must not upgrade its evidence classification.

Decide whether review remains item-level with editable fields or becomes per-field acceptance. Per-field review is more precise but can make large batches slow; the design should preserve a fast path for a reviewer who is satisfied with the whole proposal.

## Source strategy decisions

Create a field-to-source competence matrix before adding sources. For every candidate source, specify which fields it may support:

- official/canonical identity;
- candidate identity;
- identifiers;
- aliases;
- market declaration;
- regulatory authorization/restriction;
- COSING function;
- material properties;
- formulation handling;
- soapmaking behavior;
- supplier-specific facts only.

Potential source classes already discussed include:

- official EU glossary and annex datasets;
- official FDA/GSRS and CFR/FDA colour data;
- a structured CosIng mirror;
- ECHA, PubChem, CAS Common Chemistry, Kew POWO, CIR, PubMed, and COSMILE;
- manufacturer or supplier technical documents for the exact commercial material;
- reviewer-supplied professional knowledge and references.

Avoid treating an approved hostname as universally authoritative. A source may be excellent for a botanical identity and inappropriate for a legal declaration, or useful for handling guidance and inappropriate for a general CAS assignment.

## Catalogue deployment remains separate

The integrated application is now on `main`, but a durable catalogue-data deployment process is still deferred.

The original idea was to curate the development database, reverse-generate seed data, and reseed production. No Orangehill/iSeed dependency or completed reverse-seeding workflow currently exists.

After the catalogue strategy stabilizes, decide whether production catalogue data is delivered through:

- deterministic versioned seed datasets;
- an explicit catalogue export/import command;
- a data migration/release artifact;
- or a controlled combination.

Do not make production synchronization the first decision. First settle what a reviewed ingredient record and its evidence must contain.

## Representative acceptance cases

Use a small adversarial set to evaluate the chosen strategy before broad implementation:

1. **Bacaba pulp oil:** niche botanical where ordinary web research finds plausible INCI/CAS/EC but current deterministic adapters do not.
2. **Bacaba kernel oil:** nearby identity that must not be merged with pulp oil.
3. **Coconut oil or cocoa butter:** common lipid with strong identity, multiple functions, useful soap guidance, saponification stem, and possible soap declarations.
4. **Zea mays starch:** non-lipid with multiple COSING functions and no generic oil guidance.
5. **CI iron oxide colour:** EU CI identity, separate FDA colour declaration, restrictions, and colourant routing.
6. **Titanium dioxide or zinc oxide:** multi-role ingredient where regulatory colour use does not necessarily define primary catalogue taxonomy.
7. **A preservative from Annex V:** structured use conditions and maximum concentration without turning Koskalk into a compliance certification tool.
8. **A UV filter from Annex VI:** chemical official record linked to an approachable platform ingredient only when the catalogue actually needs it.
9. **An essential oil:** identity, aliases, IFRA/allergen relevance, volatility, and soap/cosmetic guidance distinct from a fixed oil.
10. **A manufactured derivative with a similar botanical prefix:** must not inherit the base ingredient's identity or identifiers through fuzzy matching.

For Bacaba pulp oil specifically, the next design should demonstrate:

- candidate INCI, CAS, and EC can be surfaced when credible page-level sources exist;
- every value has an exact URL, source tier, confidence, and identity reasoning;
- conflicting or plant-part-mismatched values remain visible;
- the human can correct or accept the proposal;
- valid guidance and other fields survive if one identity value remains unresolved;
- the resulting guidance is useful to a soapmaker or cosmetic formulator rather than a report about the research process.

## Recommended agenda for the next conversation

1. Inspect the stored proposals and sources for five representative ingredients, beginning with Bacaba pulp oil.
2. Decide the unresolved-identity research policy and field-level source competence rules.
3. Decide whether official source datasets should be imported into versioned local reference tables.
4. Define a structured, family-aware guidance fact contract and a minimum useful guidance standard.
5. Decide the boundary between platform ingredients and regulatory reference substances.
6. Decide review granularity, reviewer-supplied evidence, partial-result handling, and targeted retries.
7. Decide the smallest release slice and write a new implementation specification and plan.

## Completion criterion for the decision phase

The decision phase is complete when the team can state, without ambiguity:

- which source classes may propose each field;
- how unresolved identity research works;
- how evidence, confidence, provenance, and reviewer approval remain separate;
- what facts make ingredient guidance genuinely useful for each major ingredient family;
- what regulatory datasets are stored and how they link to selectable ingredients;
- what remains informational rather than computational compliance logic;
- how a reviewer efficiently handles both one ingredient and large batches;
- and which representative cases the first implementation must pass.

Only then should implementation planning begin.
