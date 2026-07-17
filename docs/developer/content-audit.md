# First Content Audit

Last updated: 2026-07-11

## Scope

This first pass covers the public soap calculator and the shared soap/cosmetic recipe workbench. It reviews visible labels, helper text, empty states, warnings, generated status messages, and explanatory content in:

- `resources/views/calculator`
- `resources/views/livewire/dashboard/partials/recipe-workbench`
- `resources/js/recipe-workbench`
- workbench-related Livewire and service classes

It does not yet cover the landing page, account settings, recipe index, production screens, email, or the English-only Filament admin.

The homepage was reviewed separately on 2026-07-11. Its agreed localization model is version-controlled English copy and layout, translated as complete semantic blocks through Spatie. A separate CMS or page builder is not currently planned.

The detailed review is stored in `.impeccable/critique/2026-07-11T08-25-41Z__resources-views-welcome-blade-php.md`. Its main conclusion is to preserve the visual identity and calculation preview while making the product category literal, correcting account-path promises, adding real workspace proof, and substantially reducing repeated persuasion.

Implemented on 2026-07-11: the homepage now uses a literal product heading, accurate guest and authenticated paths, one concise workspace-proof section, and no unavailable production-batch promise.

## Classification

Every user-facing text should have one owner:

1. **Interface microcopy**: labels, buttons, statuses, concise errors, empty states, tooltips, and accessibility text. Store through Laravel/Spatie translation keys.
2. **Contextual help**: short explanations tied to a field, metric, warning, or section. Manage as structured, translatable platform content when editors need to revise it without deployment.
3. **Documentation**: concepts, methods, examples, assumptions, and compliance explanations that need more than a few sentences. Publish as articles and link to them from contextual help.
4. **Platform data**: translated names or descriptions belonging to ingredients, product types, categories, regulatory regimes, and similar managed records.
5. **Remove or rewrite**: redundant, developer-facing, vague, or overly technical copy that does not help the current task.

## Executive findings

### 1. The workbench contains an embedded knowledge base

Soap-quality interpretation is currently encoded as many long JavaScript strings in `resources/js/recipe-workbench/sections/presentation-section.js`. These explain hardness, longevity, cleansing, lather, DOS risk, slime tendency, cure speed, and process context.

These texts are not ordinary button labels. Keeping them in JavaScript would make ten-language editing expensive and would force deployments for every scientific or editorial correction. The metric label and short state belong to interface microcopy; the explanation belongs to contextual help, backed by a full methodology article where needed.

### 2. Product copy sometimes explains the software architecture

Several passages describe how the application is organized rather than helping the maker complete the task. Examples include:

- "Business view without cluttering the formula bench"
- "Ingredient identity stays shared. The price memory stays private..."
- "Formula rows stay read-only here except for price per kilo, so development and costing each get their own space."
- "Categories appear once the cosmetic catalog is populated."
- "Add saponifiable oils with SAP data to see backend-calculated Soapkraft qualities here."

These should be removed or rewritten in task language before translation.

### 3. Repeated eyebrow, title, and paragraph patterns add avoidable copy

Some sections repeat the same concept three times. Packaging uses "Packaging plan" as both eyebrow and heading, followed by an explanation. Quality analysis uses "Soapkraft qualities", "At a glance", and a parenthetical disclaimer. This repetition increases visual and translation load without adding meaning.

Use one clear heading, then retain only information the user needs to act or interpret results safely.

### 4. Critical assumptions are mixed with optional explanation

The output tab explains cured-bar basis, 11% residual water, allergen treatment, and ingredient-list normalization in long visible paragraphs. The assumptions are important and must remain visible, but the full rationale should move to contextual help or documentation.

Recommended pattern:

- visible: `Cured basis · 11% residual water`
- contextual help: what the basis changes in this output
- documentation: methodology, limitations, and examples

### 5. Translation boundaries are currently mixed

Most workbench strings are hard-coded in Blade, JavaScript, or PHP. Only isolated values such as currency names already use Laravel translation keys.

The current content also mixes:

- interface labels such as `Save`, `Output`, and `Formula balanced`
- platform taxonomy such as ingredient-category labels
- managed catalog data such as product-type and regulatory-regime names
- scientific interpretation such as quality explanations
- user-authored content such as formula names and instructions

They need separate translation paths. See [localization.md](./localization.md).

### 6. Dynamic strings need translation-safe composition

Patterns such as `Price per kilogram for ` plus an ingredient name, manually assembled singular/plural text, and nested JavaScript ternaries will be difficult to translate correctly. Use complete keyed sentences with placeholders and Laravel pluralization where grammar changes by count.

## First-pass inventory

