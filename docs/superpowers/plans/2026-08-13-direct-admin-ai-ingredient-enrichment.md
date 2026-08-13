# Direct Admin AI Ingredient Enrichment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an Admin select multiple platform ingredients, queue precise source-restricted OpenAI research, review the resulting translations and enrichment proposals in Filament, and explicitly apply approved changes without command-line file handling.

**Architecture:** Reuse the existing enrichment snapshot, validator, planner, and transactional applier as the single data-safety core. Add persistent application batches/items, one Laravel queued job per ingredient, and an `IngredientResearchClient` boundary implemented with Laravel HTTP against the OpenAI Responses API. Add a versioned prompt/schema module and a Filament resource that exposes progress, evidence, approval, retry, cancellation, and apply.

**Tech Stack:** PHP 8.5, Laravel 13, PostgreSQL, Laravel database queues and job batching, Laravel HTTP client, Filament 5, Livewire 4, Pest 4, OpenAI Responses API with `web_search` and strict Structured Outputs.

---

## Implementation constraints

- The approved design is `docs/superpowers/specs/2026-08-13-direct-admin-ai-ingredient-enrichment-design.md`.
- This work builds on the current uncommitted enrichment core. Preserve every unrelated dirty-worktree change and stage only files named by each task.
- Before editing each path group, reread `.ai/rules/index.md`, all matching rules, and use Laravel Boost `search-docs` for framework-facing behavior.
- Use TDD: add one focused failing behavior, run it red, implement only enough for green, and rerun.
- Do not install an OpenAI PHP package. Keep the provider behind `App\Contracts\IngredientResearchClient` and use Laravel HTTP.
- Never place `OPENAI_API_KEY` in a queued payload, model, log, exception message, rendered page, or test fixture.
- Keep OpenAI calls outside database transactions. All multi-step writes use `DB::transaction(..., attempts: 5)` and lock rows first.
- Direct results must pass through `IngredientEnrichmentResultValidator`, `IngredientEnrichmentPlanner`, and `ApplyPlatformIngredientEnrichment`.
- No research or approval step writes ingredient data. Only explicit apply writes.
- Limit direct batches to platform ingredients and the configured maximum, initially 25.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes, `vendor/bin/filacheck --fix` after Filament changes, and `graphify update .` after all code changes.

## File map

### Shared enrichment contract and prompt

- Create `app/Services/IngredientEnrichment/IngredientEnrichmentInputBuilder.php`: build one deterministic research input record for CLI and direct jobs.
- Modify `app/Services/IngredientEnrichment/ExportPlatformIngredientEnrichment.php`: delegate record construction to the shared builder.
- Create `app/Services/IngredientEnrichment/IngredientEnrichmentResultSchema.php`: strict Responses API JSON schema matching the validator.
- Create `app/Services/IngredientEnrichment/IngredientEnrichmentResearchPrompt.php`: versioned precise instructions, source hierarchy, search procedure, and examples.
- Modify `config/ingredient-enrichment.php`: OpenAI, queue, batch-limit, domain, prompt-version, and timeout configuration.
- Modify `.env.example`: document only non-secret setting names and safe defaults.

### Provider boundary

- Create `app/Contracts/IngredientResearchClient.php`: provider-independent research interface.
- Create `app/Data/IngredientResearchResponse.php`: normalized provider response DTO.
- Create `app/Services/IngredientEnrichment/OpenAiIngredientResearchClient.php`: Responses API request, parsing, sources, usage, and safe errors.
- Create `app/Services/IngredientEnrichment/IngredientEnrichmentEvidenceVerifier.php`: prove every proposed citation came from an allowed page actually returned by the web-search call.
- Modify `app/Providers/AppServiceProvider.php`: bind the interface to the OpenAI implementation.

### Persistent workflow

- Create `app/Enums/IngredientEnrichmentBatchStatus.php` and `app/Enums/IngredientEnrichmentItemStatus.php`.
- Create `app/Models/IngredientEnrichmentBatch.php` and `app/Models/IngredientEnrichmentBatchItem.php`.
- Create factories for both models.
- Create two reversible migrations for batch and item tables.
- Create `app/Policies/IngredientEnrichmentBatchPolicy.php`.
- Create `app/Actions/IngredientEnrichment/StartIngredientEnrichmentBatch.php`.
- Create `app/Actions/IngredientEnrichment/ApproveIngredientEnrichmentItem.php`.
- Create `app/Actions/IngredientEnrichment/ApplyApprovedIngredientEnrichment.php`.
- Create `app/Actions/IngredientEnrichment/RetryIngredientEnrichmentFailures.php`.
- Create `app/Actions/IngredientEnrichment/CancelIngredientEnrichmentBatch.php`.
- Create `app/Jobs/ResearchIngredientEnrichment.php`.
- Create `app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php`: state transitions and aggregate counts.
- Create `app/Services/IngredientEnrichment/ResearchIngredientEnrichmentItem.php`: network-free state acquisition, provider call orchestration, validation, planning, and persistence.

