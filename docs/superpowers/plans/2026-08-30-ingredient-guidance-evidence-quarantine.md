# Ingredient Guidance Evidence Quarantine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent one rejected guidance-research citation from failing an entire guidance rerun while preserving strict, truthful evidence provenance and precise diagnostics.

**Architecture:** The OpenAI research client owns only transport and strict top-level response parsing. `IngredientGuidanceEvidencePolicy` becomes the single sanitization boundary and partitions candidate rows into accepted evidence and structured rejections. Full enrichment and guidance-only refresh both persist only accepted evidence, retain sanitized rejection diagnostics in the research stage, and surface a review warning; provider/network failures and malformed top-level responses still fail.

**Tech Stack:** PHP 8.5, Laravel, Pest, OpenAI Responses API structured outputs, Eloquent JSON casts.

---

## Scope and non-goals

- Do not add domains to `ingredient-enrichment.openai.allowed_domains`.
- Do not change `guidance_research.blocked_domains` in this correction.
- Do not weaken identity, INCI, identifier, COSING, market-label, or regulatory citation validation.
- Do not allow rejected evidence into authoring context, persisted `guidance_evidence`, or applied source provenance.
- Do not swallow provider/network errors or malformed top-level JSON/schema responses.
- Keep the current name-plus-code display change.
- Keep the full-enrichment `proposal.info_markdown` verifier exception only if its focused tests continue proving that identity citations remain strict.
- A curated source-quality registry is separate work.

## File map

- Create `app/Data/IngredientGuidanceEvidenceValidationResult.php`: immutable accepted/rejected result.
- Modify `app/Services/IngredientEnrichment/IngredientGuidanceEvidencePolicy.php`: classify each candidate independently and produce precise rejection codes.
- Modify `app/Services/IngredientEnrichment/OpenAiIngredientGapResearchClient.php`: stop applying business policy inside HTTP transport.
- Modify `app/Services/IngredientEnrichment/IngredientEnrichmentPipeline.php`: consume partitioned evidence in full enrichment.
- Modify `app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php`: consume partitioned evidence in guidance-only reruns.
- Modify `app/Services/IngredientEnrichment/IngredientGuidanceStageRunner.php`: validate the persisted accepted/rejected stage envelope.
- Modify `lang/en/ingredient_enrichment.php`: add one reviewer-facing aggregate warning.
- Modify `lang/en/ingredient_enrichment_admin.php`: give strict validation failures accurate reason-specific messages.
- Modify focused Pest tests listed below.

### Task 1: Introduce the evidence-validation result contract

**Files:**
- Create: `app/Data/IngredientGuidanceEvidenceValidationResult.php`
- Test: `tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php`

- [ ] **Step 1: Add a failing unit test for mixed valid and rejected candidates**

Add a test that passes three rows to the policy: one valid supplier recommendation, one unconsulted URL, and one invalid usage-metadata combination. Assert one accepted row and two structured rejections, in original order:

```php
it('partitions valid guidance evidence from rejected candidates', function (): void {
    $valid = guidanceEvidenceCandidate();
    $unconsulted = guidanceEvidenceCandidate([
        'source_url' => 'https://unconsulted.example/apricot-oil',
    ]);
    $invalidUsage = guidanceEvidenceCandidate([
        'claim_type' => 'usage',
        'usage_application' => 'not_applicable',
    ]);

    $result = guidanceEvidencePolicy()->partitionCandidates(
        [$valid, $unconsulted, $invalidUsage],
        [['url' => $valid['source_url'], 'title' => 'Supplier technical data']],
    );

    expect($result->accepted)->toBe([$valid])
        ->and($result->rejected)->toMatchArray([
            ['index' => 1, 'code' => 'unconsulted_url', 'host' => 'unconsulted.example'],
            ['index' => 2, 'code' => 'invalid_usage_metadata', 'host' => 'supplier.example'],
        ]);
});
```

- [ ] **Step 2: Run the test and verify the missing API failure**

Run:

```bash
php artisan test --compact tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php --filter='partitions valid guidance evidence'
```

Expected: FAIL because `partitionCandidates()` and the result class do not exist.

