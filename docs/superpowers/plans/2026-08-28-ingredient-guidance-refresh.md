# Ingredient Guidance Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Separate ingredient guidance and localization from identity research so an Admin can rerun, review, edit, and apply guidance later without researching or modifying approved identity data, while keeping translated guidance visibly synchronized with canonical English.

**Architecture:** Keep deterministic identity research, supplemental guidance research, English guidance authoring, and localization as separate modules inside the enrichment pipeline. A guidance-only batch reuses a persisted evidence snapshot and has its own small result contract and apply path; it never carries identity fields. English remains the canonical localization source, and each translation stores the canonical-source fingerprint it was based on so outdated text can be detected without deleting manual edits.

**Tech Stack:** PHP 8.5, Laravel 13.26, Filament 5.7, queued Laravel batches, OpenAI Responses API with strict JSON schemas, Pest 4.7, PostgreSQL/SQLite-compatible migrations, and spatie/laravel-translation-loader 2.8.

---

## Product decisions and invariants

- Full enrichment keeps its current source hierarchy and human approval requirements.
- `Rerun guidance` performs no web search. It reuses the approved identity snapshot, trusted soap chemistry, COSING functions, and persisted guidance evidence.
- `Refresh evidence and guidance` remains the existing full/gap-research workflow and is a separate explicit action.
- Guidance-only review and apply never propose or write INCI, identifiers, taxonomy, COSING functions, declarations, aliases, or soap INCI names.
- English is the canonical source for localized ingredient guidance.
- Editing English preserves every localized value but makes translations whose source fingerprint no longer matches visibly outdated.
- Editing French changes only French. It records a reviewer-authored current translation and does not touch other locales.
- Regenerating translations creates a reviewable batch. It never silently overwrites manual translations.
- Guidance evidence is persisted with source name, exact URL, concise supported summary, source tier, and retrieval date. Guidance prompt revisions can therefore run without another web search.
- Existing applied enrichment batches are the fallback evidence source for legacy ingredients that predate the persisted guidance snapshot.
- Guidance omits the INCI because the UI already shows it. Target 80–160 words rather than the current 140–280 words, and remove generic oil-phase, emulsification, storage, and whole-recipe boilerplate unless it changes an ingredient-specific decision.
- A numeric `Typical use level` is allowed only when a future structured, approved usage fact explicitly identifies the range as formulation guidance for the exact material. This plan does not reinterpret CIR reported-use concentrations, scientific formula examples, or supplier marketing as recommended limits.
- Guidance remains non-authoritative formulation guidance, not a safety assessment, regulatory limit, or performance guarantee.

## File structure

### New modules

- `app/Contracts/IngredientGuidanceAuthoringClient.php` — English-guidance interface.
- `app/Contracts/IngredientGuidanceLocalizationClient.php` — localized-guidance interface.
- `app/Data/IngredientGuidanceAuthoringResponse.php` — English response and provider accounting.
- `app/Data/IngredientGuidanceLocalizationResponse.php` — localized response and provider accounting.
- `app/Enums/IngredientEnrichmentBatchMode.php` — full, intake, guidance refresh, and localization refresh modes.
- `app/Enums/IngredientTranslationOrigin.php` — legacy, AI-generated, and reviewer-edited provenance.
- `app/Services/IngredientEnrichment/IngredientGuidancePrompt.php` — concise English guidance policy.
- `app/Services/IngredientEnrichment/IngredientGuidanceSchema.php` — strict English output schema.
- `app/Services/IngredientEnrichment/OpenAiIngredientGuidanceClient.php` — no-web authoring adapter.
- `app/Services/IngredientEnrichment/IngredientGuidanceLocalizationPrompt.php` — native-editor localization policy.
- `app/Services/IngredientEnrichment/IngredientGuidanceLocalizationSchema.php` — locale-bounded strict schema.
- `app/Services/IngredientEnrichment/OpenAiIngredientGuidanceLocalizationClient.php` — no-web localization adapter.
- `app/Services/IngredientEnrichment/IngredientGuidanceContextBuilder.php` — current facts plus reusable evidence snapshot.
- `app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php` — guidance-only queued workflow.
- `app/Services/IngredientEnrichment/IngredientGuidanceRefreshResultValidator.php` — small mode-specific result validator.
- `app/Services/IngredientEnrichment/ApplyIngredientGuidanceRefresh.php` — stale-safe guidance-only writer.
- `app/Services/IngredientTranslationSourceFingerprint.php` — hashes canonical translatable fields.
- `app/Jobs/GenerateIngredientGuidanceRefresh.php` — queue wrapper for guidance/localization refresh items.
- `app/Actions/IngredientEnrichment/StartIngredientGuidanceRefresh.php` — Admin entry point.
- `app/Actions/IngredientEnrichment/EditIngredientGuidanceProposal.php` — edits only reviewable guidance fields.
- `app/Actions/IngredientEnrichment/ApproveIngredientGuidanceProposal.php` — approves only valid guidance results.
- `app/Actions/IngredientEnrichment/ApplyApprovedIngredientGuidanceRefresh.php` — applies approved guidance batches.
- `app/Filament/Resources/IngredientEnrichmentBatches/Schemas/IngredientGuidanceProposalForm.php` — focused review editor.