### Admin UI

- Modify `app/Filament/Resources/Ingredients/Tables/IngredientsTable.php`: direct-AI bulk action.
- Create an `IngredientEnrichmentBatches` Filament resource with list/view pages, schema, table, and items relation manager.
- Create `resources/views/filament/ingredient-enrichment/review-item.blade.php`: escaped current/proposed/evidence review content.
- Create `lang/en/ingredient_enrichment_admin.php`: all workflow labels, descriptions, notifications, validation, and safe error copy.

### Tests

- Create focused feature tests for prompt/schema, persistence, start/queue, HTTP client, research processing, approval/apply, and Filament UI.
- Extend the existing export test to prove CLI and direct input records remain identical.

## Task 1: Extract the shared input record and lock the strict result schema

**Files:**

- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentInputBuilder.php`
- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentResultSchema.php`
- Modify: `app/Services/IngredientEnrichment/ExportPlatformIngredientEnrichment.php`
- Test: `tests/Feature/IngredientEnrichmentInputBuilderTest.php`
- Test: `tests/Feature/IngredientEnrichmentResultSchemaTest.php`
- Modify: `tests/Feature/PlatformIngredientEnrichmentExportTest.php`

- [ ] **Step 1: Write failing shared-record parity tests**

Create an incomplete platform ingredient with identifiers, aliases, translations, functions, and market labels. Assert that:

```php
$record = app(IngredientEnrichmentInputBuilder::class)->build($ingredient);

expect(array_keys($record))->toBe([
    'format',
    'schema_version',
    'catalog_key',
    'source_fingerprint',
    'current',
    'vocabulary',
    'requested_output',
    'research_rules',
]);

expect($record['requested_output']['fields'])->toContain(
    'translations',
    'identifiers',
    'cosing_functions',
    'market_labels',
);
```

Export that same ingredient and assert the decoded JSONL line equals `$record` exactly.

- [ ] **Step 2: Run the input tests and confirm RED**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentInputBuilderTest.php tests/Feature/PlatformIngredientEnrichmentExportTest.php
```

Expected: failure because `IngredientEnrichmentInputBuilder` does not exist.

- [ ] **Step 3: Implement the shared builder and make export delegate**

Use this public contract:

```php
final class IngredientEnrichmentInputBuilder
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshotBuilder,
    ) {}

    /** @return array<string, mixed> */
    public function build(Ingredient $ingredient): array;
}
```

Move the existing `ExportPlatformIngredientEnrichment::inputRecord()` logic unchanged into `build()`. Pass the active COSING vocabulary into the builder through a cached private method so an export batch still performs one vocabulary query. Delete the exporter's private record-construction method and call the builder.

- [ ] **Step 4: Write the strict-schema failing tests**

Assert the schema starts with:

```php
$schema = app(IngredientEnrichmentResultSchema::class)->build();

expect($schema)->toMatchArray([
    'type' => 'object',
    'additionalProperties' => false,
]);
expect($schema['required'])->toBe([
    'format', 'schema_version', 'catalog_key', 'source_fingerprint',
    'proposal', 'evidence', 'confidence', 'warnings', 'unresolved_questions',
]);
expect(data_get($schema, 'properties.proposal.additionalProperties'))->toBeFalse();
expect(data_get($schema, 'properties.proposal.properties.identifiers.items.required'))->toBe([
    'scheme', 'value', 'source_name', 'source_url', 'checked_at',
]);
```

Walk every object node recursively and assert `additionalProperties === false` and `required` contains every property key, as required by strict output schemas. Assert locale and vocabulary enums are read from application configuration and enum cases.

- [ ] **Step 5: Run schema tests RED, implement the complete schema, then rerun GREEN**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentResultSchemaTest.php
```

Implement `build(): array` for the exact validator contract. Nullable fields use `['string', 'null']`; arrays remain required but may be empty. Use enum values for category, subcategory, identifier scheme, confidence, locale, market, and COSING function keys.

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentInputBuilderTest.php tests/Feature/IngredientEnrichmentResultSchemaTest.php tests/Feature/PlatformIngredientEnrichmentExportTest.php
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit the shared contract**

