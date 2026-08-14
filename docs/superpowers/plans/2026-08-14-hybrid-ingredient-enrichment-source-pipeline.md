# Hybrid Ingredient Enrichment Source Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace live-web-search-first ingredient enrichment with resumable CosIng, EUR-Lex, FDA, and AI editorial stages that produce precise EU/US proposals with field-level evidence and safe batch review.

**Architecture:** Keep the existing batch, snapshot, validator, planner, approval, and transactional apply core. Add focused deterministic source adapters behind one resumable pipeline, persist normalized stage results on each batch item, and use OpenAI only after identity facts are assembled. Extend existing identifier, function, and market-label provenance instead of creating a second ingredient identity store.

**Tech Stack:** PHP 8.5, Laravel 13.24, PostgreSQL JSONB, Laravel HTTP/cache/queues/job batching, Filament 5.7, Livewire 4, Pest 4.7, OpenAI Responses API strict structured output, DOMDocument/DOMXPath.

---

## Implementation constraints

- Approved design: `docs/superpowers/specs/2026-08-14-hybrid-ingredient-enrichment-source-pipeline-design.md`.
- Preserve every unrelated dirty-worktree change. Stage and commit only the files named in each task.
- Before each path group, reread `.ai/rules/index.md` and matching rules. Use Laravel Boost `search-docs` before framework-facing changes.
- Use TDD: add one focused failing behavior, run it red, implement the minimum, and run it green.
- Do not add dependencies. Laravel HTTP, cache, DOMDocument, DOMXPath, and XMLReader are sufficient.
- Never make routine tests call external services. Use `Http::fake()` and committed compact fixtures.
- Keep network calls outside database transactions. Each multi-step write uses `DB::transaction(..., attempts: 5)` and locks its target first.
- Fixed source base URLs come from configuration. User-controlled URLs are never fetched.
- Do not log API keys, full provider payloads, or unredacted third-party errors.
- Research, retries, and proposal editing do not mutate canonical ingredients. Only `ApplyApprovedIngredientEnrichment` may apply a proposal.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes, `vendor/bin/filacheck --fix` after `app/Filament` changes, and `graphify update .` after all code changes.

## File map

### Source and confidence vocabulary

- Create `app/Enums/IngredientEvidenceConfidence.php`: `Verified`, `Supported`, `Conflicting`, `Unresolved`.
- Create `app/Enums/IngredientSourceTier.php`: `Official`, `StructuredMirror`, `Editorial`.
- Create `app/Enums/IngredientEnrichmentResearchStage.php`: stable stage keys and downstream invalidation order.
- Modify `app/Enums/IngredientIdentifierScheme.php`: add `PubchemSid` and `CosingRef`.
- Modify `lang/en/ingredients.php`: identifier labels.
- Modify `lang/en/ingredient_enrichment.php`: confidence, source, stage, and validation copy.
- Modify `lang/en/ingredient_enrichment_admin.php`: UI/action/notification copy.

### Durable provenance and resumable state

- Create `app/Models/IngredientIdentifierEvidence.php` and its factory.
- Create reversible migrations for identifier evidence, market/function provenance, and batch stage/edit/usage state.
- Modify `app/Models/IngredientIdentifier.php`, `IngredientMarketLabel.php`, `IngredientFunction.php`, `IngredientEnrichmentBatch.php`, and `IngredientEnrichmentBatchItem.php`.
- Modify corresponding factories.

### Deterministic source layer

- Create `app/Data/IngredientSourceResponse.php`: normalized cached HTTP response.
- Create `app/Data/IngredientSourceStageResult.php`: serializable stage result.
- Create `app/Services/IngredientEnrichment/Sources/CachedIngredientSourceHttpClient.php`: safe GET/JSON/text, retries, cache keys, and source-call accounting.
- Create `CosingCheckerClient.php`, `EurLexGlossaryClient.php`, `OpenFdaSubstanceClient.php`, and `FdaColourAdditiveClient.php` under the same `Sources` directory.
- Modify `config/ingredient-enrichment.php` and `.env.example` with non-secret endpoints, versions, timeouts, TTLs, and fallback switches.

### Identity and proposal assembly

- Create `IngredientIdentityMatchService.php`: exact candidate scoring and material-difference conflicts.
- Create `IngredientEnrichmentFactsBuilder.php`: merge deterministic source stages into canonical facts, market labels, identifiers, functions, evidence, confidence, and regulatory findings.
- Create `IngredientEnrichmentPipeline.php`: resumable ordered orchestration.
- Create `IngredientEnrichmentStageStore.php`: locked stage persistence and downstream invalidation.

### AI editorial pass

- Create `app/Contracts/IngredientEditorialClient.php`.
- Create `app/Data/IngredientEditorialResponse.php`.
- Create `app/Data/IngredientEnrichmentPipelineResponse.php`.
- Create `IngredientEnrichmentEditorialPrompt.php` and `IngredientEnrichmentEditorialSchema.php`.
- Replace the responsibilities of `OpenAiIngredientResearchClient.php` with a new `OpenAiIngredientEditorialClient.php` that has no tools in the normal path.
- Keep the existing web-search request logic in `OpenAiIngredientGapResearchClient.php`, invoked only by an explicit retry-gaps option.
- Modify `app/Providers/AppServiceProvider.php` binding.
- Remove the superseded `IngredientResearchClient`, `IngredientResearchResponse`, and `OpenAiIngredientResearchClient` only after the pipeline uses the new editorial and pipeline response contracts.

### Validation, planning, apply, and workflow

- Modify the result schema/validator/evidence verifier/planner/applier for schema v2, field confidence, ordinary market labels, and durable provenance.
- Modify `IngredientIdentitySynchronizer`, `IngredientFunctionAssignmentService`, and `IngredientMarketLabelService` to reconcile accepted provenance.
- Modify `ResearchIngredientEnrichmentItem`, the job, batch service, and retry action for resumable stages.
- Create `EditIngredientEnrichmentProposal.php` and `ApproveSafeIngredientEnrichmentBatch.php` actions.

### Filament review experience

- Create `IngredientEnrichmentReviewPresenter.php` and `IngredientEnrichmentProposalForm.php`.
- Create `resources/views/filament/ingredient-enrichment/review-fields.blade.php`.
- Modify the items relation manager, batch view page/infolist, ingredients table copy, and focused Filament tests.

### Fixtures and integration tests

- Create compact fixtures under `tests/Fixtures/IngredientEnrichment/` for CosIng argan/apricot, EUR-Lex glossary rows, openFDA argan/apricot, and FDA colour tables.
- Extend current enrichment feature tests and add focused source/pipeline/provenance/review tests.

## Task 1: Add confidence, source, stage, and identifier vocabulary

**Files:**

- Create: `app/Enums/IngredientEvidenceConfidence.php`
- Create: `app/Enums/IngredientSourceTier.php`
- Create: `app/Enums/IngredientEnrichmentResearchStage.php`
- Modify: `app/Enums/IngredientIdentifierScheme.php`
- Modify: `lang/en/ingredients.php`
- Modify: `lang/en/ingredient_enrichment.php`
- Test: `tests/Feature/IngredientEnrichmentVocabularyTest.php`

