# Ingredient Guidance Refresh Corrections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Correct translation freshness, reviewer provenance, evidence approval, retry resumption, and batch completion in the ingredient-guidance workflow, then remove the standards violations and duplication identified in review.

**Architecture:** Introduce a single guidance change planner, explicit per-locale translation write intents, a service-owned proposal-review workflow, and mode-aware resumable guidance stages. Correctness changes land first; shared OpenAI transport and collection/localization cleanup follow only after behavioral tests are green.

**Tech Stack:** PHP 8.5, Laravel 13.26, Filament 5.7, Laravel queued batches, OpenAI Responses API, Pest 4.7.

---

## Design and boundaries

Implement `docs/superpowers/specs/2026-08-28-ingredient-guidance-refresh-corrections-design.md`. Do not add a migration or dependency. Do not change identity, taxonomy, identifiers, declarations, aliases, soap INCI naming, source policy, or use-level fields.

## New files

- `app/Data/IngredientTranslationWriteIntent.php`
- `app/Data/OpenAiStructuredOutputResponse.php`
- `app/Services/IngredientEnrichment/IngredientGuidanceChangePlanner.php`
- `app/Services/IngredientEnrichment/IngredientGuidanceProposalReviewService.php`
- `app/Services/IngredientEnrichment/IngredientGuidanceStageRunner.php`
- `app/Services/IngredientEnrichment/OpenAiStructuredOutputTransport.php`
- `tests/Feature/IngredientGuidanceChangePlannerTest.php`
- `tests/Feature/IngredientGuidanceProposalReviewServiceTest.php`

---

### Task 1: Make guidance and evidence metadata reviewable

**Files:**

- Create: `app/Services/IngredientEnrichment/IngredientGuidanceChangePlanner.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentPlanner.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentReviewPresenter.php`
- Create: `tests/Feature/IngredientGuidanceChangePlannerTest.php`
- Modify: `tests/Feature/PlatformIngredientEnrichmentImportTest.php`

- [ ] **Step 1: Write failing guidance-plan tests**

Create the test file with `uses(RefreshDatabase::class);`. Cover text replacement, identical stale localization, identical current localization, and evidence-only differences.

```php
it('creates a revalidate decision for identical stale guidance', function (): void {
    $ingredient = Ingredient::factory()->create(['info_markdown' => guidanceText('English')]);
    IngredientTranslation::factory()->for($ingredient)->create([
        'locale' => 'fr',
        'info_markdown' => localizedGuidanceText('French'),
        'source_fingerprint' => str_repeat('0', 64),
    ]);

    $plan = app(IngredientGuidanceChangePlanner::class)->plan(
        $ingredient,
        guidanceRefreshResult($ingredient, [[
            'locale' => 'fr',
            'info_markdown' => localizedGuidanceText('French'),
        ]]),
        IngredientEnrichmentBatchMode::GuidanceLocalization,
    );

    expect($plan['changed'])->toBeTrue()
        ->and(collect($plan['decisions'])
            ->firstWhere('field', 'proposal.translations.fr.info_markdown')['decision'])
        ->toBe('revalidate');
});
```

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/IngredientGuidanceChangePlannerTest.php
```

Expected: FAIL because the planner does not exist.

- [ ] **Step 3: Implement the shared planner**

```php
/**
 * @param array<string, mixed> $result
 * @param list<string> $editedFields
 * @return array<string, mixed>
 */
public function plan(
    Ingredient $ingredient,
    array $result,
    IngredientEnrichmentBatchMode $mode,
    array $editedFields = [],
): array
```

Use collection pipelines to compare English guidance and translations. A text-equal locale receives `revalidate` only when the mode is `GuidanceLocalization` and its stored fingerprint differs from `IngredientTranslationSourceFingerprint::forIngredient($ingredient)`. Compare normalized result evidence with `source_data.enrichment.guidance.evidence`.

```php
'changed' => collect($decisions)->contains(
    fn (array $decision): bool => in_array(
        $decision['decision'],
        ['new', 'replace', 'revalidate'],
        true,
    ),
),
```

- [ ] **Step 4: Make full-enrichment evidence differences reviewable**

Append this decision inside `IngredientEnrichmentPlanner::plan()` before calculating `changed`:

```php
$proposed = collect($result['guidance_evidence'] ?? [])->values()->all();
$current = collect(data_get($ingredient->source_data, 'enrichment.guidance.evidence', []))->values()->all();

