# Ingredient Identifiers, Aliases, and Formula Limits Design

**Date:** 2026-08-12

## Purpose

Replace the single CAS and EC fields on ingredients with a professional identity model that supports multiple identifiers and localized alternative names without turning Koskalk into a global chemical registry. Keep the customer experience simple, preserve platform curation, and maintain strict workspace ownership.

Add a plan-configurable maximum number of ingredient lines in each formula. This closes an independent free-plan resource-abuse gap while keeping legitimate cosmetic formulas practical.

## Settled Product Decisions

- CAS is Koskalk's preferred international external identifier, but it is optional and not an ingredient's internal identity.
- An ingredient can have zero to ten typed external identifiers.
- An ingredient can have localized aliases independently from its identifiers.
- Platform ingredient identifiers and aliases are curated by administrators and read-only for workspaces.
- Workspace-owned ingredient identifiers and aliases are managed by that workspace only.
- A platform ingredient remains shared. A workspace that needs different identity or compliance data duplicates it into an independent workspace-owned ingredient.
- Duplication copies data as a starting point. It creates no inheritance or continuing synchronization with the platform ingredient.
- Workspace ingredients may have at most five aliases in total and ten identifiers in total.
- Platform ingredients may have at most five aliases per locale and ten identifiers in total.
- Workspace-wide ingredient allowances continue to use the existing `private_ingredients` plan limit.
- Formula ingredient-line allowances use a new plan limit: 30 lines for the free plan and 50 lines for paid plans. Administrators can change these values from the existing Plan settings.
- Ingredient-specific request throttling and a wider abuse-protection review remain outside this feature.

## Domain Language

### Ingredient

An ingredient is Koskalk's internal material identity. Its immutable internal database/public identity remains authoritative even when external identifiers are absent, disputed, historical, or shared by several records.

### Ingredient identifier

An ingredient identifier is a typed external reference attached to one ingredient, such as CAS, EC, UNII, or a future jurisdiction-specific identifier. It is supporting identity data, not the ingredient's primary key.

An ingredient may have several values for the same scheme. One value per scheme may be marked primary for compact display. “Primary” means preferred for display; it does not assert that competing literature values are false.

### Ingredient alias

An ingredient alias is an alternative searchable name attached to one ingredient. It may be localized, such as `huile de nigelle`, or language-neutral, such as a botanical name.

Aliases do not replace the localized display name or INCI. Alias values are not globally unique because common names can be ambiguous. Search always presents candidates and never silently resolves an alias to one ingredient.

### Formula ingredient line

A formula ingredient line is one stored ingredient row in one formula phase. The same ingredient used in two different phases counts as two lines because each row has its own phase, percentage or weight, position, and note.

## Identity Data Model

### Ingredient identifiers

Create `ingredient_identifiers` with:

- `id`
- `ingredient_id`, cascading when its ingredient is deleted
- `scheme`, stored as a string and cast to a PHP enum
- `value`, preserving the user-facing identifier
- `normalized_value`, used for matching and uniqueness
- `is_primary`
- timestamps

Initial schemes are:

- `cas`
- `ec`
- `unii`
- `echa_list`

New schemes can be added later without changing the ingredient table. ECHA list numbers must not be labelled as EC or EINECS. PubChem CID, DTXSID, and country-specific identifiers are deferred until Koskalk has a concrete use for them.

The database prevents duplicate normalized values for the same ingredient and scheme. The write service ensures at most one primary value for each ingredient and scheme. If the first value for a scheme is added without an explicit primary, it becomes primary automatically. Deleting a primary promotes the first remaining value for that scheme deterministically.

Identifier normalization trims surrounding whitespace and applies scheme-appropriate casing and separator normalization without inventing or correcting digits. Normal customer input is checked for safe length and allowed characters. The legacy migration preserves questionable existing values instead of guessing corrections.

### Ingredient aliases

Create `ingredient_aliases` with:

- `id`
- `ingredient_id`, cascading when its ingredient is deleted
- `locale`; the BCP 47 value `und` means language-neutral
- `name`, preserving the displayed alias
- `normalized_name`, used for matching and uniqueness
- `kind`, stored as a string and cast to a PHP enum
- timestamps

Initial alias kinds are:

