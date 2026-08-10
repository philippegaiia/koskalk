# Ingredient Taxonomy and CosIng Functions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the overloaded ingredient categories with a beginner-readable, professionally credible two-level taxonomy; make soap and aromatic behavior explicit capabilities; and populate platform ingredient functions from a reviewed CosIng snapshot instead of requiring manual entry.

**Architecture:** `IngredientCategory` becomes the single catalogue shelf and `IngredientSubcategory` provides an optional refinement. Cosmetic functions remain many-to-many reference metadata, with CosIng assignments distinguished from manual additions. Soap saponification and aromatic compliance stop being inferred from category, so reclassification cannot silently delete SAP, fatty-acid, allergen, or IFRA data.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, Livewire 4, Pest 4, PostgreSQL/SQLite migrations, PHP-backed enums, JSON catalogue snapshots, and the existing ingredient authoring, formula mutation, and recipe workbench services.

**Source authority:** European Commission [CosIng cosmetic ingredient database](https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database_en). CosIng functions are reference metadata, not proof that an ingredient is authorised or safe for a particular use.

**Working-tree safety:** The current worktree contains uncommitted material-price and saponification-name work, including changes in `Ingredient`, `IngredientForm`, `IngredientDataEntryService`, factories, and tests. Preserve those changes. Before implementation, either finish and commit that work or create an isolated `codex/ingredient-taxonomy-cosing` worktree from a commit containing it. Stage files path-by-path.

---

## Domain decisions

- A **platform ingredient** is shared reference data. Its category, subcategory, CosIng functions, SAP data, and compliance references are maintained centrally.
- A **workspace ingredient** belongs to one workspace. Its price and supplier context remain workspace data; functions entered for it are manual unless it is a duplicated platform ingredient carrying inherited reference metadata.
- A **category** is one required, understandable catalogue shelf. It is not a complete chemical ontology and does not describe every use of the ingredient.
- A **subcategory** is a narrower professional refinement. It is required for curated platform ingredients except `Other / needs classification`; it is optional for workspace-created ingredients.
- A **function** is a many-to-many cosmetic role such as `Humectant`, `Solvent`, or `Emollient`. Functions do not replace the category.
- A **CosIng function assignment** is imported reference metadata and is displayed separately from editable manual additions.
- **Conditioning** is a function/facet, not one chemically coherent shelf. Hair- and skin-conditioning materials remain in their material families and receive functions such as `hair_conditioning`, `skin_conditioning`, `emollient`, `film_forming`, `detangling`, `antistatic`, `smoothing`, `moisturising`, and `refatting`.
- **Silicones** are a category by themselves because they are a distinct material family with volatile, non-volatile, and elastomer subtypes. They can also carry conditioning, emollient, smoothing, film-forming, or other functions.
- **Trusted for soap saponification** is an explicit capability. It requires a trusted KOH SAP value before an ingredient can drive lye calculations.
- **Requires aromatic compliance** is an explicit capability. It controls allergen and IFRA workflows regardless of category.
- Changing a category or subcategory never deletes SAP, fatty-acid, allergen, IFRA, composition, or function records.
- Categories may suggest capability defaults in the UI, but a saved capability—not the category—controls specialist behavior.
- CosIng matching is exact and reviewable. The application must never invent functions for a fuzzy or missing match.

The user-facing category search and classification prompt should explicitly explain: “If an ingredient is used for conditioning, classify it by what it is made of, then select the conditioning function.” This keeps Cetearyl Alcohol under Fatty alcohols, Dimethicone under Silicones, Behentrimonium Chloride under Cationic surfactants, and Polyquaternium materials under Conditioning polymers while making all of them discoverable through conditioning functions.

## Taxonomy storage and translation decisions

- Keep the taxonomy in PHP enums: `IngredientCategory` and `IngredientSubcategory`.
- Do not create category or subcategory database tables. This list is application-controlled reference vocabulary, not workspace data or admin-authored records.
- Store only the enum backing values in `ingredients.category` and `ingredients.subcategory`.
- Move every enum label and description out of hardcoded `match` strings. `getLabel()` and `getDescription()` resolve keys such as `ingredients.categories.silicones.label` and `ingredients.subcategories.volatile_silicones.label`.
- Add English source strings to `lang/en/ingredients.php`. Because the existing interface-translation source includes the complete `ingredients` group, `translations:sync` will register them in `language_lines`.
- Add reviewed translations for German, Spanish, French, Italian, and Dutch to `database/seeders/data/interface-translations.json`. The catalogue must contain category labels, category descriptions, subcategory labels, and subcategory help text in all five locales; an empty translation is not acceptable for these taxonomy keys.
- Add translation contract tests that assert every enum case has an English key and a non-empty reviewed value for `de`, `es`, `fr`, `it`, and `nl`. Filament and Livewire must call the enum methods instead of duplicating labels.

## Canonical catalogue rebuild decisions

- Re-seed the platform ingredient catalogue from one canonical source, but never truncate and recreate `ingredients` in an existing database.
- Canonical seeders are idempotent upserts keyed by stable `catalog_key`; this preserves referenced ingredient IDs.
- Maintain an explicit consolidation map for duplicate and invalid platform rows. A consolidation transfers or reconciles dependencies before the redundant row is removed.
- Preserve real commercial/processing variants even when they share an INCI name: virgin/refined oils, raw/deodorized butters, and other grades can remain distinct catalogue ingredients while sharing the same CosIng identity and functions.
- Merge only true duplicates. Conflicting workspace prices, supplier listings, composition rows, or formula uses stop the merge for review; they are never silently discarded.
- Remove obvious test/junk platform rows only after the consolidation audit confirms they have no protected dependency.
- Never seed workspace-owned ingredients or workspace prices.

The current trial database audit found 167 platform rows, including overlapping catalogue sources. It specifically found duplicate Water/NaOH/KOH admin rows, duplicate Ravintsara, two obvious `ING...` test rows, multiple oil-name overlaps, and two Coconut Oil records with the same workspace price. The implementation must resolve this audit before declaring a final canonical platform count.