if ($proposed !== [] && $proposed !== $current) {
    $decisions[] = [
        'field' => 'guidance.evidence',
        'decision' => $current === [] ? 'new' : 'replace',
        'current' => $current,
        'proposed' => $proposed,
    ];
}
```

Add a translated review label for this path. Evidence remains read-only and human-review gated.

- [ ] **Step 5: Verify and commit**

```bash
php artisan test --compact tests/Feature/IngredientGuidanceChangePlannerTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/IngredientGuidanceChangePlanner.php app/Services/IngredientEnrichment/IngredientEnrichmentPlanner.php app/Services/IngredientEnrichment/IngredientEnrichmentReviewPresenter.php tests/Feature/IngredientGuidanceChangePlannerTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php
git commit -m "fix: make guidance metadata reviewable"
```

---

### Task 2: Add per-locale translation write intents

**Files:**

- Create: `app/Data/IngredientTranslationWriteIntent.php`
- Modify: `app/Services/IngredientTranslationService.php`
- Modify: `tests/Feature/IngredientTranslationTest.php`

- [ ] **Step 1: Write failing tests**

Prove that an identical stale row can refresh metadata, one reviewer intent changes only French provenance, and an identical row without an intent preserves its metadata.

```php
$service->sync($ingredient, $rows, writeIntents: [
    'fr' => new IngredientTranslationWriteIntent(
        origin: IngredientTranslationOrigin::AiGenerated,
        promptVersion: 'ingredient-guidance-localization-v1',
        refreshMetadata: true,
    ),
]);

expect($french->source_fingerprint)->toBe($fingerprints->forIngredient($ingredient))
    ->and($french->origin)->toBe(IngredientTranslationOrigin::AiGenerated);
```

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/IngredientTranslationTest.php
```

Expected: FAIL because the intent and argument do not exist.

- [ ] **Step 3: Add the intent DTO**

```php
readonly class IngredientTranslationWriteIntent
{
    public function __construct(
        public IngredientTranslationOrigin $origin,
        public ?string $promptVersion,
        public bool $refreshMetadata = false,
    ) {}
}
```

- [ ] **Step 4: Extend synchronization compatibly**

```php
/** @param array<string, IngredientTranslationWriteIntent> $writeIntents */
public function sync(
    Ingredient $ingredient,
    array $rows,
    IngredientTranslationOrigin $origin = IngredientTranslationOrigin::ReviewerEdited,
    ?string $promptVersion = null,
    array $writeIntents = [],
): void
```

Use the locale intent when present. Preserve metadata only when content is identical and `refreshMetadata` is false. Validate intent values and locale keys, using translated validation messages.

- [ ] **Step 5: Verify and commit**

```bash
php artisan test --compact tests/Feature/IngredientTranslationTest.php
vendor/bin/pint --dirty --format agent
git add app/Data/IngredientTranslationWriteIntent.php app/Services/IngredientTranslationService.php tests/Feature/IngredientTranslationTest.php
git commit -m "fix: synchronize translation provenance per locale"
```

---

### Task 3: Delegate proposal review from Actions to a Service

**Files:**

- Create: `app/Services/IngredientEnrichment/IngredientGuidanceProposalReviewService.php`
- Modify: `app/Actions/IngredientEnrichment/EditIngredientGuidanceProposal.php`
- Modify: `app/Actions/IngredientEnrichment/ApproveIngredientGuidanceProposal.php`
- Modify: `lang/en/ingredient_enrichment.php`
- Modify: `lang/en/ingredient_enrichment_admin.php`
- Create: `tests/Feature/IngredientGuidanceProposalReviewServiceTest.php`
- Modify: `tests/Feature/IngredientGuidanceBatchReviewTest.php`

- [ ] **Step 1: Write failing Service and delegation tests**

Test the Service directly for edit auditing, forbidden identity keys, stale transition, and approval. Mock the Service in each Action test:

```php
$review = Mockery::mock(IngredientGuidanceProposalReviewService::class);
$review->shouldReceive('edit')->once()->with($admin, $item, $proposal)->andReturn($item);
app()->instance(IngredientGuidanceProposalReviewService::class, $review);

app(EditIngredientGuidanceProposal::class)->handle($admin, $item, $proposal);
```

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/IngredientGuidanceProposalReviewServiceTest.php tests/Feature/IngredientGuidanceBatchReviewTest.php
```

- [ ] **Step 3: Implement the review Service**

Expose `edit(User $actor, IngredientEnrichmentBatchItem $item, array $proposal)` and `approve(User $actor, IngredientEnrichmentBatchItem $item)`. Move the five-attempt transactions, locks, stale checks, allowed-field validation, result validation, edited-field audit, approval audit, and batch refresh into it. Inject `IngredientGuidanceChangePlanner` and delete both duplicated private `plan()` implementations.

Merge translations with a collection pipeline:

```php
$candidate['translations'] = collect($current['translations'] ?? [])
    ->keyBy('locale')
    ->merge(collect($proposal['translations'] ?? [])->keyBy('locale'))
    ->values()
    ->all();
```

- [ ] **Step 4: Make Actions thin**

```php
public function handle(User $actor, IngredientEnrichmentBatchItem $item, array $proposal): IngredientEnrichmentBatchItem
{
    Gate::forUser($actor)->authorize('approve', $item->batch);

    return $this->reviews->edit($actor, $item, $proposal);
}
```

Use `reviews->approve()` in the approval Action. Keep no private domain helpers.

- [ ] **Step 5: Localize all displayed guidance messages**

Add dotted keys for wrong mode, forbidden English edit, unknown fields, invalid row shapes, absent evidence, and validator failures. Replace hard-coded messages introduced by the feature.

```bash
rg -n "This is not|cannot be edited|must be text|must be an array|Unknown field|Guidance evidence|Translation locale" app/Actions/IngredientEnrichment app/Services/IngredientEnrichment
```

Expected: no user-visible hard-coded matches in the guidance workflow.

- [ ] **Step 6: Verify and commit**

```bash
php artisan test --compact tests/Feature/IngredientGuidanceProposalReviewServiceTest.php tests/Feature/IngredientGuidanceBatchReviewTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/IngredientEnrichment/EditIngredientGuidanceProposal.php app/Actions/IngredientEnrichment/ApproveIngredientGuidanceProposal.php app/Services/IngredientEnrichment/IngredientGuidanceProposalReviewService.php lang/en/ingredient_enrichment.php lang/en/ingredient_enrichment_admin.php tests/Feature/IngredientGuidanceProposalReviewServiceTest.php tests/Feature/IngredientGuidanceBatchReviewTest.php
git commit -m "refactor: delegate ingredient guidance review"
```

---

### Task 4: Apply truthful freshness, provenance, evidence, and batch state

**Files:**

- Modify: `app/Services/IngredientEnrichment/ApplyIngredientGuidanceRefresh.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php`
- Modify: `app/Actions/IngredientEnrichment/ApplyApprovedIngredientEnrichment.php`
- Modify: `tests/Feature/IngredientGuidanceBatchReviewTest.php`
- Modify: `tests/Feature/PlatformIngredientEnrichmentImportTest.php`

- [ ] **Step 1: Write failing apply tests**

Prove:

- English-only edits leave all locale rows unchanged and outdated;
- English plus French edits make French reviewer-authored/current while German stays outdated;
- French-only edits use reviewer provenance;
- approved `revalidate` updates metadata without changing text;
- evidence-only approved work persists;
- the item records `applied_by_user_id` and the batch ends `Applied`.

```php
expect($item->fresh()->applied_by_user_id)->toBe($admin->id)
    ->and($batch->fresh()->status)->toBe(IngredientEnrichmentBatchStatus::Applied)
    ->and($french->origin)->toBe(IngredientTranslationOrigin::ReviewerEdited)
    ->and($german->source_fingerprint)->toBe($oldGermanFingerprint);
```

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/IngredientGuidanceBatchReviewTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php
```