- [ ] **Step 1: Create the failing vocabulary test**

```php
<?php

use App\Enums\IngredientEnrichmentResearchStage;
use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientIdentifierScheme;
use App\Enums\IngredientSourceTier;

it('defines stable hybrid enrichment vocabulary', function (): void {
    expect(collect(IngredientEvidenceConfidence::cases())->map->value->all())->toBe([
        'verified', 'supported', 'conflicting', 'unresolved',
    ])->and(collect(IngredientSourceTier::cases())->map->value->all())->toBe([
        'official', 'structured_mirror', 'editorial',
    ])->and(collect(IngredientIdentifierScheme::cases())->map->value->all())->toContain(
        'pubchem_sid', 'cosing_ref',
    )->and(IngredientEnrichmentResearchStage::EuStructured->downstream())->toBe([
        IngredientEnrichmentResearchStage::EuOfficial,
        IngredientEnrichmentResearchStage::UsIdentity,
        IngredientEnrichmentResearchStage::UsDeclaration,
        IngredientEnrichmentResearchStage::ConflictEvaluation,
        IngredientEnrichmentResearchStage::AiEditorial,
        IngredientEnrichmentResearchStage::Validation,
    ]);
});
```

- [ ] **Step 2: Run RED**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentVocabularyTest.php
```

Expected: failure because the three enums and two identifier schemes do not exist.

- [ ] **Step 3: Implement the exact enum contracts**

```php
enum IngredientEvidenceConfidence: string
{
    case Verified = 'verified';
    case Supported = 'supported';
    case Conflicting = 'conflicting';
    case Unresolved = 'unresolved';

    public function label(): string
    {
        return __('ingredient_enrichment.confidence.'.$this->value);
    }
}
```

Use the same string-backed pattern for `IngredientSourceTier`. Implement `IngredientEnrichmentResearchStage::ordered()` and `downstream()` with this order: identity preparation, EU structured, EU official, US identity, US declaration, conflict evaluation, AI editorial, validation.

Add `PubchemSid = 'pubchem_sid'` and `CosingRef = 'cosing_ref'` plus dotted label keys.

- [ ] **Step 4: Run GREEN, format, and commit**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentVocabularyTest.php
vendor/bin/pint --dirty --format agent
git add app/Enums/IngredientEvidenceConfidence.php app/Enums/IngredientSourceTier.php app/Enums/IngredientEnrichmentResearchStage.php app/Enums/IngredientIdentifierScheme.php lang/en/ingredients.php lang/en/ingredient_enrichment.php tests/Feature/IngredientEnrichmentVocabularyTest.php
git diff --cached --check
git commit -m "feat: define enrichment source vocabulary"
```

## Task 2: Persist identifier evidence, market/function provenance, and stage state

**Files:**

- Create: `app/Models/IngredientIdentifierEvidence.php`
- Create: `database/factories/IngredientIdentifierEvidenceFactory.php`
- Create: `database/migrations/2026_08_14_090000_create_ingredient_identifier_evidence_table.php`
- Create: `database/migrations/2026_08_14_090100_add_hybrid_provenance_to_ingredient_market_labels_and_functions.php`
- Create: `database/migrations/2026_08_14_090200_add_hybrid_stage_state_to_ingredient_enrichment_batches.php`
- Modify: `app/Models/IngredientIdentifier.php`
- Modify: `app/Models/IngredientMarketLabel.php`
- Modify: `app/Models/IngredientFunction.php`
- Modify: `app/Models/IngredientEnrichmentBatch.php`
- Modify: `app/Models/IngredientEnrichmentBatchItem.php`
- Modify: `database/factories/IngredientMarketLabelFactory.php`
- Modify: `database/factories/IngredientEnrichmentBatchFactory.php`
- Modify: `database/factories/IngredientEnrichmentBatchItemFactory.php`
- Test: `tests/Feature/IngredientEnrichmentProvenanceSchemaTest.php`

- [ ] **Step 1: Generate the model and migrations**

```bash
php artisan make:model IngredientIdentifierEvidence --factory --no-interaction
php artisan make:migration create_ingredient_identifier_evidence_table --no-interaction
php artisan make:migration add_hybrid_provenance_to_ingredient_market_labels_and_functions --no-interaction
php artisan make:migration add_hybrid_stage_state_to_ingredient_enrichment_batches --no-interaction
```

Rename only the generated migration timestamps to the exact paths above before adding content.

- [ ] **Step 2: Write failing schema/model tests**

Assert:

```php
$identifier = IngredientIdentifier::factory()->create();
$evidence = IngredientIdentifierEvidence::factory()->for($identifier)->create([
    'confidence' => IngredientEvidenceConfidence::Verified,
    'source_tier' => IngredientSourceTier::Official,
]);

expect($identifier->evidence()->first()->is($evidence))->toBeTrue()
    ->and($evidence->confidence)->toBe(IngredientEvidenceConfidence::Verified)
    ->and($evidence->source_tier)->toBe(IngredientSourceTier::Official);
```

Also assert that deleting an identifier cascades its evidence, market labels cast provenance enums/dates, function pivots expose new provenance columns, and batch items cast `research_stages`, `original_result`, and `edited_fields` as arrays. Assert batch/item `structured_source_calls` default to zero.