## Approved taxonomy

| Category value | User-facing category | Subcategory values and labels |
|---|---|---|
| `lipids` | Oils, butters & fats | `vegetable_oils` Vegetable oils; `butters` Butters; `animal_fats` Animal fats; `fractionated_modified_lipids` Fractionated or modified lipids |
| `waxes` | Waxes | `plant_waxes` Plant waxes; `animal_waxes` Animal waxes; `liquid_waxes` Liquid waxes; `mineral_waxes` Mineral waxes; `synthetic_waxes` Synthetic waxes |
| `hydrocarbons` | Hydrocarbons & mineral emollients | `mineral_oils` Mineral oils; `petrolatum_occlusives` Petrolatum and occlusives; `hydrocarbon_emollients` Hydrocarbon emollients |
| `silicones` | Silicones | `volatile_silicones` Volatile silicones; `nonvolatile_silicones` Non-volatile silicones; `silicone_elastomers` Silicone elastomers |
| `fatty_derivatives` | Fatty acids, alcohols & esters | `fatty_acids` Fatty acids; `fatty_alcohols` Fatty alcohols; `cosmetic_esters` Cosmetic esters |
| `surfactants` | Surfactants & cleansing agents | `anionic` Anionic; `amphoteric` Amphoteric; `nonionic` Nonionic; `cationic` Cationic |
| `emulsifiers` | Emulsifiers, solubilizers & co-emulsifiers | `oil_in_water` Oil-in-water (O/W); `water_in_oil` Water-in-oil (W/O); `co_emulsifiers` Co-emulsifiers; `solubilizers` Solubilizers |
| `humectants_polyols` | Humectants & polyols | `glycerin_glycols` Glycerin and glycols; `sugar_alcohols` Sugar alcohols; `other_humectants` Other humectants |
| `water_solvents_carriers` | Water, solvents & carriers | `water` Water; `alcohols` Alcohols; `organic_solvents` Organic solvents; `other_carriers` Other carriers |
| `rheology_modifiers` | Thickeners & rheology modifiers | `gums` Gums; `cellulose_derivatives` Cellulose derivatives; `synthetic_rheology_modifiers` Synthetic rheology modifiers; `mineral_thickeners` Mineral thickeners |
| `functional_polymers` | Polymers, film-formers & conditioners | `conditioning_polymers` Conditioning polymers; `film_forming_polymers` Film-forming polymers; `hair_fixatives_resins` Hair fixatives and resins; `other_functional_polymers` Other functional polymers |
| `minerals_salts_powders` | Minerals, salts & powders | `clays` Clays; `salts` Salts; `starches_absorbent_powders` Starches and absorbent powders; `functional_mineral_powders` Functional mineral powders |
| `actives` | Actives & specialty ingredients | `vitamins` Vitamins; `exfoliating_acids` Exfoliating acids; `proteins_peptides_amino_acids` Proteins, peptides and amino acids; `uv_filters` UV filters; `other_actives` Other actives |
| `botanicals_extracts` | Botanicals & extracts | `hydrosols` Hydrosols; `aqueous_glycerinated_extracts` Aqueous or glycerinated extracts; `oil_macerates` Oil macerates; `dry_extracts` Dry extracts; `plant_powders` Plant powders |
| `aromatic_materials` | Essential oils & fragrance materials | `essential_oils` Essential oils; `absolutes_resinoids` Absolutes and resinoids; `co2_extracts` CO2 extracts; `aroma_compounds` Aroma compounds; `fragrance_blends` Fragrance blends |
| `colourants` | Colourants | `mineral_pigments` Mineral pigments; `micas` Micas; `dyes_lakes` Dyes and lakes; `botanical_colourants` Botanical colourants |
| `preservation_stability` | Preservation & stability | `preservatives` Preservatives; `antioxidants` Antioxidants; `chelators` Chelators |
| `ph_adjusters_buffers` | pH adjusters & buffers | `acids` Acids; `bases` Bases; `buffer_systems` Buffer systems |
| `soapmaking_alkalis` | Soapmaking alkalis | `sodium_hydroxide` Sodium hydroxide; `potassium_hydroxide` Potassium hydroxide; `other_soap_alkalis` Other soap alkalis |
| `exfoliants_abrasives` | Exfoliants & abrasives | `natural_particles` Natural particles; `mineral_abrasives` Mineral abrasives; `synthetic_particles` Synthetic particles |
| `bases_blends_premixes` | Bases, blends & premixes | `ready_made_bases` Ready-made bases; `melt_and_pour_soap_bases` Melt-and-pour soap bases; `functional_blends` Functional blends; `proprietary_premixes` Proprietary premixes |
| `other` | Other / needs classification | no subcategory required |

Examples that define the intended distinction:

- Glycerin: `Humectants & polyols / Glycerin and glycols`; CosIng functions can include `Humectant`, `Solvent`, and `Denaturant`.
- Tocopherol: `Preservation & stability / Antioxidants`; functions remain separate.
- Lavender hydrosol: `Botanicals & extracts / Hydrosols`; it is not an essential oil.
- Shea butter: `Oils, butters & fats / Butters`; soap trust is independently enabled only with verified SAP chemistry.
- Candelilla wax: `Waxes / Plant waxes`; its category does not determine whether it has a SAP reference.
- Jojoba: `Waxes / Liquid waxes`; the explicit soap-trust capability still allows verified SAP chemistry to participate in soap calculation.
- Polyquaternium-7: `Polymers, film-formers & conditioners / Conditioning polymers`; `Hair conditioning`, `Antistatic`, or other functions remain separate assignments.
- Sodium hydroxide: `Soapmaking alkalis / Sodium hydroxide`; it remains calculator-controlled rather than a normal soap ingredient-browser row.

---

### Task 0: Isolate the feature and confirm the trial-data precondition

**Files:**

- No application files
- Inspect: `git status --short`
- Inspect: workspace ingredient count after the user's cleanup

