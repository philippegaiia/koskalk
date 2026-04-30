# Graph Report - koskalk  (2026-04-30)

## Corpus Check
- 366 files · ~150,568 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1837 nodes · 2883 edges · 79 communities detected
- Extraction: 71% EXTRACTED · 29% INFERRED · 0% AMBIGUOUS · INFERRED: 840 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- [[_COMMUNITY_Community 0|Community 0]]
- [[_COMMUNITY_Community 1|Community 1]]
- [[_COMMUNITY_Community 2|Community 2]]
- [[_COMMUNITY_Community 3|Community 3]]
- [[_COMMUNITY_Community 4|Community 4]]
- [[_COMMUNITY_Community 5|Community 5]]
- [[_COMMUNITY_Community 6|Community 6]]
- [[_COMMUNITY_Community 7|Community 7]]
- [[_COMMUNITY_Community 8|Community 8]]
- [[_COMMUNITY_Community 9|Community 9]]
- [[_COMMUNITY_Community 10|Community 10]]
- [[_COMMUNITY_Community 11|Community 11]]
- [[_COMMUNITY_Community 12|Community 12]]
- [[_COMMUNITY_Community 13|Community 13]]
- [[_COMMUNITY_Community 14|Community 14]]
- [[_COMMUNITY_Community 15|Community 15]]
- [[_COMMUNITY_Community 16|Community 16]]
- [[_COMMUNITY_Community 17|Community 17]]
- [[_COMMUNITY_Community 18|Community 18]]
- [[_COMMUNITY_Community 19|Community 19]]
- [[_COMMUNITY_Community 20|Community 20]]
- [[_COMMUNITY_Community 21|Community 21]]
- [[_COMMUNITY_Community 22|Community 22]]
- [[_COMMUNITY_Community 23|Community 23]]
- [[_COMMUNITY_Community 24|Community 24]]
- [[_COMMUNITY_Community 25|Community 25]]
- [[_COMMUNITY_Community 26|Community 26]]
- [[_COMMUNITY_Community 27|Community 27]]
- [[_COMMUNITY_Community 28|Community 28]]
- [[_COMMUNITY_Community 29|Community 29]]
- [[_COMMUNITY_Community 30|Community 30]]
- [[_COMMUNITY_Community 31|Community 31]]
- [[_COMMUNITY_Community 32|Community 32]]
- [[_COMMUNITY_Community 33|Community 33]]
- [[_COMMUNITY_Community 34|Community 34]]
- [[_COMMUNITY_Community 35|Community 35]]
- [[_COMMUNITY_Community 36|Community 36]]
- [[_COMMUNITY_Community 37|Community 37]]
- [[_COMMUNITY_Community 38|Community 38]]
- [[_COMMUNITY_Community 39|Community 39]]
- [[_COMMUNITY_Community 40|Community 40]]
- [[_COMMUNITY_Community 41|Community 41]]
- [[_COMMUNITY_Community 42|Community 42]]
- [[_COMMUNITY_Community 43|Community 43]]
- [[_COMMUNITY_Community 44|Community 44]]
- [[_COMMUNITY_Community 45|Community 45]]
- [[_COMMUNITY_Community 46|Community 46]]
- [[_COMMUNITY_Community 47|Community 47]]
- [[_COMMUNITY_Community 48|Community 48]]
- [[_COMMUNITY_Community 49|Community 49]]
- [[_COMMUNITY_Community 50|Community 50]]
- [[_COMMUNITY_Community 93|Community 93]]
- [[_COMMUNITY_Community 94|Community 94]]
- [[_COMMUNITY_Community 95|Community 95]]
- [[_COMMUNITY_Community 96|Community 96]]
- [[_COMMUNITY_Community 97|Community 97]]
- [[_COMMUNITY_Community 98|Community 98]]
- [[_COMMUNITY_Community 99|Community 99]]
- [[_COMMUNITY_Community 100|Community 100]]
- [[_COMMUNITY_Community 101|Community 101]]
- [[_COMMUNITY_Community 102|Community 102]]
- [[_COMMUNITY_Community 103|Community 103]]
- [[_COMMUNITY_Community 104|Community 104]]
- [[_COMMUNITY_Community 105|Community 105]]
- [[_COMMUNITY_Community 106|Community 106]]
- [[_COMMUNITY_Community 107|Community 107]]
- [[_COMMUNITY_Community 108|Community 108]]
- [[_COMMUNITY_Community 109|Community 109]]
- [[_COMMUNITY_Community 110|Community 110]]
- [[_COMMUNITY_Community 111|Community 111]]
- [[_COMMUNITY_Community 112|Community 112]]
- [[_COMMUNITY_Community 113|Community 113]]
- [[_COMMUNITY_Community 114|Community 114]]
- [[_COMMUNITY_Community 116|Community 116]]
- [[_COMMUNITY_Community 120|Community 120]]
- [[_COMMUNITY_Community 121|Community 121]]
- [[_COMMUNITY_Community 122|Community 122]]
- [[_COMMUNITY_Community 123|Community 123]]
- [[_COMMUNITY_Community 198|Community 198]]