- [ ] **Step 3: Run RED**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentProvenanceSchemaTest.php
```

- [ ] **Step 4: Implement reversible schema**

`ingredient_identifier_evidence` columns:

```php
$table->id();
$table->foreignId('ingredient_identifier_id')->constrained()->cascadeOnDelete();
$table->string('source_name');
$table->text('source_url');
$table->string('source_tier', 32);
$table->string('confidence', 32);
$table->string('source_version', 100)->nullable();
$table->date('source_updated_at')->nullable();
$table->timestampTz('retrieved_at');
$table->timestampsTz();
$table->unique(['ingredient_identifier_id', 'source_url'], 'ingredient_identifier_evidence_unique');
```

Add the same source tier/confidence/version/update/retrieval columns to `ingredient_market_labels`. Add confidence/source tier/source version/source update date to `ingredient_function_ingredient`; retain its existing source reference and checked date.

Add to items: `research_stages` JSONB default `{}`, `original_result` JSONB nullable, `edited_fields` JSONB nullable, `edited_by_user_id` nullable with `nullOnDelete`, `edited_at` timestamp, and `structured_source_calls` unsigned integer default zero. Add `structured_source_calls` to batches.

Every `down()` must remove foreign keys before columns and drop the evidence table.

- [ ] **Step 5: Implement models/factories and run GREEN**

Use `#[Fillable]`, `casts()`, typed relationships, and existing factory conventions.

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentProvenanceSchemaTest.php tests/Feature/IngredientEnrichmentBatchSchemaTest.php tests/Feature/IngredientMarketLabelTest.php
vendor/bin/pint --dirty --format agent
git add app/Models/IngredientIdentifierEvidence.php database/factories/IngredientIdentifierEvidenceFactory.php database/migrations/2026_08_14_090000_create_ingredient_identifier_evidence_table.php database/migrations/2026_08_14_090100_add_hybrid_provenance_to_ingredient_market_labels_and_functions.php database/migrations/2026_08_14_090200_add_hybrid_stage_state_to_ingredient_enrichment_batches.php app/Models/IngredientIdentifier.php app/Models/IngredientMarketLabel.php app/Models/IngredientFunction.php app/Models/IngredientEnrichmentBatch.php app/Models/IngredientEnrichmentBatchItem.php database/factories/IngredientMarketLabelFactory.php database/factories/IngredientEnrichmentBatchFactory.php database/factories/IngredientEnrichmentBatchItemFactory.php tests/Feature/IngredientEnrichmentProvenanceSchemaTest.php
git diff --cached --check
git commit -m "feat: persist enrichment source provenance"
```

## Task 3: Add cached safe source transport

**Files:**

- Create: `app/Data/IngredientSourceResponse.php`
- Create: `app/Data/IngredientSourceStageResult.php`
- Create: `app/Services/IngredientEnrichment/IngredientSourceException.php`
- Create: `app/Services/IngredientEnrichment/Sources/CachedIngredientSourceHttpClient.php`
- Modify: `config/ingredient-enrichment.php`
- Modify: `.env.example`
- Test: `tests/Feature/IngredientSourceHttpClientTest.php`

- [ ] **Step 1: Write failing HTTP/cache tests**

Use `Http::fake()` and `cache()->flush()`. Assert two identical JSON calls create one HTTP request, a changed source version creates a new request, a 429/5xx is retried, a 404 produces a safe `IngredientSourceException`, and the result records status, body, URL, retrieved time, cache hit, and one source call.

```php
$first = app(CachedIngredientSourceHttpClient::class)->json(
    source: 'cosing_checker',
    url: 'https://cosingchecker.com/api/v1/ingredients/',
    query: ['q' => 'ARGAN OIL', 'per_page' => 20],
    version: 'inventory-2026-03-21',
    ttl: now()->addDays(30),
);
$second = app(CachedIngredientSourceHttpClient::class)->json(/* same arguments */);

Http::assertSentCount(1);
expect($first->cacheHit)->toBeFalse()->and($second->cacheHit)->toBeTrue();
```

- [ ] **Step 2: Run RED and implement immutable DTOs**

```bash
php artisan test --compact tests/Feature/IngredientSourceHttpClientTest.php
```

`IngredientSourceResponse` contains `payload`, `url`, `retrievedAt`, `cacheHit`, and `sourceCalls`. `IngredientSourceStageResult` contains stage, status, normalized data, evidence, warnings, unresolved questions, source calls, and `toArray()/fromArray()`.

- [ ] **Step 3: Implement fixed-host cached GET behavior**

Use Laravel `Http::acceptJson()`, connect timeout, timeout, and the existing retry predicate. Generate cache keys from source, normalized URL/query, declared source version, and `response_shape_version`. Read the cache key first so the returned DTO can report a cache hit; on a miss, fetch and `cache()->put()` the normalized payload for the configured TTL. Never cache failures. Validate the requested host against the configured host for that source before sending.

Add configuration:

```php
'sources' => [
    'cosing_checker' => [
        'base_url' => env('COSING_CHECKER_BASE_URL', 'https://cosingchecker.com/api/v1'),
        'inventory_version' => '2026-03-21',
        'ttl_days' => 30,
    ],
    'eur_lex_glossary' => [
        'url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175',
        'celex' => '32025D1175',
        'ttl_days' => 30,
    ],
    'open_fda' => [
        'base_url' => env('OPENFDA_BASE_URL', 'https://api.fda.gov/other/substance.json'),
        'ttl_days' => 30,
    ],
    'fda_colours' => [
        'url' => 'https://www.fda.gov/cosmetics/cosmetic-ingredient-names/color-additives-permitted-use-cosmetics',
        'ttl_days' => 7,
    ],
],
```

- [ ] **Step 4: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/IngredientSourceHttpClientTest.php
vendor/bin/pint --dirty --format agent
git add app/Data/IngredientSourceResponse.php app/Data/IngredientSourceStageResult.php app/Services/IngredientEnrichment/IngredientSourceException.php app/Services/IngredientEnrichment/Sources/CachedIngredientSourceHttpClient.php config/ingredient-enrichment.php .env.example tests/Feature/IngredientSourceHttpClientTest.php
git diff --cached --check
git commit -m "feat: add cached ingredient source transport"
```

## Task 4: Implement the CosIng Checker inventory adapter

**Files:**

- Create: `app/Services/IngredientEnrichment/Sources/CosingCheckerClient.php`
- Create: `tests/Fixtures/IngredientEnrichment/cosing-argan.json`
- Create: `tests/Fixtures/IngredientEnrichment/cosing-apricot.json`
- Test: `tests/Feature/CosingCheckerClientTest.php`

- [ ] **Step 1: Commit compact source fixtures and failing tests**

Fixtures retain only documented response keys: `count`, `results[].slug`, `ref_number`, `inci_name`, `cas_number`, `ec_number`, `description`, `restriction`, `function`, and `update_date`.

Test exact outputs:

```php
$result = app(CosingCheckerClient::class)->lookup([
    'display_name' => 'Argan oil',
    'inci_name' => null,
    'identifiers' => [],
]);

expect($result->data['candidates'][0])->toMatchArray([
    'cosing_ref' => '54495',
    'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
    'cas' => ['223747-87-3', '299184-75-1'],
    'ec' => [],
    'functions' => ['skin_conditioning', 'skin_conditioning_emollient'],
])->and($result->data['candidates'][0]['confidence'])->toBe('supported');
```

Apricot must split `68650-44-2 / 72869-69-3`, keep EC `272-046-1`, ignore `-`, map `FRAGRANCE, SKIN CONDITIONING`, and retain ref `78931`.

- [ ] **Step 2: Run RED**

```bash
php artisan test --compact tests/Feature/CosingCheckerClientTest.php
```

- [ ] **Step 3: Implement deterministic lookup and normalization**

Query strongest signals in order: exact current INCI, primary CAS/EC, then display name. Stop after an exact normalized INCI or exact identifier match; otherwise return all candidates for conflict evaluation. Limit each query to 20.

Split CAS/EC with `/\s*[;\/]\s*/u`, discard blank/`-`, normalize duplicates, and map function display names to active `IngredientFunction` keys using an uppercase canonical name index. Unknown function text becomes an unresolved warning; never invent a key.

Evidence uses the record page `https://cosingchecker.com/ingredients/{slug}/`, tier `structured_mirror`, confidence `supported`, source version `inventory-2026-03-21`, and the API retrieval timestamp.

