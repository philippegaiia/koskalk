# Ingredient Classification Readable Response Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Change the external ingredient-classification prompt from a JSON-only category response into a readable professional answer that clarifies ambiguous ingredients and reviews or proposes INCI, CAS, and EC/EINECS identifiers.

**Architecture:** Keep prompt construction inside `IngredientEditor::classificationPrompt()` and preserve the existing generate-preview-copy workflow. Replace only the prompt contract and the helper description; no LLM integration, response parser, form autofill, schema, or persistence changes are introduced.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, Pest 4, Laravel translation files and the interface-translation JSON catalogue.

---

## File Structure

- `app/Livewire/Dashboard/IngredientEditor.php`: constructs the external assistant prompt from current form identity, taxonomy, and active function vocabulary.
- `tests/Feature/UserIngredientAuthoringTest.php`: specifies the clarification gate, readable response sections, identity-review contract, and existing regulatory safeguards.
- `lang/en/ingredients.php`: authoritative English helper description shown in the customer ingredient editor.
- `database/seeders/data/interface-translations.json`: authoritative German, Spanish, French, Italian, and Dutch translations of the revised helper description.
- `tests/Feature/IngredientEditorLocalizationTest.php`: verifies the revised English helper copy appears on the create page.
- `tests/Feature/InterfaceTranslationCatalogueTest.php`: validates translation catalogue structure and locale completeness.

### Task 1: Replace the JSON-Only Prompt Contract

**Files:**
- Modify: `tests/Feature/UserIngredientAuthoringTest.php:23-49`
- Modify: `app/Livewire/Dashboard/IngredientEditor.php:126-159`

- [ ] **Step 1: Write the failing prompt-contract assertions**

Replace the expectation block in `builds a paste-ready classification prompt from the current ingredient` with assertions covering the approved human-readable contract:

```php
expect($prompt)
    ->toContain('Vegetable glycerin', 'GLYCERIN', '56-81-5', '200-289-5', 'Palm-free supplier grade')
    ->toContain('humectants_polyols', 'glycerin_glycols', 'humectant')
    ->toContain('ask one to three concise plain-language questions')
    ->toContain('Do not classify the ingredient or propose identifiers until the user answers')
    ->toContain('Ingredient overview', 'Classification', 'Identity review', 'Functions', 'Specialist review', 'Professional notes')
    ->toContain('User-entered value', 'Proposed value', 'EC / EINECS', 'No supported proposal', 'Not verified')
    ->toContain('official European Commission COSING source', 'verified KOH SAP', 'cannot establish trusted soap chemistry')
    ->not->toContain('Return JSON only')
    ->not->toContain('"category":')
    ->not->toContain('"is_soap_saponification_trusted"')
    ->not->toContain('[replace]');
```

- [ ] **Step 2: Run the prompt test and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter='builds a paste-ready classification prompt'
```

Expected: FAIL because the current prompt still contains `Return JSON only` and lacks the clarification and identity-review sections.

- [ ] **Step 3: Replace the prompt heredoc with the readable contract**

Keep the current identity, taxonomy, and function-vocabulary construction. Replace only the heredoc returned by `classificationPrompt()` with:

```php
return <<<PROMPT
Classify and review the identity of the cosmetic or soapmaking ingredient below. Use only the category, subcategory, and function backing values supplied here. Write for a beginner while applying professional cosmetic terminology.

Current ingredient:
{$this->prettyJson($identity)}

Taxonomy:
{$taxonomy}

Available COSING function vocabulary:
{$functionVocabulary}

Clarification gate:
- If the ingredient identity is unclear, ask one to three concise plain-language questions and wait for the user's answers.
- Do not classify the ingredient or propose identifiers until the user answers.
- Clarify unknown or ambiguous product names, trade names without an INCI or composition, generic names that may represent several materials, incomplete botanicals, insufficiently described blends, and conflicting names or identifiers.
- Ask only for information that materially improves identification, such as the supplier INCI, SDS, specification sheet, composition, botanical species and plant part, or extraction method.

Rules for a sufficiently clear ingredient:
- Return readable structured text, not JSON.
- Return exactly one category label with its backing value. Return one compatible subcategory label with its backing value unless the category is "other".
- A function describes what the ingredient does; it does not replace its material category. Conditioning agents may occur in several categories.
- Review every user-entered INCI, CAS, and EC / EINECS value. Clearly distinguish the user-entered value from any proposed correction.
- For a missing identifier, provide a proposal only when it is supportable. Otherwise write "No supported proposal".
- For each identifier, give a status of consistent, questionable, missing, or conflicting; a confidence of high, medium, or low; and a supporting source or "Not verified".
- Multiple identifier candidates are allowed only when legitimate material variants exist; explain the distinction briefly.
- Suggest aromatic allergen or IFRA review only when relevant to this exact material.
- Suggest soap-saponification review when the material may need a verified KOH SAP value. This suggestion cannot establish trusted soap chemistry; never infer trust merely from "oil", "butter", "fat", or "wax".
- Describe a function as COSING-verified only when supported by an official European Commission COSING source. Otherwise list it as an additional suggested function with its exact backing key.
- Do not invent an INCI, CAS number, EC / EINECS number, SAP value, COSING reference, or source URL. Do not treat plausible memory as verification.
- State when an identifier belongs to a component rather than the complete commercial blend.

Use this response structure when the ingredient is sufficiently clear:

Ingredient overview
Write two to four short lines explaining what the ingredient is, its usual cosmetic or soapmaking role, and any material distinction a beginner should understand.