## God Nodes (most connected - your core abstractions)
1. `MediaStorage` - 54 edges
2. `InciGenerationService` - 37 edges
3. `RecipeWorkbench` - 34 edges
4. `RecipeWorkbenchService` - 29 edges
5. `Ingredient` - 26 edges
6. `RecipeController` - 25 edges
7. `RecipeWorkbookExporter` - 24 edges
8. `Recipe` - 21 edges
9. `RecipeWorkbenchVersionDataService` - 21 edges
10. `SoapCalculationService` - 20 edges

## Surprising Connections (you probably didn't know these)
- `User Dashboard` --semantically_similar_to--> `Public Dashboard Spec`  [INFERRED] [semantically similar]
  plan.md → docs/specs/public-dashboard.md
- `Draft Save And Version Save Model` --semantically_similar_to--> `Ingredient Versioning`  [INFERRED] [semantically similar]
  plan.md → docs/developer/catalog-and-admin.md
- `Filament Admin Data Stewardship` --semantically_similar_to--> `Catalog Data Stewardship Philosophy`  [INFERRED] [semantically similar]
  plan.md → docs/developer/catalog-and-admin.md
- `IngredientSapProfile` --calls--> `makeSoapOilIngredient()`  [INFERRED]
  app/Models/IngredientSapProfile.php → tests/Feature/InciGenerationPreviewTest.php
- `IngredientSapProfile` --calls--> `packagingPlanIngredient()`  [INFERRED]
  app/Models/IngredientSapProfile.php → tests/Feature/RecipeVersionPackagingPlanTest.php

## Hyperedges (group relationships)
- **Live Workbench Data Flow** — plan_runtime_model, plan_formulation_page, engine_soap_calculation_service, spec_soap_outputs [EXTRACTED 1.00]
- **Restrained Professional UI Pattern** — design_clinical_naturalist, public_ui_custom_tailwind, spec_public_dashboard, plan_professional_formulation_workspace [INFERRED 0.86]
- **Catalog Compliance Traceability Model** — catalog_versioning, catalog_compliance_structure, spec_aromatic_compliance_data, plan_compliance_page [EXTRACTED 1.00]
- **Packaging Catalog Plan Costing Separation** — packaging_items_catalog_manager, packaging_batch_first_class_packaging_plan, packaging_costing_snapshots [EXTRACTED 1.00]
- **User Price Memory Stable Costing And Batch Use** — price_management_user_ingredient_prices, recipe_costing_stable_formula_costing, packaging_batch_official_batch_use [EXTRACTED 1.00]
- **Carrier Oil CSV Calculator Import Pipeline** — carrier_oil_user_csv_diff, carrier_oil_mendrulandia_source, carrier_oil_chemistry_records [EXTRACTED 1.00]
- **Soapcraft Logo Visual Identity** — soapcraft_logo_green_light_image, soapcraft_logo_green_light_sk_monogram, soapcraft_logo_green_light_circular_seal, soapcraft_logo_green_light_green_palette, soapcraft_logo_green_light_brand_identity [INFERRED 0.84]
- **Soapkraft Beige Logo Visual Identity** — soapkraftlogo_beige_logo_image, soapkraftlogo_beige_sk_monogram, soapkraftlogo_beige_circular_seal, soapkraftlogo_beige_soft_beige_green_palette, soapkraftlogo_beige_embossed_soap_style, soapkraftlogo_beige_soapkraft_brand_identity [INFERRED 0.84]

