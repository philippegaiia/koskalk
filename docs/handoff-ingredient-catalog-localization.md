# Ingredient Catalogue Localization Handoff

**Date:** 2026-08-13
**Branch at handoff:** `codex/ingredient-catalog-curation`

## Opening request for the next discussion

Use this document as the source of truth, inspect the current implementation, and design the next localization pass before editing code.

The task has three related parts:

1. Add an Admin-assisted workflow for translating platform ingredient content.
2. Localize the fixed COSING function vocabulary without changing its backing keys or regulatory meaning.
3. Diagnose why ingredient category or subcategory labels can still appear in English even though their translation keys exist.

Begin with a repository and rendered-flow audit. Present the recommended design and implementation sequence before making changes.

## Product intent

Soapkraft gives users a curated platform ingredient catalogue that can be used as-is or duplicated into a private workspace ingredient. Smaller professionals and hobbyists may rely entirely on the platform data. Professional users remain responsible for checking supplier and market-specific documents.

Localization must make the catalogue approachable in the user's language while keeping scientific identity and regulatory provenance stable.

Early users should eventually be able to suggest translation corrections. Suggestions remain proposals reviewed by the platform; they never directly overwrite published translations.

## Settled boundaries

- English remains the canonical platform editorial source.
- Only platform ingredients receive platform-managed translations.
- Private workspace ingredients remain exactly as authored by the user.
- When a platform ingredient is duplicated, the copy receives the localized platform name, saponification name, and information when available, then becomes fully workspace-owned.
- Missing localized platform content falls back to English.
- The Admin panel remains English-only. Admin users manage translations for the public workspace experience.
- Interface translation and platform catalogue translation remain separate systems.
- Keep these canonical and untranslated:
  - INCI names;
  - CAS, EC/EINECS, UNII, InChIKey, PubChem CID, and other identifier values;
  - COSING function backing keys;
  - category and subcategory backing values;
  - SAP and fatty-acid values;
  - IFRA categories, source references, dates, percentages, units, and regulatory citations.
- A translated COSING label or description must preserve the official function's meaning. Translation must never convert a practical use into a verified COSING assignment. For example, a preservative-booster use must not become a verified `preservative` function unless that assignment is actually supported by the curated COSING source.

## Existing platform ingredient translation implementation

The application already has the main platform-content translation foundation:

- `ingredient_translations` stores one row per ingredient and locale.
- Current translatable fields are:
  - `display_name`;
  - `saponification_name`;
  - `info_markdown`.
- `IngredientTranslationService` validates and synchronizes Admin-managed translations.
- `Ingredient::localizedDisplayName()`, `localizedSaponificationName()`, and `localizedInfoMarkdown()` resolve locale-specific values with English fallback.
- The Admin ingredient form already contains a `Translations` section for platform ingredients.
- Workspace catalogue, workbench, duplication, and several production/purchasing surfaces already call localized ingredient accessors.

Important files:

- `app/Models/Ingredient.php`
- `app/Models/IngredientTranslation.php`
- `app/Services/IngredientTranslationService.php`
- `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- `app/Services/UserIngredientAuthoringService.php`
- `database/migrations/2026_07_11_151729_create_ingredient_translations_table.php`
- `database/migrations/2026_08_08_064205_add_saponification_name_to_ingredients_and_ingredient_translations_tables.php`
- `docs/superpowers/specs/2026-07-11-platform-ingredient-translations-design.md`

The next task should extend this implementation rather than create a second ingredient translation system.

## Meaning of the ingredient name fields

### Display name

The understandable catalogue name shown to users. This should be translated for platform ingredients. The canonical INCI remains separate.

### Saponification name

A concise material or source name used in some generated soap-label summaries. For example, the canonical English value may be `Coconut`, while French may use `Coco` and Brazilian Portuguese may use `Coco`.

This field is not an INCI name and may be translated. The next discussion must audit every output that consumes it before automating translations, because its label-facing role requires concise noun forms rather than descriptive ingredient names.

### Information

Platform-authored Markdown guidance. It may be translated, but scientific claims, cautions, concentrations, and cited regulatory distinctions must remain faithful to the canonical English source.

### Aliases

Aliases already have locale-aware storage. Platform aliases may contain curated common names and spelling variants for search. They should not be mechanically generated without review, and they should not replace the primary translated display name. Private workspace aliases remain workspace-owned.

## Confirmed COSING localization gap

COSING functions are currently seeded into `ingredient_functions` with English `name` and `description` values. They are rendered directly from those database columns.

Confirmed English-only paths include:

- workspace ingredient `Verified COSING functions`;
- workspace `Additional functions` selector;
- Admin verified and additional function fields;
- the ingredient classification prompt's function vocabulary.

Relevant files:

- `app/Models/IngredientFunction.php`
- `database/seeders/IngredientFunctionSeeder.php`
- `app/Livewire/Dashboard/IngredientEditor.php`
- `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- `app/Services/IngredientClassificationPromptBuilder.php`

Recommended direction to validate in the new discussion:

- Keep `ingredient_functions.key`, canonical English `name`, and canonical English `description` unchanged.
- Add interface catalogue keys for the stable vocabulary:
  - `ingredients.functions.{key}.label`
  - `ingredients.functions.{key}.description`