- [ ] **Step 3: Build locale-specific rows and intents**

```php
$editedFields = collect($item->edited_fields ?? []);
$englishEdited = $editedFields->contains('proposal.info_markdown');
$reviewerLocales = $editedFields
    ->filter(fn (mixed $path): bool => is_string($path)
        && preg_match('/^proposal\.translations\.([^\.]+)\.info_markdown$/', $path) === 1)
    ->map(fn (string $path): string => (string) str($path)
        ->between('proposal.translations.', '.info_markdown'))
    ->values();
```

If English changed, merge only reviewer-edited proposal locales into existing rows. Otherwise merge all proposal locales. Pass reviewer or AI `IngredientTranslationWriteIntent` values, with `refreshMetadata: true` for approved `revalidate` decisions.

- [ ] **Step 4: Persist evidence and audit metadata**

Count English, locale text, locale metadata, and evidence changes in `$changed`. Persist approved evidence even when prose is identical. In localization-only mode preserve the stored English guidance prompt version and update only localization provenance. Set:

```php
'applied_by_user_id' => $actor->id,
'applied_at' => now(),
```

- [ ] **Step 5: Centralize aggregate completion**

Add `IngredientEnrichmentBatchService::markAppliedWhenComplete(int $batchId): void`. Lock the batch and set `Applied` only when no active, failed, stale, or reviewable item remains and at least one item is applied or unchanged. Call it from full and guidance apply paths after `refresh()`; remove the duplicated condition from `ApplyApprovedIngredientEnrichment`.

- [ ] **Step 6: Verify and commit**

```bash
php artisan test --compact tests/Feature/IngredientGuidanceBatchReviewTest.php tests/Feature/IngredientTranslationTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/ApplyIngredientGuidanceRefresh.php app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php app/Actions/IngredientEnrichment/ApplyApprovedIngredientEnrichment.php tests/Feature/IngredientGuidanceBatchReviewTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php
git commit -m "fix: apply ingredient guidance with truthful provenance"
```

---

### Task 5: Resume the exact failed guidance stage

**Files:**

- Create: `app/Services/IngredientEnrichment/IngredientGuidanceStageRunner.php`
- Modify: `app/Enums/IngredientEnrichmentBatchMode.php`
- Modify: `app/Models/IngredientEnrichmentBatchItem.php`
- Modify: `app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php`
- Modify: `app/Actions/IngredientEnrichment/RetryIngredientEnrichmentFailures.php`
- Modify: `app/Jobs/GenerateIngredientGuidanceRefresh.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php`
- Modify: `tests/Feature/IngredientGuidanceRefreshJobTest.php`
- Modify: `tests/Feature/IngredientEnrichmentRetryTest.php`

- [ ] **Step 1: Write failing resumption tests**

Make authoring succeed and localization fail once. Retry and assert:

```php
expect($authoring->calls)->toBe(1)
    ->and($localization->calls)->toBe(2)
    ->and(data_get($item->fresh()->research_stages, 'ai_guidance_authoring.status'))->toBe('completed')
    ->and(data_get($item->fresh()->research_stages, 'ai_guidance_localization.status'))->toBe('completed');
```

Add a localization-only case proving authoring is never called.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/IngredientGuidanceRefreshJobTest.php tests/Feature/IngredientEnrichmentRetryTest.php
```

- [ ] **Step 3: Define mode-specific stages**

```php
public function guidanceStages(): array
{
    return match ($this) {
        self::GuidanceRefresh => [
            IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
            IngredientEnrichmentResearchStage::AiGuidanceLocalization,
            IngredientEnrichmentResearchStage::Validation,
        ],
        self::GuidanceLocalization => [
            IngredientEnrichmentResearchStage::AiGuidanceLocalization,
            IngredientEnrichmentResearchStage::Validation,
        ],
        default => [],
    };
}
```

Pass batch mode to `retryableFromStage()` and use this order for guidance batches; retain the existing full order otherwise.

- [ ] **Step 4: Add and use the stage runner**

```php
/** @param callable(): IngredientSourceStageResult $callback */
public function run(
    int $itemId,
    IngredientEnrichmentResearchStage $stage,
    callable $callback,
): IngredientSourceStageResult
```

Return stored completed data; otherwise execute, verify the stage, persist completion, and record the exact failed stage before rethrowing. Use it for authoring, localization, and validation. Reconstruct English guidance and accounting from stored authoring data without another provider call.

- [ ] **Step 5: Remove the redundant job boolean**

```php
public function __construct(public readonly int $itemId)