### Existing modules to change

- `IngredientEnrichmentEditorialPrompt` and `IngredientEnrichmentEditorialSchema` retain naming, taxonomy fallback, soap relevance, and localized names, but stop writing guidance.
- `IngredientEnrichmentPipeline` invokes metadata editorial, English guidance, then guidance localization as separate persisted stages.
- `IngredientTranslationService` preserves unchanged provenance and exposes freshness without coupling persistence to Filament.
- Existing enrichment batches remain the review aggregate; `mode` selects the correct result contract, editor, approval action, and apply action.

---

## Execution preparation

- [ ] **Step 1: Create an isolated worktree for implementation**

Use the `superpowers:using-git-worktrees` skill before implementation. Start from the user-selected current branch state; do not invent a base branch or discard the current `main` history.

- [ ] **Step 2: Read repository rules and search versioned documentation**

```bash
sed -n '1,260p' .ai/rules/index.md
grep -rin 'ingredient\|enrichment\|guidance\|translation\|prompt\|stale\|source' .ai/rules
```

Use Laravel Boost `search-docs` before code changes for Laravel queued batches, JSON casts, reversible migrations, Filament conditional action schemas, and Pest/Livewire action testing.

- [ ] **Step 3: Establish the focused green baseline**

```bash
php artisan test --compact \
  tests/Feature/IngredientEditorialClientTest.php \
  tests/Feature/HybridIngredientEnrichmentPipelineTest.php \
  tests/Feature/IngredientEnrichmentPipelineTest.php \
  tests/Feature/IngredientEnrichmentBatchReviewTest.php \
  tests/Feature/IngredientEnrichmentProposalEditingTest.php \
  tests/Feature/IngredientTranslationTest.php
```

Expected: PASS before the first RED assertion.

---

### Task 1: Split metadata editorial, English guidance, and localization

**Files:**

- Create: `app/Contracts/IngredientGuidanceAuthoringClient.php`
- Create: `app/Contracts/IngredientGuidanceLocalizationClient.php`
- Create: `app/Data/IngredientGuidanceAuthoringResponse.php`
- Create: `app/Data/IngredientGuidanceLocalizationResponse.php`
- Create: `app/Services/IngredientEnrichment/IngredientGuidancePrompt.php`
- Create: `app/Services/IngredientEnrichment/IngredientGuidanceSchema.php`
- Create: `app/Services/IngredientEnrichment/OpenAiIngredientGuidanceClient.php`
- Create: `app/Services/IngredientEnrichment/IngredientGuidanceLocalizationPrompt.php`
- Create: `app/Services/IngredientEnrichment/IngredientGuidanceLocalizationSchema.php`
- Create: `app/Services/IngredientEnrichment/OpenAiIngredientGuidanceLocalizationClient.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentEditorialPrompt.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentEditorialSchema.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `config/ingredient-enrichment.php`
- Test: `tests/Feature/IngredientEditorialClientTest.php`
- Create test: `tests/Feature/IngredientGuidanceClientTest.php`
- Create test: `tests/Feature/IngredientGuidanceLocalizationClientTest.php`

- [ ] **Step 1: Write failing contract and prompt tests**

Assert the metadata editorial schema no longer contains `info_markdown` and its translation rows contain only locale, display name, and optional saponification name. Assert the English guidance request has no web tools and returns only:

```php
[
    'info_markdown' => "## Overview\n\n...\n\n## Formulation use\n\n...",
    'warnings' => [],
    'unresolved_questions' => [],
]
```

Assert the localization request accepts approved English and returns only:

```php
[
    'translations' => [
        ['locale' => 'fr', 'info_markdown' => "## Vue d’ensemble\n\n..."],
        ['locale' => 'de', 'info_markdown' => "## Überblick\n\n..."],
    ],
]
```

The prompt test must prove it contains all of these policies:

```php
expect($prompt['instructions'])
    ->toContain('Do not repeat the INCI')
    ->toContain('material-specific formulation decision')
    ->toContain('80 to 160 words')
    ->toContain('Typical use level')
    ->toContain('approved structured usage fact')
    ->toContain('not a regulatory or safety limit')
    ->toContain('omit generic filler');