- `common`
- `botanical`
- `spelling`
- `former`

The database prevents duplicate normalized aliases for the same ingredient and locale. Using `und` instead of a nullable locale keeps that uniqueness enforceable for language-neutral aliases. The database deliberately permits the same alias on different ingredients. For example, `cumin` must not automatically identify *Nigella sativa* because ordinary cumin and black cumin can refer to different materials.

Alias normalization trims whitespace, collapses repeated internal whitespace, and compares case-insensitively. Alias names are limited to 150 characters.

### Ownership and tenancy

Identifiers and aliases inherit ownership and visibility from their parent ingredient. They do not duplicate `workspace_id`, `owner_type`, or visibility columns.

All mutations go through parent-ingredient authorization:

- administrators can curate platform ingredients;
- workspace members with ingredient-edit permission can mutate their workspace ingredient;
- no workspace request can mutate platform aliases/identifiers or records belonging to another workspace.

Workspace aliases participate only in searches performed by their owning workspace. Platform aliases remain available to every authenticated workspace.

### Identity synchronization boundary

A shared ingredient-identity synchronizer owns identifier and alias normalization, deduplication, limits, primary selection, and relationship replacement. Dashboard authoring, platform administration, duplication, and future imports call this service instead of persisting child rows independently.

The synchronizer receives an authorized parent ingredient and plain identifier/alias state. It locks the ingredient, rechecks the applicable platform/workspace limits, and replaces the child collections atomically. It does not decide who may edit the ingredient; the calling application service performs authorization before invoking it.

## Limits

### Per ingredient

| Ingredient ownership | Alias limit | Identifier limit |
| --- | ---: | ---: |
| Platform | 5 per locale, including the `und` language-neutral group | 10 total |
| Workspace | 5 total | 10 total |

The limits apply to creates, edits, duplication, and any future import path. Normalized duplicates are rejected before counting. The server enforces every limit; browser controls are convenience only.

The existing workspace `private_ingredients` plan quota bounds total workspace-owned child records. Separate workspace-wide alias and identifier plan limits are unnecessary. With the current free allowance of 20 private ingredients, the maximum is 100 workspace aliases and 200 workspace identifiers.

### Formula lines

Add the `formula_items_per_recipe` key to the existing plan-limit vocabulary:

- Free plan: 30
- Paid plan: 50
- Empty plan value: unlimited, matching existing plan-limit semantics
- Zero: no ingredient lines may be saved

The value is editable in Admin → Plans → Limits. The free-plan seeder creates the missing value at 30 without overwriting an administrator's later change. Existing billable plans receive 50 only when they do not already define the value; newly created paid plans expose the same setting and default it to 50 in the admin workflow.

The count includes all normalized ingredient rows across all formula phases. It excludes packaging items, instructions, phase headings, and calculated outputs such as lye or water quantities that are not stored as recipe ingredient rows.

## User Experience

### Platform ingredients

The workspace ingredient detail displays primary CAS and EC values in the ordinary identity summary. If additional identifiers exist, a compact “Additional identifiers” disclosure lists them by scheme.

Platform aliases and identifiers are read-only. Users can search with them but cannot change them. The existing “duplicate to customize” workflow remains the path to ownership.

### Creating or editing a workspace ingredient

The initial identity form remains understandable:

- Name
- INCI
- CAS number, optional
- EC number, optional

An “Add another identifier” control reveals typed additional rows. The first value entered for a scheme becomes primary automatically; advanced controls allow changing the primary value.

An optional “Alternative names” area lets the workspace enter up to five aliases. The locale defaults to the active workspace/user locale. Botanical aliases may be language-neutral.

The UI shows remaining capacity before the user reaches a limit and gives a translated validation message when the server rejects excess rows.

### Admin platform curation

The shared ingredient form replaces its single CAS and EC inputs with identifier management and adds localized alias management. Administrators can select a locale for each alias and enter up to five aliases in each locale.

The form emphasizes CAS and EC while keeping UNII and ECHA list numbers available under additional identifiers. It must not imply that ECHA list numbers are EINECS entries.

### Formula workbench

The workbench receives the current plan's formula-line limit and displays a compact count such as `12 / 30 ingredient lines`.