- [ ] **Step 1: Protect the existing uncommitted work**

Run `git status --short`, preserve every existing path, and start from a commit/worktree that already contains the material-price and saponification-name changes. Do not stash, reset, or overwrite user work.

- [ ] **Step 2: Confirm the user-managed cleanup is complete**

The user is removing these three workspace-owned trial records before implementation:

```text
USR-YJX5MTZT  yiuyi iuyiuyi       essential_oil
USR-MZIMNPYI  hydrosol lavande    essential_oil
USR-XRQDXORW  huile de jojojo     carrier_oil
```

Do not delete or otherwise mutate these records from this feature branch.

- [ ] **Step 3: Verify the migration starts with no workspace ingredients**

Run a read-only count and confirm that no workspace-owned ingredient remains. If any record remains, stop before the catalogue migration and report it instead of deleting it.

- [ ] **Step 4: Record the platform baseline**

Expected before implementation: zero workspace-owned ingredients and a read-only audit of all 167 current platform rows. The canonical post-consolidation count is recorded only after every duplicate/test-row decision is approved. Platform prices remain workspace-owned and must be transferred or reconciled when their linked platform ingredient is consolidated.

---

### Task 1: Add the two-level taxonomy enums

**Files:**

- Modify: `app/Enums/IngredientCategory.php`
- Create: `app/Enums/IngredientSubcategory.php`
- Create: `tests/Unit/IngredientTaxonomyTest.php`

- [ ] **Step 1: Write failing enum contract tests**

Test all 22 category values in the order shown above, every subcategory-to-category mapping, unique values/labels, `Other` having no children, and category helper methods returning only valid subcategories.

```php
expect(array_column(IngredientCategory::cases(), 'value'))->toBe([
    'lipids',
    'waxes',
    'hydrocarbons',
    'silicones',
    'fatty_derivatives',
    'surfactants',
    'emulsifiers',
    'humectants_polyols',
    'water_solvents_carriers',
    'rheology_modifiers',
    'functional_polymers',
    'minerals_salts_powders',
    'actives',
    'botanicals_extracts',
    'aromatic_materials',
    'colourants',
    'preservation_stability',
    'ph_adjusters_buffers',
    'soapmaking_alkalis',
    'exfoliants_abrasives',
    'bases_blends_premixes',
    'other',
]);

expect(IngredientSubcategory::Hydrosols->category())
    ->toBe(IngredientCategory::BotanicalsExtracts);
```

- [ ] **Step 2: Run the test and verify RED**

```bash
php artisan test --compact tests/Unit/IngredientTaxonomyTest.php
```

Expected: FAIL because the new cases and `IngredientSubcategory` do not exist.

- [ ] **Step 3: Implement focused enums**

Keep label, description, icon, and colour presentation in `IngredientCategory`. Put every subcategory case and its single parent mapping in `IngredientSubcategory`, using the complete values in the approved taxonomy table. Add `IngredientCategory::subcategories(): array` and `IngredientSubcategory::optionsFor(IngredientCategory|string|null): array`. Do not put soap, aromatic, or phase behavior in either enum.

- [ ] **Step 4: Verify and commit**

```bash
php artisan test --compact tests/Unit/IngredientTaxonomyTest.php
vendor/bin/pint --dirty --format agent
git add app/Enums/IngredientCategory.php app/Enums/IngredientSubcategory.php tests/Unit/IngredientTaxonomyTest.php
git commit -m "feat: define ingredient taxonomy"
```

Expected: PASS.

---

### Task 2: Add taxonomy, capability, and CosIng provenance schema

**Files:**

- Create: a timestamped migration named `migrate_ingredient_taxonomy_and_function_provenance`
- Modify: `app/Models/Ingredient.php`
- Modify: `app/Models/IngredientFunction.php`
- Modify: `database/factories/IngredientFactory.php`
- Modify: `database/factories/IngredientFunctionFactory.php`
- Modify: `tests/Feature/IngredientCatalogSchemaTest.php`

- [ ] **Step 1: Write failing schema/model tests**

Prove that:

- `ingredients.category` is populated and cast to the new enum.
- `ingredients.subcategory` is nullable and cast to `IngredientSubcategory`.
- `is_potentially_saponifiable` has become `is_soap_saponification_trusted`.
- `requires_aromatic_compliance` defaults to false.
- `cosing_reference` is nullable.
- `taxonomy_source`, `taxonomy_reviewed_at`, and `taxonomy_reviewed_by_user_id` identify how, when, and by whom the category/subcategory was curated.
- pivot rows expose `source`, `source_reference`, `source_checked_at`, and `assigned_by_user_id`.

- [ ] **Step 2: Run the focused test and verify RED**

```bash
php artisan test --compact tests/Feature/IngredientCatalogSchemaTest.php
```

Expected: FAIL because the taxonomy, capability, and provenance columns do not exist.

- [ ] **Step 3: Generate and implement the migration**

Use `php artisan make:migration migrate_ingredient_taxonomy_and_function_provenance --no-interaction`. In ordered operations:

1. Add nullable `subcategory`, required `requires_aromatic_compliance` defaulting false, and nullable `cosing_reference`.
2. Rename `is_potentially_saponifiable` to `is_soap_saponification_trusted`.
3. Add ingredient provenance columns: `taxonomy_source` with database default `workspace_user`, nullable `taxonomy_reviewed_at`, and nullable `taxonomy_reviewed_by_user_id` with `nullOnDelete`. Backfill platform rows to `platform_curated`; platform seeders and admin services always set it explicitly.
4. Add pivot provenance columns: `source` defaulting `manual`, nullable `source_reference`, nullable `source_checked_at`, and nullable `assigned_by_user_id` with `nullOnDelete`.
5. Backfill old categories to safe broad values before the new enum is used.
6. Backfill aromatic capability for old `essential_oil`, `fragrance_oil`, and `co2_extract` rows.
7. Keep existing SAP, fatty-acid, allergen, IFRA, function, and component rows untouched.
8. Make `category` non-null after backfill, with `other` as the safe value.