## Communities

### Community 0 - "Community 0"
Cohesion: 0.03
Nodes (23): ReportMissingCarrierOilChemistry, InciNameLookup, cosmeticDraftPayload(), cosmeticDraftPayloadWithPhases(), cosmeticIngredient(), recipeWorkbenchComparableDraftPayload(), down(), collapseVersionScopedDuplicates() (+15 more)

### Community 1 - "Community 1"
Cohesion: 0.03
Nodes (29): SoapSap, DiffCarrierOilsFromCsv, IngredientController, PackagingItemEditor, IfraCertificateFactory, IngredientComponentFactory, IngredientSapProfileFactory, makeSoapOilIngredient() (+21 more)

### Community 2 - "Community 2"
Cohesion: 0.03
Nodes (24): RecipeItemFactory, RecipePhaseFactory, RecipeVersionFactory, createRecipeWithDraftAndPublishedVersion(), createRecipeWithDraftOnly(), createRecipeWithTwoPublishedVersions(), deletionSoapDraftPayload(), makeDeletionCarrierOilIngredient() (+16 more)

### Community 3 - "Community 3"
Cohesion: 0.04
Nodes (14): DashboardController, HomeController, PackagingItemController, RecipeController, IngredientsIndex, PackagingItemsIndex, ProductTypeFactory, RecipeFactory (+6 more)

### Community 4 - "Community 4"
Cohesion: 0.03
Nodes (16): getLabel(), options(), ImportCarrierOilChemistryFromMendrulandia, IngredientEditor, IngredientAllergenEntryFactory, IngredientFattyAcidFactory, IfraProductCategory, IngredientAllergenEntry (+8 more)

### Community 5 - "Community 5"
Cohesion: 0.03
Nodes (6): ProductType, Recipe, MediaStorage, RecipeContentUpdater, RecipeRichContentAttachmentProvider, RecipeWorkbenchContentFormSchema

### Community 6 - "Community 6"
Cohesion: 0.04
Nodes (12): workspace(), SettingsIndex, WorkspaceFactory, WorkspaceMemberFactory, CreateDefaultCompany, User, Workspace, WorkspaceInvitation (+4 more)

### Community 7 - "Community 7"
Cohesion: 0.05
Nodes (17): canAccessWorkspace(), canDeleteWorkspaceRecords(), canEditWorkspaceRecords(), canManageWorkspace(), workspaceHasRole(), isAccessibleBy(), isOwnedBy(), isWorkspaceAccessibleBy() (+9 more)

### Community 8 - "Community 8"
Cohesion: 0.04
Nodes (65): Home Page Redesign Spec, Chemistry Lives In SAP Profiles, Aromatic Compliance Structure, Ingredient Categories, Catalog Data Stewardship Philosophy, Ingredient Versioning, Current Implemented Foundations, Ingredient Model Split (+57 more)

### Community 9 - "Community 9"
Cohesion: 0.05
Nodes (8): RecipePhase, RecipeVersion, RecipeVersionCosting, UserIngredientPrice, UserPackagingItem, RecipeVersionCostingSynchronizer, UserIngredientPriceMemory, UserPackagingItemAuthoringService

### Community 10 - "Community 10"
Cohesion: 0.07
Nodes (37): FattyAcidExporter, IngredientExporter, additionWeightTotal(), averageFattyAcidProfile(), curedBatchWeight(), finalBatchWeight(), lyeBreakdown(), normalizeSapValue() (+29 more)

### Community 11 - "Community 11"
Cohesion: 0.05
Nodes (10): extractIngredientDataEntryState(), syncIngredientDataEntryState(), RecipesIndex, IngredientResource, CreateIngredient, EditIngredient, ListIngredients, IngredientForm (+2 more)

