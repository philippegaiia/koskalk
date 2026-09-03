# AI Enrichment Residual Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the remaining evidence, source-independence, identity-discovery, regulatory-label, and guidance-copy defects before the corroborated CAS/EC path is enabled in production, then compare Luna reasoning efforts on a stable pipeline.

**Architecture:** Keep deterministic identity and regulatory data entirely outside the model. Preserve every accepted evidence row through proposal editing and application, resolve publisher independence with a Public Suffix List rather than hostname heuristics, and keep discovery termination aligned with the names the matcher considers authoritative. Continue the renderer's drop-and-warn policy, but deterministically reject evidence-workflow language that should never reach catalogue prose.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, Eloquent, Laravel HTTP fakes, `jeremykendall/php-domain-parser` 6.x with a locally deployed Public Suffix List snapshot, SQLite for focused tests and PostgreSQL for the pre-production verification run.

---

## Scope and release order

The current working tree already contains fixes 1–9. The review documents did not change application code. Do not mix the residual work into that uncommitted delta.

Release order:

1. Checkpoint fixes 1–9 and their tests.
2. Preserve the two-authority evidence trail through edit and apply. This blocks production.
3. Replace hostname counting with registrable-publisher-domain counting. This blocks production.
4. Harden US declaration qualifier removal.
5. Align FDA discovery termination with matcher-authoritative names.
6. Enforce natural catalogue prose at the renderer boundary.
7. Run PostgreSQL, worker, and model-effort acceptance checks.

Do not enable corroborated secondary CAS/EC values in production until Tasks 1–3 pass together.

## File map

**Evidence lifecycle**

- Modify `app/Actions/IngredientEnrichment/EditIngredientEnrichmentProposal.php`: preserve and re-index all evidence rows belonging to an unchanged identifier.
- Modify `app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php`: pass all matching identifier evidence to `IngredientDataEntryService` and persist value provenance/field confidence in enrichment metadata.
- Test `tests/Feature/IngredientEnrichmentProposalEditingTest.php`: unchanged corroborated identifiers retain two evidence rows after an unrelated edit.
- Test `tests/Feature/PlatformIngredientEnrichmentImportTest.php`: the applied identifier has both evidence records and the applied ingredient retains structured provenance.

**Publisher independence**

- Create `app/Services/IngredientEnrichment/SourcePublisherDomainResolver.php`: turn a consulted URL into a PSL-backed registrable publisher domain, or `null` when it cannot be resolved safely.
- Modify `app/Services/IngredientEnrichment/CorroboratedIngredientIdentifierService.php`: inject the resolver and count publisher domains instead of literal hosts.
- Create `tests/Unit/Services/SourcePublisherDomainResolverTest.php`.
- Modify `tests/Unit/Services/CorroboratedIngredientIdentifierServiceTest.php`.
- Modify `composer.json` and `composer.lock` only after dependency approval.
- Add a deployable PSL snapshot at `resources/data/public-suffix-list.dat`; do not fetch it during an enrichment job.

**US declaration**

- Modify `app/Services/IngredientEnrichment/UsIngredientDeclarationService.php`.
- Modify `tests/Feature/UsIngredientDeclarationServiceTest.php`.

**FDA discovery**

- Modify `app/Services/IngredientEnrichment/Sources/OpenFdaSubstanceClient.php`.
- Modify `tests/Feature/OpenFdaSubstanceClientTest.php`.

**Guidance prose**

- Modify `app/Services/IngredientEnrichment/IngredientGuidanceDraftRenderer.php`.
- Modify `tests/Unit/Services/IngredientGuidanceDraftRendererTest.php`.

**Shared rules**

- Modify `.ai/rules/ingredient-enrichment.md` after the code is green so the rules describe the implemented boundaries: registrable publisher domains, evidence survival through apply, and deterministic rejection of evidence-layer catalogue prose.

## Task 0: Checkpoint the reviewed fixes

- [ ] **Step 1: Confirm the tree still represents the reviewed delta**

Run:

```bash
git status --short
git diff --check
```