- [ ] **Step 3: Create the immutable result class**

Run:

```bash
php artisan make:class Data/IngredientGuidanceEvidenceValidationResult --no-interaction
```

Then replace the generated class with:

```php
<?php

namespace App\Data;

final readonly class IngredientGuidanceEvidenceValidationResult
{
    /**
     * @param  list<array<string, mixed>>  $accepted
     * @param  list<array{index:int,code:string,host:?string}>  $rejected
     */
    public function __construct(
        public array $accepted,
        public array $rejected,
    ) {}
}
```

- [ ] **Step 4: Run the focused test again**

Expected: still FAIL because the policy has not implemented partitioning.

- [ ] **Step 5: Commit the contract and failing test**

```bash
git add app/Data/IngredientGuidanceEvidenceValidationResult.php tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php
git commit -m "test: define guidance evidence partition contract"
```

### Task 2: Make evidence policy classify rows without weakening validation

**Files:**
- Modify: `app/Services/IngredientEnrichment/IngredientGuidanceEvidencePolicy.php`
- Modify: `lang/en/ingredient_enrichment_admin.php`
- Modify: `tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php`

- [ ] **Step 1: Add rejection-code coverage**

Add table-driven tests for these exact codes:

```php
$cases = [
    'invalid_shape' => 'not-an-array',
    'invalid_field' => guidanceEvidenceCandidate(['field' => 'proposal.inci_name']),
    'invalid_url' => guidanceEvidenceCandidate(['source_url' => 'not-a-url']),
    'blocked_domain' => guidanceEvidenceCandidate(['source_url' => 'https://amazon.com/apricot-oil']),
    'unconsulted_url' => guidanceEvidenceCandidate(['source_url' => 'https://other.example/apricot-oil']),
    'invalid_classification' => guidanceEvidenceCandidate(['claim_type' => 'not-configured']),
    'invalid_usage_metadata' => guidanceEvidenceCandidate(['usage_application' => 'not_applicable']),
];
```

For each case, assert the row is absent from `accepted`, the rejection contains its zero-based index and code, and only the parsed hostname is retained. Do not store summaries, query strings, or page content in rejection diagnostics.

- [ ] **Step 2: Run the policy test and verify failures**

```bash
php artisan test --compact tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php
```

Expected: FAIL on the new partition assertions.

- [ ] **Step 3: Implement `partitionCandidates()` and refactor strict validation to reuse it**

Add:

```php
public function partitionCandidates(array $candidates, array $consultedSources): IngredientGuidanceEvidenceValidationResult
{
    $consultedUrls = $this->consultedUrlMap($consultedSources);
    $accepted = [];
    $rejected = [];

    foreach ($candidates as $index => $candidate) {
        $evaluation = $this->evaluateCandidate($candidate, $index, $consultedUrls);

        if ($evaluation['accepted'] !== null) {
            $accepted[] = $evaluation['accepted'];
        } else {
            $rejected[] = $evaluation['rejection'];
        }
    }

    return new IngredientGuidanceEvidenceValidationResult($accepted, $rejected);
}
```

Use a private evaluator with this exact return shape:

```php
/**
 * @return array{
 *   accepted:array<string,mixed>|null,
 *   rejection:array{index:int,code:string,host:?string}|null
 * }
 */
private function evaluateCandidate(mixed $candidate, int $index, array $consultedUrls): array
```

The evaluator must preserve every current rule:

- exact candidate keys;
- `field === 'proposal.info_markdown'`;
- non-empty source name and summary;
- valid canonical HTTP(S) URL;
- blocked-domain rejection;
- exact canonical correlation to `web_search_call.action.sources`;
- configured closed vocabularies;
- recommendation source-kind/application/basis/bounds rules;
- decimal bounds from 0 through 100 and minimum not greater than maximum.

Change `validateCandidates()` to call `partitionCandidates()`. If rejections exist, throw a `ValidationException` for the first rejection using its specific code rather than the generic website message. If none exist, return `accepted`. This preserves strict callers while enabling tolerant research orchestration.

Add these exact admin validation messages and map rejection codes to them:

```php
'guidance_evidence_invalid_shape' => 'A proposed guidance citation has an invalid structure.',
'guidance_evidence_invalid_field' => 'A proposed guidance citation targets a field outside ingredient guidance.',
'guidance_evidence_invalid_url' => 'A proposed guidance citation does not contain a valid HTTP(S) URL.',
'guidance_evidence_blocked_domain' => 'A proposed guidance citation comes from a blocked website.',
'guidance_evidence_unconsulted_url' => 'A proposed guidance citation was not present in the pages consulted for this request.',
'guidance_evidence_invalid_classification' => 'A proposed guidance citation has an unsupported evidence classification.',
'guidance_evidence_invalid_usage_metadata' => 'A proposed usage recommendation has inconsistent application, basis, source, or percentage metadata.',
```

- [ ] **Step 4: Run all evidence-policy tests**

```bash
php artisan test --compact tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit the policy boundary**

```bash
git add app/Services/IngredientEnrichment/IngredientGuidanceEvidencePolicy.php lang/en/ingredient_enrichment_admin.php tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php
git commit -m "fix: partition rejected guidance evidence"
```

### Task 3: Keep transport parsing separate from evidence policy

**Files:**
- Modify: `app/Services/IngredientEnrichment/OpenAiIngredientGapResearchClient.php`
- Test: `tests/Feature/IngredientEditorialClientTest.php`

- [ ] **Step 1: Add a failing transport-boundary test**

Fake a completed OpenAI response containing one validly shaped candidate whose URL is not present in `web_search_call.action.sources`. Assert the client returns the raw candidate and consulted sources without throwing. Keep existing tests proving that invalid top-level output, failed HTTP responses, missing API keys, and malformed JSON still throw.

```php
$response = app(IngredientGuidanceResearchClient::class)->research($facts);

expect($response->candidateEvidence)->toHaveCount(1)
    ->and($response->sources)->toBe([]);
```

- [ ] **Step 2: Run the focused client tests**

```bash
php artisan test --compact tests/Feature/IngredientEditorialClientTest.php --filter='guidance research'
```

Expected: FAIL because the client currently calls `validateCandidates()`.

- [ ] **Step 3: Remove business-policy validation from the client**

Remove `IngredientGuidanceEvidencePolicy` constructor injection and return the schema-validated `candidate_evidence` unchanged. Preserve extraction of `web_search_call.action.sources`, provider IDs, token accounting, and web-search counts.

Also constrain the structured-output schema field:

```php
'field' => [
    'type' => 'string',
    'enum' => ['proposal.info_markdown'],
],
```

Expand the research instructions with the cross-field contract:

```text
For non-usage claims, set usage_application and percentage_basis to not_applicable and both percentage bounds to null. For usage claims, use formulation_recommendation, a recommendation-capable source kind, cosmetics or soapmaking application, a matching basis, and at least one exact source-provided bound.
```

- [ ] **Step 4: Run the client tests**

Expected: PASS, including provider/network/top-level failure coverage.

- [ ] **Step 5: Commit the transport correction**

```bash
git add app/Services/IngredientEnrichment/OpenAiIngredientGapResearchClient.php tests/Feature/IngredientEditorialClientTest.php
git commit -m "refactor: separate guidance transport from evidence policy"
```

### Task 4: Consume partitioned evidence in both enrichment modes

**Files:**
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentPipeline.php`
- Modify: `app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php`
- Modify: `lang/en/ingredient_enrichment.php`
- Test: `tests/Feature/HybridIngredientEnrichmentPipelineTest.php`
- Test: `tests/Feature/IngredientGuidanceRefreshJobTest.php`

- [ ] **Step 1: Add a mixed-row full-enrichment test**

Return one valid and one invalid guidance candidate from the fake research client. Assert the pipeline completes, authoring receives only the valid row, the research stage contains one rejection, and the resulting warnings include the rejection summary.

- [ ] **Step 2: Add mixed-row and all-rejected guidance-rerun tests**

For mixed evidence, assert:

```php
expect($item->status)->toBe(IngredientEnrichmentItemStatus::Warning)
    ->and($item->result['guidance_evidence'])->toHaveCount(1)
    ->and(data_get($item->research_stages, 'ai_guidance_research.data.rejected_evidence'))->toHaveCount(1);
```