- [ ] **Step 4: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/CosingCheckerClientTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/Sources/CosingCheckerClient.php tests/Fixtures/IngredientEnrichment/cosing-argan.json tests/Fixtures/IngredientEnrichment/cosing-apricot.json tests/Feature/CosingCheckerClientTest.php
git diff --cached --check
git commit -m "feat: read structured cosing candidates"
```

## Task 5: Implement official EUR-Lex glossary corroboration

**Files:**

- Create: `app/Services/IngredientEnrichment/Sources/EurLexGlossaryClient.php`
- Create: `tests/Fixtures/IngredientEnrichment/eur-lex-glossary.html`
- Test: `tests/Feature/EurLexGlossaryClientTest.php`

- [ ] **Step 1: Add a compact official-table fixture and failing tests**

The fixture contains two rows with sequence number plus common ingredient name. Assert exact normalized matches upgrade only `inci_name`/EU declaration evidence to verified, while a name absent from the glossary remains supported and carries no fake official evidence.

```php
$result = app(EurLexGlossaryClient::class)->verify([
    'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
]);

expect($result->data['matched'])->toBeTrue()
    ->and($result->data['common_ingredient_name'])->toBe('ARGANIA SPINOSA KERNEL OIL')
    ->and($result->evidence[0]['confidence'])->toBe('verified')
    ->and($result->evidence[0]['source_version'])->toBe('32025D1175');
```

- [ ] **Step 2: Run RED and implement the parser**

```bash
php artisan test --compact tests/Feature/EurLexGlossaryClientTest.php
```

Fetch the configured HTML through cached transport. Parse with `DOMDocument::loadHTML()` and `DOMXPath('//table//tr[td]')`; take the final cell text as the name, normalize whitespace/case for the lookup key, and preserve exact official display text. Cache the parsed name map separately under CELEX plus parser version so a 10 MB document is parsed once.

Official evidence URL is the configured EUR-Lex CELEX URL, tier `official`, confidence `verified`, version `32025D1175`, and retrieval timestamp from the cached response.

- [ ] **Step 3: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/EurLexGlossaryClientTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/Sources/EurLexGlossaryClient.php tests/Fixtures/IngredientEnrichment/eur-lex-glossary.html tests/Feature/EurLexGlossaryClientTest.php
git diff --cached --check
git commit -m "feat: verify eu ingredient glossary names"
```

## Task 6: Implement FDA GSRS/openFDA identity and US declaration candidates

**Files:**

- Create: `app/Services/IngredientEnrichment/Sources/OpenFdaSubstanceClient.php`
- Create: `app/Services/IngredientEnrichment/UsIngredientDeclarationService.php`
- Create: `tests/Fixtures/IngredientEnrichment/openfda-argan.json`
- Create: `tests/Fixtures/IngredientEnrichment/openfda-apricot.json`
- Test: `tests/Feature/OpenFdaSubstanceClientTest.php`
- Test: `tests/Feature/UsIngredientDeclarationServiceTest.php`

- [ ] **Step 1: Add exact identity fixtures and failing tests**

Assert argan produces UNII `4V59G5UW9X`, common/display name `ARGAN OIL`, INCI synonym `ARGANIA SPINOSA KERNEL OIL`, and no invented CAS. Assert apricot produces UNII `54JB35T06A` and CAS `72869-69-3` only when the response codes actually include it.

```php
$candidate = app(OpenFdaSubstanceClient::class)->lookup([
    'display_name' => 'Argan oil',
    'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
    'identifiers' => [],
])->data['candidates'][0];

expect($candidate['unii'])->toBe('4V59G5UW9X')
    ->and($candidate['common_name'])->toBe('ARGAN OIL')
    ->and($candidate['inci_names'])->toContain('ARGANIA SPINOSA KERNEL OIL');
```

US declaration tests assert `ARGAN OIL` is a supported proposal with FDA naming-rule evidence, not a verified label and not the UNII. A CI-only value must not be returned for a colourant.

- [ ] **Step 2: Run RED and implement query behavior**

```bash
php artisan test --compact tests/Feature/OpenFdaSubstanceClientTest.php tests/Feature/UsIngredientDeclarationServiceTest.php
```

Use openFDA queries exactly as documented:

```php
['search' => 'names.name:"'.str_replace('"', '', $name).'"', 'limit' => 20]
['search' => 'codes.code:"'.$identifier.'"', 'limit' => 20]
['search' => 'unii:"'.$unii.'"', 'limit' => 20]
```

Read top-level `unii`, English `names`, `display_name`, `preferred`, `name_orgs`, and only recognized code systems (`CAS`, `PUBCHEM`, `FDA UNII`). Preserve unknown codes only in diagnostic stage data.

`UsIngredientDeclarationService` selects an exact English common/display name after identity matching. It attaches the FDA 21 CFR 701.3 naming guidance as official rule evidence while leaving the field confidence supported unless an exact FDA colour regulation supplies the declaration.

- [ ] **Step 3: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/OpenFdaSubstanceClientTest.php tests/Feature/UsIngredientDeclarationServiceTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/Sources/OpenFdaSubstanceClient.php app/Services/IngredientEnrichment/UsIngredientDeclarationService.php tests/Fixtures/IngredientEnrichment/openfda-argan.json tests/Fixtures/IngredientEnrichment/openfda-apricot.json tests/Feature/OpenFdaSubstanceClientTest.php tests/Feature/UsIngredientDeclarationServiceTest.php
git diff --cached --check
git commit -m "feat: resolve fda ingredient identities"
```

## Task 7: Implement the dedicated FDA colour lookup

**Files:**

- Create: `app/Services/IngredientEnrichment/Sources/FdaColourAdditiveClient.php`
- Create: `tests/Fixtures/IngredientEnrichment/fda-colours.html`
- Test: `tests/Feature/FdaColourAdditiveClientTest.php`

- [ ] **Step 1: Add compact table fixtures and failing tests**

Include one certification-exempt colour and one certified colour table. Assert parsed output contains FDA declaration name, certification class, eye/general/external permissions, limitations, and exact CFR section link.

```php
$result = app(FdaColourAdditiveClient::class)->lookup([
    'ci' => 'CI 19140',
    'names' => ['TARTRAZINE', 'YELLOW 5'],
]);

expect($result->data['matches'][0])->toMatchArray([
    'declaration_name' => 'FD&C Yellow No. 5',
    'certification_required' => true,
    'eye_area' => true,
    'generally' => true,
    'external_use' => true,
]);
```

Also assert an unmatched CI/name combination returns unresolved and never guesses from CI.

- [ ] **Step 2: Run RED and implement robust table parsing**

```bash
php artisan test --compact tests/Feature/FdaColourAdditiveClientTest.php
```

Parse the official page with DOMXPath. Determine certification class from the preceding table heading, map cells by normalized header text rather than column position, and retain the linked CFR URL. Match only exact normalized FDA/common/chemical names supplied by deterministic EU identity facts. CI alone is insufficient because the FDA page does not provide a universal CI crosswalk.

Return verified official evidence for exact matches. Return unresolved when no exact match exists so an Admin may run controlled gap research.

- [ ] **Step 3: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/FdaColourAdditiveClientTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/Sources/FdaColourAdditiveClient.php tests/Fixtures/IngredientEnrichment/fda-colours.html tests/Feature/FdaColourAdditiveClientTest.php
git diff --cached --check
git commit -m "feat: resolve official us colour declarations"
```

## Task 8: Match identities, detect conflicts, and assemble deterministic facts