```bash
git add app/Services/IngredientEnrichment/IngredientEnrichmentInputBuilder.php app/Services/IngredientEnrichment/IngredientEnrichmentResultSchema.php app/Services/IngredientEnrichment/ExportPlatformIngredientEnrichment.php tests/Feature/IngredientEnrichmentInputBuilderTest.php tests/Feature/IngredientEnrichmentResultSchemaTest.php tests/Feature/PlatformIngredientEnrichmentExportTest.php
git diff --cached --check
git commit -m "refactor: share ingredient enrichment contract"
```

## Task 2: Implement and test the extremely precise research prompt

**Files:**

- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentResearchPrompt.php`
- Modify: `config/ingredient-enrichment.php`
- Modify: `.env.example`
- Test: `tests/Feature/IngredientEnrichmentResearchPromptTest.php`

- [ ] **Step 1: Add failing prompt protocol tests**

Build a representative vegetable oil record and assert:

```php
$prompt = app(IngredientEnrichmentResearchPrompt::class)->build($record, CarbonImmutable::parse('2026-08-13'));

expect($prompt)->toHaveKeys(['version', 'instructions', 'input'])
    ->and($prompt['version'])->toBe('ingredient-enrichment-research-v1')
    ->and($prompt['instructions'])->toContain(
        '# Identity',
        '# Non-negotiable rules',
        '# Required source hierarchy',
        '# Field-specific evidence rules',
        '# Required search procedure',
        '# Examples',
        'https://ec.europa.eu/growth/tools-databases/cosing/',
        'https://echa.europa.eu/information-on-chemicals',
        'https://commonchemistry.cas.org/',
        'https://pubchem.ncbi.nlm.nih.gov/',
        'https://powo.science.kew.org/',
        'https://www.fda.gov/cosmetics/cosmetics-labeling/cosmetic-ingredient-names',
        'https://www.ecfr.gov/current/title-21/chapter-I/subchapter-A/part-73',
        'https://eur-lex.europa.eu/eli/reg/2009/1223/oj/eng',
        'https://pubmed.ncbi.nlm.nih.gov/',
        'https://www.cir-safety.org/ingredients',
        'never invent',
        'search-result snippet',
        'unresolved_questions',
    )
    ->and($prompt['input'])->toContain(
        '<ingredient_research_input>',
        '</ingredient_research_input>',
        '2026-08-13',
        $record['source_fingerprint'],
    );
```

Add separate assertions that the instructions contain examples for a vegetable oil, an ambiguous essential oil, and a CI colourant with a distinct US declaration. Assert user-controlled ingredient text appears only inside the XML input and never interpolates into instructions.

- [ ] **Step 2: Run RED and implement the versioned prompt**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentResearchPromptTest.php
```

Implement:

```php
final class IngredientEnrichmentResearchPrompt
{
    /** @return array{version:string,instructions:string,input:string} */
    public function build(array $record, CarbonImmutable $checkedAt): array
    {
        return [
            'version' => (string) config('ingredient-enrichment.openai.prompt_version'),
            'instructions' => $this->instructions(),
            'input' => '<ingredient_research_input checked_at="'.$checkedAt->toDateString().'">'."\n"
                .json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
                .'</ingredient_research_input>',
        ];
    }
}
```

The fixed `instructions()` nowdoc must contain every rule and exact website from the approved design, the eight-step search sequence, field-level rules, prompt-injection warning, no-guess behavior, and all three compact examples.

- [ ] **Step 3: Add explicit OpenAI and batch configuration**

Extend `config/ingredient-enrichment.php` with:

```php
'direct_ai' => [
    'enabled' => env('INGREDIENT_ENRICHMENT_AI_ENABLED', false),
    'queue' => env('INGREDIENT_ENRICHMENT_QUEUE', 'default'),
    'default_batch_size' => (int) env('INGREDIENT_ENRICHMENT_DEFAULT_BATCH_SIZE', 10),
    'maximum_batch_size' => (int) env('INGREDIENT_ENRICHMENT_MAXIMUM_BATCH_SIZE', 25),
],
'openai' => [
    'api_key' => env('OPENAI_API_KEY'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    'model' => env('INGREDIENT_ENRICHMENT_MODEL', 'gpt-5.6-terra'),
    'reasoning_effort' => env('INGREDIENT_ENRICHMENT_REASONING_EFFORT', 'low'),
    'timeout_seconds' => (int) env('INGREDIENT_ENRICHMENT_TIMEOUT', 300),
    'connect_timeout_seconds' => (int) env('INGREDIENT_ENRICHMENT_CONNECT_TIMEOUT', 15),
    'prompt_version' => 'ingredient-enrichment-research-v1',
    'allowed_domains' => [
        'ec.europa.eu', 'single-market-economy.ec.europa.eu', 'eur-lex.europa.eu',
        'echa.europa.eu', 'commonchemistry.cas.org', 'pubchem.ncbi.nlm.nih.gov',
        'powo.science.kew.org', 'fda.gov', 'ecfr.gov',
        'pubmed.ncbi.nlm.nih.gov', 'cir-safety.org',
    ],
],
```