### Community 12 - "Community 12"
Cohesion: 0.11
Nodes (8): RecipeWorkbench, persistCosting(), persistWorkbench(), refreshCalculationPreview(), refreshLabelingPreview(), serializeCosting(), serializeDraft(), serializeRow()

### Community 13 - "Community 13"
Cohesion: 0.05
Nodes (12): up(), up(), up(), up(), up(), up(), up(), up() (+4 more)

### Community 14 - "Community 14"
Cohesion: 0.12
Nodes (1): InciGenerationService

### Community 15 - "Community 15"
Cohesion: 0.12
Nodes (9): getLabel(), iodineFactor(), options(), SoapCalculationService, benchmarkQualities(), puRiskResult(), soapQualityTuningCoconutOil(), superfatQualityResult() (+1 more)

### Community 16 - "Community 16"
Cohesion: 0.08
Nodes (7): IfraCertificateLimitFactory, ProductFamilyIfraCategoryFactory, IfraCertificate, IfraCertificateLimit, ProductFamilyIfraCategory, IfraProductCategorySeeder, RecipeWorkbenchIfraOptionsBuilder

### Community 17 - "Community 17"
Cohesion: 0.12
Nodes (3): RecipeNormalizationService, RecipeWorkbenchPayloadNormalizer, RecipeWorkbenchPhaseBlueprints

### Community 18 - "Community 18"
Cohesion: 0.07
Nodes (7): Brand, CreateProductType, EditProductType, ListProductTypes, ProductTypeResource, ProductTypeForm, ProductTypesTable

### Community 19 - "Community 19"
Cohesion: 0.28
Nodes (1): RecipeWorkbookExporter

### Community 20 - "Community 20"
Cohesion: 0.09
Nodes (6): AllergenResource, CreateAllergen, EditAllergen, ListAllergens, AllergenForm, AllergensTable

### Community 21 - "Community 21"
Cohesion: 0.09
Nodes (23): Recipe Costing Design, Remove price_eur And display_name_en Columns, Costing Derives Packaging Rows From Packaging Plan, First-Class Recipe Version Packaging Plan, No Backfill For Development Packaging Rows, Official Recipe Batch Use Flow, Recipe Workbench Packaging Tab, Batch Context In Browser Print Views (+15 more)

### Community 22 - "Community 22"
Cohesion: 0.1
Nodes (5): FattyAcidResource, CreateFattyAcid, EditFattyAcid, ListFattyAcids, FattyAcidsTable

### Community 23 - "Community 23"
Cohesion: 0.11
Nodes (5): IfraProductCategoryResource, CreateIfraProductCategory, EditIfraProductCategory, ListIfraProductCategories, IfraProductCategoriesTable

### Community 24 - "Community 24"
Cohesion: 0.11
Nodes (5): IfraCertificateResource, CreateIfraCertificate, EditIfraCertificate, ListIfraCertificates, IfraCertificatesTable

### Community 25 - "Community 25"
Cohesion: 0.11
Nodes (5): IngredientSapProfileResource, CreateIngredientSapProfile, EditIngredientSapProfile, ListIngredientSapProfiles, IngredientSapProfilesTable

### Community 26 - "Community 26"
Cohesion: 0.11
Nodes (5): IngredientAllergenEntryResource, CreateIngredientAllergenEntry, EditIngredientAllergenEntry, ListIngredientAllergenEntries, IngredientAllergenEntriesTable

### Community 27 - "Community 27"
Cohesion: 0.13
Nodes (11): createCatalogSection(), createPersistenceSection(), createRecipeWorkbench(), createRecipeWorkbenchState(), defaultPhaseBlueprints(), phaseItemsForBlueprints(), createCostingSection(), createFormulaSection() (+3 more)

### Community 28 - "Community 28"
Cohesion: 0.35
Nodes (1): RecipeWorkbenchPreviewService

### Community 29 - "Community 29"
Cohesion: 0.31
Nodes (1): RecipeVersionPackagingItemPolicy

### Community 30 - "Community 30"
Cohesion: 0.31
Nodes (1): RecipeVersionCostingItemPolicy