**Files:**

- Create: `app/Services/IngredientEnrichment/IngredientIdentityMatchService.php`
- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentFactsBuilder.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentInputBuilder.php`
- Test: `tests/Feature/IngredientIdentityMatchServiceTest.php`
- Test: `tests/Feature/IngredientEnrichmentFactsBuilderTest.php`

- [ ] **Step 1: Write failing exact-match and conflict tests**

Cover:

- exact INCI plus shared identifier selects one candidate;
- display-name-only fuzzy similarity cannot produce verified/supported identity;
- kernel oil versus kernel oil unsaponifiables conflicts;
- hydrogenated versus unhydrogenated conflicts;
- different plant part, chemical form, or hydration state conflicts;
- official/mirror exact-name disagreement chooses the official name and records a discrepancy;
- multiple CAS values remain separate identifier rows;
- EU and US market labels remain separate.

```php
$facts = app(IngredientEnrichmentFactsBuilder::class)->build(
    record: $record,
    euStructured: IngredientSourceStageResult::fromArray($eu),
    euOfficial: IngredientSourceStageResult::fromArray($official),
    usIdentity: IngredientSourceStageResult::fromArray($us),
    usDeclaration: IngredientSourceStageResult::fromArray($declaration),
);

expect(data_get($facts, 'proposal.identifiers'))->toContainEqual([
    'scheme' => 'unii',
    'value' => '4V59G5UW9X',
    'is_primary' => true,
])
    ->and(data_get($facts, 'proposal.market_labels.0.market_code'))->toBe('eu')
    ->and(data_get($facts, 'proposal.market_labels.1.market_code'))->toBe('us');
```

- [ ] **Step 2: Run RED and implement identity rules**

```bash
php artisan test --compact tests/Feature/IngredientIdentityMatchServiceTest.php tests/Feature/IngredientEnrichmentFactsBuilderTest.php
```

Normalize Unicode whitespace and case but preserve exact display values. Score exact INCI and exact identifier matches only. Use explicit material-difference tokens (`hydrogenated`, `unsaponifiables`, `extract`, `oil`, plant part, salt/hydrate terms) to reject merges; do not use probabilistic similarity to approve identity.

The facts builder returns a schema-v2 proposal base containing canonical identity, category/subcategory preservation, identifiers, CosIng functions, EU/US market labels, regulatory findings, field evidence with source tier/confidence/version/retrieval, warnings, unresolved questions, and an item confidence derived from the lowest material field.

- [ ] **Step 3: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/IngredientIdentityMatchServiceTest.php tests/Feature/IngredientEnrichmentFactsBuilderTest.php tests/Feature/IngredientEnrichmentInputBuilderTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/IngredientIdentityMatchService.php app/Services/IngredientEnrichment/IngredientEnrichmentFactsBuilder.php app/Services/IngredientEnrichment/IngredientEnrichmentInputBuilder.php tests/Feature/IngredientIdentityMatchServiceTest.php tests/Feature/IngredientEnrichmentFactsBuilderTest.php
git diff --cached --check
git commit -m "feat: assemble source-backed ingredient facts"
```

## Task 9: Reduce OpenAI to one editorial and translation pass

**Files:**

- Create: `app/Contracts/IngredientEditorialClient.php`
- Create: `app/Data/IngredientEditorialResponse.php`
- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentEditorialPrompt.php`
- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentEditorialSchema.php`
- Create: `app/Services/IngredientEnrichment/OpenAiIngredientEditorialClient.php`
- Create: `app/Services/IngredientEnrichment/OpenAiIngredientGapResearchClient.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `config/ingredient-enrichment.php`
- Modify: `tests/Feature/IngredientEnrichmentResearchPromptTest.php`
- Modify: `tests/Feature/OpenAiIngredientResearchClientTest.php`

- [ ] **Step 1: Rewrite tests around the editorial-only contract**

Assert the normal request has no `tools`, no `include` for web-search sources, and a strict schema containing only:

```php
[
    'display_name',
    'saponification_name',
    'info_markdown',
    'soapmaking_relevant',
    'translations',
    'warnings',
    'unresolved_questions',
]
```

Assert the prompt includes deterministic INCI, identifiers, functions, EU/US declarations, field confidence, and evidence; it forbids changing those structured facts. Assert translations preserve headings and introduce no new claims.

Add a separate test proving the gap client includes web search and the existing allowed-domain filter only when explicitly called.

- [ ] **Step 2: Run RED**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentResearchPromptTest.php tests/Feature/OpenAiIngredientResearchClientTest.php
```

- [ ] **Step 3: Implement the editorial interface and strict response parser**

```php
interface IngredientEditorialClient
{
    /** @param array<string, mixed> $facts */
    public function edit(array $facts): IngredientEditorialResponse;
}
```

`OpenAiIngredientEditorialClient` reuses the safe HTTP/retry/error parsing patterns from the current client, but omits web-search tools. It returns editorial values, model/request IDs, input/output tokens, and zero web calls.

`OpenAiIngredientGapResearchClient` retains the existing source-restricted web-search behavior behind `ingredient-enrichment.gap_research.enabled`, default false. It returns candidate evidence only; it never bypasses deterministic conflict evaluation. Keep the existing `IngredientResearchClient` binding temporarily so the application remains runnable until Task 10 switches the job to the pipeline.

Add `cosmileeurope.eu` to the gap-research allow-list for individually cited, paraphrased editorial guidance only. The gap client and validator must reject COSMILE evidence for legal declarations, authorization, identifiers, or verified CosIng assignments.

- [ ] **Step 4: Bind the editorial interface, run GREEN, and commit**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentResearchPromptTest.php tests/Feature/OpenAiIngredientResearchClientTest.php
vendor/bin/pint --dirty --format agent
git add app/Contracts/IngredientEditorialClient.php app/Data/IngredientEditorialResponse.php app/Services/IngredientEnrichment/IngredientEnrichmentEditorialPrompt.php app/Services/IngredientEnrichment/IngredientEnrichmentEditorialSchema.php app/Services/IngredientEnrichment/OpenAiIngredientEditorialClient.php app/Services/IngredientEnrichment/OpenAiIngredientGapResearchClient.php app/Providers/AppServiceProvider.php config/ingredient-enrichment.php tests/Feature/IngredientEnrichmentResearchPromptTest.php tests/Feature/OpenAiIngredientResearchClientTest.php
git diff --cached --check
git commit -m "refactor: limit ai enrichment to editorial work"
```

## Task 10: Add resumable pipeline stages and targeted retry

**Files:**

- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentStageStore.php`
- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentPipeline.php`
- Create: `app/Data/IngredientEnrichmentPipelineResponse.php`
- Delete: `app/Contracts/IngredientResearchClient.php`
- Delete: `app/Data/IngredientResearchResponse.php`
- Delete: `app/Services/IngredientEnrichment/OpenAiIngredientResearchClient.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Services/IngredientEnrichment/ResearchIngredientEnrichmentItem.php`
- Modify: `app/Jobs/ResearchIngredientEnrichment.php`
- Modify: `app/Actions/IngredientEnrichment/RetryIngredientEnrichmentFailures.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php`
- Modify: `app/Models/IngredientEnrichmentBatch.php`
- Modify: `app/Models/IngredientEnrichmentBatchItem.php`
- Test: `tests/Feature/IngredientEnrichmentPipelineTest.php`
- Modify: `tests/Feature/ResearchIngredientEnrichmentJobTest.php`

- [ ] **Step 1: Write failing stage persistence tests**

Assert:

- stages run in enum order;
- each completed stage is persisted before the next source call;
- a US-source failure retains completed EU stages;
- retry skips completed EU stages and reruns US identity plus downstream stages;
- a warning item with an unresolved stage is eligible for retry gaps even though it did not fail;
- invalidating EU structured removes all downstream stage results;
- AI is not called before conflict evaluation;
- source calls aggregate independently from web searches and tokens;
- stale ingredient fingerprints stop before all sources.

```php
$pipeline->run($item->id);

