# Broader Ingredient Guidance Research Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Use `superpowers:test-driven-development` for every behavior change and read `testing-best-practices` before modifying tests.

**Goal:** Produce concise, material-specific ingredient guidance from freshly researched, claim-linked practical evidence without allowing non-official sources to influence identity or regulatory fields.

**Architecture:** Keep the deterministic identity/regulatory stages unchanged and isolate broader web discovery inside the existing `AiGuidanceResearch` stage. A guidance evidence policy validates each discovered source, correlates every citation with the provider's returned web-search sources, classifies the claim and its scope, and converts accepted findings into the persisted guidance-evidence format. Guidance authoring returns structured one-sentence claims rather than free Markdown; a deterministic renderer checks each claim's evidence or trusted-fact references and then produces the fixed Markdown sections. Full enrichment and guidance refresh share this path, while localization-only continues to translate approved English without research.

**Tech Stack:** PHP 8.5, Laravel 13.29, OpenAI Responses API web search and strict structured output, PostgreSQL JSON/JSONB persistence already present, Pest.

---

## Settled product decisions

- Official and deterministic sources remain mandatory for INCI, COSING functions, identifiers, market declarations, restrictions, and other regulatory facts.
- Broader sources are admitted only into `proposal.info_markdown` evidence. They can never populate or corroborate identity or regulatory fields.
- Guidance discovery searches the open web. It is not constrained to a static allow-list of official domains.
- Accepted guidance source kinds are manufacturer technical documentation, supplier technical product pages, peer-reviewed or institutional scientific material, regulatory reference material used editorially, professional formulation references, and recognized specialist references.
- Marketplaces, social networks, community posts, generic blogs, AI-generated pages, search-result pages, SEO summaries, and unsourced marketing are excluded.
- Product-grade manufacturer or supplier evidence may support recommendations for that grade only. The prose must qualify it and cannot generalize it to every commercial grade of the INCI.
- Recommended percentages are useful editorial guidance even though they are not official or regulatory limits. Preserve whether each recommendation applies to cosmetics or soapmaking and whether the percentage is based on the total formula, oil phase, or soap-oil blend. When one page gives both applications, store two evidence records rather than one ambiguous combined range.
- Reported-use concentrations and experimental concentrations are not usage recommendations. A recommended-use statement requires a manufacturer, supplier, professional, or specialist source that explicitly presents the percentage as formulation guidance.
- Conflicting source recommendations remain separate. Never average or silently merge them; select one with its source/scope visibly qualified or omit the percentage and surface the conflict for review.
- Guidance refresh performs fresh guidance research. A retry of the same batch reuses its completed research stage; a new refresh batch searches again.
- Localization-only performs no research and does not rewrite English.
- Every generated English sentence is represented as a structured claim supported by accepted evidence or an allow-listed deterministic fact path.
- Unsupported claims, claims contradicting unresolved questions, and material-class tautologies are omitted.
- Remove the 80-word minimum. Keep 160 words as a hard maximum and prefer shorter copy when evidence is sparse.
- Keep the existing human review and apply workflow. No database migration is required because research stages and applied evidence already live in JSON columns.

## Source and evidence vocabulary

New research evidence uses these closed values:

```php
// claim_type
[
    'origin', 'physical_form', 'formulation_role', 'processing', 'dispersion',
    'solubility', 'compatibility', 'sensory', 'stability', 'usage', 'soapmaking',
]

// source_kind
[
    'manufacturer_technical', 'supplier_technical', 'scientific', 'regulatory_reference',
    'professional_reference', 'specialist_reference',
]

// scope
['material', 'product_grade']

// evidence_kind
['fact', 'formulation_recommendation', 'experimental_observation']

// usage_application
['cosmetics', 'soapmaking', 'not_applicable']

// percentage_basis
['total_formula', 'oil_phase', 'soap_oils', 'not_applicable']
```

Persisted guidance evidence keeps the existing keys and adds the four classification keys:

```php
[
    'source_name' => '...',
    'source_url' => 'https://...',
    'summary' => '...',
    'source_tier' => 'editorial',
    'retrieved_at' => '2026-08-30T00:00:00+00:00',
    'claim_type' => 'stability',
    'source_kind' => 'manufacturer_technical',
    'scope' => 'product_grade',
    'evidence_kind' => 'formulation_recommendation',
    'usage_application' => 'cosmetics',
    'recommended_min_percent' => '1',
    'recommended_max_percent' => '10',
    'percentage_basis' => 'total_formula',
]
```

Legacy five-key evidence remains readable. Normalize it in memory as `claim_type=origin`, `source_kind=legacy_editorial`, `scope=material`, `evidence_kind=fact`, `usage_application=not_applicable`, null percentage bounds, and `percentage_basis=not_applicable`; fresh guidance research never emits `legacy_editorial`.

## Files in scope

**Create**