Add the setting names to `.env.example` with AI disabled. Never add a real key.

- [ ] **Step 4: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentResearchPromptTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/IngredientEnrichmentResearchPrompt.php config/ingredient-enrichment.php .env.example tests/Feature/IngredientEnrichmentResearchPromptTest.php
git diff --cached --check
git commit -m "feat: define precise ingredient research prompt"
```

## Task 3: Add persistent batches, items, statuses, factories, and policy

**Files:**

- Create: `app/Enums/IngredientEnrichmentBatchStatus.php`
- Create: `app/Enums/IngredientEnrichmentItemStatus.php`
- Create: `app/Models/IngredientEnrichmentBatch.php`
- Create: `app/Models/IngredientEnrichmentBatchItem.php`
- Create: `database/factories/IngredientEnrichmentBatchFactory.php`
- Create: `database/factories/IngredientEnrichmentBatchItemFactory.php`
- Create: `database/migrations/2026_08_13_200000_create_ingredient_enrichment_batches_table.php`
- Create: `database/migrations/2026_08_13_200001_create_ingredient_enrichment_batch_items_table.php`
- Create: `app/Policies/IngredientEnrichmentBatchPolicy.php`
- Test: `tests/Feature/IngredientEnrichmentBatchSchemaTest.php`

- [ ] **Step 1: Generate files and write failing model/schema tests**

Use Artisan with `--no-interaction`. Test public UUID route keys, enum/date/JSON casts, relationships, cascade behavior, unique batch+ingredient, nullable requester/approver/applier foreign keys, indexes on status and batch/status, and policy denial for non-Admins.

```bash
php artisan make:model IngredientEnrichmentBatch --factory --no-interaction
php artisan make:model IngredientEnrichmentBatchItem --factory --no-interaction
php artisan make:migration create_ingredient_enrichment_batches_table --no-interaction
php artisan make:migration create_ingredient_enrichment_batch_items_table --no-interaction
php artisan make:policy IngredientEnrichmentBatchPolicy --model=IngredientEnrichmentBatch --no-interaction
php artisan test --compact tests/Feature/IngredientEnrichmentBatchSchemaTest.php
```

Expected: RED until the generated files contain the designed fields and behavior.

- [ ] **Step 2: Implement enum states and transitions**

Batch cases: `Pending`, `Processing`, `ReadyForReview`, `PartiallyFailed`, `Applied`, `Cancelled`.

Item cases: `Pending`, `Researching`, `Ready`, `Warning`, `Failed`, `Approved`, `Applying`, `Stale`, `Applied`, `Unchanged`, `Cancelled`.

Both are string-backed PascalCase enums. Add `label(): string` using `ingredient_enrichment_admin.status.*` keys and helper predicates such as `isTerminal()` only when used by services.

- [ ] **Step 3: Implement the two reversible tables**

The batch table stores public ID, requester, status, Laravel batch ID, model/reasoning/prompt/schema/mode, denormalized counts and usage, timestamps, and standard timestamps. The item table stores public ID, batch, nullable ingredient, status, snapshot/fingerprint, result/report/plan/replacement JSON, confidence, warnings/questions/sources JSON, provider response ID/request ID/model/usage, failure data, attempt count, approver/applier, lifecycle timestamps, and standard timestamps.

Use strings for enum columns, JSON for structured records, and explicit delete behavior: item deletion cascades when its batch is deleted, while deleting an ingredient sets the item foreign key to null so the retained snapshot/provenance remains auditable. Use `down()` with `dropIfExists()` in reverse dependency order.

- [ ] **Step 4: Implement models, factories, and Admin-only policy**

Use `#[Fillable]`, `HasFactory`, `HasPublicId`, typed relationships, and `casts()` methods. Policy methods `viewAny`, `view`, `create`, `update`, `approve`, `apply`, `retry`, and `cancel` return `$user->is_admin`.