- Give `IngredientFunction` explicit localized label and description methods with canonical English fallback.
- Replace every direct `pluck('name', 'id')` and verified-name rendering path with the localized resolver.
- Feed localized labels plus unchanged backing keys into the classification prompt. The requested response language remains explicit in the prompt.

This follows the existing category/subcategory pattern and allows review through the established interface translation catalogue. A new per-ingredient function-translation table is not warranted because the vocabulary is global and stable.

## Category and subcategory status

Category and subcategory localization is already designed and populated:

- `IngredientCategory::getLabel()` resolves `ingredients.categories.{value}.label`.
- `IngredientSubcategory::getLabel()` resolves `ingredients.subcategories.{value}.label`.
- Workspace selectors use `IngredientCategory::options()` and `IngredientSubcategory::optionsFor()`.
- The authoritative interface catalogue contains category and subcategory translations for German, Spanish, French, Italian, Dutch, and Brazilian Portuguese.

Therefore, English labels in a non-English workspace should be treated as a bug investigation, not as missing platform ingredient translations.

The audit must distinguish:

- Admin rendering, which is expected to remain English;
- workspace rendering with a non-English application locale, which should be localized;
- stale or absent database catalogue imports;
- cached translation values;
- a surface bypassing the enum label methods and rendering raw values or canonical English.

Capture the exact route, selected locale, component, and rendered label before changing the taxonomy implementation.

## Translation-assistance workflow to design

The current ingredient classification helper generates a prompt for an external LLM; Soapkraft is not connected to an LLM provider. Reuse that product pattern for translation assistance.

Recommended MVP flow:

1. An Admin chooses a platform ingredient and target locale.
2. Soapkraft builds a translation prompt from the canonical English display name, saponification name, information, relevant aliases, and controlled terminology rules.
3. The Admin copies the prompt into an external LLM.
4. The returned draft is previewed beside the English source.
5. The Admin edits and explicitly approves the translated fields before saving.
6. Published translations continue to use the existing `ingredient_translations` table.

Decide during design whether the first version uses readable manual output or a strict JSON response that can populate the Admin form. A structured response is justified only if the application parses it into unsaved draft state; it must never silently publish an LLM response.

Do not add an API provider, queue, background translation job, or dependency until the user explicitly chooses in-application LLM automation. Keep prompt construction independently testable so the same contract could support an API later.

For bulk catalogue preparation, an ignored draft/export file and reviewed import may be more efficient than editing every ingredient in Filament. It must reuse the same field boundaries, fallback rules, and explicit review gate.

## Quality and rollout

- Luna xhigh is acceptable for first-pass interface and platform-content drafts.
- Use a stronger model only for targeted review of regulatory, ingredient, soap-label, and specialist production terminology.
- Human/native-speaker review is ideal but should not block the MVP.
- Add a lightweight correction-suggestion workflow later so early users can participate.
- Keep every locale inactive until both interface and important platform catalogue content are sufficiently reviewed.
- Brazilian Portuguese interface translations are complete in the authoritative catalogue but `pt_BR` remains inactive at this handoff.

## Required audit and design sequence

1. Inspect the dirty worktree and preserve all existing changes. At handoff there were many uncommitted paths on `codex/ingredient-catalog-curation`; do not stash, reset, or stage unrelated files.
2. Verify all current consumers of the three localized ingredient fields and identify any remaining direct canonical-field rendering in workspace surfaces.
3. Reproduce the category/subcategory English-label report in a non-English workspace and identify the exact bypass or loading failure.
4. Inventory every direct use of `IngredientFunction::name` and `description` in user-facing or prompt output.
5. Confirm the COSING translation-key design and glossary.
6. Design the Admin translation prompt/preview workflow using the existing ingredient translation service.
7. Present the implementation plan, migration impact if any, validation rules, and focused Pest coverage before editing code.

## Acceptance criteria for the eventual implementation

- Platform ingredient display name, saponification name, and information can be drafted for a selected locale from Admin and are saved only after explicit approval.
- User-owned ingredients are never included in platform translation management.
- English fallback works independently for each translated field.
- COSING function labels and descriptions render in the workspace locale everywhere they are shown.
- COSING backing keys, assignments, assignment source, and source references remain unchanged.
- The classification prompt shows localized function labels while preserving exact allowed backing values.
- Category and subcategory selectors render in the workspace locale; Admin remains English.
- INCI and all controlled identifier values remain canonical.
- Platform duplication copies the localized values into the private ingredient as already intended.
- Focused tests cover English fallback, locale fallback such as `pt-BR` to `pt_BR` or the selected canonical rule, private-ingredient isolation, prompt vocabulary, category/subcategory selectors, and every COSING rendering path.
- Existing translation catalogue placeholder and completeness tests remain green.

## Existing references

- `docs/developer/translation-handoff.md`
- `docs/superpowers/specs/2026-07-11-platform-ingredient-translations-design.md`
- `docs/superpowers/specs/2026-08-09-ingredient-classification-prompt-response-design.md`
- `docs/superpowers/specs/2026-08-09-admin-ingredient-classification-helper-design.md`
- `docs/superpowers/plans/2026-08-08-ingredient-taxonomy-cosing-functions.md`
- `docs/superpowers/specs/2026-08-13-brazilian-portuguese-interface-translation-design.md`