```

- [ ] **Step 2: Run the focused tests and verify RED**

```bash
php artisan test --compact \
  tests/Feature/IngredientEditorialClientTest.php \
  tests/Feature/IngredientGuidanceClientTest.php \
  tests/Feature/IngredientGuidanceLocalizationClientTest.php
```

Expected: FAIL because the guidance interfaces and adapters do not exist and metadata editorial still returns guidance.

- [ ] **Step 3: Add the two narrow interfaces and responses**

```php
interface IngredientGuidanceAuthoringClient
{
    /** @param array<string, mixed> $context */
    public function author(array $context): IngredientGuidanceAuthoringResponse;
}

interface IngredientGuidanceLocalizationClient
{
    /** @param array<string, mixed> $context */
    public function localize(array $context): IngredientGuidanceLocalizationResponse;
}
```

Each readonly response contains its strict payload plus `responseId`, `requestId`, `model`, `inputTokens`, and `outputTokens`. Neither response exposes `webSearchCalls`; these modules must never search.

- [ ] **Step 4: Implement strict prompts, schemas, and OpenAI adapters**

Set configuration to:

```php
'guidance' => [
    'minimum_words' => 80,
    'maximum_words' => 160,
    // retain headings and localized_headings
],
'openai' => [
    // retain current settings
    'editorial_prompt_version' => 'ingredient-enrichment-metadata-v1',
    'guidance_prompt_version' => 'ingredient-guidance-v1',
    'guidance_localization_prompt_version' => 'ingredient-guidance-localization-v1',
],
```

Implement both adapters with the existing timeout, retry, strict JSON schema, and `store => false` conventions from `OpenAiIngredientEditorialClient`. Do not include `tools` or `include`.

- [ ] **Step 5: Bind both interfaces and verify GREEN**

```php
$this->app->bind(IngredientGuidanceAuthoringClient::class, OpenAiIngredientGuidanceClient::class);
$this->app->bind(IngredientGuidanceLocalizationClient::class, OpenAiIngredientGuidanceLocalizationClient::class);
```

Run the focused tests, then commit:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/IngredientEditorialClientTest.php tests/Feature/IngredientGuidanceClientTest.php tests/Feature/IngredientGuidanceLocalizationClientTest.php
git add app/Contracts app/Data app/Services/IngredientEnrichment app/Providers/AppServiceProvider.php config/ingredient-enrichment.php tests/Feature/IngredientEditorialClientTest.php tests/Feature/IngredientGuidanceClientTest.php tests/Feature/IngredientGuidanceLocalizationClientTest.php
git commit -m "refactor: separate ingredient guidance authoring"
```

---

### Task 2: Persist separate pipeline stages and reusable guidance evidence

**Files:**

- Modify: `app/Enums/IngredientEnrichmentResearchStage.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentPipeline.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentResultSchema.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php`
- Modify: `app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php`
- Modify: `config/ingredient-enrichment.php`
- Test: `tests/Feature/IngredientEnrichmentVocabularyTest.php`
- Test: `tests/Feature/HybridIngredientEnrichmentPipelineTest.php`
- Test: `tests/Feature/IngredientEnrichmentValidationTest.php`
- Test: `tests/Feature/PlatformIngredientEnrichmentImportTest.php`

- [ ] **Step 1: Write failing stage-order and evidence-persistence tests**

Require this order while retaining the legacy `AiEditorial` value for stored batches:

```php
IngredientEnrichmentResearchStage::AiGuidanceResearch,
IngredientEnrichmentResearchStage::AiEditorial,
IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
IngredientEnrichmentResearchStage::AiGuidanceLocalization,
IngredientEnrichmentResearchStage::Validation,
```

Assert an applied ingredient stores:

```php
data_get($ingredient->source_data, 'enrichment.guidance') === [
    'evidence' => [[
        'source_name' => 'COSMILE Europe',
        'source_url' => 'https://cosmileeurope.eu/inci/detail/1152/argania-spinosa-kernel-oil/',
        'summary' => 'A supported practical formulation fact.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-28T00:00:00+00:00',
    ]],
    'guidance_prompt_version' => 'ingredient-guidance-v1',
    'localization_prompt_version' => 'ingredient-guidance-localization-v1',
    'approved_at' => '2026-08-28T00:00:00+00:00',
];
```

- [ ] **Step 2: Run tests and verify RED**

```bash
php artisan test --compact \
  tests/Feature/IngredientEnrichmentVocabularyTest.php \
  tests/Feature/HybridIngredientEnrichmentPipelineTest.php \
  tests/Feature/IngredientEnrichmentValidationTest.php \
  tests/Feature/PlatformIngredientEnrichmentImportTest.php
```

- [ ] **Step 3: Split pipeline execution and merge localized output deterministically**

After metadata editorial, call English authoring with deterministic facts plus `AiGuidanceResearch` candidate evidence. Then call localization with the approved English proposal and metadata-localized names. Merge by locale in PHP:

```php
$localizedGuidance = collect($guidanceLocalization['translations'] ?? [])->keyBy('locale');
$translations = collect($metadata['translations'] ?? [])->map(function (array $translation) use ($localizedGuidance): array {
    $guidance = $localizedGuidance->get($translation['locale'], []);

    return [
        ...$translation,
        'info_markdown' => $guidance['info_markdown'] ?? '',
    ];
})->values()->all();
```

Normalize localized headings after this merge. Persist each provider response inside its corresponding research stage so retrying authoring does not repeat guidance research or metadata editorial.

- [ ] **Step 4: Add a versioned top-level guidance evidence envelope**

Bump the enrichment result schema version and add:

```php
'guidance_evidence' => $this->array($this->object([
    'source_name' => $this->string(),
    'source_url' => $this->string(),
    'summary' => $this->string(),
    'source_tier' => $this->string(enum: ['editorial']),
    'retrieved_at' => $this->string(format: 'date-time'),
])),
```

Populate it from the already-performed guidance research; do not search again. Keep the ordinary `evidence` rows for correlated review citations.

- [ ] **Step 5: Persist the approved envelope on apply**

In `ApplyPlatformIngredientEnrichment`, write `source_data.enrichment.guidance` only after validation and approval. Preserve the previous envelope when an unrelated enrichment result contains no replacement guidance evidence.