| Surface | Current content | Classification | Recommendation | Priority |
| --- | --- | --- | --- | --- |
| Workbench navigation | Formula, Packaging, Costing, Output, Instructions & Media | Interface microcopy | Keep, standardize terminology, translate with stable keys | P1 |
| Formula settings | Field labels, modes, unit labels, show/hide states | Interface microcopy | Keep concise and move to translation keys | P1 |
| Formula settings empty states | Catalog-population messages | Remove or rewrite | Replace implementation language with a user-facing unavailable state | P0 |
| Water mode and superfat | Names only, with critical negative-superfat confirmation | Microcopy plus contextual help | Keep controls concise; add help for mode definitions; retain a direct safety warning | P1 |
| Ingredient browser | Search, filters, result counts, inspector labels | Microcopy plus platform data | Translate controls; translate category taxonomy separately; preserve ingredient names through platform-data resolution | P1 |
| Ingredient rows | Dynamic accessible labels using concatenated strings | Interface microcopy | Use placeholder-based full strings | P1 |
| Reaction core | Section title, balance status, lye/water totals | Interface microcopy | Keep; standardize `base`, `oils`, and `reaction core` terminology | P1 |
| Cosmetic phases | Phase labels and full-formula-basis explanation | Microcopy plus contextual help | Keep labels; shorten visible explanation; document percentage/weight behavior once | P2 |
| Post-reaction phases | Reorder instructions repeated in section descriptions | Remove or rewrite | Put drag behavior in accessible labels/tooltips; remove repeated visible instructions | P0 |
| Soap qualities | Metric labels, target states, dozens of interpretations | Microcopy plus contextual help/documentation | Keep metric names and concise states in UI; move explanations into editable help content; document methodology | P0 |
| Output basis | Cured basis, residual water, ingredient normalization | Contextual help plus documentation | Keep assumptions visible as compact metadata; move rationale and examples out of the main flow | P0 |
| Ingredient-list preview | Generated/final/plain-language variants and warnings | Microcopy plus documentation | Keep workflow labels; clarify `generated` versus `final`; document labeling limitations | P1 |
| Restrictions | Status, rule labels, warnings, regime names | Microcopy plus platform/regulatory data | Translate UI states; resolve regime/rule labels from managed data; document that screening is guidance | P0 |
| Declared allergens | Basis explanation and empty state | Contextual help | Keep a short basis note; move detailed counting rationale to documentation | P1 |
| Costing introduction | Internal architecture and privacy explanation | Remove or rewrite | Replace with a direct task heading; keep privacy details only where they affect trust | P0 |
| Costing tables | Price, unit, subtotal, empty states | Interface microcopy | Keep and translate; use locale-aware number/currency formatting | P1 |
| Packaging plan | Repeated heading and explanatory paragraph | Remove or rewrite | Use one heading and a concise unit-basis hint | P0 |
| Save and version messages | Save, lock, duplicate, comparison, deletion statuses | Interface microcopy | Consolidate terminology and move PHP/JS fallbacks to translation keys | P1 |
| Free calculator introduction | No-account eyebrow, title, explanatory sentence | Interface/marketing microcopy | Keep one concise value statement; remove repetition after a visual review | P2 |
| Account teasers | Saved bench record, private catalog, production-ready copy | Interface/marketing microcopy | Rewrite `bench record` and verify claims against available plan features | P2 |

## Immediate removal and rewrite queue

These are the strongest candidates for the first cleanup pass:

1. Remove software-architecture language from Costing.
2. Replace `backend-calculated` and `catalog is populated` wording.
3. Remove duplicated section eyebrows when they repeat the heading.
4. Remove repeated visible drag-and-reorder instructions; preserve accessible instructions.
5. Reduce long output-basis paragraphs to compact assumptions plus a help entry point.
6. Replace parenthetical quality disclaimers with a direct statement: results are estimates affected by process, additives, and cure.

## Editorial decisions recorded after this audit

- The soap bench should use `Saponification` rather than `Core reaction`.
- The stage currently described as `Post-reaction phases` should use `Formula additions`. In cold-process soap, the reaction is not necessarily complete when additives or fragrance are incorporated.
- Review page headers and explanatory text for natural, practical language before translation. Remove copy that tries to sell the product or explains application architecture instead of helping the maker complete the task.
- Translation is a downstream activity. Approve the English source and terminology glossary first so every locale starts from stable wording.

## Documentation topics discovered

Koskalk does not yet have end-user application documentation, a help center, or a complete "how to use the app" guide. The developer documents in this repository describe architecture and implementation; they are not user documentation.

The audit identifies the following candidate topics for a future documentation set:

- Understanding water calculation modes
- Superfat, negative superfat, and lye safety
- How Soapkraft quality indicators are calculated and interpreted
- Fatty-acid groups and their practical meaning
- Cured-weight and residual-water assumptions
- Generated versus final ingredient lists
- INCI, plain-language names, and market-specific labeling
- Allergen declarations and formula basis
- Restriction screening, regulatory regimes, and limitations
- IFRA product context
- Formula percentages versus weight entry
- Ingredient and packaging costing basis

These articles do not exist yet. When created, they should be available independently and reusable by contextual help and the future companion.

## Contextual help and companion direction

Use stable semantic help keys such as:

- `formula.water_mode`
- `formula.superfat`
- `quality.dos_risk`
- `output.cured_basis`
- `labeling.generated_vs_final`
- `compliance.restriction_screening`

A help record should eventually support title, concise summary, article body or article link, locale, publication status, revision metadata, and optional placement metadata. The workbench and companion should resolve the same published content by key. Do not maintain separate companion explanations.

For safety and compliance content, show the concise warning directly in the workflow. Help and documentation may explain it further but must not hide information needed to avoid harm.

## Recommended sequence

1. Complete the P0 copy cleanup before translating the workbench.
2. Establish a terminology glossary for formula, phase, batch, base, output, ingredient list, INCI, restriction, and compliance terms.
3. Move ordinary interface strings to Laravel/Spatie keys, including PHP and JavaScript messages.
4. Define the information architecture for new end-user documentation, including getting-started and task-based guides.
5. Design the structured contextual-help and documentation model from the topics discovered here.
6. Connect the future companion to the same help keys.
7. Audit the remaining public application surfaces before activating another locale.

## Audit limitation

This is a source-level content audit, not yet a complete visual or task-based usability review. A later pass should inspect each workflow in the browser at desktop and mobile widths, because copy that appears concise in source may still compete with surrounding controls or repeat nearby visual information.