Expected: fixes 1–9 are still uncommitted; `git diff --check` reports no whitespace errors. The two review Markdown files and Workbuddy memory files are not application changes.

- [ ] **Step 2: Re-run the reviewed focused tests before making residual changes**

Run:

```bash
php artisan test --compact \
  tests/Feature/IngredientEnrichmentPipelineTest.php \
  tests/Feature/IngredientEnrichmentRetryTest.php \
  tests/Feature/IngredientEnrichmentVocabularyTest.php \
  tests/Feature/IngredientIdentityMatchServiceTest.php \
  tests/Feature/OpenFdaSubstanceClientTest.php \
  tests/Feature/UsIngredientDeclarationServiceTest.php \
  tests/Unit/Services/IngredientGuidanceDraftRendererTest.php \
  tests/Unit/Services/CorroboratedIngredientIdentifierServiceTest.php
```

Expected: all focused tests pass.

- [ ] **Step 3: Commit fixes 1–9 separately from unrelated baseline repairs**

Stage only the application, configuration, rule, and regression-test files listed in `docs/handoff-ai-enrichment-fixes-1-9.md`. Do not stage `.workbuddy-ai/**`, `scripts/sync-boost-skills.sh`, or the review documents with product code.

Commit:

```bash
git commit -m "fix: adjudicate ingredient enrichment findings"
```

Then stage `tests/Feature/MediaAssetFoundationTest.php` and `tests/Feature/ProductionBenchProductionCalendarTest.php` as a separate test-only commit:

```bash
git commit -m "test: repair media and calendar baselines"
```

Expected: the residual implementation begins from reviewable commits rather than one large dirty-tree delta.

## Task 1: Preserve corroborating evidence through edit and apply

### 1A. Proposal editing

- [ ] **Step 1: Add a failing edit regression test**

In `tests/Feature/IngredientEnrichmentProposalEditingTest.php`, add a test that:

1. Creates one proposed secondary CAS identifier.
2. Adds two `evidence` rows with the same field, `proposal.identifiers.0`, but different publisher URLs.
3. Adds one `source_confirmed` provenance row containing both URLs.
4. Edits only `proposal.display_name`.
5. Asserts that the edited result still has exactly two evidence rows for the identifier and still has both provenance URLs.

The core assertion must use known values:

```php
$identifierEvidence = collect($edited->result['evidence'])
    ->where('field', 'proposal.identifiers.0')
    ->pluck('source_url')
    ->values()
    ->all();

expect($identifierEvidence)->toBe([
    'https://supplier-a.example/technical/marula-oil.pdf',
    'https://supplier-b.example/technical/marula-oil.pdf',
])->and(collect($edited->result['value_provenance'])
    ->firstWhere('field', 'proposal.identifiers.0')['source_urls'])
    ->toBe([
        'https://supplier-a.example/technical/marula-oil.pdf',
        'https://supplier-b.example/technical/marula-oil.pdf',
    ]);
```

- [ ] **Step 2: Run the test and verify the current failure**

Run:

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentProposalEditingTest.php --filter="preserves every corroborating evidence row"
```

Expected: FAIL because `synchronizeSourceEvidence()` collapses the two rows into the identifier's single inline source.

- [ ] **Step 3: Preserve evidence by identifier identity, not array index alone**

Change `synchronizeSourceEvidence()` to receive both the normalized proposal before editing and the submitted proposal after editing:

```php
'evidence' => $this->synchronizeSourceEvidence(
    $currentResult['evidence'] ?? [],
    $currentProposal,
    $proposal,
),
```

For `proposal.identifiers.*`:

- Match old and new rows by normalized `scheme:value`.
- When scheme, value, and inline source attributes are unchanged, copy every old evidence row and rewrite only its `field` index.
- When the identifier or its inline source attributes changed, create one evidence row from the newly submitted inline source, preserving the existing reviewer-supplied behavior.
- Drop evidence for an identifier that the reviewer removed.
- Keep the existing synchronization behavior for aliases, COSING functions, and market labels.

Add focused private helpers with explicit array-shape PHPDoc:

```php
private function identifierKey(array $row): string
{
    return mb_strtolower(trim((string) ($row['scheme'] ?? '')))
        .':'
        .mb_strtolower(trim((string) ($row['value'] ?? '')));
}