### Community 31 - "Community 31"
Cohesion: 0.31
Nodes (1): RecipeVersionCostingPolicy

### Community 32 - "Community 32"
Cohesion: 0.31
Nodes (1): RecipeVersionCostingPackagingItemPolicy

### Community 33 - "Community 33"
Cohesion: 0.31
Nodes (1): UserPackagingItemPolicy

### Community 34 - "Community 34"
Cohesion: 0.22
Nodes (8): livewire.dashboard.partials.recipe-workbench.costing-tab, livewire.dashboard.partials.recipe-workbench.formula-tab, livewire.dashboard.partials.recipe-workbench.header, livewire.dashboard.partials.recipe-workbench.instructions-media, livewire.dashboard.partials.recipe-workbench.navigation, livewire.dashboard.partials.recipe-workbench.output-tab, livewire.dashboard.partials.recipe-workbench.packaging-catalog-modal, livewire.dashboard.partials.recipe-workbench.packaging-tab

### Community 35 - "Community 35"
Cohesion: 0.25
Nodes (8): Carrier Oil Chemistry Records, Diff Carrier Oils From CSV Command, Mendrulandia Numeric Fatty Acid Key Mapping, Import Carrier Oil Chemistry Command, Common Name To INCI Lookup Table, Mendrulandia Calculator Oil Data Source, SoapCalc Fallback Source, Carrier Oil CSV Diff Workflow