For all rejected evidence, assert:

- authoring receives `guidance_evidence === []`;
- deterministic ingredient facts remain present in the authoring context;
- the generated proposal remains reviewable with `Warning` status;
- final `guidance_evidence` and item `sources` are empty;
- the warning clearly says no researched evidence survived validation;
- rejected rows are absent from authoring context, final result, item sources, and applied provenance.

- [ ] **Step 3: Run the new tests and verify all-or-nothing failures**

```bash
php artisan test --compact tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/IngredientGuidanceRefreshJobTest.php
```

Expected: FAIL because both orchestrators still call strict `validateCandidates()`.

- [ ] **Step 4: Add reviewer-facing aggregate warnings**

Add English translation entries:

```php
'guidance_evidence_rejected' => ':count researched evidence item was rejected because it did not meet the evidence rules.|:count researched evidence items were rejected because they did not meet the evidence rules.',
'guidance_evidence_none_accepted' => 'No researched evidence passed validation; the proposed guidance uses catalogue facts only.',
```

- [ ] **Step 5: Partition in both orchestrators**

Replace strict calls with:

```php
$validation = $this->guidanceEvidencePolicy->partitionCandidates(
    $response->candidateEvidence,
    $response->sources,
);
$candidateEvidence = $validation->accepted;
$guidanceEvidence = $this->guidanceEvidencePolicy->toPersisted(
    $candidateEvidence,
    CarbonImmutable::now(),
);
$warnings = collect($response->warnings)
    ->when($validation->rejected !== [], fn ($warnings) => $warnings->push(
        trans_choice(
            'ingredient_enrichment.warnings.guidance_evidence_rejected',
            count($validation->rejected),
            ['count' => count($validation->rejected)],
        ),
    ))
    ->when($candidateEvidence === [], fn ($warnings) => $warnings->push(
        __('ingredient_enrichment.warnings.guidance_evidence_none_accepted'),
    ))
    ->filter()
    ->unique()
    ->values()
    ->all();
```

Persist this key in the research stage:

```php
'rejected_evidence' => $validation->rejected,
```

When research is disabled, use `rejected_evidence => []` and do not emit the “none accepted” warning. Only an attempted research pass with zero accepted rows receives that warning.

- [ ] **Step 6: Run both orchestration test files**

Expected: PASS.

- [ ] **Step 7: Commit shared orchestration behavior**

```bash
git add app/Services/IngredientEnrichment/IngredientEnrichmentPipeline.php app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php lang/en/ingredient_enrichment.php tests/Feature/HybridIngredientEnrichmentPipelineTest.php tests/Feature/IngredientGuidanceRefreshJobTest.php
git commit -m "fix: keep guidance reruns reviewable after evidence rejection"
```

### Task 5: Make persisted research stages auditable and resumable

**Files:**
- Modify: `app/Services/IngredientEnrichment/IngredientGuidanceStageRunner.php`
- Modify: `tests/Feature/IngredientGuidanceRefreshJobTest.php`

- [ ] **Step 1: Add a failing resume test with rejected diagnostics**

Persist a completed research stage containing one accepted row, one persisted guidance-evidence row, and one rejection. Retry authoring and assert research is not called again and the stored rejection survives unchanged.

- [ ] **Step 2: Run the resume test**

Expected: FAIL because `rejected_evidence` is not an allowed research-stage key.

- [ ] **Step 3: Extend research-stage validation**

Require `rejected_evidence` to be a list. Each row must have exactly:

```php
['index', 'code', 'host']
```

Validate `index` as a non-negative integer, `code` against the closed rejection-code list, and `host` as a string or null. Continue calling strict `validateCandidates()` only for the already accepted `candidate_evidence`, and continue requiring accepted candidate count to equal persisted `guidance_evidence` count. Do not compare rejected count to accepted count.

- [ ] **Step 4: Update every disabled/stored research-stage fixture**

Add:

```php
'rejected_evidence' => [],
```

to `emptyGuidanceResearchData()`, disabled-research fixtures, and stored-stage helpers.

- [ ] **Step 5: Run all guidance refresh job tests**