Use this compatibility map only for the migration bridge; Task 6 applies the precise catalogue mapping:

```php
$categoryMap = [
    'carrier_oil' => 'lipids',
    'essential_oil' => 'aromatic_materials',
    'fragrance_oil' => 'aromatic_materials',
    'botanical_extract' => 'botanicals_extracts',
    'co2_extract' => 'aromatic_materials',
    'clay' => 'minerals_salts_powders',
    'glycol' => 'humectants_polyols',
    'colorant' => 'colourants',
    'preservative' => 'preservation_stability',
    'additive' => 'other',
    'alkali' => 'soapmaking_alkalis',
    'liquid' => 'water_solvents_carriers',
];
```

- [ ] **Step 4: Update model contracts**

Add the new fillable attributes/casts and pivot metadata:

```php
return $this->belongsToMany(IngredientFunction::class, 'ingredient_function_ingredient')
    ->withPivot(['source', 'source_reference', 'source_checked_at', 'assigned_by_user_id'])
    ->withTimestamps();
```

Use these provenance values:

| Record | Field | Values | Meaning |
|---|---|---|---|
| Ingredient | `taxonomy_source` | `platform_curated`, `workspace_user`, `supplier_review` | Who/what selected the category and subcategory |
| Ingredient | `taxonomy_reviewed_at` | timestamp or null | Last human review of the taxonomy assignment |
| Ingredient | `taxonomy_reviewed_by_user_id` | user ID or null | Human reviewer; null for deterministic imports |
| Ingredient | `cosing_reference` | CosIng reference or null | Exact CosIng identity used for matching |
| Function assignment pivot | `source` | `cosing`, `manual`, `inherited` | Origin of this ingredient-function relationship |
| Function assignment pivot | `source_reference` | CosIng reference, supplier document, or null | Evidence for the relationship |
| Function assignment pivot | `source_checked_at` | timestamp or null | Date the relationship was checked |
| Function assignment pivot | `assigned_by_user_id` | user ID or null | User who accepted/entered the assignment; null for deterministic imports |

An LLM classification is never stored as an authoritative source. If a user accepts an LLM suggestion, the saved taxonomy/function assignment is `workspace_user` or `manual` and still remains editable.

Factory defaults become `IngredientCategory::Other`, no subcategory, and both capabilities false.

- [ ] **Step 5: Verify and commit**

```bash
php artisan test --compact tests/Feature/IngredientCatalogSchemaTest.php
vendor/bin/pint --dirty --format agent
git add app/Models/Ingredient.php app/Models/IngredientFunction.php database/factories/IngredientFactory.php database/factories/IngredientFunctionFactory.php database/migrations tests/Feature/IngredientCatalogSchemaTest.php
git commit -m "feat: store ingredient taxonomy capabilities and function provenance"
```

---

### Task 3: Decouple specialist behavior from category

**Files:**

- Modify: `app/Models/Ingredient.php`
- Modify: `app/Services/IngredientDataEntryService.php`
- Modify: `app/Services/UserIngredientAuthoringService.php`
- Modify: `app/Services/IngredientFormulaMutationService.php`
- Modify: `app/Services/RecipeWorkbenchIngredientCatalogBuilder.php`
- Modify: `app/Console/Commands/ReportMissingCarrierOilChemistry.php`
- Modify: `app/Console/Commands/ImportCarrierOilChemistryFromMendrulandia.php`
- Modify: `app/Console/Commands/DiffCarrierOilsFromCsv.php`
- Modify: `tests/Feature/CarrierOilChemistryWorkflowTest.php`
- Modify: `tests/Feature/IngredientDataEntryServiceTest.php`
- Modify: `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`
- Modify: `tests/Feature/IngredientFormulaMutationServiceTest.php`
- Modify: `tests/Feature/RecipeWorkbenchPersistenceTest.php`

- [ ] **Step 1: Write failing behavior tests**

Cover these invariants:

- A trusted wax or unusual lipid with KOH SAP can drive saponification even though its category is not checked.
- A lipid without the trust flag cannot drive saponification.
- An aromatic-capability ingredient requires allergen/IFRA handling even if temporarily categorized `Other`.
- Reclassifying a carrier oil does not delete its SAP or fatty-acid rows.
- Reclassifying an aromatic ingredient does not delete allergens or IFRA certificates.
- A platform soap ingredient duplicated into a workspace retains bounded trusted chemistry.
- A workspace ingredient cannot become trusted merely by choosing the Lipids category.
- Replacement candidates require compatible capabilities as well as an appropriate main category.