### Community 36 - "Community 36"
Cohesion: 0.29
Nodes (6): inviteMember, removeMember({{ $member->id }}), saveCompany, saveProfile, $set(, updatePassword

### Community 37 - "Community 37"
Cohesion: 0.29
Nodes (6): livewire.dashboard.partials.recipe-workbench.cosmetic-formula, livewire.dashboard.partials.recipe-workbench.formula-analysis, livewire.dashboard.partials.recipe-workbench.formula-settings, livewire.dashboard.partials.recipe-workbench.ingredient-browser, livewire.dashboard.partials.recipe-workbench.post-reaction, livewire.dashboard.partials.recipe-workbench.reaction-core

### Community 38 - "Community 38"
Cohesion: 0.33
Nodes (1): ProductFamily

### Community 39 - "Community 39"
Cohesion: 0.33
Nodes (6): Formula Deletion Policy Design, Formula Deletion Policy Implementation Plan, Delete Buttons In Recipe List Workbench And Version View, Hard Delete Recipes And Versions, Recipe Deletion Feature Tests, Server-Enforced Copy-Paste Confirmation

### Community 40 - "Community 40"
Cohesion: 0.53
Nodes (6): Application Brand Asset, Soapcraft Brand Identity, Circular Embossed Seal, Light Green Natural Palette, Soapcraft Green Light Logo Image, SK Monogram

### Community 41 - "Community 41"
Cohesion: 0.47
Nodes (6): Circular Seal Badge, Embossed Soap Style, Soapkraft Beige Logo Image, SK Monogram, Soapkraft Brand Identity, Soft Beige Green Palette

### Community 42 - "Community 42"
Cohesion: 0.4
Nodes (1): UserFactory

### Community 43 - "Community 43"
Cohesion: 0.8
Nodes (4): initializeSidebar(), setSidebarState(), sidebarIsDesktop(), sidebarStoredState()

### Community 44 - "Community 44"
Cohesion: 0.7
Nodes (4): extractBaseName(), fuzzyMatch(), matchScore(), normalizeName()

### Community 45 - "Community 45"
Cohesion: 0.4
Nodes (5): CurrentAppUserResolver Authorization Pattern, Recipe And Version DELETE Routes, Last Published Version Warning, RecipeController Destroy Methods, RecipeWorkbench Delete Version Action

### Community 46 - "Community 46"
Cohesion: 0.4
Nodes (5): Deep Copy Ingredient Relations, Skip Platform Images During Duplication, Platform Ingredient Duplication To User-Owned Copy, Saponifiable Ingredient SAP Edit Threshold, User-Owned Ingredient Compliance Disclaimer

### Community 47 - "Community 47"
Cohesion: 0.5
Nodes (1): AppServiceProvider

### Community 48 - "Community 48"
Cohesion: 0.5
Nodes (1): FattyAcid

### Community 49 - "Community 49"
Cohesion: 0.5
Nodes (1): Allergen

### Community 50 - "Community 50"
Cohesion: 0.83
Nodes (3): extractBaseName(), getMatchScore(), namesMatch()

### Community 93 - "Community 93"
Cohesion: 0.67
Nodes (1): IngredientFactory

### Community 94 - "Community 94"
Cohesion: 0.67
Nodes (1): IngredientFunctionFactory

### Community 95 - "Community 95"
Cohesion: 0.67
Nodes (1): AllergenFactory

### Community 96 - "Community 96"
Cohesion: 0.67
Nodes (1): ProductFamilyFactory

### Community 97 - "Community 97"
Cohesion: 0.67
Nodes (1): IfraProductCategoryFactory

### Community 98 - "Community 98"
Cohesion: 0.67
Nodes (1): FattyAcidFactory

### Community 99 - "Community 99"
Cohesion: 0.67
Nodes (1): AdminPanelProvider

### Community 100 - "Community 100"
Cohesion: 0.67
Nodes (1): RecipeCsvExporter

### Community 101 - "Community 101"
Cohesion: 1.0
Nodes (2): createExifOrientedJpegFixture(), withExifOrientation()

### Community 102 - "Community 102"
Cohesion: 1.0
Nodes (2): writeCatalogFixtures(), writeCsv()

### Community 103 - "Community 103"
Cohesion: 1.0
Nodes (1): Controller

### Community 104 - "Community 104"
Cohesion: 1.0
Nodes (1): dashboard.settings-index

### Community 105 - "Community 105"
Cohesion: 1.0
Nodes (1): dashboard.packaging-items-index

### Community 106 - "Community 106"
Cohesion: 1.0
Nodes (1): dashboard.packaging-item-editor

### Community 107 - "Community 107"
Cohesion: 1.0
Nodes (1): livewire.dashboard.partials.duplicate-ingredient-modal

### Community 108 - "Community 108"
Cohesion: 1.0
Nodes (1): clearFilters

### Community 109 - "Community 109"
Cohesion: 1.0
Nodes (1): livewire.dashboard.partials.recipe-workbench.ingredient-list-preview

### Community 110 - "Community 110"
Cohesion: 1.0
Nodes (1): dashboard.recipes-index

### Community 111 - "Community 111"
Cohesion: 1.0
Nodes (1): dashboard.recipe-workbench

### Community 112 - "Community 112"
Cohesion: 1.0
Nodes (1): recipes.partials.version-sheet

### Community 113 - "Community 113"
Cohesion: 1.0
Nodes (1): dashboard.ingredients-index

### Community 114 - "Community 114"
Cohesion: 1.0
Nodes (1): dashboard.ingredient-editor

### Community 116 - "Community 116"
Cohesion: 1.0
Nodes (1): TestCase

### Community 120 - "Community 120"
Cohesion: 1.0
Nodes (2): Recipe Packaging And Batch Use Design, Packaging Batch Use Implementation Plan

### Community 121 - "Community 121"
Cohesion: 1.0
Nodes (2): Price Management And Ingredient Duplication Design, Price Management And Ingredient Duplication Implementation Plan

### Community 122 - "Community 122"
Cohesion: 1.0
Nodes (2): Carrier Oil Data Seeder Design Spec, Carrier Oil Data Seeder Implementation Plan

### Community 123 - "Community 123"
Cohesion: 1.0
Nodes (2): Packaging Costing Clarification Implementation Plan, Packaging Costing Design

### Community 198 - "Community 198"
Cohesion: 1.0
Nodes (1): Application Page Map

## Knowledge Gaps
- **85 isolated node(s):** `Controller`, `dashboard.settings-index`, `dashboard.packaging-items-index`, `dashboard.packaging-item-editor`, `livewire.dashboard.partials.recipe-workbench.header` (+80 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **Thin community `Community 14`** (37 nodes): `InciGenerationService.php`, `InciGenerationService`, `.appendIncorporatedIngredientRows()`, `.appendSaponifiedIngredientRows()`, `.appendStandaloneIngredientRow()`, `.basisState()`, `.buildListVariants()`, `.combineSoapLabels()`, `.declarationNotes()`, `.declarationReplacementLabel()`, `.declarationRows()`, `.declarationStatusLabel()`, `.deduplicateOwnershipFlags()`, `.defaultListVariant()`, `.expandedContexts()`, `.finalLabels()`, `.generate()`, `.incorporatedIngredientLabel()`, `.ingredientById()`, `.ingredientGraphRelations()`, `.ingredientListLabel()`, `.ingredientRowContributions()`, `.ingredientRowsState()`, `.isParfumLabel()`, `.listVariants()`, `.mergeRowKind()`, `.normalizeLabel()`, `.normalizePrintedLabel()`, `.preloadIngredientGraph()`, `.preloadIngredientsForPayload()`, `.resolveRowContexts()`, `.rowWeight()`, `.soapLabel()`, `.superfatRatio()`, `.thresholdPercent()`, `.variantWarnings()`, `.warnings()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 19`** (25 nodes): `RecipeWorkbookExporter.php`, `RecipeWorkbookExporter`, `.addBlank()`, `.addHeader()`, `.addRows()`, `.addStyledRow()`, `.addTitle()`, `.export()`, `.headerStyle()`, `.labelStyle()`, `.labelValueColumnStyles()`, `.moneyStyle()`, `.numberStyle()`, `.pairs()`, `.prepareSheet()`, `.titleStyle()`, `.totalNumberStyle()`, `.totalStyle()`, `.wrapStyle()`, `.writeCostingSheet()`, `.writeDeclarationSheet()`, `.writeFormulaSheet()`, `.writeOutputsSheet()`, `.writePackagingSheet()`, `.writeSummarySheet()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 28`** (11 nodes): `RecipeWorkbenchPreviewService.php`, `.toPreviewPayload()`, `RecipeWorkbenchPreviewService`, `.calculationFromWorkbenchDraft()`, `.__construct()`, `.inciFromWorkbenchDraft()`, `.labelingFromWorkbenchDraft()`, `.previewInci()`, `.previewRowWeight()`, `.previewSoapCalculation()`, `.snapshotFromWorkbenchDraft()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 29`** (9 nodes): `RecipeVersionPackagingItemPolicy.php`, `RecipeVersionPackagingItemPolicy`, `.create()`, `.delete()`, `.forceDelete()`, `.restore()`, `.update()`, `.view()`, `.viewAny()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 30`** (9 nodes): `RecipeVersionCostingItemPolicy.php`, `RecipeVersionCostingItemPolicy`, `.create()`, `.delete()`, `.forceDelete()`, `.restore()`, `.update()`, `.view()`, `.viewAny()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 31`** (9 nodes): `RecipeVersionCostingPolicy.php`, `RecipeVersionCostingPolicy`, `.create()`, `.delete()`, `.forceDelete()`, `.restore()`, `.update()`, `.view()`, `.viewAny()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 32`** (9 nodes): `RecipeVersionCostingPackagingItemPolicy.php`, `RecipeVersionCostingPackagingItemPolicy`, `.create()`, `.delete()`, `.forceDelete()`, `.restore()`, `.update()`, `.view()`, `.viewAny()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 33`** (9 nodes): `UserPackagingItemPolicy.php`, `UserPackagingItemPolicy`, `.create()`, `.delete()`, `.forceDelete()`, `.restore()`, `.update()`, `.view()`, `.viewAny()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 38`** (6 nodes): `ProductFamily.php`, `ProductFamily`, `.casts()`, `.ifraCategoryMappings()`, `.ifraProductCategories()`, `.productTypes()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 42`** (5 nodes): `UserFactory.php`, `UserFactory`, `.admin()`, `.definition()`, `.unverified()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 47`** (4 nodes): `AppServiceProvider.php`, `AppServiceProvider`, `.boot()`, `.register()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 48`** (4 nodes): `FattyAcid.php`, `FattyAcid`, `.casts()`, `.ingredientEntries()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 49`** (4 nodes): `Allergen.php`, `Allergen`, `.casts()`, `.ingredientEntries()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 93`** (3 nodes): `IngredientFactory.php`, `IngredientFactory`, `.definition()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 94`** (3 nodes): `IngredientFunctionFactory.php`, `IngredientFunctionFactory`, `.definition()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 95`** (3 nodes): `AllergenFactory.php`, `AllergenFactory`, `.definition()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 96`** (3 nodes): `ProductFamilyFactory.php`, `ProductFamilyFactory`, `.definition()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 97`** (3 nodes): `IfraProductCategoryFactory.php`, `IfraProductCategoryFactory`, `.definition()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 98`** (3 nodes): `FattyAcidFactory.php`, `FattyAcidFactory`, `.definition()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 99`** (3 nodes): `AdminPanelProvider.php`, `AdminPanelProvider`, `.panel()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 100`** (3 nodes): `RecipeCsvExporter.php`, `RecipeCsvExporter`, `.export()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 101`** (3 nodes): `createExifOrientedJpegFixture()`, `withExifOrientation()`, `MediaStorageTest.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 102`** (3 nodes): `writeCatalogFixtures()`, `writeCsv()`, `CatalogSeederTest.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 103`** (2 nodes): `Controller.php`, `Controller`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 104`** (2 nodes): `dashboard.settings-index`, `settings.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 105`** (2 nodes): `dashboard.packaging-items-index`, `index.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 106`** (2 nodes): `dashboard.packaging-item-editor`, `editor.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 107`** (2 nodes): `livewire.dashboard.partials.duplicate-ingredient-modal`, `ingredients-index.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 108`** (2 nodes): `clearFilters`, `recipes-index.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 109`** (2 nodes): `livewire.dashboard.partials.recipe-workbench.ingredient-list-preview`, `output-tab.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 110`** (2 nodes): `dashboard.recipes-index`, `index.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 111`** (2 nodes): `dashboard.recipe-workbench`, `workbench.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 112`** (2 nodes): `recipes.partials.version-sheet`, `version.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 113`** (2 nodes): `dashboard.ingredients-index`, `index.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 114`** (2 nodes): `dashboard.ingredient-editor`, `editor.blade.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 116`** (2 nodes): `TestCase.php`, `TestCase`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 120`** (2 nodes): `Recipe Packaging And Batch Use Design`, `Packaging Batch Use Implementation Plan`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 121`** (2 nodes): `Price Management And Ingredient Duplication Design`, `Price Management And Ingredient Duplication Implementation Plan`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 122`** (2 nodes): `Carrier Oil Data Seeder Design Spec`, `Carrier Oil Data Seeder Implementation Plan`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 123`** (2 nodes): `Packaging Costing Clarification Implementation Plan`, `Packaging Costing Design`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Community 198`** (1 nodes): `Application Page Map`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `MediaStorage` connect `Community 5` to `Community 0`, `Community 1`, `Community 3`, `Community 4`, `Community 9`, `Community 18`?**
  _High betweenness centrality (0.062) - this node is a cross-community bridge._
- **Why does `isOwnedBy()` connect `Community 7` to `Community 1`, `Community 3`, `Community 4`?**
  _High betweenness centrality (0.027) - this node is a cross-community bridge._
- **Are the 23 inferred relationships involving `MediaStorage` (e.g. with `.fallbackImageUrl()` and `.featuredImageUrl()`) actually correct?**
  _`MediaStorage` has 23 INFERRED edges - model-reasoned connections that need verification._
- **What connects `Controller`, `dashboard.settings-index`, `dashboard.packaging-items-index` to the rest of the system?**
  _85 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Community 0` be split into smaller, more focused modules?**
  _Cohesion score 0.03 - nodes in this community are weakly interconnected._
- **Should `Community 1` be split into smaller, more focused modules?**
  _Cohesion score 0.03 - nodes in this community are weakly interconnected._
- **Should `Community 2` be split into smaller, more focused modules?**
  _Cohesion score 0.03 - nodes in this community are weakly interconnected._