- [ ] **Step 5: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentBatchSchemaTest.php
vendor/bin/pint --dirty --format agent
git add app/Enums/IngredientEnrichmentBatchStatus.php app/Enums/IngredientEnrichmentItemStatus.php app/Models/IngredientEnrichmentBatch.php app/Models/IngredientEnrichmentBatchItem.php database/factories/IngredientEnrichmentBatchFactory.php database/factories/IngredientEnrichmentBatchItemFactory.php database/migrations/2026_08_13_200000_create_ingredient_enrichment_batches_table.php database/migrations/2026_08_13_200001_create_ingredient_enrichment_batch_items_table.php app/Policies/IngredientEnrichmentBatchPolicy.php tests/Feature/IngredientEnrichmentBatchSchemaTest.php
git diff --cached --check
git commit -m "feat: persist ingredient enrichment batches"
```

## Task 4: Implement the OpenAI Responses API client boundary

**Files:**

- Create: `app/Contracts/IngredientResearchClient.php`
- Create: `app/Data/IngredientResearchResponse.php`
- Create: `app/Services/IngredientEnrichment/OpenAiIngredientResearchClient.php`
- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentEvidenceVerifier.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/OpenAiIngredientResearchClientTest.php`

- [ ] **Step 1: Write HTTP-faked request and parsing tests**

Use `Http::fake()` and `Http::assertSent()` to require:

```php
expect($request->url())->toBe('https://api.openai.com/v1/responses')
    ->and($request['model'])->toBe('gpt-5.6-terra')
    ->and($request['store'])->toBeFalse()
    ->and($request['tools'][0])->toMatchArray([
        'type' => 'web_search',
        'filters' => ['allowed_domains' => config('ingredient-enrichment.openai.allowed_domains')],
    ])
    ->and($request['include'])->toBe(['web_search_call.action.sources'])
    ->and(data_get($request, 'text.format.type'))->toBe('json_schema')
    ->and(data_get($request, 'text.format.strict'))->toBeTrue();
```

Fake a raw Responses payload containing a `web_search_call`, an assistant `message` with `output_text`, `usage`, response `id`, and `x-request-id` header. Assert normalized result JSON, unique sources, model, response ID, request ID, and tokens.

Add tests for missing API key, 401, 429 followed by success, 500 exhaustion, refusal, incomplete response, missing output text, invalid JSON, and source entries outside the configured allow-list. Assert exceptions use domain-safe translated messages and never contain a key or authorization header.

- [ ] **Step 2: Run RED and implement the contract/DTO**

```php
interface IngredientResearchClient
{
    /** @param array<string, mixed> $record */
    public function research(array $record): IngredientResearchResponse;
}
```

```php
final readonly class IngredientResearchResponse
{
    public function __construct(
        public array $result,
        public string $responseId,
        public string $requestId,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $webSearchCalls,
        public array $sources,
    ) {}
}
```

- [ ] **Step 3: Implement Laravel HTTP request/parsing**

The client injects the prompt and schema services, reads only `config()`, applies bearer auth, JSON headers, connect/overall timeouts, and bounded retry for 429/5xx/connection failures. It sends `instructions`, XML-delimited `input`, reasoning, filtered web search, source include, strict schema, and `store: false`.

Parse output text only from `output[].type === 'message'` and `content[].type === 'output_text'`. Parse sources only from `output[].type === 'web_search_call'` and `action.sources`. Capture the provider response ID and HTTP `x-request-id`. Validate domain membership before returning the DTO.

Implement `IngredientEnrichmentEvidenceVerifier` with a network-free contract accepting the normalized result and consulted source list. It extracts every `source_url` from general evidence, identifiers, COSING functions, and market labels; normalizes URL fragments and trailing slashes; requires an HTTP(S) page-level URL on an allowed domain; and requires each citation to match a consulted source URL. It rejects invented citations, disallowed domains, bare home pages where a page-level source is required, and source-name/URL contradictions. Add focused tests proving a plausible but unconsulted FDA or PubChem URL is rejected.

- [ ] **Step 4: Bind, run GREEN, and commit**

```php
$this->app->bind(IngredientResearchClient::class, OpenAiIngredientResearchClient::class);
```

```bash
php artisan test --compact tests/Feature/OpenAiIngredientResearchClientTest.php
vendor/bin/pint --dirty --format agent
git add app/Contracts/IngredientResearchClient.php app/Data/IngredientResearchResponse.php app/Services/IngredientEnrichment/OpenAiIngredientResearchClient.php app/Services/IngredientEnrichment/IngredientEnrichmentEvidenceVerifier.php app/Providers/AppServiceProvider.php tests/Feature/OpenAiIngredientResearchClientTest.php
git diff --cached --check
git commit -m "feat: research ingredients through OpenAI responses"
```

## Task 5: Create batches and process one queued research job per ingredient

**Files:**