```php
$ingredient->forceFill([
    'category' => IngredientCategory::Waxes,
    'subcategory' => IngredientSubcategory::PlantWaxes,
    'is_soap_saponification_trusted' => true,
])->save();

expect($ingredient->fresh('sapProfile')->canDriveSoapSaponification())->toBeTrue();
```

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/CarrierOilChemistryWorkflowTest.php tests/Feature/IngredientDataEntryServiceTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientFormulaMutationServiceTest.php
```

- [ ] **Step 3: Change the capability rules**

`Ingredient::canDriveSoapSaponification()` becomes:

```php
public function canDriveSoapSaponification(): bool
{
    return $this->is_soap_saponification_trusted
        && $this->sapProfile?->koh_sap_value !== null;
}
```

`requiresAromaticCompliance()` returns the stored boolean. Remove `aromaticCases()`/`aromaticValues()` as behavioral gates.

Recipe-workbench rules become:

- aromatic capability: `fragrance`;
- trusted SAP capability: `saponified_oils` and `additives`;
- soapmaking alkalis and primary water: calculator-controlled, no normal soap browser phase;
- all other usable materials: `additives`;
- cosmetic formulas: all active accessible categories remain available.

- [ ] **Step 4: Preserve specialist records**

Remove category-based calls that clear SAP/fatty-acid and allergen/IFRA records. Sync those relations only when their form state was intentionally submitted. A category change must not turn hidden/absent form state into a delete instruction.

Keep explicit deletion of specialist records out of this feature. A future “Clear chemistry/compliance data” action can be separately confirmed and audited.

- [ ] **Step 5: Update duplication, validation, commands, and replacement compatibility**

Replace every `CarrierOil` gate with the explicit soap-trust rule. Replace aromatic category queries with the explicit capability column. Category stays relevant for catalogue grouping and broad replacement relevance, but capabilities are mandatory compatibility constraints.

- [ ] **Step 6: Verify and commit**

```bash
php artisan test --compact tests/Feature/CarrierOilChemistryWorkflowTest.php tests/Feature/IngredientDataEntryServiceTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientFormulaMutationServiceTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
vendor/bin/pint --dirty --format agent
git add app/Models/Ingredient.php app/Services/IngredientDataEntryService.php app/Services/UserIngredientAuthoringService.php app/Services/IngredientFormulaMutationService.php app/Services/RecipeWorkbenchIngredientCatalogBuilder.php app/Console/Commands tests/Feature
git commit -m "refactor: decouple ingredient capabilities from taxonomy"
```

---

### Task 4: Separate imported and manual function assignments

**Files:**

- Create: `app/Enums/IngredientFunctionSource.php`
- Create: `app/Services/IngredientFunctionAssignmentService.php`
- Modify: `app/Services/IngredientDataEntryService.php`
- Modify: `app/Services/UserIngredientAuthoringService.php`
- Modify: `app/Models/Ingredient.php`
- Create: `tests/Feature/IngredientFunctionAssignmentTest.php`

- [ ] **Step 1: Write failing assignment tests**

Prove that:

- saving manual functions does not remove CosIng rows;
- removing a manual function does not remove the same ingredient's other CosIng assignments;
- duplicating a platform ingredient carries CosIng assignments and provenance;
- importing CosIng functions does not remove manual additions;
- invalid or inactive function IDs are rejected/ignored consistently with current service behavior.

- [ ] **Step 2: Implement source-aware synchronization**

Use exact source values:

```php
enum IngredientFunctionSource: string
{
    case CosIng = 'cosing';
    case Manual = 'manual';
    case Inherited = 'inherited';
}
```

Expose two service operations:

```php
public function syncManual(Ingredient $ingredient, array $functionIds): void;

/** @param array<int, string> $functionKeys */
public function syncCosIng(
    Ingredient $ingredient,
    array $functionKeys,
    string $sourceReference,
    CarbonImmutable $checkedAt,
): void;
```

`syncManual()` replaces only pivot rows whose source is `manual`. `syncCosIng()` replaces only rows whose source is `cosing`. Neither operation detaches the other source. If the same function already exists from CosIng, a manual duplicate is unnecessary because the pivot uniqueness constraint already communicates that function.

- [ ] **Step 3: Route all form and duplication writes through the service**

Replace direct `functions()->sync()` calls. Platform duplication copies CosIng rows as `inherited`; later workspace additions remain `manual`.

- [ ] **Step 4: Verify and commit**

```bash
php artisan test --compact tests/Feature/IngredientFunctionAssignmentTest.php tests/Feature/IngredientDataEntryServiceTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php
vendor/bin/pint --dirty --format agent
git add app/Enums/IngredientFunctionSource.php app/Services/IngredientFunctionAssignmentService.php app/Services/IngredientDataEntryService.php app/Services/UserIngredientAuthoringService.php app/Models/Ingredient.php tests/Feature
git commit -m "feat: track ingredient function provenance"
```

---

### Task 5: Build and validate the reviewed CosIng assignment dataset

**Files:**

- Create: `database/seeders/data/cosing_ingredient_functions.json`
- Create: `app/Support/CosIngFunctionDataset.php`
- Create: `tests/Feature/CosIngFunctionDatasetTest.php`
- Reuse unchanged: `database/seeders/IngredientFunctionSeeder.php`

- [ ] **Step 1: Treat the existing function list as canonical**

`IngredientFunctionSeeder` already contains the CosIng function vocabulary, stable keys, labels, descriptions, ordering, and active status. Do not generate a second function list, rename keys, or duplicate descriptions. This task maps platform ingredients to those existing keys.

- [ ] **Step 2: Define the committed assignment contract**

Every matched platform ingredient uses `catalog_key`, not a fuzzy runtime name lookup. The parser enforces this exact row shape:

```php
/**
 * @phpstan-type CosIngAssignment array{
 *     catalog_key: non-empty-string,
 *     inci_name: non-empty-string,
 *     cosing_reference: non-empty-string,
 *     source_url: non-empty-string,
 *     verified_at: string,
 *     function_keys: non-empty-list<non-empty-string>
 * }
 */
```

The final file must contain no placeholder text. An ingredient with no exact active CosIng match is omitted and appears in the audit report rather than receiving guessed functions.

- [ ] **Step 3: Write failing dataset validation tests**

Validate unique catalogue keys, unique CosIng references when present, ISO dates, official Commission URLs, sorted unique function keys, exact INCI agreement after whitespace/case normalization, and that every assignment key resolves to an active function already seeded by `IngredientFunctionSeeder`.

- [ ] **Step 4: Populate the post-consolidation platform review set**

For each canonical platform row defined by Task 6, search CosIng by exact INCI first, then confirm with CAS/EC when available. Record all active CosIng functions. Never classify by an LLM's chemical knowledge alone. Multi-INCI commercial blends and generic materials remain unmatched unless CosIng has the exact entry; their functions come from supplier documentation/manual assignment.

The review output must have three explicit lists:

- exact matches ready to import;
- ambiguous matches requiring admin review;
- no CosIng match, with no generated functions.

- [ ] **Step 5: Implement the parser and run RED/GREEN**

`CosIngFunctionDataset` reads and validates the JSON with `JSON_THROW_ON_ERROR`, returns typed array shapes, and throws a row-specific `RuntimeException` for malformed content.

```bash
php artisan test --compact tests/Feature/CosIngFunctionDatasetTest.php
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add database/seeders/data/cosing_ingredient_functions.json app/Support/CosIngFunctionDataset.php tests/Feature/CosIngFunctionDatasetTest.php
git commit -m "data: add reviewed CosIng ingredient functions"
```

---

### Task 6: Consolidate and canonically re-seed the platform catalogue

**Files:**

- Create: `database/seeders/data/ingredient_catalog_taxonomy.json`
- Create: `database/seeders/data/ingredient_catalog_consolidation.json`
- Create: `app/Support/IngredientCatalogTaxonomyDataset.php`
- Create: `app/Support/IngredientCatalogConsolidationDataset.php`
- Create: `app/Services/IngredientCatalogConsolidationService.php`
- Create: `app/Console/Commands/ConsolidateIngredientCatalog.php`
- Modify: `database/seeders/IngredientCatalogSeeder.php`
- Modify: `database/seeders/CarrierOilSeeder.php`
- Create: `database/seeders/IngredientCatalogMetadataSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `tests/Feature/IngredientCatalogMetadataSeederTest.php`
- Create: `tests/Feature/IngredientCatalogConsolidationTest.php`