public function handle(IngredientGuidanceRefreshProcessor $processor): void
{
    if (! $this->batch()?->cancelled()) {
        $processor->handle($this->itemId);
    }
}
```

Update every dispatch and retry site. The processor loads mode from the locked batch.

- [ ] **Step 6: Verify and commit**

```bash
php artisan test --compact tests/Feature/IngredientGuidanceRefreshJobTest.php tests/Feature/IngredientEnrichmentRetryTest.php tests/Feature/StartIngredientEnrichmentBatchTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/IngredientGuidanceStageRunner.php app/Enums/IngredientEnrichmentBatchMode.php app/Models/IngredientEnrichmentBatchItem.php app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php app/Actions/IngredientEnrichment/RetryIngredientEnrichmentFailures.php app/Jobs/GenerateIngredientGuidanceRefresh.php app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php tests/Feature/IngredientGuidanceRefreshJobTest.php tests/Feature/IngredientEnrichmentRetryTest.php tests/Feature/StartIngredientEnrichmentBatchTest.php
git commit -m "fix: resume ingredient guidance stages on retry"
```

---

### Task 6: Extract shared no-web OpenAI transport

**Files:**

- Create: `app/Data/OpenAiStructuredOutputResponse.php`
- Create: `app/Services/IngredientEnrichment/OpenAiStructuredOutputTransport.php`
- Modify: `app/Services/IngredientEnrichment/OpenAiIngredientEditorialClient.php`
- Modify: `app/Services/IngredientEnrichment/OpenAiIngredientGuidanceClient.php`
- Modify: `app/Services/IngredientEnrichment/OpenAiIngredientGuidanceLocalizationClient.php`
- Modify: `tests/Feature/IngredientEditorialClientTest.php`
- Modify: `tests/Feature/IngredientGuidanceClientTest.php`
- Modify: `tests/Feature/IngredientGuidanceLocalizationClientTest.php`

- [ ] **Step 1: Strengthen passing characterization tests**

For all three clients assert `/responses`, `store === false`, strict schema, no `tools` or `include`, provider accounting, and safe 429/5xx errors.

```bash
php artisan test --compact tests/Feature/IngredientEditorialClientTest.php tests/Feature/IngredientGuidanceClientTest.php tests/Feature/IngredientGuidanceLocalizationClientTest.php
```

Expected: PASS before refactoring.

- [ ] **Step 2: Add the response DTO and transport**

The DTO contains decoded `payload`, response/request IDs, model, and token counts. The transport exposes:

```php
public function send(
    string $instructions,
    string $input,
    string $schemaName,
    array $schema,
): OpenAiStructuredOutputResponse
```

Move API-key validation, HTTP configuration, retries, strict schema construction, `store => false`, response validation, JSON decoding, accounting, and safe provider errors into it. Accept no arbitrary tools/options.

- [ ] **Step 3: Reduce clients to domain adaptation**

Each client builds its prompt and schema, calls the transport, validates its domain payload, and constructs its existing response DTO. Keep all public contracts unchanged. Do not move `OpenAiIngredientGapResearchClient` because it uses web tools.

- [ ] **Step 4: Verify and commit**

```bash
php artisan test --compact tests/Feature/IngredientEditorialClientTest.php tests/Feature/IngredientGuidanceClientTest.php tests/Feature/IngredientGuidanceLocalizationClientTest.php
vendor/bin/pint --dirty --format agent
git add app/Data/OpenAiStructuredOutputResponse.php app/Services/IngredientEnrichment/OpenAiStructuredOutputTransport.php app/Services/IngredientEnrichment/OpenAiIngredientEditorialClient.php app/Services/IngredientEnrichment/OpenAiIngredientGuidanceClient.php app/Services/IngredientEnrichment/OpenAiIngredientGuidanceLocalizationClient.php tests/Feature/IngredientEditorialClientTest.php tests/Feature/IngredientGuidanceClientTest.php tests/Feature/IngredientGuidanceLocalizationClientTest.php
git commit -m "refactor: share ingredient structured output transport"
```

---

### Task 7: Verify the review UI and complete regression checks

**Files:**

- Modify if required: `app/Filament/Resources/IngredientEnrichmentBatches/RelationManagers/ItemsRelationManager.php`
- Modify if required: `app/Services/IngredientEnrichment/IngredientEnrichmentReviewPresenter.php`
- Modify: `tests/Feature/IngredientGuidanceRefreshActionTest.php`
- Modify: `tests/Feature/IngredientGuidanceBatchReviewTest.php`
- Modify through Laravel Boost `record-rule`: `.ai/rules/ingredient-enrichment.md`

- [ ] **Step 1: Test metadata-only review UX**

Prove a `revalidate` row is visible, contains no identity fields, uses no replacement checklist, can be approved/applied, and leaves the completed batch without reviewable approved work.

- [ ] **Step 2: Run UI checks**

```bash
vendor/bin/filacheck --fix
php artisan test --compact tests/Feature/IngredientGuidanceRefreshActionTest.php tests/Feature/IngredientGuidanceBatchReviewTest.php tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php tests/Feature/Filament/CatalogResourcesTest.php
```

- [ ] **Step 3: Record the durable correction rule**

Use Boost `record-rule` with glob `app/Services/IngredientEnrichment/**`:

```text
Guidance plans treat stale-locale revalidation and changed evidence as reviewable metadata changes even when prose is identical. If English is reviewer-edited, unedited generated localizations are not applied as current; only reviewer-edited locales may receive the new canonical fingerprint. Guidance retries resume completed stages according to persisted batch mode.
```

If `record-rule` is unavailable, report it and do not hand-edit a tool-owned rule file.

- [ ] **Step 4: Run the complete focused suite**

```bash
php artisan test --compact \
  tests/Feature/IngredientEditorialClientTest.php \
  tests/Feature/IngredientGuidanceClientTest.php \
  tests/Feature/IngredientGuidanceLocalizationClientTest.php \
  tests/Feature/IngredientGuidanceChangePlannerTest.php \
  tests/Feature/IngredientGuidanceContextBuilderTest.php \
  tests/Feature/IngredientGuidanceRefreshJobTest.php \
  tests/Feature/IngredientGuidanceProposalReviewServiceTest.php \
  tests/Feature/IngredientGuidanceBatchReviewTest.php \
  tests/Feature/IngredientGuidanceRefreshActionTest.php \
  tests/Feature/IngredientEnrichmentRetryTest.php \
  tests/Feature/IngredientEnrichmentPipelineTest.php \
  tests/Feature/IngredientEnrichmentResultSchemaTest.php \
  tests/Feature/PlatformIngredientEnrichmentImportTest.php \
  tests/Feature/IngredientTranslationTest.php \
  tests/Feature/StartIngredientEnrichmentBatchTest.php \
  tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php
```

- [ ] **Step 5: Run mandatory final checks**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
git diff --check
graphify update .
git status --short
```

Expected: tests, formatting, FilaCheck, graph refresh, and whitespace checks pass; status contains only intended correction files.

- [ ] **Step 6: Commit final verification changes**

```bash
git add app lang tests
git commit -m "test: verify ingredient guidance corrections"
```

Include `.ai/rules/ingredient-enrichment.md` in `git add` only if `record-rule` changed it.

---

## Acceptance walkthrough

1. Edit only English in a guidance proposal; existing locales remain unchanged and outdated after apply.
2. Edit English and French; French becomes current/reviewer-authored while untouched German stays outdated.
3. Regenerate stale French with identical returned prose; approval refreshes metadata without changing text.
4. Fail localization after authoring, retry, and confirm authoring is not called again.
5. Review an evidence-only result; it persists only after approval.
6. Apply all approved guidance rows; confirm actor audit and batch `Applied` status.
7. Confirm guidance review still exposes no identity fields or replacement controls.
8. Confirm all no-web OpenAI requests remain strict and `store => false`.