expect(array_keys($item->fresh()->research_stages))->toBe([
    'identity_preparation', 'eu_structured', 'eu_official', 'us_identity',
    'us_declaration', 'conflict_evaluation', 'ai_editorial', 'validation',
]);
```

- [ ] **Step 2: Run RED and implement locked stage storage**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentPipelineTest.php tests/Feature/ResearchIngredientEnrichmentJobTest.php
```

`IngredientEnrichmentStageStore::complete()`, `fail()`, and `invalidateFrom()` each lock the item in a five-attempt transaction. Store normalized stage arrays only; never provider secrets or full raw HTML.

- [ ] **Step 3: Implement pipeline orchestration**

`IngredientEnrichmentPipeline::run(int $itemId, bool $allowGapResearch = false): IngredientEnrichmentPipelineResponse` loads saved stages, runs only incomplete stages, persists after every stage, composes deterministic facts, calls the editorial client, validates the final result, and returns the normalized result plus provider metadata, tokens, web-search count, and structured-source count.

Refactor `ResearchIngredientEnrichmentItem` so its initial stale/status transaction remains, the pipeline runs outside transactions, and final validation/planning persistence remains locked. On the first successful finalization, write the normalized result to both `original_result` and `result`; later proposal edits change only `result`. Failure code format is `source_{stage}_{safe_code}` or `provider_{safe_code}`.

Remove the superseded all-in-one research contract, response DTO, client, and service-provider binding only after this refactor compiles. Bind only `IngredientEditorialClient` to `OpenAiIngredientEditorialClient`.

`RetryIngredientEnrichmentFailures::handle()` accepts `bool $allowGapResearch = false`, selects failed items plus warning items that contain an unresolved stage, invalidates that stage and its downstream stages, resets them to pending without clearing earlier completed stages, and passes the flag in the queued job. Add a `retryableFromStage()` decision from stored stage status rather than rerunning everything.

- [ ] **Step 4: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentPipelineTest.php tests/Feature/ResearchIngredientEnrichmentJobTest.php tests/Feature/StartIngredientEnrichmentBatchTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/IngredientEnrichmentStageStore.php app/Services/IngredientEnrichment/IngredientEnrichmentPipeline.php app/Data/IngredientEnrichmentPipelineResponse.php app/Contracts/IngredientResearchClient.php app/Data/IngredientResearchResponse.php app/Services/IngredientEnrichment/OpenAiIngredientResearchClient.php app/Providers/AppServiceProvider.php app/Services/IngredientEnrichment/ResearchIngredientEnrichmentItem.php app/Jobs/ResearchIngredientEnrichment.php app/Actions/IngredientEnrichment/RetryIngredientEnrichmentFailures.php app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php app/Models/IngredientEnrichmentBatch.php app/Models/IngredientEnrichmentBatchItem.php tests/Feature/IngredientEnrichmentPipelineTest.php tests/Feature/ResearchIngredientEnrichmentJobTest.php
git diff --cached --check
git commit -m "feat: resume ingredient research by stage"
```

## Task 11: Validate schema v2 and persist accepted provenance on apply

**Files:**

- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentResultSchema.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentEvidenceVerifier.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentPlanner.php`
- Modify: `app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php`
- Modify: `app/Services/IngredientIdentitySynchronizer.php`
- Modify: `app/Services/IngredientFunctionAssignmentService.php`
- Modify: `app/Services/IngredientMarketLabelService.php`
- Modify: `app/Models/Ingredient.php`
- Modify: `config/ingredient-enrichment.php`
- Test: `tests/Feature/IngredientEnrichmentResultSchemaTest.php`
- Modify: `tests/Feature/IngredientEnrichmentValidationTest.php`
- Modify: `tests/Feature/PlatformIngredientEnrichmentImportTest.php`
- Modify: `tests/Feature/IngredientIdentitySynchronizerTest.php`
- Modify: `tests/Feature/IngredientFunctionAssignmentTest.php`
- Modify: `tests/Feature/IngredientMarketLabelTest.php`

- [ ] **Step 1: Add failing schema-v2 and provenance tests**

Require every source-backed row/evidence entry to contain:

```php
[
    'source_name', 'source_url', 'source_tier', 'confidence',
    'source_version', 'source_updated_at', 'retrieved_at',
]
```

Require `field_confidence` keyed by exact proposal field path and `regulatory_findings` as non-applied review data. Reject unknown source tiers/confidences, malformed timestamps, supported values falsely marked official/verified, and conflicting/unresolved required fields from safe approval.

For safe approval, required paths are the matched canonical `proposal.inci_name`, EU and US declaration rows, every returned identifier/function row, English editorial fields, and every configured translation field. A source explicitly returning no optional identifier or no CosIng function does not itself block safe approval; an unresolved identity match or unresolved supported-market declaration does.

Apply tests must prove identifier evidence is reconciled, function provenance persists, ordinary EU/US market labels apply, secondary CAS/EC values survive replacement, unchanged plans write nothing, and reapply is idempotent.