private function sourceAttributes(array $row): array
{
    return collect($row)->only([
        'source_name',
        'source_url',
        'source_tier',
        'confidence',
        'source_version',
        'source_updated_at',
        'retrieved_at',
    ])->all();
}
```

Do not infer corroboration from `value_provenance.source_urls`; the accepted `evidence` rows remain authoritative.

- [ ] **Step 4: Run proposal-edit tests**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentProposalEditingTest.php
```

Expected: PASS, including the existing test where a reviewer explicitly changes an identifier's source.

### 1B. Application

- [ ] **Step 5: Add a failing apply regression test**

In `tests/Feature/PlatformIngredientEnrichmentImportTest.php`, apply a result containing one secondary CAS and two evidence rows for `proposal.identifiers.0`. Assert:

```php
$identifier = $ingredient->fresh()
    ->identifiers()
    ->where('scheme', 'cas')
    ->where('normalized_value', '68956-68-3')
    ->firstOrFail();

expect($identifier->evidence()->orderBy('source_url')->pluck('source_url')->all())
    ->toBe([
        'https://supplier-a.example/technical/marula-oil.pdf',
        'https://supplier-b.example/technical/marula-oil.pdf',
    ])
    ->and(data_get($ingredient->fresh()->source_data, 'enrichment.core.value_provenance.0.source_urls'))
    ->toBe([
        'https://supplier-a.example/technical/marula-oil.pdf',
        'https://supplier-b.example/technical/marula-oil.pdf',
    ]);
```

- [ ] **Step 6: Run the test and verify the current failure**

```bash
php artisan test --compact tests/Feature/PlatformIngredientEnrichmentImportTest.php --filter="applies every corroborating identifier evidence row"
```

Expected: FAIL because `syncCanonicalAndIdentity()` currently manufactures one evidence row from the identifier's single inline URL.

- [ ] **Step 7: Pass accepted evidence into identity synchronization**

Modify `ApplyPlatformIngredientEnrichment::syncCanonicalAndIdentity()` to accept the validated result evidence. For identifier index `N`, collect every evidence row whose field is exactly `proposal.identifiers.N` and pass those rows under that identifier's `identifier_evidence.evidence` array.

Retain the inline-source fallback only when the result contains no evidence rows for that identifier, so legacy imports do not silently lose their single source.

At the same apply boundary, persist the already validated arrays separately:

```php
data_set($sourceData, 'enrichment.core', [
    // existing metadata...
    'field_confidence' => is_array($result['field_confidence'] ?? null)
        ? $result['field_confidence']
        : [],
    'value_provenance' => is_array($result['value_provenance'] ?? null)
        ? $result['value_provenance']
        : [],
]);
```

Do not merge confidence into source tier and do not label corroborated evidence as official.

- [ ] **Step 8: Run the evidence lifecycle tests**

```bash
php artisan test --compact \
  tests/Feature/IngredientEnrichmentProposalEditingTest.php \
  tests/Feature/PlatformIngredientEnrichmentImportTest.php \
  tests/Feature/IngredientDataEntryServiceTest.php
```

Expected: PASS. Existing Admin saves that omit `identifier_evidence` must continue preserving prior evidence.

- [ ] **Step 9: Commit the blocker fix**

```bash
git add app/Actions/IngredientEnrichment/EditIngredientEnrichmentProposal.php \
  app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php \
  tests/Feature/IngredientEnrichmentProposalEditingTest.php \
  tests/Feature/PlatformIngredientEnrichmentImportTest.php
git commit -m "fix: preserve enrichment identifier provenance"
```

## Task 2: Resolve independent publishers with the Public Suffix List

This task requires owner approval for one production dependency. Do not implement a two-label heuristic: it incorrectly collapses or separates publishers under multi-label suffixes and private suffixes.