- `app/Contracts/IngredientGuidanceResearchClient.php`
- `app/Services/IngredientEnrichment/IngredientGuidanceEvidencePolicy.php`
- `app/Services/IngredientEnrichment/IngredientGuidanceDraftRenderer.php`
- `tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php`
- `tests/Unit/Services/IngredientGuidanceDraftRendererTest.php`

**Modify**

- `config/ingredient-enrichment.php`
- `app/Providers/AppServiceProvider.php`
- `app/Data/IngredientGapResearchResponse.php`
- `app/Services/IngredientEnrichment/OpenAiIngredientGapResearchClient.php`
- `app/Services/IngredientEnrichment/IngredientGuidancePrompt.php`
- `app/Services/IngredientEnrichment/IngredientGuidanceSchema.php`
- `app/Services/IngredientEnrichment/OpenAiIngredientGuidanceClient.php`
- `app/Services/IngredientEnrichment/IngredientEnrichmentPipeline.php`
- `app/Services/IngredientEnrichment/IngredientEnrichmentResultSchema.php`
- `app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php`
- `app/Services/IngredientEnrichment/IngredientGuidanceContextBuilder.php`
- `app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php`
- `app/Services/IngredientEnrichment/IngredientGuidanceRefreshResultValidator.php`
- `app/Services/IngredientEnrichment/IngredientGuidanceStageRunner.php`
- `app/Enums/IngredientEnrichmentBatchMode.php`
- `app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php`
- `app/Services/IngredientEnrichment/ApplyIngredientGuidanceRefresh.php`
- `lang/en/ingredient_enrichment.php`
- `tests/Feature/IngredientEditorialClientTest.php`
- `tests/Feature/IngredientGuidanceClientTest.php`
- `tests/Feature/HybridIngredientEnrichmentPipelineTest.php`
- `tests/Feature/IngredientEnrichmentPipelineTest.php`
- `tests/Feature/IngredientGuidanceRefreshJobTest.php`
- `tests/Feature/IngredientGuidanceRefreshValidationTest.php`
- `tests/Feature/ApplyPlatformIngredientEnrichmentTest.php`
- `tests/Feature/ApplyIngredientGuidanceRefreshTest.php`

**Explicitly out of scope**

- Changing platform ingredient identity, regulatory, or structured-source precedence.
- Using supplier or manufacturer material to establish legal status, INCI, identifiers, or safety limits.
- Workspace-authored guidance and the Rich Editor feature.
- Automatically applying generated guidance without Admin review.
- Adding a new database table or migrating existing JSON evidence rows.
- Researching again during localization-only batches.

---

## Task 1: Establish the baseline and introduce a guidance-research boundary

**Files:**

- Create: `app/Contracts/IngredientGuidanceResearchClient.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Services/IngredientEnrichment/OpenAiIngredientGapResearchClient.php`
- Modify: `app/Data/IngredientGapResearchResponse.php`
- Test: `tests/Feature/IngredientEditorialClientTest.php`

- [ ] **Step 1: Record the dirty-worktree baseline without touching the active Rich Editor changes.**

```bash
git status --short
git diff --check
```

Expected: the existing workspace-guidance implementation remains present and no file from this plan has been edited yet.

- [ ] **Step 2: Run the existing enrichment baseline.**

```bash
php artisan test --compact tests/Feature/IngredientEditorialClientTest.php tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/IngredientEnrichmentPipelineTest.php tests/Feature/IngredientGuidanceClientTest.php tests/Feature/IngredientGuidanceRefreshJobTest.php tests/Feature/IngredientGuidanceRefreshValidationTest.php
```

Expected: PASS before research behavior changes.

- [ ] **Step 3: Add a failing contract-resolution test.**

Add to `tests/Feature/IngredientEditorialClientTest.php`:

```php
use App\Contracts\IngredientGuidanceResearchClient;
use App\Services\IngredientEnrichment\OpenAiIngredientGapResearchClient;

it('binds guidance research to the OpenAI web-search client', function (): void {
    expect(app(IngredientGuidanceResearchClient::class))
        ->toBeInstanceOf(OpenAiIngredientGapResearchClient::class);
});
```

- [ ] **Step 4: Run the contract test and verify that it fails because the interface does not exist.**

```bash
php artisan test --compact tests/Feature/IngredientEditorialClientTest.php --filter='binds guidance research'
```

- [ ] **Step 5: Create the contract and bind it.**

```php
<?php

namespace App\Contracts;

use App\Data\IngredientGapResearchResponse;

interface IngredientGuidanceResearchClient
{
    /** @param array<string, mixed> $facts */
    public function research(array $facts): IngredientGapResearchResponse;
}
```

Make `OpenAiIngredientGapResearchClient` implement this interface and add the provider binding:

```php
$this->app->bind(
    IngredientGuidanceResearchClient::class,
    OpenAiIngredientGapResearchClient::class,
);
```

- [ ] **Step 6: Run the contract test.**