- [ ] **Step 1: Define and approve consolidation decisions**

Use explicit `keep`, `merge_into`, and `remove` decisions keyed by current `catalog_key`. At minimum, review these groups from the current database audit:

- `ADM-ILLF4IRL` against `CH2` (Water);
- `ADM-P8YDYPKK` against `CH1` (Sodium hydroxide);
- `ADM-VSBOZPVX` against `CH3` (Potassium hydroxide);
- `EO25` against `EO26` (Ravintsara);
- `ING029` and `ING618` as obvious test/junk rows;
- same-name oil overlaps from the two existing seed sources;
- duplicate Coconut Oil rows carrying same-workspace prices.

Do not merge solely because INCI matches. Keep meaningful grade variants such as virgin/refined oils and raw/deodorized butters. The consolidation JSON is reviewed before any destructive execution.

- [ ] **Step 2: Write failing consolidation tests**

Prove that merges preserve/repoint recipe items, costing rows, components, supplier listings, stock/procurement references, media, SAP/fatty-acid data, functions, and workspace prices. If source and target both have a price for one workspace, keep the most recent only when canonical price, currency, and source semantics agree; otherwise abort that merge with a review report. Prove that a failed merge rolls back atomically.

- [ ] **Step 3: Implement consolidation and canonical upserts**

Lock source and target ingredients in deterministic ID order, reconcile every dependency, delete only the approved redundant row, and record each action in command output. The service must be idempotent: an already-completed merge is reported as complete rather than recreated.

Expose the service through `ingredients:consolidate-catalog`. It performs a read-only dry run by default and mutates only with `--apply`. `IngredientCatalogSeeder` and `CarrierOilSeeder` then become one canonical upsert flow keyed by stable `catalog_key`. They must not create a second copy based only on display-name differences.

- [ ] **Step 4: Define exact catalogue metadata**

Use one entry per platform `catalog_key`, validated against this exact shape:

```php
/**
 * @phpstan-type CatalogTaxonomyAssignment array{
 *     catalog_key: non-empty-string,
 *     category: non-empty-string,
 *     subcategory: non-empty-string|null,
 *     is_soap_saponification_trusted: bool,
 *     requires_aromatic_compliance: bool
 * }
 */
```

Every canonical post-consolidation platform ingredient must appear exactly once. `subcategory` may be null only when category is `other`. Existing trusted chemistry determines the soap flag; taxonomy never creates trust.

- [ ] **Step 5: Encode known correction cases**

The dataset must explicitly correct at least these known errors from the current trial catalogue:

- Candelilla and Carnauba: Waxes / Plant waxes.
- Ghassoul: Minerals, salts & powders / Clays.
- Red and Yellow ochre: Colourants / Mineral pigments.
- Tocopherol: Preservation & stability / Antioxidants.
- Corn starch: Minerals, salts & powders / Starches and absorbent powders.
- Sodium hydroxide and potassium hydroxide: Soapmaking alkalis with their exact subcategories.
- Jojoba: Waxes / Liquid waxes while retaining independently verified soap trust.
- Linden flour currently misfiled as a carrier oil: Botanicals & extracts / Plant powders.
- Existing oils, butters, animal fats, and fractionated coconut/MCT: their precise Lipids subcategories.

- [ ] **Step 6: Write failing seeder tests**

Prove complete platform coverage, valid parent/child combinations, idempotency, preservation of ingredient IDs and workspace price relations, preservation of existing chemistry/compliance data, and application of the sample corrections above.

- [ ] **Step 7: Apply metadata after canonical catalogue upserts**

`IngredientCatalogSeeder` may use the metadata dataset directly when creating rows; `IngredientCatalogMetadataSeeder` remains responsible for updating an existing trial database in place and importing CosIng assignments through `IngredientFunctionAssignmentService`.

Do not truncate or recreate platform ingredients. A redundant row is deleted only by the approved consolidation service after all references are reconciled.

- [ ] **Step 8: Verify and commit**

```bash
php artisan test --compact tests/Feature/IngredientCatalogConsolidationTest.php tests/Feature/IngredientCatalogMetadataSeederTest.php tests/Feature/CosIngFunctionDatasetTest.php
vendor/bin/pint --dirty --format agent
git add database/seeders/data/ingredient_catalog_taxonomy.json database/seeders/data/ingredient_catalog_consolidation.json app/Support/IngredientCatalogTaxonomyDataset.php app/Support/IngredientCatalogConsolidationDataset.php app/Services/IngredientCatalogConsolidationService.php app/Console/Commands/ConsolidateIngredientCatalog.php database/seeders/IngredientCatalogSeeder.php database/seeders/CarrierOilSeeder.php database/seeders/IngredientCatalogMetadataSeeder.php database/seeders/DatabaseSeeder.php tests/Feature
git commit -m "feat: consolidate and re-seed platform ingredient catalog"
```