- [ ] **Step 2: Run RED**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentResultSchemaTest.php tests/Feature/IngredientEnrichmentValidationTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php tests/Feature/IngredientIdentitySynchronizerTest.php tests/Feature/IngredientFunctionAssignmentTest.php tests/Feature/IngredientMarketLabelTest.php
```

- [ ] **Step 3: Implement schema/validator/evidence changes**

Bump `schema_version` to 2. The evidence verifier no longer requires a model-returned URL to appear in OpenAI consulted sources. It instead validates exact allowed hosts by source tier, requires adapter provenance, and rejects a mirror URL marked official.

Update the planner decision rows to include `confidence`, `evidence`, and `warnings` for the exact field. Preserve current data unless replacement is explicit.

- [ ] **Step 4: Extend domain services and apply**

`IngredientIdentitySynchronizer` accepts optional evidence grouped by normalized scheme/value and reconciles `IngredientIdentifierEvidence` after identifiers exist. `IngredientFunctionAssignmentService` accepts the new provenance values. `IngredientMarketLabelService` removes the colourant-only assertion while retaining platform-only, one-per-market, date, and US-bare-CI validation.

`ApplyPlatformIngredientEnrichment` passes only the planner's effective accepted rows to those services and retains result fingerprint/idempotency behavior. Regulatory findings remain in the batch audit and are not written as compliance rules.

- [ ] **Step 5: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentResultSchemaTest.php tests/Feature/IngredientEnrichmentValidationTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php tests/Feature/IngredientIdentitySynchronizerTest.php tests/Feature/IngredientFunctionAssignmentTest.php tests/Feature/IngredientMarketLabelTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/IngredientEnrichmentResultSchema.php app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php app/Services/IngredientEnrichment/IngredientEnrichmentEvidenceVerifier.php app/Services/IngredientEnrichment/IngredientEnrichmentPlanner.php app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php app/Services/IngredientIdentitySynchronizer.php app/Services/IngredientFunctionAssignmentService.php app/Services/IngredientMarketLabelService.php app/Models/Ingredient.php config/ingredient-enrichment.php tests/Feature/IngredientEnrichmentResultSchemaTest.php tests/Feature/IngredientEnrichmentValidationTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php tests/Feature/IngredientIdentitySynchronizerTest.php tests/Feature/IngredientFunctionAssignmentTest.php tests/Feature/IngredientMarketLabelTest.php
git diff --cached --check
git commit -m "feat: apply field-level enrichment provenance"
```

## Task 12: Resolve ordinary EU/US declarations without unsafe fallback

**Files:**

- Modify: `app/Services/IngredientDeclarationNameResolver.php`
- Modify: `app/Services/InciGenerationService.php`
- Modify: `app/Filament/Resources/Ingredients/Pages/EditIngredient.php`
- Modify: `app/Services/IngredientMarketLabelService.php`
- Modify: `lang/en/ingredient_admin.php`
- Modify: `tests/Feature/IngredientDeclarationNameResolverTest.php`
- Modify: `tests/Feature/Filament/IngredientMarketLabelsActionTest.php`

- [ ] **Step 1: Add failing ordinary-ingredient declaration tests**

Assert:

- explicit EU and US rows resolve for a non-colourant;
- a missing US row blocks market-adapted output;
- a missing EU ordinary row may use canonical INCI only when the caller explicitly selects legacy fallback and the output is marked non-verified;
- colour EU CI fallback remains;
- colour US bare CI remains invalid;
- Admin market-label editing works for every platform ingredient and remains unavailable for private ingredients.

- [ ] **Step 2: Run RED and implement explicit resolver semantics**

```bash
php artisan test --compact tests/Feature/IngredientDeclarationNameResolverTest.php tests/Feature/Filament/IngredientMarketLabelsActionTest.php
```

Use this public signature:

```php
public function resolve(
    Ingredient $ingredient,
    string $marketCode,
    ?CarbonImmutable $onDate = null,
    bool $allowLegacyEuFallback = false,
): ?string;
```

Always validate/eager-load market labels before category branching. Remove the service's colourant-only assertion and rename UI copy from “Market colour labels” to “Market declaration names.” Keep the colour-specific CI checks.

- [ ] **Step 3: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/IngredientDeclarationNameResolverTest.php tests/Feature/Filament/IngredientMarketLabelsActionTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
git add app/Services/IngredientDeclarationNameResolver.php app/Services/InciGenerationService.php app/Filament/Resources/Ingredients/Pages/EditIngredient.php app/Services/IngredientMarketLabelService.php lang/en/ingredient_admin.php tests/Feature/IngredientDeclarationNameResolverTest.php tests/Feature/Filament/IngredientMarketLabelsActionTest.php
git diff --cached --check
git commit -m "feat: resolve declarations for eu and us"
```

## Task 13: Add proposal editing and safe batch approval actions

**Files:**

- Create: `app/Actions/IngredientEnrichment/EditIngredientEnrichmentProposal.php`
- Create: `app/Actions/IngredientEnrichment/ApproveSafeIngredientEnrichmentBatch.php`
- Create: `app/Filament/Resources/IngredientEnrichmentBatches/Schemas/IngredientEnrichmentProposalForm.php`
- Modify: `app/Actions/IngredientEnrichment/ApproveIngredientEnrichmentItem.php`
- Modify: `app/Models/IngredientEnrichmentBatchItem.php`
- Modify: `lang/en/ingredient_enrichment_admin.php`
- Test: `tests/Feature/IngredientEnrichmentProposalEditingTest.php`
- Modify: `tests/Feature/IngredientEnrichmentBatchReviewTest.php`

- [ ] **Step 1: Write failing edit/audit/safe-approval tests**

Assert editing:

- preserves the untouched researched result already stored in `original_result`;
- updates only allowed proposal fields;
- stores sorted unique `edited_fields`, actor, and timestamp;
- revalidates and replans under the current fingerprint;
- cannot edit an applied/stale/private item;
- cannot erase required evidence for a source-backed field.

Assert safe approval approves ready/warning-free items whose required fields are verified/supported, excludes conflicts/unresolved/validation failures, records the actor, and returns exact approved/skipped counts.

- [ ] **Step 2: Run RED and implement the edit action**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentProposalEditingTest.php tests/Feature/IngredientEnrichmentBatchReviewTest.php
```

Use a locked five-attempt transaction. Accept a normalized proposal array from the Filament form, merge only exact allow-listed paths, set `original_result` only when null, re-run validator/planner, and return the item in ready/warning state rather than auto-approving.

The Filament proposal form includes canonical scalar fields, category/subcategory selects, guidance, identifier repeater, CosIng function selector, translation repeater, and market-label repeater. Source-backed rows require source/provenance fields; translations do not.

- [ ] **Step 3: Implement safe batch approval**

Use `ApproveIngredientEnrichmentItem` for each eligible item so fingerprint, validation, plan, and authorization rules stay centralized. Eligibility requires no item warnings, no `conflicting`/`unresolved` required field, and ready status. Return `['approved' => int, 'skipped' => int]`.

- [ ] **Step 4: Run GREEN and commit**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentProposalEditingTest.php tests/Feature/IngredientEnrichmentBatchReviewTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
git add app/Actions/IngredientEnrichment/EditIngredientEnrichmentProposal.php app/Actions/IngredientEnrichment/ApproveSafeIngredientEnrichmentBatch.php app/Filament/Resources/IngredientEnrichmentBatches/Schemas/IngredientEnrichmentProposalForm.php app/Actions/IngredientEnrichment/ApproveIngredientEnrichmentItem.php app/Models/IngredientEnrichmentBatchItem.php lang/en/ingredient_enrichment_admin.php tests/Feature/IngredientEnrichmentProposalEditingTest.php tests/Feature/IngredientEnrichmentBatchReviewTest.php
git diff --cached --check
git commit -m "feat: edit and safely approve enrichment proposals"
```

## Task 14: Replace raw proposal dumps with field-level review UI

**Files:**

- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentReviewPresenter.php`
- Create: `resources/views/filament/ingredient-enrichment/review-fields.blade.php`
- Modify: `app/Filament/Resources/IngredientEnrichmentBatches/RelationManagers/ItemsRelationManager.php`
- Modify: `app/Filament/Resources/IngredientEnrichmentBatches/Pages/ViewIngredientEnrichmentBatch.php`
- Modify: `app/Filament/Resources/IngredientEnrichmentBatches/Schemas/IngredientEnrichmentBatchInfolist.php`
- Modify: `app/Filament/Resources/Ingredients/Tables/IngredientsTable.php`
- Modify: `lang/en/ingredient_enrichment_admin.php`
- Modify: `tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php`
- Modify: `tests/Feature/Filament/IngredientEnrichmentBulkActionTest.php`