- Create: `app/Actions/IngredientEnrichment/StartIngredientEnrichmentBatch.php`
- Create: `app/Jobs/ResearchIngredientEnrichment.php`
- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php`
- Create: `app/Services/IngredientEnrichment/ResearchIngredientEnrichmentItem.php`
- Test: `tests/Feature/StartIngredientEnrichmentBatchTest.php`
- Test: `tests/Feature/ResearchIngredientEnrichmentJobTest.php`

- [ ] **Step 1: Write failing batch-start tests**

Use `Bus::fake()` or `Queue::fake()` and assert Admin-only, AI enabled, API key present, non-empty selection, unique IDs, platform-only ingredients, maximum 25, one item/snapshot/fingerprint per ingredient, and one batched job per item. Assert no rows remain if validation fails and dispatch happens after persistence.

- [ ] **Step 2: Implement start action**

```php
public function handle(User $actor, Collection $ingredients): IngredientEnrichmentBatch
```

Authorize against the batch policy. Normalize/sort unique ingredient IDs. Validate configuration and selection before writing. In one retryable transaction, lock ingredients in ID order, verify platform ownership, create the batch, and create items from `IngredientEnrichmentInputBuilder`. After commit, dispatch:

```php
$laravelBatch = Bus::batch(
    $batch->items->map(fn (IngredientEnrichmentBatchItem $item) => new ResearchIngredientEnrichment($item->id))->all(),
)->name("ingredient-enrichment:{$batch->public_id}")
  ->allowFailures()
  ->onQueue((string) config('ingredient-enrichment.direct_ai.queue'))
  ->dispatch();
```

Persist the Laravel batch ID and processing state in a second locked transaction.

- [ ] **Step 3: Write failing processor/job tests**

Bind a fake `IngredientResearchClient`, invoke the job, and assert ready/warning/unchanged statuses, normalized result, validation report, plan, sources, usage, provider IDs, attempt count, batch counts, and zero ingredient writes. Add stale-before-call behavior, invalid model result, safe client failure, retry/overlap behavior, missing item, cancelled item, idempotent replay, and `failed(Throwable)` persistence.

- [ ] **Step 4: Implement processor and job**

`ResearchIngredientEnrichmentItem::handle(int $itemId)` performs three phases:

1. locked transaction: acquire pending/failed item, compare fingerprint, mark researching, increment attempts;
2. no transaction: call `IngredientResearchClient::research($item->snapshot)`;
3. locked transaction: reload item/ingredient, recheck fingerprint, run `IngredientEnrichmentEvidenceVerifier` against the provider's consulted sources, validate the normalized result, plan with no replacement fields, merge validator/result/unresolved warnings, persist result/report/plan/provenance, set ready/warning/unchanged or failed, and refresh aggregate counts.

The queue job uses `Batchable`, `Queueable`, `ShouldBeUnique`, `tries = 3`, `timeout = 330`, `failOnTimeout = true`, `backoff(): array`, `uniqueId()`, and `WithoutOverlapping`.

- [ ] **Step 5: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/StartIngredientEnrichmentBatchTest.php tests/Feature/ResearchIngredientEnrichmentJobTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/IngredientEnrichment/StartIngredientEnrichmentBatch.php app/Jobs/ResearchIngredientEnrichment.php app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php app/Services/IngredientEnrichment/ResearchIngredientEnrichmentItem.php tests/Feature/StartIngredientEnrichmentBatchTest.php tests/Feature/ResearchIngredientEnrichmentJobTest.php
git diff --cached --check
git commit -m "feat: queue ingredient enrichment research"
```

## Task 6: Add approval, explicit replacement, apply, retry, and cancellation

**Files:**

- Create: `app/Actions/IngredientEnrichment/ApproveIngredientEnrichmentItem.php`
- Create: `app/Actions/IngredientEnrichment/ApplyApprovedIngredientEnrichment.php`
- Create: `app/Actions/IngredientEnrichment/RetryIngredientEnrichmentFailures.php`
- Create: `app/Actions/IngredientEnrichment/CancelIngredientEnrichmentBatch.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php`
- Test: `tests/Feature/IngredientEnrichmentBatchReviewTest.php`

- [ ] **Step 1: Write failing approval/apply tests**

Prove approval writes no ingredient data, validates replacement fields through the existing planner, re-plans against the current ingredient, marks stale on fingerprint drift, and stores approver/time. Prove apply processes only approved items, uses selected replacements, preserves unselected reviewed values, isolates each ingredient transaction, records applier/time, and reports applied/unchanged/stale/failed totals.

- [ ] **Step 2: Implement approval and apply actions**

Use these contracts:

```php
public function handle(User $actor, IngredientEnrichmentBatchItem $item, array $replaceFields = []): IngredientEnrichmentBatchItem;
```

```php
/** @return array{applied:int,unchanged:int,stale:int,failed:int} */
public function handle(User $actor, IngredientEnrichmentBatch $batch): array;
```