- [ ] **Step 6: Verify, format, and commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/IngredientEnrichmentVocabularyTest.php tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/IngredientEnrichmentValidationTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php
git add app/Enums/IngredientEnrichmentResearchStage.php app/Services/IngredientEnrichment config/ingredient-enrichment.php tests/Feature/IngredientEnrichmentVocabularyTest.php tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/IngredientEnrichmentValidationTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php
git commit -m "feat: persist ingredient guidance evidence"
```

---

### Task 3: Track translation origin and canonical-source freshness

**Files:**

- Create: `database/migrations/2026_08_28_090000_track_ingredient_translation_freshness.php`
- Create: `app/Enums/IngredientTranslationOrigin.php`
- Create: `app/Services/IngredientTranslationSourceFingerprint.php`
- Modify: `app/Models/IngredientTranslation.php`
- Modify: `app/Services/IngredientTranslationService.php`
- Modify: `app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php`
- Modify: `database/factories/IngredientTranslationFactory.php`
- Test: `tests/Feature/IngredientTranslationTest.php`

- [ ] **Step 1: Generate the migration and write failing freshness tests**

```bash
php artisan make:migration track_ingredient_translation_freshness --table=ingredient_translations --no-interaction
```

Test these cases:

1. Existing rows are backfilled against current canonical English.
2. Editing only English leaves localized text untouched and makes every prior translation outdated.
3. Editing only French marks French `reviewer_edited` and current; German remains unchanged.
4. Editing English and French in one save makes French current and preserves German as outdated.
5. An AI enrichment apply marks generated rows `ai_generated` with the guidance-localization prompt version.

- [ ] **Step 2: Add reversible columns and backfill**

```php
Schema::table('ingredient_translations', function (Blueprint $table): void {
    $table->string('source_fingerprint', 64)->nullable()->after('info_markdown');
    $table->string('origin', 32)->default('legacy')->after('source_fingerprint');
    $table->string('prompt_version', 100)->nullable()->after('origin');
});
```

Backfill in deterministic `ingredient_id` chunks using the same PHP fingerprint implementation as the new module. `down()` drops the three columns. Do not store a mutable `is_stale` boolean; freshness is derived by comparing fingerprints.

- [ ] **Step 3: Implement the fingerprint and origin enum**

```php
public function forIngredient(Ingredient $ingredient): string
{
    return hash('sha256', json_encode([
        'display_name' => $this->normalize($ingredient->display_name),
        'saponification_name' => $this->normalize($ingredient->saponification_name),
        'info_markdown' => $this->normalize($ingredient->info_markdown),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
```

Enum cases are `Legacy`, `AiGenerated`, and `ReviewerEdited`, backed by `legacy`, `ai_generated`, and `reviewer_edited`.

- [ ] **Step 4: Preserve metadata for unchanged localized rows**

Extend `IngredientTranslationService::sync()` with an origin and optional prompt version. Compare normalized submitted localized fields with the stored row:

- unchanged row: preserve origin, prompt version, and source fingerprint;
- manually changed row: set `ReviewerEdited`, clear prompt version, and store the current canonical fingerprint;
- AI-changed/new row: set `AiGenerated`, store the localization prompt version and current canonical fingerprint.

Return freshness in `formData()` as a derived presentation field, but strip it in `validateRows()` so it is never trusted as submitted persistence state.

Pass `IngredientTranslationOrigin::AiGenerated` and the configured localization prompt version from `ApplyPlatformIngredientEnrichment`; the ingredient Admin editor keeps the reviewer default.

- [ ] **Step 5: Verify and commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/IngredientTranslationTest.php
git add app/Enums/IngredientTranslationOrigin.php app/Models/IngredientTranslation.php app/Services/IngredientTranslationService.php app/Services/IngredientTranslationSourceFingerprint.php app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php database/factories/IngredientTranslationFactory.php database/migrations/2026_08_28_090000_track_ingredient_translation_freshness.php tests/Feature/IngredientTranslationTest.php
git commit -m "feat: track ingredient translation freshness"
```

---

### Task 4: Build the reusable guidance context and legacy evidence fallback

**Files:**

- Create: `app/Services/IngredientEnrichment/IngredientGuidanceContextBuilder.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentSnapshotBuilder.php`
- Create test: `tests/Feature/IngredientGuidanceContextBuilderTest.php`

- [ ] **Step 1: Write failing context tests**

Assert the builder:

- takes canonical identity, COSING functions, and trusted soap chemistry from the current approved ingredient;
- reads `source_data.enrichment.guidance.evidence` first;
- falls back to the newest applied batch item's `research_stages.ai_guidance_research.data.candidate_evidence`;
- never calls an HTTP client;
- returns empty supplemental evidence with a concrete warning when neither source exists.

- [ ] **Step 2: Implement one deep interface**

```php
/** @return array<string, mixed> */
public function build(Ingredient $ingredient): array
{
    $snapshot = $this->snapshots->snapshot($ingredient);
    $evidence = $this->persistedEvidence($ingredient)
        ?? $this->legacyBatchEvidence($ingredient)
        ?? [];

    return [
        'subject_public_id' => (string) $ingredient->public_id,
        'source_fingerprint' => $this->snapshots->fingerprint($ingredient),
        'current' => $snapshot,
        'guidance_evidence' => $evidence,
        'requested_output' => ['guidance' => config('ingredient-enrichment.guidance')],
    ];
}
```

Keep legacy batch querying inside this module so callers do not learn the storage fallback.

- [ ] **Step 3: Verify and commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/IngredientGuidanceContextBuilderTest.php
git add app/Services/IngredientEnrichment/IngredientGuidanceContextBuilder.php app/Services/IngredientEnrichment/IngredientEnrichmentSnapshotBuilder.php tests/Feature/IngredientGuidanceContextBuilderTest.php
git commit -m "feat: reuse approved ingredient guidance context"
```

---

### Task 5: Add guidance-only and localization-only queued batches

**Files:**

- Create: `app/Enums/IngredientEnrichmentBatchMode.php`
- Create: `app/Jobs/GenerateIngredientGuidanceRefresh.php`
- Create: `app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php`
- Create: `app/Services/IngredientEnrichment/IngredientGuidanceRefreshResultValidator.php`
- Create: `app/Actions/IngredientEnrichment/StartIngredientGuidanceRefresh.php`
- Modify: `app/Models/IngredientEnrichmentBatch.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php`
- Modify: `database/factories/IngredientEnrichmentBatchFactory.php`
- Modify: `database/factories/IngredientEnrichmentBatchItemFactory.php`
- Create test: `tests/Feature/IngredientGuidanceRefreshJobTest.php`
- Modify test: `tests/Feature/StartIngredientEnrichmentBatchTest.php`

- [ ] **Step 1: Write failing batch and processor tests**

Test that `GuidanceRefresh` calls authoring and localization but no research client, while `GuidanceLocalization` calls only localization. Both modes must snapshot the ingredient, queue one job per ingredient, accumulate tokens, and become stale before provider calls when the current fingerprint changed.

The mode-specific result contract is:

```php
[
    'format' => 'soapkraft-ingredient-guidance-refresh-result',
    'schema_version' => 1,
    'mode' => 'guidance_refresh',
    'subject_public_id' => (string) $ingredient->public_id,
    'source_fingerprint' => $snapshotFingerprint,
    'info_markdown' => $newEnglishGuidance,
    'translations' => [
        ['locale' => 'fr', 'info_markdown' => $newFrenchGuidance],
    ],
    'guidance_evidence' => $reusedEvidence,
    'prompt_versions' => [
        'guidance' => 'ingredient-guidance-v1',
        'localization' => 'ingredient-guidance-localization-v1',
    ],
    'warnings' => [],
    'unresolved_questions' => [],
];
```

- [ ] **Step 2: Implement enum-backed batch modes**

Use `FillMissing`, `Intake`, `GuidanceRefresh`, and `GuidanceLocalization` cases with existing stored values plus `guidance_refresh` and `guidance_localization`. Cast `IngredientEnrichmentBatch::mode` to the enum without changing the database column.

- [ ] **Step 3: Add batch creation and dispatch**

Add `startGuidanceRefresh(User $actor, Collection $ingredients, bool $localizationOnly = false)` to `IngredientEnrichmentBatchService`. Use the same platform-only locking, maximum-size validation, Laravel batch dispatch, and queue configuration as full enrichment, but build snapshots through `IngredientGuidanceContextBuilder` and enqueue `GenerateIngredientGuidanceRefresh`.

- [ ] **Step 4: Implement the processor and strict validator**

The processor must:

1. lock and recheck the item/ingredient fingerprint;
2. call authoring only for `GuidanceRefresh`;
3. call localization for all configured locales or only stale locales for `GuidanceLocalization`;
4. normalize localized headings;
5. validate the small result contract;
6. create plan decisions only for `proposal.info_markdown` and `proposal.translations.{locale}.info_markdown`;
7. set the standard ready/warning/failed status and provider accounting.

- [ ] **Step 5: Verify and commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/IngredientGuidanceRefreshJobTest.php tests/Feature/StartIngredientEnrichmentBatchTest.php
git add app/Enums/IngredientEnrichmentBatchMode.php app/Jobs/GenerateIngredientGuidanceRefresh.php app/Models/IngredientEnrichmentBatch.php app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php app/Services/IngredientEnrichment/IngredientGuidanceRefreshResultValidator.php app/Actions/IngredientEnrichment/StartIngredientGuidanceRefresh.php database/factories/IngredientEnrichmentBatchFactory.php database/factories/IngredientEnrichmentBatchItemFactory.php tests/Feature/IngredientGuidanceRefreshJobTest.php tests/Feature/StartIngredientEnrichmentBatchTest.php
git commit -m "feat: queue ingredient guidance refreshes"
```

---

### Task 6: Review and apply guidance without touching identity

**Files:**

- Create: `app/Actions/IngredientEnrichment/EditIngredientGuidanceProposal.php`
- Create: `app/Actions/IngredientEnrichment/ApproveIngredientGuidanceProposal.php`
- Create: `app/Actions/IngredientEnrichment/ApplyApprovedIngredientGuidanceRefresh.php`
- Create: `app/Services/IngredientEnrichment/ApplyIngredientGuidanceRefresh.php`
- Modify: `app/Actions/IngredientEnrichment/ApplyApprovedIngredientEnrichment.php`
- Modify: `app/Services/IngredientTranslationService.php`
- Create test: `tests/Feature/IngredientGuidanceBatchReviewTest.php`
- Modify test: `tests/Feature/IngredientEnrichmentBatchReviewTest.php`

- [ ] **Step 1: Write failing edit, approval, stale-apply, and isolation tests**

Prove that a reviewer can edit English or French guidance, that an item changed after generation becomes stale, and that apply changes only:

```php
[
    'ingredients.info_markdown',
    'ingredient_translations.info_markdown',
    'ingredient_translations.source_fingerprint',
    'ingredient_translations.origin',
    'ingredient_translations.prompt_version',
    'ingredients.source_data->enrichment->guidance',
]
```

Snapshot and assert INCI, taxonomy, identifiers, functions, aliases, declarations, soap names, translated display names, and translated saponification names are byte-for-byte unchanged.

- [ ] **Step 2: Implement the focused actions**

Each action uses the batch policy, `lockForUpdate()`, a five-attempt transaction, the mode-specific validator, and existing item statuses. `EditIngredientGuidanceProposal` accepts only:

```php
[
    'info_markdown' => 'string',
    'translations' => [
        ['locale' => 'string', 'info_markdown' => 'string'],
    ],
]
```

Reject extra identity keys rather than silently ignoring them.

- [ ] **Step 3: Implement the stale-safe writer**

`ApplyIngredientGuidanceRefresh` locks the platform ingredient, verifies `source_fingerprint`, writes English only for `GuidanceRefresh`, updates only localized `info_markdown`, and delegates translation metadata to `IngredientTranslationService`. Persist the reused evidence and prompt versions under `source_data.enrichment.guidance` after the text writes succeed.

- [ ] **Step 4: Delegate aggregate apply by batch mode**

At the start of `ApplyApprovedIngredientEnrichment::handle()`:

```php
if ($batch->mode->isGuidance()) {
    return $this->guidanceRefresh->handle($actor, $batch);
}
```

Keep the current full-enrichment and intake code unchanged after that seam.

- [ ] **Step 5: Verify and commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/IngredientGuidanceBatchReviewTest.php tests/Feature/IngredientEnrichmentBatchReviewTest.php
git add app/Actions/IngredientEnrichment app/Services/IngredientEnrichment/ApplyIngredientGuidanceRefresh.php app/Services/IngredientTranslationService.php tests/Feature/IngredientGuidanceBatchReviewTest.php tests/Feature/IngredientEnrichmentBatchReviewTest.php
git commit -m "feat: review and apply guidance-only changes"
```

---

### Task 7: Add focused Filament actions and translation freshness UX

**Files:**

- Create: `app/Filament/Resources/IngredientEnrichmentBatches/Schemas/IngredientGuidanceProposalForm.php`
- Modify: `app/Filament/Resources/IngredientEnrichmentBatches/RelationManagers/ItemsRelationManager.php`
- Modify: `app/Filament/Resources/Ingredients/Pages/EditIngredient.php`
- Modify: `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- Modify: `app/Filament/Resources/Ingredients/Tables/IngredientsTable.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentReviewPresenter.php`
- Modify: `lang/en/ingredient_admin.php`
- Modify: `lang/en/ingredient_enrichment_admin.php`
- Create test: `tests/Feature/Filament/IngredientGuidanceRefreshActionTest.php`
- Modify test: `tests/Feature/IngredientTranslationTest.php`

- [ ] **Step 1: Write failing Filament tests**

Authenticate with `$this->actingAs(User::factory()->create(['is_admin' => true]))`. Assert:

- the ingredient table exposes `Rerun guidance` as a selected-record bulk action;
- the ingredient edit page exposes `Regenerate outdated translations` only when stale locales exist;
- starting either action redirects to the created review batch;
- guidance batches show only English/localized guidance editors;
- full enrichment batches retain the existing complete proposal editor;
- review rows for guidance batches exclude identity fields;
- translation rows show Current, Outdated, AI-generated, or Manually edited state without deleting text.

- [ ] **Step 2: Add the focused proposal schema**

```php
return [
    MarkdownEditor::make('info_markdown')
        ->label(__('ingredient_enrichment_admin.review.labels.info_markdown'))
        ->required()
        ->columnSpanFull(),
    Repeater::make('translations')->schema([
        Select::make('locale')->options($localeOptions)->disabled()->dehydrated(),
        MarkdownEditor::make('info_markdown')->required()->columnSpanFull(),
    ])->reorderable(false)->columnSpanFull(),
];
```

Use the existing complete schema only for full/intake modes.

- [ ] **Step 3: Route edit and approve actions by mode**

In `ItemsRelationManager`, select the schema and action from `$record->batch->mode`. Guidance approval needs no replacement-field checklist because its contract is explicitly replaceable guidance; it still requires confirmation and human approval.

- [ ] **Step 4: Add rerun and stale-translation entry points**

Add `Rerun guidance` beside the existing full-enrichment bulk action. On `EditIngredient`, show `Regenerate outdated translations` with stale locales in the modal description and start `GuidanceLocalization` mode for that ingredient.

In the Translations tab, render freshness from the server-derived form state. Do not expose origin, prompt version, or source fingerprint as editable inputs.

- [ ] **Step 5: Run Filament checks, tests, and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
php artisan test --compact tests/Feature/Filament/IngredientGuidanceRefreshActionTest.php tests/Feature/IngredientTranslationTest.php tests/Feature/IngredientEnrichmentBatchReviewTest.php
git add app/Filament app/Services/IngredientEnrichment/IngredientEnrichmentReviewPresenter.php lang/en/ingredient_admin.php lang/en/ingredient_enrichment_admin.php tests/Feature/Filament/IngredientGuidanceRefreshActionTest.php tests/Feature/IngredientTranslationTest.php tests/Feature/IngredientEnrichmentBatchReviewTest.php
git commit -m "feat: add guidance refresh review workflow"
```

Expected: tests pass and FilaCheck reports no unresolved Filament issues.

---

### Task 8: Regression verification and durable project rule

**Files:**

- Modify through Laravel Boost `record-rule`: `.ai/rules/ingredient-enrichment.md`
- Verify: all files changed by Tasks 1–7

- [ ] **Step 1: Record the settled architecture**

Use Laravel Boost `record-rule` with glob `app/Services/IngredientEnrichment/**` and a concise note stating:

```text
Guidance research, English guidance authoring, and localized guidance are separate persisted stages. Guidance-only reruns reuse approved evidence and must never include or apply identity fields. English changes preserve localized text but make translations stale through canonical-source fingerprints; localized manual edits affect only that locale.
```

- [ ] **Step 2: Run the complete focused suite**

```bash
php artisan test --compact \
  tests/Feature/IngredientEditorialClientTest.php \
  tests/Feature/IngredientGuidanceClientTest.php \
  tests/Feature/IngredientGuidanceLocalizationClientTest.php \
  tests/Feature/IngredientGuidanceContextBuilderTest.php \
  tests/Feature/IngredientGuidanceRefreshJobTest.php \
  tests/Feature/IngredientGuidanceBatchReviewTest.php \
  tests/Feature/Filament/IngredientGuidanceRefreshActionTest.php \
  tests/Feature/HybridIngredientEnrichmentPipelineTest.php \
  tests/Feature/IngredientEnrichmentPipelineTest.php \
  tests/Feature/IngredientEnrichmentBatchReviewTest.php \
  tests/Feature/IngredientEnrichmentProposalEditingTest.php \
  tests/Feature/IngredientEnrichmentValidationTest.php \
  tests/Feature/PlatformIngredientEnrichmentImportTest.php \
  tests/Feature/IngredientTranslationTest.php
```

Expected: PASS.

- [ ] **Step 3: Run mandatory formatting and static checks**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
git diff --check
```

Expected: no formatting errors, deprecations, or whitespace failures.

- [ ] **Step 4: Refresh the knowledge graph and inspect the final diff**

```bash
graphify update .
git status --short
git diff --stat
git diff --check
```

Confirm that the diff contains no dependency changes and no unrelated modifications.

- [ ] **Step 5: Commit final verification adjustments**

```bash
git add .ai/rules/ingredient-enrichment.md graphify-out app config database lang tests
git commit -m "test: verify ingredient guidance refresh workflow"
```

---

## Acceptance walkthrough

1. Select an already approved platform ingredient and run `Rerun guidance`.
2. Confirm the batch performs zero web searches and proposes only English and localized guidance.
3. Review old versus proposed English/French text, edit French, approve, and apply.
4. Confirm identity, taxonomy, identifiers, functions, declarations, and localized names are unchanged.
5. Edit canonical English guidance directly in the ingredient editor.
6. Confirm existing translations remain visible but become Outdated.
7. Edit only French and save; confirm French becomes current/manual and other locales are untouched.
8. Run `Regenerate outdated translations`, approve selected locale proposals, and apply.
9. Confirm translation prompt/source fingerprints update only after approval.
10. Inspect the ingredient's guidance provenance and verify the reused exact source URLs, retrieval date, and prompt versions are retained.

## Explicitly deferred

- Curating supplier/manufacturer technical-data domains for recommended usage ranges.
- A structured database field for cosmetic or soapmaking use percentages.
- Automatic evidence refresh based solely on age.
- Machine-updating translations immediately after an English edit without review.
- Translating from a manually edited non-English locale into other locales.