- [ ] **Step 1: Obtain dependency approval, then install the parser**

After approval:

```bash
composer require jeremykendall/php-domain-parser:^6.4
```

Keep a reviewed PSL snapshot at `resources/data/public-suffix-list.dat`. Runtime enrichment must read the local file and must not download network data. If the parser or PSL cannot resolve a URL safely, return `null`; an unresolved publisher never counts toward corroboration.

- [ ] **Step 2: Write resolver tests first**

Create `tests/Unit/Services/SourcePublisherDomainResolverTest.php` covering:

```php
it('collapses sibling subdomains to one publisher domain', function (): void {
    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve('https://docs.supplier.com/path'))->toBe('supplier.com')
        ->and($resolver->resolve('https://shop.supplier.com/other'))->toBe('supplier.com');
});

it('handles multi-label public suffixes without collapsing unrelated publishers', function (): void {
    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve('https://docs.supplier-a.co.uk/path'))->toBe('supplier-a.co.uk')
        ->and($resolver->resolve('https://shop.supplier-b.co.uk/path'))->toBe('supplier-b.co.uk');
});

it('fails closed for malformed urls and unknown suffixes', function (): void {
    $resolver = app(SourcePublisherDomainResolver::class);

    expect($resolver->resolve('not-a-url'))->toBeNull()
        ->and($resolver->resolve('https://localhost/path'))->toBeNull();
});
```

These tests parse hosts only; they do not make DNS or HTTP requests. The implementation API and local-data requirement follow the package's official documentation: <https://github.com/jeremykendall/php-domain-parser>.

- [ ] **Step 3: Verify the resolver tests fail before implementation**

```bash
php artisan test --compact tests/Unit/Services/SourcePublisherDomainResolverTest.php
```

Expected: FAIL because the resolver does not exist.

- [ ] **Step 4: Implement a fail-closed resolver**

Create `SourcePublisherDomainResolver` as a container-resolved service. Load `Pdp\Rules` lazily from `resource_path('data/public-suffix-list.dat')`, parse the URL host with `Pdp\Domain::fromIDNA2008()`, call `Rules::resolve()`, require a known suffix and a non-empty registrable domain, and catch `Pdp\CannotProcessHost` to return `null`.

Its public contract is:

```php
public function resolve(string $url): ?string
```

- [ ] **Step 5: Add corroboration regressions**

Extend `tests/Unit/Services/CorroboratedIngredientIdentifierServiceTest.php` with:

- `docs.supplier.com` + `shop.supplier.com` from one registrable publisher: rejected.
- `supplier-a.com` + `supplier-b.com`: accepted.
- two unrelated `*.co.uk` publishers: accepted.
- one parseable source plus one malformed/unknown source: rejected.

- [ ] **Step 6: Inject the resolver and count publisher domains**

Replace `authorityHosts()` with a method that maps each consulted source URL through `SourcePublisherDomainResolver::resolve()`, filters `null`, deduplicates publisher domains, and requires at least two.

Rename variables and PHPDoc from `host` to `publisherDomain`. Update the class comment because literal hosts are no longer the authority boundary.

- [ ] **Step 7: Run and commit**

```bash
php artisan test --compact \
  tests/Unit/Services/SourcePublisherDomainResolverTest.php \
  tests/Unit/Services/CorroboratedIngredientIdentifierServiceTest.php
```

Expected: PASS.

```bash
git add composer.json composer.lock resources/data/public-suffix-list.dat \
  app/Services/IngredientEnrichment/SourcePublisherDomainResolver.php \
  app/Services/IngredientEnrichment/CorroboratedIngredientIdentifierService.php \
  tests/Unit/Services/SourcePublisherDomainResolverTest.php \
  tests/Unit/Services/CorroboratedIngredientIdentifierServiceTest.php
git commit -m "fix: corroborate identifiers across independent publishers"
```

## Task 3: Normalize editorial qualifiers in US declaration labels

- [ ] **Step 1: Add a dataset for the reproduced failures**

In `tests/Feature/UsIngredientDeclarationServiceTest.php`, drive the public `propose()` method with this dataset:

```php
dataset('qualified marula display names', [
    'comma punctuation' => ['Organic, Virgin Marula Oil'],
    'non-breaking hyphen' => ['Cold‑Pressed Organic Marula Oil'],
    'parenthesized qualifier' => ['Organic (Virgin) Marula Oil'],
    'mixed case' => ['ORGANIC Refined Marula Oil'],
]);
```

Every row must produce exactly `Marula (Sclerocarya Birrea) Oil`.

Add positive controls proving that meaningful descriptors survive:

- `Organic Sweet Almond Oil` keeps `Sweet`.
- `Organic High Oleic Sunflower Oil` keeps `High Oleic`.

- [ ] **Step 2: Run and verify the failures**

```bash
php artisan test --compact tests/Feature/UsIngredientDeclarationServiceTest.php
```

Expected: the comma, non-breaking-hyphen, and parenthesized cases fail on the current tokenization.

- [ ] **Step 3: Normalize before matching qualifiers**

In `stripEditorialQualifiers()`:

1. Normalize Unicode dash characters U+2010 through U+2015 and U+2212 to `-`.
2. Match editorial phrases with either spaces or hyphens between their words.
3. For qualifier comparison only, trim leading/trailing Unicode punctuation from each token.
4. Remove the full original token when the cleaned token is an editorial qualifier.
5. Collapse leftover whitespace and dangling separators.
6. Preserve words not in the curated blocklist.

Do not broaden the blocklist during this task. The intended fix is robust tokenization, not guessing more marketing vocabulary.

- [ ] **Step 4: Run and commit**

```bash
php artisan test --compact tests/Feature/UsIngredientDeclarationServiceTest.php
```

Expected: PASS.

```bash
git add app/Services/IngredientEnrichment/UsIngredientDeclarationService.php \
  tests/Feature/UsIngredientDeclarationServiceTest.php
git commit -m "fix: normalize qualifiers in US ingredient labels"
```

## Task 4: Align FDA discovery termination with matcher-authoritative names

- [ ] **Step 1: Add the alias-suppression regression**

In `tests/Feature/OpenFdaSubstanceClientTest.php`, fake two responses:

1. A hydrogenated candidate whose ordinary `names[]` contains `ARGANIA SPINOSA KERNEL OIL`, but whose INCI-qualified name is `HYDROGENATED ARGANIA SPINOSA KERNEL OIL`.
2. The real argan-oil candidate whose INCI-qualified name equals the query variant.

Assert that two requests occur and that the final candidate set contains the real record. This test must distinguish an ordinary alias from a name carrying `name_orgs: INCI`.

- [ ] **Step 2: Run and verify the current premature stop**

```bash
php artisan test --compact tests/Feature/OpenFdaSubstanceClientTest.php --filter="ordinary alias"
```

Expected: FAIL because `batchNamesTermExactly()` currently includes `common_name` and every ordinary `names[]` alias.

- [ ] **Step 3: Use the same authoritative name set as the matcher**

Restrict `batchNamesTermExactly()` to `inci_name` plus `inci_names`. For normalized OpenFDA candidates, that effectively means `inci_names`; keep the `inci_name` key in the helper for consistency with candidates from other sources.

Do not remove ordinary aliases from `normalizeCandidate()`: they remain useful for display and review. They simply must not terminate discovery.

- [ ] **Step 4: Run the source and matcher tests**

```bash
php artisan test --compact \
  tests/Feature/OpenFdaSubstanceClientTest.php \
  tests/Feature/IngredientIdentityMatchServiceTest.php
```

Expected: PASS. Existing sibling-form protection remains green.

- [ ] **Step 5: Commit**

```bash
git add app/Services/IngredientEnrichment/Sources/OpenFdaSubstanceClient.php \
  tests/Feature/OpenFdaSubstanceClientTest.php
git commit -m "fix: continue FDA discovery past ordinary aliases"
```

## Task 5: Enforce natural guidance prose deterministically