Every item mutation locks the item and ingredient, re-authorizes, revalidates the stored normalized result, and refreshes the plan. Apply calls the existing applier with the approved replacement fields, catches/report unexpected failures per item, and never prevents unrelated approved items from applying.

- [ ] **Step 3: Write and implement retry/cancel tests**

Retry accepts only failed items with current fingerprints, resets safe failure fields, and dispatches only those jobs in a new Laravel job batch. Stale failures remain stale. Cancel authorizes, cancels the Laravel batch when present, marks only pending items cancelled, and preserves ready/approved/applied proposals.

- [ ] **Step 4: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentBatchReviewTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/IngredientEnrichment/ApproveIngredientEnrichmentItem.php app/Actions/IngredientEnrichment/ApplyApprovedIngredientEnrichment.php app/Actions/IngredientEnrichment/RetryIngredientEnrichmentFailures.php app/Actions/IngredientEnrichment/CancelIngredientEnrichmentBatch.php app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php tests/Feature/IngredientEnrichmentBatchReviewTest.php
git diff --cached --check
git commit -m "feat: review and apply enrichment batches"
```

## Task 7: Add the Ingredients-table Run AI enrichment bulk action

**Files:**

- Modify: `app/Filament/Resources/Ingredients/Tables/IngredientsTable.php`
- Create: `lang/en/ingredient_enrichment_admin.php`
- Test: `tests/Feature/Filament/IngredientEnrichmentBulkActionTest.php`

- [ ] **Step 1: Write failing Filament bulk-action tests**

```php
livewire(ListIngredients::class)
    ->selectTableRecords($ingredients->pluck('id')->all())
    ->callAction(TestAction::make('runAiEnrichment')->table()->bulk())
    ->assertHasNoActionErrors()
    ->assertNotified(__('ingredient_enrichment_admin.notifications.batch_started'));