```bash
php artisan test --compact tests/Feature/IngredientEditorialClientTest.php --filter='binds guidance research'
```

Expected: PASS.

- [ ] **Step 7: Commit the boundary separately.**

```bash
git add app/Contracts/IngredientGuidanceResearchClient.php app/Providers/AppServiceProvider.php app/Services/IngredientEnrichment/OpenAiIngredientGapResearchClient.php tests/Feature/IngredientEditorialClientTest.php
git commit -m "refactor: isolate ingredient guidance research client"
```

---

## Task 2: Accept broad discovery but strictly qualify evidence

**Files:**

- Create: `app/Services/IngredientEnrichment/IngredientGuidanceEvidencePolicy.php`
- Create: `tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php`
- Modify: `config/ingredient-enrichment.php`
- Modify: `app/Services/IngredientEnrichment/OpenAiIngredientGapResearchClient.php`
- Modify: `app/Data/IngredientGapResearchResponse.php`
- Test: `tests/Feature/IngredientEditorialClientTest.php`

- [ ] **Step 1: Generate the policy and unit test.**

```bash
php artisan make:class Services/IngredientEnrichment/IngredientGuidanceEvidencePolicy --no-interaction
php artisan make:test --pest --unit Services/IngredientGuidanceEvidencePolicyTest --no-interaction
```

- [ ] **Step 2: Write failing policy tests for all acceptance boundaries.**

Cover these exact cases in `IngredientGuidanceEvidencePolicyTest.php`:

- accepts an HTTP(S) candidate whose canonical URL appears in the provider's consulted-source list;
- preserves `field`, `source_name`, `source_url`, `summary`, `claim_type`, `source_kind`, `scope`, `evidence_kind`, `usage_application`, both percentage bounds, and `percentage_basis`;
- rejects any field other than `proposal.info_markdown`;
- rejects unknown classification values;
- rejects empty summaries and malformed URLs;
- rejects candidate URLs absent from the provider's source list;
- rejects marketplace, social, community, search-result, and configured blocked hosts including subdomains;
- rejects `claim_type=usage` unless `evidence_kind=formulation_recommendation`, the source kind is manufacturer/supplier/professional/specialist, the application and percentage basis are explicit, and at least one decimal-string bound is present;
- rejects negative bounds, bounds above 100, and a minimum greater than the maximum using decimal-string comparison;
- splits source material that recommends different cosmetics and soapmaking ranges into separate candidate evidence rows;
- rejects reported-use or experimental percentages as recommendations;
- requires non-usage evidence to use `usage_application=not_applicable`, null percentage bounds, and `percentage_basis=not_applicable`;
- accepts product-grade manufacturer or supplier recommendations but retains `scope=product_grade`;
- accepts a specialist recommendation with `scope=material` while retaining its non-regulatory source kind;
- converts an accepted candidate to the thirteen-key persisted evidence shape with `source_tier=editorial` and the supplied retrieval timestamp;
- normalizes legacy five-key persisted evidence with the documented compatibility classifications.

Use a fixture shaped exactly like:

```php
$candidate = [
    'field' => 'proposal.info_markdown',
    'source_name' => 'Example manufacturer technical data sheet',
    'source_url' => 'https://manufacturer.example/technical/apricot-oil.pdf',
    'summary' => 'The exact product grade is recommended at 1–10% in cosmetic formulations.',
    'claim_type' => 'usage',
    'source_kind' => 'manufacturer_technical',
    'scope' => 'product_grade',
    'evidence_kind' => 'formulation_recommendation',
    'usage_application' => 'cosmetics',
    'recommended_min_percent' => '1',
    'recommended_max_percent' => '10',
    'percentage_basis' => 'total_formula',
];
```

- [ ] **Step 3: Run the policy tests and verify failure.**

```bash
php artisan test --compact tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php
```

- [ ] **Step 4: Add the guidance source-policy configuration.**

Set `guidance.minimum_words` to `0`, retain `guidance.maximum_words` at `160`, bump `guidance_prompt_version` to `ingredient-guidance-v2`, and configure:

```php
'guidance_research' => [
    'enabled' => env('INGREDIENT_ENRICHMENT_GUIDANCE_RESEARCH_ENABLED', true),
    'prompt_version' => 'ingredient-guidance-research-v2',
    'blocked_domains' => [
        'amazon.com', 'ebay.com', 'etsy.com', 'aliexpress.com',
        'facebook.com', 'instagram.com', 'pinterest.com', 'reddit.com',
        'tiktok.com', 'youtube.com', 'wikipedia.org',
    ],
    'allowed_claim_types' => [
        'origin', 'physical_form', 'formulation_role', 'processing', 'dispersion',
        'solubility', 'compatibility', 'sensory', 'stability', 'usage', 'soapmaking',
    ],
    'allowed_source_kinds' => [
        'manufacturer_technical', 'supplier_technical', 'scientific', 'regulatory_reference',
        'professional_reference', 'specialist_reference',
    ],
    'allowed_scopes' => ['material', 'product_grade'],
    'allowed_evidence_kinds' => [
        'fact', 'formulation_recommendation', 'experimental_observation',
    ],
    'allowed_usage_applications' => [
        'cosmetics', 'soapmaking', 'not_applicable',
    ],
    'allowed_percentage_bases' => [
        'total_formula', 'oil_phase', 'soap_oils', 'not_applicable',
    ],
],
```