At the limit, ingredient-add controls stop adding rows and show a translated plan-limit message. The server independently checks the normalized payload during save and publish.

An existing formula above a newly lowered plan limit remains viewable, printable, and exportable. The user may edit it without increasing its current line count and may progressively remove rows. Creating, duplicating, restoring, or otherwise increasing a formula beyond the current plan limit is rejected.

## Search

Ingredient searches cover:

- localized display name with the existing English fallback
- INCI
- active-locale aliases
- language-neutral aliases
- English aliases when the active locale has no corresponding localized alias
- every identifier value

Workspace searches combine accessible platform ingredients with workspace-owned ingredients. User-owned aliases never leak into another workspace's search results.

The formula ingredient catalogue receives normalized search terms for aliases and identifiers so browser-side filtering behaves consistently with server-side platform search. Search results continue to display the localized ingredient name and INCI rather than replacing the name with the matching alias.

An ambiguous alias can return several candidates. No create, duplicate, or formula action may automatically choose the first alias match.

## Platform Duplication

Duplicating a platform ingredient creates an independent workspace ingredient and copies:

- the localized platform display name, saponification name, and information for the active locale, falling back to English;
- all identifier records, preserving the primary selection;
- language-neutral aliases;
- aliases for the active locale, or English aliases when the active locale has none;
- SAP and fatty-acid data;
- composition/components;
- allergen entries;
- restricted-substance entries;
- function assignments and their assignment classification;
- IFRA certificates and limits;
- the existing responsibility/origin marker used for duplicated data.

Alias copying follows a deterministic priority—active locale, language-neutral, then English fallback—and stops at the workspace limit of five. Duplicate normalized values are discarded. Identifier copying stops at ten, although a valid platform ingredient cannot exceed that limit.

After duplication, the workspace owns every copied child row. Later platform edits do not update it, and workspace edits do not affect the platform record.

## Classification Prompt and Exports

The classification prompt builder receives identifier collections rather than two scalar fields. Its current ingredient block lists every entered identifier grouped by scheme. The assistant may propose missing identifiers, but the application still does not parse or import the response automatically.

Ingredient exports replace the scalar CAS and EC columns with deterministic CAS and EC list columns and an all-identifiers representation. Primary values appear first. Aliases are exported with locale and kind so catalogue curation can round-trip them later.

## Migration and Existing Data

The migration sequence is:

1. Create identifier and alias tables with indexes, constraints, and reversible `down()` logic.
2. Backfill each nonblank `ingredients.cas_number` and `ingredients.ec_number` into primary identifier rows.
3. Split clearly comma- or semicolon-separated legacy values, trim them, and remove normalized duplicates without changing digits.
4. Preserve malformed or questionable legacy identifiers for administrator review rather than silently correcting or discarding them.
5. Update every reader and writer to use relationships.
6. Remove `cas_number` and `ec_number` from `ingredients` only after backfill and code conversion are covered by tests.

Rollback recreates the two legacy columns and writes the primary CAS and EC value back before removing the new tables. Alternative values cannot be represented in the legacy schema and are therefore not part of the rollback projection.

The migration does not reseed or replace the ingredient catalogue. Existing platform and workspace ingredients retain their records and receive identifier children from their current values.

## Formula-Limit Enforcement

The entitlement service exposes the resolved `formula_items_per_recipe` value for a user/workspace. Formula validation counts the normalized phase items before any recipe-version structure is written.

Enforcement covers:

- saving a new or existing draft;
- publishing;
- duplicating a recipe;
- restoring a historical version;
- any server action that replaces the current formula structure.

For an existing over-limit formula, the allowed count for an ordinary edit is the greater of the plan limit and the current saved count. This preserves access while preventing growth. A new recipe or duplicate has no grandfathered count.

Formula-limit validation belongs before `RecipeVersionStructureSynchronizer` deletes and recreates rows. A rejected payload must leave the stored formula unchanged.

## Validation and Error Handling