---

### Task 7: Redesign the admin ingredient form and catalogue table

**Files:**

- Modify: `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- Modify: `app/Filament/Resources/Ingredients/Tables/IngredientsTable.php`
- Modify: `app/Filament/Resources/IngredientAllergenEntries/Schemas/IngredientAllergenEntryForm.php`
- Modify: `app/Filament/Resources/IfraCertificates/Schemas/IfraCertificateForm.php`
- Modify: `app/Filament/Exports/IngredientExporter.php`
- Modify: `lang/en/ingredients.php`
- Modify: `database/seeders/data/interface-translations.json`
- Create: `tests/Feature/IngredientTaxonomyLocalizationTest.php`
- Modify: `tests/Feature/Filament/CatalogResourcesTest.php`

- [ ] **Step 1: Write failing Filament tests**

Test required category, platform-required subcategory except `Other`, subtype filtering, capability-controlled panel visibility, category changes retaining saved specialist data, CosIng functions rendered read-only, and manual functions editable separately.

Also test that every category and subcategory label/description is resolved through the translation keys in `lang/en/ingredients.php`, that the interface translation catalogue contains those keys for every supported locale, and that Filament displays the translated label rather than the enum backing value.

- [ ] **Step 2: Replace category toggle buttons with searchable selects**

Use a required searchable category `Select`, a live filtered subcategory `Select`, and clear helper text:

```php
Select::make('category')
    ->options(IngredientCategory::class)
    ->searchable()
    ->required()
    ->live(),

Select::make('subcategory')
    ->options(fn (Get $get): array => IngredientSubcategory::optionsFor($get('category')))
    ->required(fn (Get $get, ?Ingredient $record): bool =>
        ($record?->owner_type === null)
        && $get('category') !== IngredientCategory::Other->value
    ),
```

Changing category clears only an incompatible unsaved subcategory form value. It does not clear persisted chemistry or compliance relations.

- [ ] **Step 3: Make capability controls explicit**

Show `Trusted for soap saponification` and `Requires allergen / IFRA compliance` in a separate “Specialist data” section. Soap Chemistry visibility follows the soap flag. Aromatic Compliance visibility follows the aromatic flag.

Show imported CosIng functions as source-labelled read-only entries. Rename the current function selector to `Additional functions` and route it through manual-only synchronization.

- [ ] **Step 4: Update tables, filters, exporter, and relationship selectors**

Display/filter/export category and subcategory. Aromatic relationship selectors query `requires_aromatic_compliance = true`; they no longer query category values.

- [ ] **Step 5: Run Filament verification and commit**

```bash
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php
php artisan test --compact tests/Feature/IngredientTaxonomyLocalizationTest.php
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
git add app/Filament tests/Feature/Filament/CatalogResourcesTest.php
git commit -m "feat: redesign admin ingredient classification"
```

---

### Task 8: Redesign workspace authoring and add the copyable classification prompt

**Files:**

- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `resources/views/livewire/dashboard/ingredient-editor.blade.php` if the action placement requires view markup
- Modify: `lang/en/ingredients.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/PublicIngredientPagesTest.php`
- Modify: `tests/Feature/IngredientEditorLocalizationTest.php`

- [ ] **Step 1: Write failing workspace-authoring tests**

Cover category required, subcategory optional, filtered subtype options, `Other` accepted, aromatic capability suggested on aromatic selection but never silently cleared, soap trust not granted by category, and copied prompt containing the current ingredient identity plus every allowed category/subcategory.

- [ ] **Step 2: Implement the beginner-first fields**

The workspace form shows:

1. `Category` — required searchable select.
2. `More specific type` — optional filtered select with “Not sure? Leave this empty.”
3. Functions — imported/inherited functions read-only, additional functions editable.
4. Specialist switches/panels only where needed.

Quick component creation keeps category required and subcategory optional.

- [ ] **Step 3: Add “Copy classification prompt”**

Generate the prompt locally; do not send ingredient data to an external LLM. Include name, INCI, CAS/EC when present, the complete approved taxonomy, CosIng function vocabulary, capability questions, and strict JSON output:

```json
{
  "category": "one allowed category value",
  "subcategory": "one allowed child value or null",
  "function_keys": ["allowed_function_key"],
  "suggests_soap_saponification_review": false,
  "requires_aromatic_compliance": false,
  "confidence": "high|medium|low",
  "reason": "short explanation"
}
```

The prompt must state that SAP trust requires a reliable source and cannot be established by the LLM. It must state that CosIng functions should be verified against the official database.

- [ ] **Step 4: Add translations**

Add English source strings and update the existing interface translation catalogue for supported locales. Keep professional words, but pair them with plain-language helper text.

- [ ] **Step 5: Verify and commit**

```bash
php artisan test --compact tests/Feature/PublicIngredientPagesTest.php tests/Feature/IngredientEditorLocalizationTest.php
vendor/bin/pint --dirty --format agent
git add app/Livewire/Dashboard/IngredientEditor.php resources/views/livewire/dashboard/ingredient-editor.blade.php lang/en/ingredients.php database/seeders/data/interface-translations.json tests/Feature
git commit -m "feat: simplify workspace ingredient classification"
```

---

### Task 9: Update all old enum references and regression fixtures

**Files:**

- Modify: every PHP file returned by `rg -n "IngredientCategory::(CarrierOil|EssentialOil|FragranceOil|BotanicalExtract|Co2Extract|Clay|Glycol|Colorant|Preservative|Additive|Alkali|Liquid)" app database tests`
- Modify: `app/Filament/Resources/UserIngredients/Tables/UserIngredientsTable.php`
- Modify: affected ingredient, formula, costing, public page, and workbench tests

- [ ] **Step 1: Replace fixtures by intent, not mechanically**

Use these defaults in tests:

- generic additive fixture: `Other` unless the test is about taxonomy;
- soap oil: `Lipids / Vegetable oils` plus explicit trust and SAP;
- essential/fragrance/CO2: `Aromatic materials` plus exact subtype and aromatic capability;
- clay: `Minerals, salts & powders / Clays`;
- glycol: `Humectants & polyols / Glycerin and glycols`;
- preservative: `Preservation & stability / Preservatives`;
- water: `Water, solvents & carriers / Water`.

- [ ] **Step 2: Remove obsolete category helpers**

The following searches must return no behavioral uses:

```bash
rg -n "aromaticCases|aromaticValues|isCarrierOilCategory|isPublicAromaticCategory|is_potentially_saponifiable" app database tests
```

Migration compatibility references to `is_potentially_saponifiable` are allowed only in the rename migration.

- [ ] **Step 3: Run broad regression suites**

```bash
php artisan test --compact tests/Feature/IngredientsIndexPriceTest.php tests/Feature/PublicIngredientPagesTest.php tests/Feature/IngredientFormulaMutationServiceTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchContractTest.php tests/Feature/InciGenerationPreviewTest.php tests/Feature/RecipeVersionCostingTest.php
```

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app database tests lang resources
git commit -m "test: align ingredient workflows with new taxonomy"
```