Remove the obsolete `gap_research.allowed_domains` list. Keep the strict `openai.allowed_domains` list used by the legacy direct research client unchanged.

- [ ] **Step 5: Implement the policy as the single normalization boundary.**

Give the service these public methods:

```php
/** @param list<array<string,mixed>> $candidates @param list<array{url:string,title:string}> $consultedSources */
public function validateCandidates(array $candidates, array $consultedSources): array;

/** @param list<array<string,mixed>> $candidates */
public function toPersisted(array $candidates, CarbonImmutable $retrievedAt): array;

/** @return list<array<string,mixed>> */
public function normalizePersisted(mixed $rows, ?CarbonImmutable $fallbackRetrievedAt = null): array;
```

Canonicalize URLs by lowercasing scheme and host, removing fragments, preserving the path/query, and treating a sole trailing slash as equivalent. A candidate must match a consulted source after canonicalization. Throw `RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'))` for malformed provider output and `ValidationException` with the existing disallowed-source translation for policy violations.

- [ ] **Step 6: Change the research JSON schema and prompt.**

Each `candidate_evidence` object must require the twelve candidate keys shown above with closed enums sourced from configuration. The instructions must:

- search manufacturer technical sheets/application notes, supplier technical product pages, peer-reviewed or institutional scientific sources, regulatory references used only editorially, professional formulation references, and recognized specialist references;
- reject marketplaces, social/community content, generic blogs, AI/SEO pages, search-result snippets, and unsourced marketing;
- distinguish material-wide facts from product-grade recommendations;
- classify experimental observations separately and prohibit turning them into general recommendations;
- allow a recommended-percentage claim only when the exact source labels it as formulation guidance, and capture its application, lower/upper bound, and percentage basis without converting or guessing;
- output separate evidence rows when a source provides distinct cosmetics and soapmaking recommendations;
- keep supplier/specialist recommendations useful but explicitly non-regulatory and source-attributed;
- keep conflicting recommended ranges as separate evidence instead of synthesizing a new range;
- omit category-obvious facts unless the exact material has a non-obvious practical consequence;
- continue forbidding identity and regulatory claims.

- [ ] **Step 7: Remove `allowed_domains` from the guidance web-search request.**

The request tool must be:

```php
'tools' => [[
    'type' => 'web_search',
]],
```

Collect `web_search_call.action.sources` before accepting candidate evidence, validate candidates through the policy, and return only policy-approved candidates in `IngredientGapResearchResponse`.

- [ ] **Step 8: Add HTTP-level tests.**

In `IngredientEditorialClientTest.php`, assert that:

- the outgoing guidance research tool contains no `filters.allowed_domains`;
- valid broad-source evidence is returned with classifications intact;
- a fabricated candidate URL not present in `action.sources` fails;
- a blocked source fails even when it appears in `action.sources`;
- COSMILE still cannot support identity or regulatory fields.

- [ ] **Step 9: Run the policy and client tests.**

```bash
php artisan test --compact tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php tests/Feature/IngredientEditorialClientTest.php
```

- [ ] **Step 10: Commit source discovery and qualification.**

```bash
git add config/ingredient-enrichment.php app/Data/IngredientGapResearchResponse.php app/Services/IngredientEnrichment/IngredientGuidanceEvidencePolicy.php app/Services/IngredientEnrichment/OpenAiIngredientGapResearchClient.php tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php tests/Feature/IngredientEditorialClientTest.php
git commit -m "feat: broaden and qualify ingredient guidance research"
```

---

## Task 3: Render guidance from supported claims instead of free prose

**Files:**

- Create: `app/Services/IngredientEnrichment/IngredientGuidanceDraftRenderer.php`
- Create: `tests/Unit/Services/IngredientGuidanceDraftRendererTest.php`
- Modify: `app/Services/IngredientEnrichment/IngredientGuidancePrompt.php`
- Modify: `app/Services/IngredientEnrichment/IngredientGuidanceSchema.php`
- Modify: `app/Services/IngredientEnrichment/OpenAiIngredientGuidanceClient.php`
- Test: `tests/Feature/IngredientGuidanceClientTest.php`

- [ ] **Step 1: Generate the renderer and unit test.**

```bash
php artisan make:class Services/IngredientEnrichment/IngredientGuidanceDraftRenderer --no-interaction
php artisan make:test --pest --unit Services/IngredientGuidanceDraftRendererTest --no-interaction
```