- [ ] **Step 1: Write failing presenter and Livewire assertions**

Assert review rows include path, label, current, proposed, decision, confidence, evidence links, source tier, version/retrieval date, and conflict explanation. Assert rendered HTML shows `Verified`/`Supported`, exact source title/link, and current/proposed values; raw consulted sources are in a collapsed diagnostic section.

Assert batch header actions include `Approve safe batch`, `Retry gaps`, and `Apply approved`. Retry gaps exposes an optional “Use restricted web research” toggle only when configuration enables it.

- [ ] **Step 2: Run RED and implement the presenter/view**

```bash
php artisan test --compact tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php tests/Feature/Filament/IngredientEnrichmentBulkActionTest.php
```

`IngredientEnrichmentReviewPresenter::rows(IngredientEnrichmentBatchItem $item): array` joins plan decisions to `field_confidence` and evidence by exact path. The Blade view escapes text, permits only validated HTTP/HTTPS evidence URLs, renders badges, and never renders raw provider payloads.

- [ ] **Step 3: Wire Filament actions and metrics**

Replace `KeyValueEntry` proposal dumps with a view entry using the presenter. Add edit and approve actions per item, safe approval and retry-gaps header actions, structured-source-call metrics, and clearer bulk-action copy: structured EU/FDA lookup first, one paid editorial/translation call second.

- [ ] **Step 4: Run Filament checks, GREEN, and commit**

```bash
php artisan test --compact tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php tests/Feature/Filament/IngredientEnrichmentBulkActionTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
php artisan test --compact tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php tests/Feature/Filament/IngredientEnrichmentBulkActionTest.php
git add app/Services/IngredientEnrichment/IngredientEnrichmentReviewPresenter.php resources/views/filament/ingredient-enrichment/review-fields.blade.php app/Filament/Resources/IngredientEnrichmentBatches/RelationManagers/ItemsRelationManager.php app/Filament/Resources/IngredientEnrichmentBatches/Pages/ViewIngredientEnrichmentBatch.php app/Filament/Resources/IngredientEnrichmentBatches/Schemas/IngredientEnrichmentBatchInfolist.php app/Filament/Resources/Ingredients/Tables/IngredientsTable.php lang/en/ingredient_enrichment_admin.php tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php tests/Feature/Filament/IngredientEnrichmentBulkActionTest.php
git diff --cached --check
git commit -m "feat: review enrichment evidence by field"
```

## Task 15: Prove argan, apricot, colour, failure, retry, and apply end to end

**Files:**

- Create: `tests/Feature/HybridIngredientEnrichmentPipelineTest.php`
- Modify: `tests/Feature/ResearchIngredientEnrichmentJobTest.php`
- Modify: `tests/Feature/IngredientEnrichmentBatchReviewTest.php`
- Modify: `tests/Feature/PlatformIngredientEnrichmentImportTest.php`
- Modify: `tests/Feature/IngredientEnrichmentResearchPromptTest.php`

- [ ] **Step 1: Add end-to-end argan and apricot tests with only faked HTTP**

Argan assertions:

- CosIng ref `54495`;
- CAS `223747-87-3` and `299184-75-1` retained;
- UNII `4V59G5UW9X` retained;
- EU declaration `ARGANIA SPINOSA KERNEL OIL` verified by EUR-Lex;
- US declaration `ARGAN OIL` supported, not falsely verified;
- CosIng functions mapped;
- one editorial call, zero normal web searches.

Apricot assertions:

- CosIng ref `78931`;
- CAS `68650-44-2` and `72869-69-3`, EC `272-046-1`, UNII `54JB35T06A` retained;
- exact kernel oil is not confused with unsaponifiables;
- EU/US declarations remain distinct;
- functions are source-backed.

- [ ] **Step 2: Add partial failure/retry and colour tests**

Simulate openFDA failure after EU success, assert retained EU stages, retry only the failed/downstream stages, then approve/apply. Add a colour fixture proving EU CI and FDA declaration differ, a bare CI fails US, certification state remains diagnostic, and no compliance authorization row is created.

- [ ] **Step 3: Run focused integration suite and fix only demonstrated failures**

```bash
php artisan test --compact tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/ResearchIngredientEnrichmentJobTest.php tests/Feature/IngredientEnrichmentBatchReviewTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php tests/Feature/IngredientEnrichmentResearchPromptTest.php
```

Expected: all pass with no external network calls.

- [ ] **Step 4: Run the full enrichment regression suite**

```bash
php artisan test --compact --filter=IngredientEnrichment
php artisan test --compact tests/Feature/IngredientDeclarationNameResolverTest.php tests/Feature/IngredientIdentitySynchronizerTest.php tests/Feature/IngredientFunctionAssignmentTest.php tests/Feature/IngredientMarketLabelTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
graphify update .
```

- [ ] **Step 5: Commit integration coverage**

```bash
git add tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/ResearchIngredientEnrichmentJobTest.php tests/Feature/IngredientEnrichmentBatchReviewTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php tests/Feature/IngredientEnrichmentResearchPromptTest.php
git diff --cached --check
git commit -m "test: verify hybrid ingredient enrichment flow"
```

## Task 16: Perform final migration, safety, and manual smoke verification

**Files:**

- Modify only files implicated by a failing check.

- [ ] **Step 1: Verify migrations on the test database**

```bash
php artisan migrate --env=testing --no-interaction
php artisan test --compact tests/Feature/IngredientEnrichmentProvenanceSchemaTest.php tests/Feature/IngredientEnrichmentBatchSchemaTest.php
```

Expected: migrations succeed and both schema suites pass.

- [ ] **Step 2: Run all focused tests**

```bash
php artisan test --compact --filter=Ingredient
```

Expected: all ingredient-related tests pass. If the filter includes unrelated pre-existing failures, rerun each failing file to distinguish a regression and fix only regressions caused by this plan.

- [ ] **Step 3: Run static project checks**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
git diff --check
graphify update .
```

Expected: no formatting, Filament deprecation, whitespace, or graph-update errors.

- [ ] **Step 4: Smoke one local ingredient without applying it**

In Admin, select only `argan_oil`, start enrichment, and run the configured queue worker. Verify the item reaches review, shows deterministic source calls before the editorial call, displays field-level EU/FDA evidence, and leaves the ingredient unchanged before approval/apply. Do not use `Approve` or `Apply approved` during this smoke step.

- [ ] **Step 5: Inspect and commit any final check-only corrections**

```bash
git status --short
git diff --check
```

Stage only correction files caused by this implementation, then:

```bash
git commit -m "fix: finalize hybrid enrichment pipeline"
```

Skip this commit when no corrections remain.