- Every mutation validates ownership through the parent ingredient or recipe.
- Identifier and alias synchronization locks the parent ingredient before count, uniqueness, and primary-selection checks.
- Formula count validation runs inside the existing recipe write boundary before structure replacement.
- Concurrent primary-identifier changes cannot leave two primary values for one scheme.
- Excess identifier, alias, or formula rows produce translated validation errors rather than partial writes.
- Invalid locale or unsupported scheme/kind values are rejected.
- Empty aliases and identifiers are discarded before counting.
- Search input remains bounded and parameterized.
- All new user-facing text is translated in English, German, Spanish, French, Italian, and Dutch and synchronized with the interface-translation catalogue.

## Testing

### Schema and models

- identifier and alias relationships, enum casts, normalization, uniqueness, primary selection, and cascades;
- legacy CAS/EC backfill, trimming, separator splitting, duplicate removal, and preservation of questionable values;
- rollback projection to the primary legacy values;
- platform versus workspace count rules.

### Authorization and tenancy

- workspace owners can manage their own aliases and identifiers;
- platform and other-workspace mutations are rejected;
- workspace aliases are invisible to other workspaces;
- platform aliases remain globally searchable.

### Forms and persistence

- customer create/edit state maps to identifier and alias relationships;
- simple CAS/EC entry automatically creates primary rows;
- admin create/edit supports multiple identifiers and localized aliases;
- per-ingredient limits are enforced on create, update, and duplication;
- prompt generation and exports contain all identifiers.

### Duplication

- localized identity uses the active locale with English fallback;
- identifiers, selected aliases, restricted substances, allergens, composition, functions, SAP data, and IFRA data are copied;
- copied rows belong solely to the new workspace ingredient;
- platform changes do not propagate to the copy.

### Search

- display name, translated name, INCI, alias, CAS, EC, and additional scheme matches;
- active-locale, neutral, and English-fallback alias behavior;
- ambiguous aliases return multiple candidates;
- no cross-workspace alias leakage;
- formula-browser search terms include aliases and identifiers without changing displayed names.

### Formula plans

- free plan resolves to 30 and paid plan resolves to 50;
- Plan admin can change the limit or leave it unlimited;
- exactly-at-limit save/publish succeeds and over-limit operations fail without changing stored rows;
- the count spans all phases, counts repeated ingredients in separate phases, and excludes packaging;
- grandfathered formulas can remain unchanged or shrink but cannot grow;
- new duplication and historical restoration cannot bypass the current plan limit;
- browser controls show the count and prevent an additional row while server validation remains authoritative.

### Verification gates

- focused Pest feature tests for every changed write/search path;
- focused JavaScript tests for formula add controls and search terms;
- interface-translation catalogue validation;
- Filacheck after Filament changes;
- Pint after PHP changes;
- frontend build after JavaScript changes;
- `git diff --check`;
- Graphify refresh.

## Acceptance Scenarios

1. An administrator gives a platform ingredient two CAS values, one EC value, and localized aliases without changing its internal identity.
2. A French workspace finds the ingredient by its French alias, INCI, or any identifier and sees the French display name.
3. Searching an ambiguous common name returns every accessible candidate and selects none automatically.
4. A workspace uses the shared platform ingredient with its own price while identity and compliance remain read-only.
5. A workspace duplicates the platform ingredient and receives localized identity, identifiers, relevant aliases, substances, allergens, functions, SAP data, and IFRA data as independent owned records.
6. The workspace edits its copy's identifiers and aliases without affecting the platform or another workspace.
7. A workspace cannot save a sixth alias or an eleventh identifier.
8. The free plan accepts a 30-line formula and rejects a 31-line formula without deleting the saved 30-line structure.
9. A paid plan accepts 50 lines, and an administrator can change that allowance from Plan settings.
10. A downgraded workspace can open, print, export, and reduce an existing over-limit formula but cannot add another line.
11. Existing CAS and EC values survive deployment as primary identifier records; the catalogue is not reseeded.

## Deferred Work

- PubChem, FDA GSRS/UNII, EPA CompTox, or other external identity lookup integrations.
- A global chemical-substance registry maintained independently from Koskalk ingredients.
- Automatic verification, correction, or regulatory approval of user-entered identifiers.
- Identifier-level provenance/source records.
- Workspace compliance overlays on shared platform ingredients.
- Synchronization between a platform ingredient and a workspace-owned duplicate.
- Country-specific product classification for U.S. true soap, EU cosmetics, or detergents.
- Ingredient-specific request throttling and a general authenticated-write abuse review.