- [ ] **Step 2: Write failing renderer tests.**

The model draft has this strict shape:

```php
[
    'overview' => [[
        'text' => 'A fixed oil pressed from apricot kernels.',
        'claim_type' => 'origin',
        'support_type' => 'fact',
        'evidence_indexes' => [],
        'fact_paths' => ['current.canonical.display_name'],
        'usage_application' => 'not_applicable',
    ]],
    'formulation_use' => [[
        'text' => 'The cited product grade is recommended at 1–10%.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [0],
        'fact_paths' => [],
        'usage_application' => 'cosmetics',
    ]],
    'soapmaking' => [],
    'warnings' => [],
    'unresolved_questions' => [],
]
```

Cover:

- deterministic rendering of `## Overview`, `## Formulation use`, and optional `## Soapmaking`;
- one sentence per claim, no embedded headings or newlines;
- evidence indexes must exist and referenced evidence must have the same `claim_type`;
- `support_type=evidence` forbids fact paths and requires evidence indexes;
- `support_type=fact` forbids evidence indexes and allows only present paths beneath `proposal`, `editorial_context`, or `current.canonical`, plus trusted soap chemistry paths;
- formulation-use claims about processing, dispersion, solubility, compatibility, sensory, stability, or usage require evidence, not material-class knowledge;
- usage claims require `evidence_kind=formulation_recommendation`, a matching cosmetics/soapmaking application, explicit bounds, and an explicit percentage basis;
- cosmetics usage claims may appear only under Formulation use and soapmaking usage claims may appear only under Soapmaking;
- product-grade evidence remains available to the prompt as product-grade and is not silently promoted to material scope;
- soapmaking claims require `claim_type=soapmaking` evidence or a non-empty trusted-soap-chemistry fact path;
- empty soapmaking claims omit the heading;
- output shorter than 80 words is accepted;
- output above 160 words is rejected;
- an Apricot Oil solubility claim referencing fatty-acid evidence is rejected because the claim types do not match.

- [ ] **Step 3: Run the renderer tests and verify failure.**

```bash
php artisan test --compact tests/Unit/Services/IngredientGuidanceDraftRendererTest.php
```

- [ ] **Step 4: Implement the renderer.**

Use this public API:

```php
/** @param array<string,mixed> $draft @param array<string,mixed> $context */
public function render(array $draft, array $context): array;
```

Return only the existing downstream shape:

```php
[
    'info_markdown' => $markdown,
    'warnings' => $draft['warnings'],
    'unresolved_questions' => $draft['unresolved_questions'],
]
```

Validate claims before joining their exact `text` values with a single space under deterministic headings. Count words after removing headings and reject only when the configured maximum is exceeded. Do not pad output to a minimum.

- [ ] **Step 5: Replace the authoring schema with structured claims.**

`IngredientGuidanceSchema::build()` must expose five required top-level keys: `overview`, `formulation_use`, `soapmaking`, `warnings`, and `unresolved_questions`. All three section fields are arrays of strict claim objects containing `text`, `claim_type`, `support_type`, `evidence_indexes`, `fact_paths`, and `usage_application`. Use the configured claim/application values and `support_type` enum `['evidence', 'fact']`.

- [ ] **Step 6: Rewrite the guidance prompt around evidence discipline.**

The prompt must require:

- one sentence per claim object;
- every formulation-use sentence to cite matching guidance evidence;
- no general knowledge outside accepted evidence and allow-listed deterministic facts;
- no repetition of INCI;
- no generic oil/water, generic emulsifier, generic storage, generic botanical, or generic product-list filler;
- product-grade qualification whenever `scope=product_grade`;
- experimental findings described only as bounded observations, never universal advice;
- no reported or experimental concentration presented as a typical-use range;
- recommended percentages from manufacturer, supplier, professional, or specialist guidance clearly labelled as recommendations rather than official, regulatory, or safety limits;
- the correct cosmetics/soapmaking application and total-formula/oil-phase/soap-oils basis stated whenever a percentage is shown;
- no averaging or merging of conflicting recommended ranges;
- concise output with no minimum and 160 words maximum after rendering.

- [ ] **Step 7: Render the transport payload in the client.**

Inject `IngredientGuidanceDraftRenderer` into `OpenAiIngredientGuidanceClient` and replace direct payload assignment with:

```php
$guidance = $this->renderer->render($response->payload, $context);
```

Return `$guidance` through the existing `IngredientGuidanceAuthoringResponse`, leaving all downstream consumers on the stable `info_markdown`, `warnings`, and `unresolved_questions` shape.

- [ ] **Step 8: Update client feature tests.**

Assert the strict structured-claim schema is sent, valid claims render to Markdown, and unsupported or mismatched claims throw before any proposal is stored.

- [ ] **Step 9: Run renderer and client tests.**

```bash
php artisan test --compact tests/Unit/Services/IngredientGuidanceDraftRendererTest.php tests/Feature/IngredientGuidanceClientTest.php
```