The prompt already asks for natural prose, but prompt text is not enforcement. Preserve drop-and-warn behavior: omit the bad claim, keep the rest of the draft, and add the existing omission warning.

- [ ] **Step 1: Replace the test fixture that currently blesses meta-language**

In the first renderer test, replace:

```text
A supplier describes this product grade as suitable for the oil phase.
```

with a direct formulation sentence such as:

```text
Add it to the oil phase of anhydrous products or emulsions.
```

- [ ] **Step 2: Add failing meta-prose cases**

Add a dataset asserting omission and a warning for:

```php
dataset('guidance evidence meta prose', [
    ['A documented product grade is a reddish, viscous liquid.'],
    ['For this product grade, add it to the oil phase.'],
    ['The specified cold-pressed grade is water-insoluble.'],
    ['A manufacturer recommends this range.'],
    ['A supplier describes this product grade as suitable for emulsions.'],
]);
```

Add positive controls that remain accepted:

- `A refined grade can be added to a heated oil phase.`
- `Typical use level: 1–8% of the total formula.`
- `In a Pickering-emulsion experiment, dispersion was observed under the tested conditions.`

- [ ] **Step 3: Run and verify the prompt-only gap**

```bash
php artisan test --compact tests/Unit/Services/IngredientGuidanceDraftRendererTest.php
```

Expected: the new negative cases currently render instead of being dropped.

- [ ] **Step 4: Add a narrow evidence-meta prose guard**

Add a private predicate called from `validateClaim()` alongside `isCatalogueMetaClaim()`. It should reject:

- `this product grade` and `for this grade` references that fail to name an actual grade;
- evidence-layer adjectives (`cited`, `documented`, `specified`, `referenced`, `supplied`, `reported`, `listed`, `verified`) when they qualify the material, grade, profile, or data;
- source-attribution narration such as `a supplier recommends` or `a manufacturer describes`.

Do not reject explicit material distinctions such as `a refined grade`, and do not reject bounded experimental language such as `under the tested conditions`.

Do not rewrite claims with regular expressions. Rewriting could silently alter evidence fidelity; drop-and-warn is the established safe behavior.

- [ ] **Step 5: Run and commit**

```bash
php artisan test --compact tests/Unit/Services/IngredientGuidanceDraftRendererTest.php
```

Expected: PASS.

```bash
git add app/Services/IngredientEnrichment/IngredientGuidanceDraftRenderer.php \
  tests/Unit/Services/IngredientGuidanceDraftRendererTest.php
git commit -m "fix: keep evidence meta language out of guidance"
```

## Task 6: Update shared rules after behavior is green

- [ ] **Step 1: Amend `.ai/rules/ingredient-enrichment.md`**

Record these implemented invariants:

- publisher independence uses PSL-backed registrable domains, including private suffix rules, and fails closed;
- every corroborating evidence row survives proposal edits and is applied to the final identifier;
- applied enrichment metadata retains `field_confidence` and `value_provenance` separately;
- the guidance renderer drops evidence-workflow language rather than relying only on prompt compliance.

Remove the now-stale phrase that defines independence as merely `www`-stripped literal hosts.

- [ ] **Step 2: Commit the rule correction**

```bash
git add .ai/rules/ingredient-enrichment.md
git commit -m "docs: record enrichment evidence boundaries"
```

## Task 7: Verification and model-effort decision

- [ ] **Step 1: Format changed PHP**

```bash
vendor/bin/pint --dirty --format agent
```

Expected: formatter exits successfully. Re-run the focused tests if Pint changes code.

- [ ] **Step 2: Run the complete focused suite**

```bash
php artisan test --compact \
  tests/Feature/IngredientEnrichmentProposalEditingTest.php \
  tests/Feature/PlatformIngredientEnrichmentImportTest.php \
  tests/Feature/IngredientDataEntryServiceTest.php \
  tests/Feature/IngredientEnrichmentPipelineTest.php \
  tests/Feature/IngredientIdentityMatchServiceTest.php \
  tests/Feature/OpenFdaSubstanceClientTest.php \
  tests/Feature/UsIngredientDeclarationServiceTest.php \
  tests/Unit/Services/IngredientGuidanceDraftRendererTest.php \
  tests/Unit/Services/CorroboratedIngredientIdentifierServiceTest.php \
  tests/Unit/Services/SourcePublisherDomainResolverTest.php
```