---

### Task 10: Add catalogue audit tooling and complete rollout verification

**Files:**

- Create: `app/Console/Commands/AuditIngredientCatalogMetadata.php`
- Create: `tests/Feature/AuditIngredientCatalogMetadataCommandTest.php`
- Modify: `database/seeders/DatabaseSeeder.php` if command-backed imports require shared ordering

- [ ] **Step 1: Write failing audit-command tests**

The command must fail with a non-zero exit code when a platform ingredient has missing/invalid taxonomy, a required platform subcategory is missing, a trusted soap ingredient lacks KOH SAP, an aromatic ingredient lacks the capability, a CosIng row references an unknown ingredient/function, or a stale old category value remains.

- [ ] **Step 2: Implement read-only audit output**

Create `ingredients:audit-catalog-metadata` with grouped counts and record identifiers. It must not mutate data. Output groups:

```text
Invalid taxonomy
Missing platform subtype
Soap trust without KOH SAP
Aromatic subtype without compliance capability
CosIng exact matches
CosIng ambiguous/no-match review
Manual-only platform functions
Unresolved consolidation decisions
Conflicting duplicate workspace prices
```

- [ ] **Step 3: Test migrations and seeders on a fresh database**

```bash
php artisan test --compact tests/Feature/IngredientCatalogSchemaTest.php tests/Feature/IngredientCatalogMetadataSeederTest.php tests/Feature/AuditIngredientCatalogMetadataCommandTest.php
```

Expected: PASS and exactly the canonical dataset count in a fresh seeded fixture. The test must derive the expectation from the canonical dataset rather than hardcode the old 167-row count.

- [ ] **Step 4: Apply to the trial database**

Take a recoverable database backup, then run:

```bash
php artisan migrate --no-interaction
php artisan db:seed --class=IngredientFunctionSeeder --no-interaction
php artisan db:seed --class=IngredientCatalogSeeder --no-interaction
php artisan ingredients:consolidate-catalog
php artisan ingredients:consolidate-catalog --apply
php artisan db:seed --class=IngredientCatalogMetadataSeeder --no-interaction
php artisan ingredients:audit-catalog-metadata
```

Run the `--apply` command only after reviewing the dry-run output against the approved consolidation JSON. Expected afterward: no unresolved duplicate/junk decisions, no old category values, no invalid parent/child pairs, and an explicit review list for every canonical platform ingredient without an exact CosIng match.

- [ ] **Step 5: Perform focused UI acceptance checks**

- A Glycerin fixture is easy to find under Humectants & polyols and displays its functions.
- A Lavender hydrosol fixture is classified under Botanicals & extracts / Hydrosols, not as an essential oil.
- A trusted oil still participates in soap calculation after reclassification.
- A category change does not erase chemistry or compliance records.
- Platform CosIng functions cannot be removed by saving manual additions.
- Workspace prices remain workspace-specific and unchanged.
- The classification prompt copies valid JSON instructions without sending data externally.

- [ ] **Step 6: Run final quality gates**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
php artisan test --compact
graphify update .
git diff --check
```

Expected: Filament check clean, Pint clean, full Pest suite PASS, graph refreshed, and no whitespace errors.

- [ ] **Step 7: Final commit**

```bash
git add app database lang resources tests graphify-out
git commit -m "feat: complete ingredient taxonomy and CosIng rollout"
```

---

## Acceptance criteria

- The platform catalogue is idempotently rebuilt from one canonical dataset; retained ingredients keep their IDs, and approved duplicate removals occur only after dependency reconciliation.
- True grade variants remain distinct even when they share an INCI name; unresolved duplicate or price conflicts block consolidation.
- Every curated platform ingredient has a valid subcategory unless explicitly placed in `Other / needs classification`.
- The user-managed removal of the three disposable workspace ingredients is verified as a precondition; the taxonomy feature performs no workspace-ingredient deletion.
- The existing `IngredientFunctionSeeder` remains the single function vocabulary; the new dataset stores only ingredient-to-function assignments and provenance.
- No price row is treated as platform-owned; workspace price behavior remains unchanged.
- Category changes never delete chemistry, allergen, IFRA, composition, or function data.
- Soap calculation is controlled by explicit trust plus KOH SAP, not by the Lipids category.
- Allergen/IFRA behavior is controlled by explicit aromatic capability, not by the Aromatic materials category.
- Exact CosIng matches import all reviewed functions with source and verification metadata.
- Ambiguous/unmatched ingredients are flagged for review and never receive hallucinated functions.
- Users see a simple category, an optional specific type, plain-language help, and an optional copyable LLM prompt.
- Admins see complete professional metadata and a clear distinction between CosIng and manual functions.
- Every category and subcategory label, description, and help text has a reviewed English, German, Spanish, French, Italian, and Dutch value.
- Migrations, seeders, imports, and audits are idempotent and covered by Pest tests.