- [ ] **Step 10: Commit evidence-linked authoring.**

```bash
git add app/Services/IngredientEnrichment/IngredientGuidanceDraftRenderer.php app/Services/IngredientEnrichment/IngredientGuidancePrompt.php app/Services/IngredientEnrichment/IngredientGuidanceSchema.php app/Services/IngredientEnrichment/OpenAiIngredientGuidanceClient.php tests/Unit/Services/IngredientGuidanceDraftRendererTest.php tests/Feature/IngredientGuidanceClientTest.php
git commit -m "feat: author guidance from supported claims"
```

---

## Task 4: Preserve classified guidance evidence through full enrichment

**Files:**

- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentPipeline.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentResultSchema.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php`
- Modify: `app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php`
- Test: `tests/Feature/HybridIngredientEnrichmentPipelineTest.php`
- Test: `tests/Feature/IngredientEnrichmentPipelineTest.php`
- Test: `tests/Feature/ApplyPlatformIngredientEnrichmentTest.php`

- [ ] **Step 1: Write failing full-pipeline tests.**

Assert that a normal `fill_missing` run:

- calls `IngredientGuidanceResearchClient` once;
- persists the completed `ai_guidance_research` stage before authoring;
- passes only accepted, classified evidence to authoring;
- preserves all thirteen persisted evidence keys in the final result;
- counts research web calls and provider tokens;
- resumes a completed research stage after an authoring failure without searching again;
- searches again in a newly created enrichment batch.

- [ ] **Step 2: Bind test doubles through the new contract.**

Replace concrete `OpenAiIngredientGapResearchClient` test instances with anonymous implementations of `IngredientGuidanceResearchClient`. Return `IngredientGapResearchResponse` containing fully classified candidate evidence and consulted sources.

- [ ] **Step 3: Run the focused pipeline tests and verify failure.**

```bash
php artisan test --compact tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/IngredientEnrichmentPipelineTest.php
```

- [ ] **Step 4: Inject the contract and evidence policy into the full pipeline.**

Replace the concrete research-client constructor type with `IngredientGuidanceResearchClient`. After research, validate/normalize evidence once and use `IngredientGuidanceEvidencePolicy::toPersisted()` instead of the pipeline's existing five-key `guidanceEvidence()` mapping.

- [ ] **Step 5: Extend full-result schema and validation.**

`guidance_evidence` rows must accept exactly the thirteen persisted keys. Validate each closed classification, percentage bound, application, and basis; require `source_tier=editorial`; validate timestamps and URLs; and keep legacy five-key rows acceptable only through `normalizePersisted()` before validation.

- [ ] **Step 6: Persist research provenance when full enrichment is applied.**

Under `source_data.enrichment.guidance`, persist:

```php
[
    'evidence' => $guidanceEvidence,
    'research_prompt_version' => config('ingredient-enrichment.openai.guidance_research.prompt_version'),
    'guidance_prompt_version' => config('ingredient-enrichment.openai.guidance_prompt_version'),
    'localization_prompt_version' => config('ingredient-enrichment.openai.guidance_localization_prompt_version'),
    'approved_at' => CarbonImmutable::now()->toIso8601String(),
]
```

- [ ] **Step 7: Add apply-roundtrip assertions.**

Verify that classified evidence and `research_prompt_version` survive application to `ingredients.source_data`, and that identity evidence/source tiers remain unchanged.

- [ ] **Step 8: Run full-enrichment and apply tests.**

```bash
php artisan test --compact tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/IngredientEnrichmentPipelineTest.php tests/Feature/ApplyPlatformIngredientEnrichmentTest.php
```

- [ ] **Step 9: Commit the full-enrichment path.**

```bash
git add app/Services/IngredientEnrichment/IngredientEnrichmentPipeline.php app/Services/IngredientEnrichment/IngredientEnrichmentResultSchema.php app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/IngredientEnrichmentPipelineTest.php tests/Feature/ApplyPlatformIngredientEnrichmentTest.php
git commit -m "feat: retain classified guidance evidence"
```

---

## Task 5: Make guidance regeneration perform fresh research

**Files:**

- Modify: `app/Enums/IngredientEnrichmentBatchMode.php`
- Modify: `app/Services/IngredientEnrichment/IngredientGuidanceContextBuilder.php`
- Modify: `app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php`
- Modify: `app/Services/IngredientEnrichment/IngredientGuidanceStageRunner.php`
- Modify: `app/Services/IngredientEnrichment/IngredientGuidanceRefreshResultValidator.php`
- Modify: `app/Services/IngredientEnrichment/ApplyIngredientGuidanceRefresh.php`
- Modify: `lang/en/ingredient_enrichment.php`
- Test: `tests/Feature/IngredientGuidanceRefreshJobTest.php`
- Test: `tests/Feature/IngredientGuidanceRefreshValidationTest.php`
- Test: `tests/Feature/ApplyIngredientGuidanceRefreshTest.php`

- [ ] **Step 1: Replace the current no-research refresh expectation with failing fresh-research tests.**

Update the first refresh job test so `GuidanceRefresh` expects calls `research=1`, `author=1`, `localize=1`, a completed `ai_guidance_research` stage, non-zero `web_search_calls`, research tokens included in totals, and fresh evidence in the result. Add separate coverage proving `GuidanceLocalization` remains `research=0`, `author=0`, `localize=1`.

- [ ] **Step 2: Add retry and provenance tests.**

Cover:

- authoring failure followed by retry reuses completed research;
- failure during research reruns research and has no downstream stages;
- changing the research prompt version invalidates research, authoring, localization, and validation on an explicitly retried item;
- changing only the guidance prompt version reuses research but invalidates authoring and downstream stages;
- a new guidance-refresh batch performs a fresh search even when applied evidence exists;
- research returning no accepted evidence produces a warning and permits concise trusted-fact guidance rather than restoring unsupported legacy prose.

- [ ] **Step 3: Run the refresh tests and verify failure.**

```bash
php artisan test --compact tests/Feature/IngredientGuidanceRefreshJobTest.php
```

- [ ] **Step 4: Add research to the guidance-refresh stage list.**

```php
self::GuidanceRefresh => [
    IngredientEnrichmentResearchStage::AiGuidanceResearch,
    IngredientEnrichmentResearchStage::AiGuidanceAuthoring,
    IngredientEnrichmentResearchStage::AiGuidanceLocalization,
    IngredientEnrichmentResearchStage::Validation,
],
```

Leave `GuidanceLocalization` unchanged.

- [ ] **Step 5: Expose prior evidence only as research input.**

Have `IngredientGuidanceContextBuilder` normalize persisted/legacy evidence through `IngredientGuidanceEvidencePolicy` and return it under `prior_guidance_evidence`. Do not pass it directly to authoring in a new guidance-refresh batch. The research prompt may use it as leads to re-check, replace, or omit.

- [ ] **Step 6: Run and persist the research stage before authoring.**

Inject `IngredientGuidanceResearchClient` and `IngredientGuidanceEvidencePolicy` into `IngredientGuidanceRefreshProcessor`. Add:

```php
private function runResearch(int $itemId, array $context): IngredientSourceStageResult;
```

The stage callback calls `research($context)`, converts accepted candidates to persisted evidence, stores provider accounting and web-search sources, and carries warnings/unresolved questions. `runAuthoring()` receives the fresh stage evidence. It must never silently replace an empty fresh result with old evidence.

- [ ] **Step 7: Extend stage provenance and dependency fingerprints.**

`IngredientGuidanceStageRunner` must:

- validate, cache, and attach stage context to `AiGuidanceResearch`;
- add `research_dependency_fingerprint` based on the frozen input snapshot;
- make the authoring dependency fingerprint include completed research evidence/warnings/unresolved questions;
- include research configuration and prompt version in provider configuration fingerprints;
- retain existing localization and validation dependency behavior;
- validate research provider IDs, tokens, web-search-call count, evidence shape, warnings, and unresolved questions.

- [ ] **Step 8: Use fresh evidence in the candidate result and telemetry.**

Build `guidance_evidence` from the completed research stage. Sum research, authoring, and localization tokens; set `web_search_calls` from research; set item `sources` from accepted evidence URLs. Merge research warnings and unresolved questions into the proposal warning state.

- [ ] **Step 9: Extend guidance-refresh validation and apply persistence.**

Accept/normalize the thirteen-key evidence rows, preserve legacy input compatibility, and persist `research_prompt_version` alongside the existing prompt versions on apply. A localization-only apply must preserve existing research evidence and research prompt version.

- [ ] **Step 10: Run refresh, validation, and apply tests.**

```bash
php artisan test --compact tests/Feature/IngredientGuidanceRefreshJobTest.php tests/Feature/IngredientGuidanceRefreshValidationTest.php tests/Feature/ApplyIngredientGuidanceRefreshTest.php
```

- [ ] **Step 11: Commit fresh guidance regeneration.**

```bash
git add app/Enums/IngredientEnrichmentBatchMode.php app/Services/IngredientEnrichment/IngredientGuidanceContextBuilder.php app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php app/Services/IngredientEnrichment/IngredientGuidanceStageRunner.php app/Services/IngredientEnrichment/IngredientGuidanceRefreshResultValidator.php app/Services/IngredientEnrichment/ApplyIngredientGuidanceRefresh.php lang/en/ingredient_enrichment.php tests/Feature/IngredientGuidanceRefreshJobTest.php tests/Feature/IngredientGuidanceRefreshValidationTest.php tests/Feature/ApplyIngredientGuidanceRefreshTest.php
git commit -m "feat: research ingredient guidance on regeneration"
```

---

## Task 6: Lock the Apricot Oil failure mode and verify the complete feature

**Files:**

- Modify: `tests/Feature/IngredientGuidanceClientTest.php`
- Modify: `tests/Feature/HybridIngredientEnrichmentPipelineTest.php`
- Modify: `tests/Feature/IngredientGuidanceRefreshJobTest.php`
- Modify: `tests/Feature/ApplyPlatformIngredientEnrichmentTest.php`
- Modify: `tests/Feature/ApplyIngredientGuidanceRefreshTest.php`

- [ ] **Step 1: Add the concrete Apricot Oil regression.**

Use research evidence containing only:

1. fatty-acid composition with `claim_type=stability` or `formulation_role`; and
2. a specific Pickering-emulsion experiment with `claim_type=dispersion`, `scope=product_grade` or bounded material scope, and `evidence_kind=experimental_observation`.

Return unresolved questions including water solubility, recommended processing phase, and qualitative soapmaking behavior. Assert that an authoring draft containing “It is not water-soluble,” a universal emulsifier requirement, or a soapmaking claim without matching support is rejected. Then assert that a shorter draft using only supported composition/stability consequences passes.

- [ ] **Step 2: Add source-isolation regressions.**

Prove that manufacturer or specialist guidance evidence cannot appear in `proposal.inci_name`, identifiers, COSING functions, market labels, or regulatory findings, and that applying guidance never changes those fields.

- [ ] **Step 3: Add sparse-evidence behavior.**

Assert that a valid guidance proposal below 80 words passes without a word-count warning, retains the required section order, and omits Soapmaking when trusted soap chemistry/evidence is absent.

- [ ] **Step 4: Run all focused enrichment tests.**

```bash
php artisan test --compact tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php tests/Unit/Services/IngredientGuidanceDraftRendererTest.php tests/Feature/IngredientEditorialClientTest.php tests/Feature/IngredientGuidanceClientTest.php tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/IngredientEnrichmentPipelineTest.php tests/Feature/IngredientGuidanceRefreshJobTest.php tests/Feature/IngredientGuidanceRefreshValidationTest.php tests/Feature/ApplyPlatformIngredientEnrichmentTest.php tests/Feature/ApplyIngredientGuidanceRefreshTest.php
```

Expected: all focused tests pass.

- [ ] **Step 5: Format PHP and rerun focused tests.**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php tests/Unit/Services/IngredientGuidanceDraftRendererTest.php tests/Feature/IngredientEditorialClientTest.php tests/Feature/IngredientGuidanceClientTest.php tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/IngredientEnrichmentPipelineTest.php tests/Feature/IngredientGuidanceRefreshJobTest.php tests/Feature/IngredientGuidanceRefreshValidationTest.php tests/Feature/ApplyPlatformIngredientEnrichmentTest.php tests/Feature/ApplyIngredientGuidanceRefreshTest.php
```