Expected: PASS.

- [ ] **Step 3: Run the full suite on SQLite**

```bash
php artisan test --compact
```

Expected baseline: at least 2880 passed, 0 failed, with only the known skips; the final count will be higher because of the new regressions.

- [ ] **Step 4: Run the focused and full suites against an isolated PostgreSQL test database**

Use the production PostgreSQL driver and schema in a disposable CI/staging database, never the production database. Run the same focused command and then:

```bash
DB_CONNECTION=pgsql php artisan test --compact
```

Expected: PASS with no order-sensitive JSON comparison failures. The environment must supply test-only PostgreSQL credentials before this command is allowed to run.

- [ ] **Step 5: Verify the production-equivalent worker boundary**

Start the staging worker with the deployed production command and confirm it uses `--timeout=0` or a timeout greater than 2000 seconds. Confirm:

- job timeout: 2000 seconds;
- queue `retry_after`: 2100 seconds;
- provider-call timeout: 600 seconds;
- no stale `WithoutOverlapping` lock remains after a forced provider timeout.

Run one complete Marula enrichment through that worker before deployment.

- [ ] **Step 6: Measure the identity ambiguity gate rate**

Run the same representative catalogue sample at the fixes-1–9 checkpoint and at the residual-hardening HEAD, using identical cached deterministic source responses. Record:

- total ingredients attempted;
- successful deterministic identity matches;
- `identity_unresolved` count;
- unresolved rows carrying `Identity candidates remain ambiguous and require human review.`;
- unresolved rows caused by material-form mismatch rather than a score tie.

Inspect tied candidates by UNII and INCI name. Do not weaken the ambiguity gate merely to reduce the count: duplicate registry records remain a human-review condition. This measurement decides whether a UI/reporting improvement is needed; it does not block the correctness release unless the fixed FDA traversal causes a material regression.

- [ ] **Step 7: Run a controlled Luna xhigh versus max benchmark**

Use the same clean snapshots of Marula oil, Apricot kernel oil, and Buriti oil. Run guidance refreshes—not full identity reruns—once with Luna xhigh and once with Luna max. Deterministic identity should already be identical and is not a model-selection criterion.

For each run record:

- elapsed time and timeout status;
- input and output tokens;
- accepted guidance evidence count;
- rendered claims and dropped-claim warnings;
- whether Overview, Formulation use, and Soapmaking contain useful ingredient-specific text;
- presence of forbidden meta-language;
- reviewer edits required before approval.

Choose max only if it materially improves accepted, natural, ingredient-specific guidance without increasing timeout incidence. Otherwise keep xhigh. Do not use a more expensive reasoning effort to compensate for deterministic identity, provenance, or validation defects.

- [ ] **Step 8: Refresh the architecture graph**

```bash
graphify update .
```

Expected: the evidence and publisher-domain services appear in the refreshed graph.

## Final acceptance criteria

- A corroborated CAS/EC value cannot be admitted by two subdomains of one publisher.
- An unresolved or malformed publisher domain never counts as independent evidence.
- An unchanged corroborated identifier retains both evidence rows after proposal editing.
- Applying that proposal creates both `IngredientIdentifierEvidence` rows.
- Applied metadata retains field confidence and value provenance separately.
- `Organic,`, `(Virgin)`, and `Cold‑Pressed` do not leak into US declaration labels.
- An ordinary FDA alias cannot stop discovery before an authoritative INCI-name candidate is queried.
- Guidance containing `documented product grade` or `a manufacturer recommends` is omitted with a warning; natural direct prose remains.
- Focused tests, the full SQLite suite, and a production-driver PostgreSQL run are green.
- A staging worker completes a Marula run with production-equivalent timeout settings.
- Model effort is chosen from guidance quality, latency, and token evidence collected after deterministic correctness is restored.