```

Assert the action exists in the bulk group, its modal names the model/count/payment warning/content scope/fill-missing rule, it redirects to the created batch, and it reports missing configuration, too many records, or invalid selection without partial writes.

- [ ] **Step 2: Implement the bulk action through the domain Action**

Add `BulkAction::make('runAiEnrichment')` before export. It requires confirmation, deselects after completion, uses translated copy, displays model and selected count, and injects `StartIngredientEnrichmentBatch`. It calls the Action with `auth()->user()` and selected Eloquent records, then redirects to the batch view URL.

- [ ] **Step 3: Run, Filacheck, format, and commit**

```bash
php artisan test --compact tests/Feature/Filament/IngredientEnrichmentBulkActionTest.php
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
git add app/Filament/Resources/Ingredients/Tables/IngredientsTable.php lang/en/ingredient_enrichment_admin.php tests/Feature/Filament/IngredientEnrichmentBulkActionTest.php
git diff --cached --check
git commit -m "feat: start enrichment batches from admin"
```

## Task 8: Build the Admin batch list, progress, evidence review, and actions

**Files:**

- Create: `app/Filament/Resources/IngredientEnrichmentBatches/IngredientEnrichmentBatchResource.php`
- Create: `app/Filament/Resources/IngredientEnrichmentBatches/Pages/ListIngredientEnrichmentBatches.php`
- Create: `app/Filament/Resources/IngredientEnrichmentBatches/Pages/ViewIngredientEnrichmentBatch.php`
- Create: `app/Filament/Resources/IngredientEnrichmentBatches/Schemas/IngredientEnrichmentBatchInfolist.php`
- Create: `app/Filament/Resources/IngredientEnrichmentBatches/Tables/IngredientEnrichmentBatchesTable.php`
- Create: `app/Filament/Resources/IngredientEnrichmentBatches/RelationManagers/ItemsRelationManager.php`
- Create: `resources/views/filament/ingredient-enrichment/review-item.blade.php`
- Modify: `lang/en/ingredient_enrichment_admin.php`
- Test: `tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php`

- [ ] **Step 1: Generate the resource and write failing access/list/view tests**

```bash
php artisan make:filament-resource IngredientEnrichmentBatch --view --no-interaction
```

Test Admin access and non-Admin rejection, navigation under Catalog, batch status/progress/model/requester/usage/timestamps, eager-loaded item counts, public-ID URLs, and no create/edit/delete pages.

- [ ] **Step 2: Implement the read-oriented resource**

The list table shows public ID, status badge, progress (`terminal_count / total_count`), model, requester, input/output tokens, created/completed dates, and filters for status/model. Use `with('requester')` and no lazy loading.

The view infolist shows all batch metadata and aggregate counts. Register `ItemsRelationManager` and header actions for approve all ready, apply approved, retry failures, and cancel pending. Each header action delegates to the corresponding application Action and sends a translated result notification.

- [ ] **Step 3: Write failing item review/action tests**

Assert the relation table polls while processing and shows ingredient, status, confidence, warnings, source count, and decision counts. Test review modal content for current/proposed canonical fields, multiple identifiers, COSING sources, English guidance, each locale translation, EU/US labels, warnings, unresolved questions, and escaped clickable HTTP(S) sources.

Test individual approve with replacement checkboxes, bulk approve-ready, apply-approved totals, retry, and cancel. Assert approval leaves ingredient data unchanged and apply changes it.

- [ ] **Step 4: Implement relation manager and escaped review view**

Add translated labels/options to `IngredientEnrichmentReplaceField` if they do not already exist. Use `CheckboxList::make('replace_fields')->options(IngredientEnrichmentReplaceField::options())` only for fields whose plan decision is `preserved`. The custom Blade view reads the stored plan/result, uses escaped `{{ }}` output, iterates arrays without queries, and renders links only after URL validation. Never use `{!! !!}`.

- [ ] **Step 5: Run, Filacheck, format, and commit**

```bash
php artisan test --compact tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php tests/Feature/Filament/IngredientEnrichmentBulkActionTest.php
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
git add app/Filament/Resources/IngredientEnrichmentBatches resources/views/filament/ingredient-enrichment/review-item.blade.php lang/en/ingredient_enrichment_admin.php tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php
git diff --cached --check
git commit -m "feat: review enrichment batches in admin"
```

## Task 9: Add end-to-end safety coverage and operational verification

**Files:**

- Test: `tests/Feature/IngredientEnrichmentDirectWorkflowTest.php`

- [ ] **Step 1: Write the end-to-end faked-provider test**

Create an Admin and several minimal ingredients: vegetable oil, essential oil, wax, and colourant. Start one batch, execute each queued job against a fake provider, approve only valid items, select one explicit replacement, and apply. Assert:

- all proposals retain their page-level sources;
- translations exist for every configured locale;
- multiple identifiers survive;
- exact COSING evidence survives;
- Soapmaking appears only where relevant;
- EU/US market labels exist only for the colourant;
- invalid/ambiguous essential-oil identity remains warning/unresolved;
- reviewed fields are preserved unless explicitly replaced;
- no private ingredient can enter the workflow;
- rerunning an applied result is idempotent.

- [ ] **Step 2: Run the complete focused suite**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentInputBuilderTest.php tests/Feature/IngredientEnrichmentResultSchemaTest.php tests/Feature/IngredientEnrichmentResearchPromptTest.php tests/Feature/IngredientEnrichmentBatchSchemaTest.php tests/Feature/OpenAiIngredientResearchClientTest.php tests/Feature/StartIngredientEnrichmentBatchTest.php tests/Feature/ResearchIngredientEnrichmentJobTest.php tests/Feature/IngredientEnrichmentBatchReviewTest.php tests/Feature/IngredientEnrichmentDirectWorkflowTest.php tests/Feature/Filament/IngredientEnrichmentBulkActionTest.php tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php tests/Feature/IngredientEnrichmentValidationTest.php tests/Feature/PlatformIngredientEnrichmentExportTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php tests/Feature/IngredientMarketLabelTest.php tests/Feature/Filament/IngredientMarketLabelsActionTest.php
```

Expected: all pass.

- [ ] **Step 3: Run static project checks**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
graphify update .
git diff --check
```

- [ ] **Step 4: Run the full suite**

```bash
php artisan test --compact
```

Expected: all existing and new tests pass with only previously acknowledged skips.

- [ ] **Step 5: Verify development configuration without making a paid call**

```bash
php artisan config:show ingredient-enrichment
php artisan queue:monitor default --max=100
```

Confirm AI is disabled by default, no API key is printed by application UI/logging, the default queue exists, and `composer run dev` includes a queue worker. Do not make a live OpenAI request during automated verification.

- [ ] **Step 6: Commit final integration coverage**

```bash
git add tests/Feature/IngredientEnrichmentDirectWorkflowTest.php
git diff --cached --check
git commit -m "test: cover direct ingredient enrichment workflow"
```

## Post-implementation developer setup

The feature remains disabled until the developer sets:

```dotenv
INGREDIENT_ENRICHMENT_AI_ENABLED=true
OPENAI_API_KEY=your-server-side-project-key
INGREDIENT_ENRICHMENT_MODEL=gpt-5.6-terra
QUEUE_CONNECTION=database
```

For local development, use the existing `composer run dev`, which already runs a worker for `media,default`. In a deployed environment, run a persistent Laravel queue worker for the configured enrichment queue. No per-ingredient command and no JSONL upload are part of the normal flow.