- [ ] **Step 6: Run the complete suite and structural checks.**

```bash
php artisan test --compact
git diff --check
graphify update .
```

Expected: complete test suite passes; no whitespace errors; graph refresh completes.

- [ ] **Step 7: Review the final diff specifically for boundary leaks.**

```bash
git diff -- config/ingredient-enrichment.php app/Contracts/IngredientGuidanceResearchClient.php app/Providers/AppServiceProvider.php app/Services/IngredientEnrichment app/Enums/IngredientEnrichmentBatchMode.php tests/Unit/Services tests/Feature
```

Confirm that broad-source evidence enters only guidance research/authoring and never identity or regulatory proposal construction.

- [ ] **Step 8: Commit the regression and verification coverage.**

```bash
git add tests/Feature/IngredientGuidanceClientTest.php tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/IngredientGuidanceRefreshJobTest.php tests/Feature/ApplyPlatformIngredientEnrichmentTest.php tests/Feature/ApplyIngredientGuidanceRefreshTest.php
git commit -m "test: prevent unsupported ingredient guidance claims"
```

---

## Manual acceptance check

After the full suite passes, run one Admin guidance refresh for Apricot Oil and inspect the proposal before applying:

- the batch records at least one web-search call;
- sources include practical evidence beyond the regulatory identity set when trustworthy material is available;
- every formulation sentence can be traced to a displayed evidence source;
- a product-grade recommendation is explicitly qualified;
- no sentence merely says that an oil is insoluble in water;
- no experimental Pickering-emulsion result becomes universal emulsifier advice;
- cosmetic and soapmaking recommended percentages retain their distinct application and percentage basis and are labelled as supplier/manufacturer/specialist recommendations rather than official limits;
- Soapmaking is omitted if no supported soap chemistry is available;
- the copy may be shorter than 80 words;
- translations preserve the accepted English meaning without adding claims.

Do not apply the proposal if the evidence panel contains an irrelevant or low-quality source. Capture its URL and classification so the source policy can be tightened with a regression test.