```bash
php artisan test --compact tests/Feature/IngredientGuidanceRefreshJobTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit stage compatibility**

```bash
git add app/Services/IngredientEnrichment/IngredientGuidanceStageRunner.php app/Services/IngredientEnrichment/IngredientEnrichmentPipeline.php app/Services/IngredientEnrichment/IngredientGuidanceRefreshProcessor.php tests/Feature/IngredientGuidanceRefreshJobTest.php
git commit -m "fix: persist guidance evidence rejection diagnostics"
```

### Task 6: Verify the full-enrichment verifier change remains narrow

**Files:**
- Modify if required: `app/Services/IngredientEnrichment/IngredientEnrichmentEvidenceVerifier.php`
- Test: `tests/Unit/Services/IngredientEnrichmentEvidenceVerifierTest.php`

- [ ] **Step 1: Strengthen the existing verifier tests**

Assert all three boundaries:

1. a consulted, non-blocked broad URL is allowed only for `proposal.info_markdown`;
2. the same URL is rejected for `proposal.inci_name`;
3. an unconsulted or blocked URL is rejected for `proposal.info_markdown`.

Replace the placeholder `expect(true)->toBeTrue()` with an explicit completion assertion:

```php
$verified = false;
app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, $consultedSources);
$verified = true;

expect($verified)->toBeTrue();
```

- [ ] **Step 2: Run the verifier tests**

```bash
php artisan test --compact tests/Unit/Services/IngredientEnrichmentEvidenceVerifierTest.php
```

Expected: PASS without widening identity rules.

- [ ] **Step 3: Commit the verifier guardrail**

```bash
git add app/Services/IngredientEnrichment/IngredientEnrichmentEvidenceVerifier.php tests/Unit/Services/IngredientEnrichmentEvidenceVerifierTest.php
git commit -m "test: keep broad guidance citations isolated"
```

### Task 7: Focused and regression verification

**Files:**
- No new files.

- [ ] **Step 1: Run the complete focused guidance/enrichment set**

```bash
php artisan test --compact \
  tests/Unit/Services/IngredientGuidanceEvidencePolicyTest.php \
  tests/Unit/Services/IngredientEnrichmentEvidenceVerifierTest.php \
  tests/Feature/IngredientEditorialClientTest.php \
  tests/Feature/IngredientGuidanceClientTest.php \
  tests/Feature/HybridIngredientEnrichmentPipelineTest.php \
  tests/Feature/IngredientEnrichmentPipelineTest.php \
  tests/Feature/IngredientGuidanceRefreshJobTest.php \
  tests/Feature/IngredientGuidanceRefreshActionTest.php
```

Expected: all pass.

- [ ] **Step 2: Format PHP and check whitespace**

```bash
vendor/bin/pint --dirty --format agent
git diff --check
```

Expected: Pint passes and `git diff --check` prints nothing.

- [ ] **Step 3: Refresh the code graph**

```bash
graphify update .
```

Expected: graph update completes.

- [ ] **Step 4: Run the full suite**

```bash
php artisan test --compact
```

Expected: all tests pass, with only established skips.

- [ ] **Step 5: Restart the queue worker and manually retry one failed guidance row**

```bash
php artisan queue:restart
```

Expected manual behavior:

- the item no longer fails because one evidence row is rejected;
- accepted source rows appear in the proposal;
- rejected rows are absent from guidance and provenance;
- the proposal is `Warning` when any row was rejected;
- an all-rejected response remains reviewable and explicitly says it uses catalogue facts only;
- ingredient identity remains displayed as `Apricot Oil (apricot_oil)`.

Do not retry production rows until the code is deployed and the production worker has restarted.

## Luna Max execution constraints

- Work only in the files listed above; preserve unrelated dirty-worktree changes.
- Do not revert existing user changes with `git checkout`, `git restore`, or `git reset`.
- Do not add a URL merely to make a fixture pass.
- Do not convert rejected evidence into warnings while still sending it to authoring.
- Do not mark provider/network failures successful.
- Do not commit unrelated files. Stage explicit paths for every commit.
- Before claiming completion, report the focused-suite and full-suite counts and show `git status --short` for the scoped files.