Classification
- Category: label (backing_value)
- Subcategory: label (backing_value), or Not applicable
- Reason: brief professional explanation

Identity review
- INCI
  - User-entered value: value or Not provided
  - Proposed value: value or No supported proposal
  - Status: consistent, questionable, missing, or conflicting
  - Confidence: high, medium, or low
  - Source: supporting source or Not verified
- CAS number
  - User-entered value: value or Not provided
  - Proposed value: value or No supported proposal
  - Status: consistent, questionable, missing, or conflicting
  - Confidence: high, medium, or low
  - Source: supporting source or Not verified
- EC / EINECS number
  - User-entered value: value or Not provided
  - Proposed value: value or No supported proposal
  - Status: consistent, questionable, missing, or conflicting
  - Confidence: high, medium, or low
  - Source: supporting source or Not verified

Functions
- Verified COSING functions: label (backing_key) with official reference, or None verified
- Additional suggested functions: label (backing_key) with a short reason, or None

Specialist review
- Soap saponification: Relevant or Not relevant, with a short reason
- Aromatic allergen / IFRA: Relevant or Not relevant, with a short reason

Professional notes
Add one short comment only when a useful caution, unresolved ambiguity, material variant, blend limitation, or supplier-document check remains. Otherwise omit this section.
PROMPT;
```

- [ ] **Step 4: Run the prompt tests and verify GREEN**

Run:

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter='classification prompt'
```

Expected: 3 tests pass, including prompt generation from the latest identity and the blank-identity guard.

- [ ] **Step 5: Format and commit the prompt contract**

Run:

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Dashboard/IngredientEditor.php tests/Feature/UserIngredientAuthoringTest.php
git commit -m "feat: return readable ingredient classification guidance"
```

Expected: Pint passes and the commit contains only the prompt implementation and its feature test.

### Task 2: Explain Identifier Review in the Helper Copy

**Files:**
- Modify: `tests/Feature/IngredientEditorLocalizationTest.php:23-51`
- Modify: `lang/en/ingredients.php:285-296`
- Modify: `database/seeders/data/interface-translations.json` at `editor.classification_prompt.description`

- [ ] **Step 1: Write the failing localization assertion**

Add this assertion after the classification-helper heading assertion in `uses the approved task-focused copy on the add ingredient page`:

```php
->assertSeeText('Generate a prompt for classification, identifier review, and concise professional notes. Enter an ingredient name or INCI first.')
```

- [ ] **Step 2: Run the localization test and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/IngredientEditorLocalizationTest.php --filter='uses the approved task-focused copy'
```

Expected: FAIL because the page still shows the former description.

- [ ] **Step 3: Update the English and translated helper descriptions**

Set `ingredients.editor.classification_prompt.description` in `lang/en/ingredients.php` to:

```php
'description' => 'Generate a prompt for classification, identifier review, and concise professional notes. Enter an ingredient name or INCI first.',
```

Set the corresponding catalogue translations to:

```json
{
  "de": "Erzeuge einen Prompt für Klassifizierung, Identitätsprüfung und kurze fachliche Hinweise. Gib zuerst den Namen der Zutat oder die INCI-Bezeichnung ein.",
  "es": "Genera un prompt para la clasificación, la revisión de identificadores y notas profesionales breves. Introduce primero el nombre del ingrediente o el INCI.",
  "fr": "Générez un prompt pour le classement, la vérification des identifiants et de brèves notes professionnelles. Saisissez d’abord le nom de l’ingrédient ou son INCI.",
  "it": "Genera un prompt per la classificazione, la verifica degli identificatori e brevi note professionali. Inserisci prima il nome dell’ingrediente o l’INCI.",
  "nl": "Genereer een prompt voor classificatie, controle van identificatiegegevens en korte professionele notities. Vul eerst de ingrediëntnaam of INCI in."
}
```

- [ ] **Step 4: Run localization and catalogue tests**

Run:

```bash
php artisan test --compact tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: all tests pass and the catalogue contains every supported locale.

- [ ] **Step 5: Validate JSON, format PHP, and commit translations**

Run:

```bash
php -r 'json_decode(file_get_contents("database/seeders/data/interface-translations.json"), true, 512, JSON_THROW_ON_ERROR); echo "valid\n";'
vendor/bin/pint --dirty --format agent
git add lang/en/ingredients.php database/seeders/data/interface-translations.json tests/Feature/IngredientEditorLocalizationTest.php
git commit -m "feat: explain ingredient identity review helper"
```

Expected: JSON validation prints `valid`, Pint passes, and the commit contains only helper copy, catalogue translations, and the localization assertion.

### Task 3: Final Verification

**Files:**
- Verify all files changed by Tasks 1 and 2

- [ ] **Step 1: Run the focused feature suites**

Run:

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: all focused tests pass with zero failures.

- [ ] **Step 2: Run formatting and repository checks**

Run:

```bash
vendor/bin/pint --dirty --format agent
git diff --check
```

Expected: Pint reports `passed` and `git diff --check` produces no output.

- [ ] **Step 3: Refresh the code graph**

Run:

```bash
graphify update .
```

Expected: Graphify completes its AST extraction and reports `Code graph updated`.

- [ ] **Step 4: Review the final scoped diff**

Run:

```bash
git show --stat --oneline HEAD~2..HEAD
git status --short
```

Expected: the two feature commits contain only the prompt contract, prompt tests, helper copy, translations, and localization test. Any unrelated pre-existing working-tree changes remain untouched.
