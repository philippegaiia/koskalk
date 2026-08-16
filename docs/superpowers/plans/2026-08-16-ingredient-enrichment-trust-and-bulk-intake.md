# Ingredient Enrichment Trust and Bulk Intake Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an Admin paste or upload one or many incomplete ingredient identities, research each row independently, review or edit every proposal, and promote only approved rows into valid platform ingredients while correcting the enrichment trust and evidence model.

**Architecture:** Keep incomplete rows in a new intake aggregate that never participates in catalogue queries. Generalize an enrichment batch item to target exactly one existing ingredient or one intake item, but keep one normalized subject snapshot contract for the research pipeline. Deterministic identity stages remain authoritative only for exact value-correlated evidence; AI and reviewer values have explicit provenance. Approval stays per item, and an intake promotion applies all canonical writes atomically through existing domain services.

**Tech Stack:** PHP 8.5, Laravel 13.25, PostgreSQL in development/production, SQLite in tests, Laravel queues/job batching/private storage, Filament 5.7, Livewire 4, Pest 4.7, OpenAI Responses API structured output.

---

## Approved design and implementation constraints

- Approved design: `docs/superpowers/specs/2026-08-15-ingredient-enrichment-trust-and-bulk-intake-design.md`.
- Preserve unrelated dirty-worktree changes. Do not reset, rewrite, or stage files outside the active task.
- Before each task, reread `.ai/rules/index.md`, every matching rule, and search `.ai/rules` for the task vocabulary.
- Use Laravel Boost `search-docs` before framework-facing work when the tool is available. Otherwise inspect the installed Laravel 13.25 / Filament 5.7 APIs and sibling code.
- Use TDD for every behavior: add one focused failing assertion, run it red, implement the minimum, and run it green.
- Do not add dependencies. Parse CSV with PHP's native CSV facilities and the existing private filesystem.
- Routine tests must not call external services. Use `Http::fake()` and compact committed fixtures.
- Never mutate a canonical ingredient during intake, research, editing, approval, or rejection. Only apply/promotion writes catalogue data.
- One queue job processes one subject. One failed row must not stop unrelated rows.
- Keep network and filesystem I/O outside retried database transactions.
- Keep the stable persisted research-stage keys. This feature changes behavior and subjects, not historical stage-key vocabulary.
- Preserve reviewed values and evidence unless the reviewer explicitly approves their replacement.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes, `vendor/bin/filacheck --fix` after changes under `app/Filament`, and `graphify update .` after code changes.

## Settled implementation choices

1. An intake-origin enrichment item always targets `ingredient_intake_item_id`, including when the duplicate resolution is **enrich existing**. The intake item then links `existing_ingredient_id`; the subject snapshot reads that ingredient's current reviewed state. This preserves intake audit and the exclusive-target invariant.
2. Exact duplicate resolution happens before research for that row. Changing it invalidates its snapshot and completed stages. Similar matches only warn.
3. One, ten, or seventy rows use the same workflow. Seventy is a capacity acceptance case, not a minimum. Intake uses its own maximum (default `100`); the direct existing-ingredient maximum remains `25`.
4. The batch family hint populates `research_family` for routing only. It never writes category or satisfies promotion classification.
5. Controlled gap research is an explicit persisted batch choice and defaults off. Deterministic stages and the editorial pass still run normally.
6. Source tier backing values already stored in JSON remain stable: `official`, `structured_mirror`, and `editorial`. User-facing labels become Official, Official mirror, and Approved secondary. Add `reviewer_supplied` without rewriting historical payloads.
7. `Edited and approved` is derived from edit audit fields. `Rejected` is the only new stored terminal review status.
8. PostgreSQL gets an actual exclusive-target check constraint. SQLite test databases get equivalent insert/update enforcement triggers so the same invariant is exercised in CI. Subject foreign keys restrict deletion to retain audit; the platform deletion service reports those dependencies and requires the related audit batch to be deleted first.
9. Intake promotion records the approving reviewer as taxonomy reviewer and sets `requires_admin_review = false`; it does not create a second approval cycle.
10. Individual human approval may accept conflicting or unresolved source confidence. Structural validity, subject identity/fingerprint, and required catalogue invariants still apply; confidence and warnings are review information, not an automated veto.
11. Results identify `subject_type` and `subject_public_id`. `catalog_key` remains required for direct existing-ingredient results and is null for intake results until promotion. This strict-output change bumps the prompt/result schema version; historical results remain displayable and must be retried into the new schema before reapproval if edited.

## File map

### New intake domain

- Create `app/Enums/IngredientIntakeBatchStatus.php`
- Create `app/Enums/IngredientIntakeItemStatus.php`
- Create `app/Enums/IngredientIntakeInputMethod.php`
- Create `app/Enums/IngredientResearchFamily.php`
- Create `app/Enums/IngredientDuplicateResolution.php`
- Create `app/Models/IngredientIntakeBatch.php`
- Create `app/Models/IngredientIntakeItem.php`
- Create `database/factories/IngredientIntakeBatchFactory.php`
- Create `database/factories/IngredientIntakeItemFactory.php`
- Create migrations for intake tables and enrichment subject/review extensions
- Create `app/Policies/IngredientIntakeBatchPolicy.php`

### Intake parsing and duplicates

- Create `app/Data/IngredientIntakeRow.php`
- Create `app/Services/IngredientIntake/IngredientIntakeParser.php`
- Create `app/Services/IngredientIntake/IngredientDuplicateDetector.php`
- Create `app/Actions/IngredientIntake/CreateIngredientIntakeBatch.php`
- Create `app/Actions/IngredientIntake/UpdateIngredientIntakeRow.php`
- Create `app/Actions/IngredientIntake/ResolveIngredientIntakeDuplicate.php`
- Create `app/Services/IngredientIntake/IngredientIntakeBatchDeletionService.php`

### Enrichment subject and workflow

- Create `app/Data/IngredientEnrichmentSubject.php`
- Create `app/Services/IngredientEnrichment/IngredientEnrichmentSubjectBuilder.php`
- Create `app/Actions/IngredientIntake/StartIngredientIntakeResearch.php`
- Create `app/Actions/IngredientEnrichment/RejectIngredientEnrichmentItem.php`
- Create `app/Actions/IngredientIntake/PromoteIngredientIntakeItem.php`
- Modify current batch, pipeline, review, retry, apply, validation, identity, facts, and provenance services
- Remove `app/Actions/IngredientEnrichment/ApproveSafeIngredientEnrichmentBatch.php`

### Filament

- Create an `IngredientIntakeBatches` resource with list/create/view pages, schemas, table, and item relation manager
- Modify the enrichment resource so intake subjects render useful labels and explicit per-item approve/reject actions
- Remove the safe-batch approval action

### Language and tests

- Modify `lang/en/ingredient_enrichment.php`
- Modify `lang/en/ingredient_enrichment_admin.php`
- Create `lang/en/ingredient_intake_admin.php`
- Add focused Pest coverage named in each task below

---

## Task 1: Add the intake aggregate and exclusive enrichment target schema

**Files:**

- Create: `app/Enums/IngredientIntakeBatchStatus.php`
- Create: `app/Enums/IngredientIntakeItemStatus.php`
- Create: `app/Enums/IngredientIntakeInputMethod.php`
- Create: `app/Enums/IngredientResearchFamily.php`
- Create: `app/Enums/IngredientDuplicateResolution.php`
- Create: `app/Models/IngredientIntakeBatch.php`
- Create: `app/Models/IngredientIntakeItem.php`
- Create: `database/factories/IngredientIntakeBatchFactory.php`
- Create: `database/factories/IngredientIntakeItemFactory.php`
- Create: `database/migrations/<timestamp>_create_ingredient_intake_batches_table.php`
- Create: `database/migrations/<timestamp>_create_ingredient_intake_items_table.php`
- Create: `database/migrations/<timestamp>_add_intake_subject_and_rejection_to_ingredient_enrichment.php`
- Modify: `app/Enums/IngredientEnrichmentItemStatus.php`
- Modify: `app/Models/IngredientEnrichmentBatch.php`
- Modify: `app/Models/IngredientEnrichmentBatchItem.php`
- Modify: `database/factories/IngredientEnrichmentBatchFactory.php`
- Modify: `database/factories/IngredientEnrichmentBatchItemFactory.php`
- Modify: `app/Services/PlatformIngredientDeletionService.php`
- Test: `tests/Feature/IngredientIntakeSchemaTest.php`
- Test: `tests/Feature/IngredientEnrichmentBatchSchemaTest.php`
- Test: `tests/Feature/PlatformIngredientDeletionTest.php`

- [ ] **Step 1: Generate the models, factories, policy, and migrations**

```bash
php artisan make:model IngredientIntakeBatch --factory --no-interaction
php artisan make:model IngredientIntakeItem --factory --no-interaction
php artisan make:policy IngredientIntakeBatchPolicy --model=IngredientIntakeBatch --no-interaction
php artisan make:migration create_ingredient_intake_batches_table --no-interaction
php artisan make:migration create_ingredient_intake_items_table --no-interaction
php artisan make:migration add_intake_subject_and_rejection_to_ingredient_enrichment --no-interaction
php artisan make:test --pest IngredientIntakeSchemaTest --no-interaction
```

- [ ] **Step 2: Write failing schema tests**

Cover the model relationships, enum casts, private upload metadata, original and normalized identity fields, duplicate candidates, resolution links, promotion links, and audit columns. Add database assertions proving both invalid enrichment targets fail:

```php
it('requires exactly one enrichment subject', function (): void {
    $batch = IngredientEnrichmentBatch::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $intakeItem = IngredientIntakeItem::factory()->create();

    expect(fn () => IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => $ingredient->id,
        'ingredient_intake_item_id' => $intakeItem->id,
        'catalog_key' => $ingredient->catalog_key,
    ]))->toThrow(QueryException::class);

    expect(fn () => IngredientEnrichmentBatchItem::factory()->create([
        'ingredient_enrichment_batch_id' => $batch->id,
        'ingredient_id' => null,
        'ingredient_intake_item_id' => null,
        'catalog_key' => null,
    ]))->toThrow(QueryException::class);
});
```

Also assert an intake-target item accepts `catalog_key = null`, an item target is unique within a batch, and a rejected item casts reviewer/timestamp/reason correctly. Assert platform deletion reports enrichment/intake audit dependencies instead of surfacing a raw foreign-key exception.

- [ ] **Step 3: Run RED**

```bash
php artisan test --compact tests/Feature/IngredientIntakeSchemaTest.php tests/Feature/IngredientEnrichmentBatchSchemaTest.php tests/Feature/PlatformIngredientDeletionTest.php
```

Expected: failures because the intake tables, models, target FK, rejection fields, and enums do not exist.

- [ ] **Step 4: Implement reversible migrations**

`ingredient_intake_batches` stores public id, name, notes, input method, family hint, `allow_gap_research`, original filename, private disk/path, creator, status, counts, linked enrichment batch, and timestamps.

`ingredient_intake_items` stores immutable submitted strings and row number, conservative normalized strings, duplicate candidate JSON, resolution, linked existing/promoted ingredient, row status, safe failure details, approval/promotion audit, and timestamps. Use `restrictOnDelete()` for identity target links so audit cannot become invalid implicitly.

The enrichment migration must:

- make `catalog_key` nullable;
- add nullable `ingredient_intake_item_id` with `restrictOnDelete()`;
- change `ingredient_id` to `restrictOnDelete()`;
- add unique `(ingredient_enrichment_batch_id, ingredient_intake_item_id)`;
- add `rejected_by_user_id`, `rejected_at`, `rejection_reason`, and batch `rejected_count`;
- add a PostgreSQL check using `num_nonnulls(ingredient_id, ingredient_intake_item_id) = 1`;
- add equivalent SQLite `BEFORE INSERT` and `BEFORE UPDATE` triggers for the test connection;
- remove the constraint/triggers before reversing columns in `down()`;
- restore the original `ingredient_id` nullable `nullOnDelete()` foreign key in `down()`.

Update `PlatformIngredientDeletionService::dependencyCounts()` and its localized message so enrichment batch-item targets and intake existing/promoted links block deletion clearly. The reviewer can delete the related terminal audit batch first, then retry ingredient deletion.

- [ ] **Step 5: Implement enums, models, factories, policy, and labels**

Use `#[Fillable]`, explicit `casts()`, typed relationships, `HasPublicId`, and sibling factory conventions. Keep intake row statuses small: `Draft`, `NeedsResolution`, `Queued`, `Researching`, `Ready`, `Failed`, `Approved`, `Promoted`, `LinkedExisting`, `Rejected`. `LinkedExisting` is the terminal post-apply status for an intake row resolved to an existing ingredient; `Promoted` is the terminal status for a newly created ingredient. Add `Rejected` to `IngredientEnrichmentItemStatus`.

- [ ] **Step 6: Run GREEN and format**

```bash
php artisan test --compact tests/Feature/IngredientIntakeSchemaTest.php tests/Feature/IngredientEnrichmentBatchSchemaTest.php tests/Feature/PlatformIngredientDeletionTest.php
vendor/bin/pint --dirty --format agent
```

Expected: all assertions pass on SQLite, including exclusive target enforcement.

## Task 2: Parse paste and CSV input without weakening identity

**Files:**

- Create: `app/Data/IngredientIntakeRow.php`
- Create: `app/Services/IngredientIntake/IngredientIntakeParser.php`
- Create: `app/Actions/IngredientIntake/CreateIngredientIntakeBatch.php`
- Create: `tests/Fixtures/IngredientIntake/valid-identities.csv`
- Create: `tests/Fixtures/IngredientIntake/malformed-identities.csv`
- Create: `tests/Feature/IngredientIntakeParserTest.php`
- Create: `tests/Feature/CreateIngredientIntakeBatchTest.php`
- Modify: `config/ingredient-enrichment.php`
- Modify: `.env.example`
- Modify: `lang/en/ingredient_intake_admin.php`

- [ ] **Step 1: Write failing parser tests**

Test paste and CSV for current name only, INCI only, both, blank-row removal, case-insensitive header aliases, quoted commas, Unicode, duplicate row numbers, malformed rows, and a seventy-row payload. Explicitly prove a single row and ten rows are accepted.

```php
it('accepts one ten and seventy identities with the same rules', function (int $count): void {
    $rows = collect(range(1, $count))
        ->map(fn (int $index): string => "Ingredient {$index}\t")
        ->prepend("current_name\tinci_name")
        ->implode("\n");

    expect(app(IngredientIntakeParser::class)->parsePasted($rows))->toHaveCount($count);
})->with([1, 10, 70]);
```

- [ ] **Step 2: Run RED**

```bash
php artisan test --compact tests/Feature/IngredientIntakeParserTest.php tests/Feature/CreateIngredientIntakeBatchTest.php
```

- [ ] **Step 3: Implement conservative parsing and normalization**

`IngredientIntakeRow` carries `rowNumber`, `originalCurrentName`, `originalInciName`, `normalizedCurrentName`, and `normalizedInciName`. Normalization may trim, Unicode-normalize, collapse whitespace, and case-fold for matching; it must not remove parenthetical text, plant parts, salt/form words, CI numbers, or punctuation by concatenating tokens.

Use `SplFileObject::fgetcsv(',', '"', '')` for CSV. Accept only a documented header alias map. Return row-specific validation errors without persisting partial results.

Add `ingredient_intake.maximum_batch_size` with default `100` and document `INGREDIENT_INTAKE_MAXIMUM_BATCH_SIZE=100`. This is a maximum, not a required size. Leave `direct_ai.maximum_batch_size` and `INGREDIENT_ENRICHMENT_MAXIMUM_BATCH_SIZE` at `25` for the existing direct-selection workflow.

- [ ] **Step 4: Implement atomic intake creation**

`CreateIngredientIntakeBatch::handle(User $actor, array $metadata, array $rows)` authorizes, validates the configured maximum, stores the batch/items in `DB::transaction(..., attempts: 5)`, and stores the uploaded CSV on the configured private disk before the transaction. If DB creation fails, delete the newly stored file in the catch path. Store the original filename separately from the generated path.

- [ ] **Step 5: Run GREEN**

```bash
php artisan test --compact tests/Feature/IngredientIntakeParserTest.php tests/Feature/CreateIngredientIntakeBatchTest.php
vendor/bin/pint --dirty --format agent
```

## Task 3: Detect exact and similar duplicates and require explicit resolution

**Files:**

- Create: `app/Services/IngredientIntake/IngredientDuplicateDetector.php`
- Create: `app/Actions/IngredientIntake/UpdateIngredientIntakeRow.php`
- Create: `app/Actions/IngredientIntake/ResolveIngredientIntakeDuplicate.php`
- Create: `tests/Feature/IngredientIntakeDuplicateTest.php`
- Modify: `app/Models/IngredientIntakeItem.php`
- Modify: `lang/en/ingredient_intake_admin.php`

- [ ] **Step 1: Write failing duplicate tests**

Prove:

- an exact normalized current name or INCI pauses only that row;
- a possible match is stored as a warning and does not block;
- no resolution is selected automatically;
- resolving to existing stores `existing_ingredient_id`;
- confirming distinct clears that link;
- changing resolution after research clears the old enrichment item stages/result/approval and requires a fresh snapshot;
- parenthetical content, plant part, salt, extraction form, and colour index prevent false exact matches.

- [ ] **Step 2: Run RED**

```bash
php artisan test --compact tests/Feature/IngredientIntakeDuplicateTest.php
```

- [ ] **Step 3: Implement lightweight matching**

Run exact comparisons against display names, INCI names, aliases, and identifiers using conservative normalized values. For a fuzzy warning, score only narrowed textual candidates and persist candidate id, label, matched field, and score. Do not merge, link, or classify automatically.

`ResolveIngredientIntakeDuplicate` locks the intake item and any previous enrichment item. If resolution changes after a snapshot exists, delete/invalidate the item research state before storing the new resolution; never reuse research performed against a different subject.

- [ ] **Step 4: Run GREEN**

```bash
php artisan test --compact tests/Feature/IngredientIntakeDuplicateTest.php
vendor/bin/pint --dirty --format agent
```

## Task 4: Introduce one normalized enrichment subject contract

**Files:**

- Create: `app/Data/IngredientEnrichmentSubject.php`
- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentSubjectBuilder.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentInputBuilder.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentSnapshotBuilder.php`
- Modify: `app/Models/IngredientEnrichmentBatchItem.php`
- Create: `tests/Feature/IngredientEnrichmentSubjectBuilderTest.php`
- Modify: `tests/Feature/IngredientEnrichmentInputBuilderTest.php`

- [ ] **Step 1: Write failing subject tests**

Assert three paths:

1. direct existing subject reads the current ingredient snapshot;
2. intake-distinct subject contains entered names, empty canonical state, duplicate context, and family hint;
3. intake-enrich-existing subject remains intake-targeted but reads the linked ingredient's reviewed canonical state and fingerprints the duplicate resolution.

Assert `research_family` prefers an existing verified category and otherwise uses the intake hint. Assert family hint is absent from proposed/persisted category.

- [ ] **Step 2: Run RED**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentSubjectBuilderTest.php tests/Feature/IngredientEnrichmentInputBuilderTest.php
```

- [ ] **Step 3: Implement the DTO and builder**

Use an immutable data object with explicit fields for subject type/id, input names, current canonical snapshot, duplicate resolution, `research_family`, and fingerprint inputs. Do not pass Eloquent models through pipeline stages.

Move common record construction from `IngredientEnrichmentInputBuilder` into the subject builder while keeping the existing public method as a compatibility wrapper for direct ingredient enrichment.

- [ ] **Step 4: Run GREEN**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentSubjectBuilderTest.php tests/Feature/IngredientEnrichmentInputBuilderTest.php tests/Feature/PlatformIngredientEnrichmentExportTest.php
vendor/bin/pint --dirty --format agent
```

## Task 5: Queue eligible intake rows and preserve row-level isolation

**Files:**

- Create: `app/Actions/IngredientIntake/StartIngredientIntakeResearch.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php`
- Modify: `app/Actions/IngredientEnrichment/StartIngredientEnrichmentBatch.php`
- Modify: `app/Jobs/ResearchIngredientEnrichment.php`
- Modify: `app/Services/IngredientEnrichment/ResearchIngredientEnrichmentItem.php`
- Modify: `app/Models/IngredientIntakeBatch.php`
- Modify: `app/Models/IngredientIntakeItem.php`
- Create: `tests/Feature/StartIngredientIntakeResearchTest.php`
- Modify: `tests/Feature/StartIngredientEnrichmentBatchTest.php`
- Modify: `tests/Feature/ResearchIngredientEnrichmentJobTest.php`

- [ ] **Step 1: Write failing dispatch tests**

Cover one, ten, and seventy rows; exact-match rows waiting for resolution; unrelated eligible rows dispatched; one failed job not cancelling siblings; family hint and `allow_gap_research` copied into snapshots; and rerunning start not creating duplicate batch items.

- [ ] **Step 2: Run RED**

```bash
php artisan test --compact tests/Feature/StartIngredientIntakeResearchTest.php tests/Feature/StartIngredientEnrichmentBatchTest.php tests/Feature/ResearchIngredientEnrichmentJobTest.php
```

- [ ] **Step 3: Extract reusable batch creation/dispatch**

Let the service create items from prepared subjects. Keep DB creation and locks in one transaction, dispatch `Bus::batch()` after commit, then store the Laravel batch id in a second short locked transaction. If dispatch fails, record a safe batch failure rather than leaving invisible pending work.

When a paused exact-match row is later resolved, add its item once and dispatch that single job without rerunning successful rows. Keep the intake and enrichment aggregate counts synchronized under locks.

- [ ] **Step 4: Run GREEN**

```bash
php artisan test --compact tests/Feature/StartIngredientIntakeResearchTest.php tests/Feature/StartIngredientEnrichmentBatchTest.php tests/Feature/ResearchIngredientEnrichmentJobTest.php
vendor/bin/pint --dirty --format agent
```

## Task 6: Correct candidate selection, exact evidence correlation, and identity normalization

**Files:**

- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentPipeline.php`
- Modify: `app/Services/IngredientEnrichment/IngredientIdentityMatchService.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentFactsBuilder.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentEvidenceVerifier.php`
- Modify: `app/Services/IngredientEnrichment/Sources/OpenFdaSubstanceClient.php`
- Modify: `app/Services/IngredientEnrichment/Sources/EurLexGlossaryClient.php`
- Modify: `tests/Feature/IngredientIdentityMatchServiceTest.php`
- Modify: `tests/Feature/IngredientEnrichmentFactsBuilderTest.php`
- Modify: `tests/Feature/HybridIngredientEnrichmentPipelineTest.php`
- Create: `tests/Feature/IngredientEnrichmentEvidenceCorrelationTest.php`

- [ ] **Step 1: Add regression tests for the known false-confidence paths**

Fixtures must include multiple GSRS candidates and an EUR-Lex response where only a sodium/potassium derivative matches. Prove the implementation:

- evaluates every US candidate and does not privilege index zero;
- rejects a plant-part, salt, extraction, or chemical-form conflict;
- does not let soap-salt evidence verify the base INCI;
- does not remove parenthetical text and concatenate identity tokens;
- attaches evidence only when `field` and normalized exact `value` agree;
- retains legacy fallback as unverified.

- [ ] **Step 2: Run RED**

```bash
php artisan test --compact tests/Feature/IngredientIdentityMatchServiceTest.php tests/Feature/IngredientEnrichmentFactsBuilderTest.php tests/Feature/IngredientEnrichmentEvidenceCorrelationTest.php tests/Feature/HybridIngredientEnrichmentPipelineTest.php
```

- [ ] **Step 3: Implement scored candidate selection**

Replace every `candidates[0]` assumption with a selector that scores exact identifiers, exact normalized names, INCI synonym match, plant part, chemical form, and conflict penalties. Return an unambiguous candidate only when it clears the configured threshold and margin; otherwise retain plausible candidates and mark the identity conflicting/unresolved for review.

- [ ] **Step 4: Implement field/value evidence lookup**

Replace generic `sourceEvidence($evidence)` fallback with `sourceEvidenceFor(string $field, mixed $value, array $evidence)`. A base INCI, NaOH salt, KOH salt, CAS, EC, UNII, market label, or COSING function must each obtain evidence for its exact proposed value. The verifier rejects an attempted confidence upgrade when that correlation is absent.

- [ ] **Step 5: Preserve routing order and colour proxy**

Keep logical order: identity preparation, US identity, EU structured, EU official, market declaration, conflicts, optional gap research, editorial, validation. Route FDA colour research when `research_family === colourants`, without writing the category.

- [ ] **Step 6: Run GREEN**

```bash
php artisan test --compact tests/Feature/IngredientIdentityMatchServiceTest.php tests/Feature/IngredientEnrichmentFactsBuilderTest.php tests/Feature/IngredientEnrichmentEvidenceCorrelationTest.php tests/Feature/HybridIngredientEnrichmentPipelineTest.php
vendor/bin/pint --dirty --format agent
```

## Task 7: Separate source tier, field confidence, and value provenance

**Files:**

- Modify: `app/Enums/IngredientSourceTier.php`
- Create: `app/Enums/IngredientValueProvenance.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentResultSchema.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentEditorialSchema.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentEditorialPrompt.php`
- Modify: `app/Services/IngredientEnrichment/OpenAiIngredientEditorialClient.php`
- Modify: `app/Services/IngredientEnrichment/OpenAiIngredientGapResearchClient.php`
- Modify: `config/ingredient-enrichment.php`
- Modify: `lang/en/ingredient_enrichment.php`
- Modify: `tests/Feature/IngredientEnrichmentVocabularyTest.php`
- Modify: `tests/Feature/IngredientEnrichmentResultSchemaTest.php`
- Modify: `tests/Feature/IngredientEnrichmentValidationTest.php`
- Modify: `tests/Feature/IngredientEnrichmentResearchPromptTest.php`
- Modify: `tests/Feature/IngredientEditorialClientTest.php`

- [ ] **Step 1: Write failing vocabulary/schema tests**

Add value provenance cases `SourceConfirmed`, `AiProposed`, `ReviewerSupplied`, and `Unresolved`. Add `ReviewerSupplied` source tier while preserving all existing backing values. Require a value-provenance row per material proposal field.

`IngredientEvidenceConfidence` remains unchanged: `Verified`, `Supported`, `Conflicting`, and `Unresolved` keep their current backing values.

- [ ] **Step 2: Write failing soap proposal tests**

Prove NaOH and KOH proposals are independent, may be AI-proposed only when a reliable base identity exists, carry concise reasoning, remain visibly unverified, and cannot receive `Verified` confidence without exact evidence. A missing or ambiguous base identity must keep them unresolved.

- [ ] **Step 3: Run RED**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentVocabularyTest.php tests/Feature/IngredientEnrichmentResultSchemaTest.php tests/Feature/IngredientEnrichmentValidationTest.php tests/Feature/IngredientEnrichmentResearchPromptTest.php tests/Feature/IngredientEditorialClientTest.php
```

- [ ] **Step 4: Extend the strict schema and prompt**

Add a top-level `value_provenance` list keyed by field path:

```php
[
    'field' => 'proposal.soap_inci_naoh_name',
    'kind' => 'ai_proposed',
    'reasoning' => 'Proposed from the reviewed base botanical identity; no exact official salt entry was located.',
    'source_urls' => [],
]
```

Also add top-level `subject_type` and `subject_public_id`. Make `catalog_key` nullable in the strict schema, but validate it contextually: required and equal to the ingredient key for direct subjects; null for intake subjects. Cross-check subject type/public id and fingerprint against the locked batch item. Bump the configured result schema and prompt versions. Keep historical results readable in the review presenter; an edited historical result must be retried/revalidated into the current schema before approval.

The prompt must request useful formulation/soap guidance, synonyms, localized headings, and independent soap declarations, while prohibiting invented official support. Gap research receives only configured allowed hosts and runs only when the batch explicitly enabled it.

- [ ] **Step 5: Validate trust dimensions independently**

Validator rules:

- evidence URL/tier does not imply value confidence;
- field confidence cannot exceed exact evidence;
- AI-proposed/reviewer-supplied values retain their provenance after approval;
- a reviewer edit changes provenance to reviewer supplied without rewriting historical source evidence;
- partial valid results remain reviewable with precise field warnings.

- [ ] **Step 6: Run GREEN**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentVocabularyTest.php tests/Feature/IngredientEnrichmentResultSchemaTest.php tests/Feature/IngredientEnrichmentValidationTest.php tests/Feature/IngredientEnrichmentResearchPromptTest.php tests/Feature/IngredientEditorialClientTest.php
vendor/bin/pint --dirty --format agent
```

## Task 8: Make review decisions explicit and remove safe-batch approval

**Files:**

- Create: `app/Actions/IngredientEnrichment/RejectIngredientEnrichmentItem.php`
- Modify: `app/Actions/IngredientEnrichment/ApproveIngredientEnrichmentItem.php`
- Modify: `app/Actions/IngredientEnrichment/EditIngredientEnrichmentProposal.php`
- Delete: `app/Actions/IngredientEnrichment/ApproveSafeIngredientEnrichmentBatch.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentReviewPresenter.php`
- Modify: `app/Filament/Resources/IngredientEnrichmentBatches/Pages/ViewIngredientEnrichmentBatch.php`
- Modify: `app/Filament/Resources/IngredientEnrichmentBatches/RelationManagers/ItemsRelationManager.php`
- Modify: `app/Filament/Resources/IngredientEnrichmentBatches/Schemas/IngredientEnrichmentBatchInfolist.php`
- Modify: `lang/en/ingredient_enrichment_admin.php`
- Modify: `tests/Feature/IngredientEnrichmentBatchReviewTest.php`
- Modify: `tests/Feature/IngredientEnrichmentProposalEditingTest.php`
- Modify: `tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php`

- [ ] **Step 1: Replace safe-batch tests with explicit review tests**

Test approve, manual approval with conflicting/unresolved confidence, edited-and-approved derived label, reject with audit/reason, return rejected item to review, rejected exclusion from apply, approval revalidation after edits, and modal behavior. A successful Filament approve action must notify, unmount/close, and refresh the row status. Invalid/missing required catalogue data must still prevent approval.

- [ ] **Step 2: Run RED**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentBatchReviewTest.php tests/Feature/IngredientEnrichmentProposalEditingTest.php tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php
```

- [ ] **Step 3: Implement review actions under locks**

`ApproveIngredientEnrichmentItem` revalidates the current edited result, subject reference/fingerprint, and required catalogue invariants before setting Approved and audit fields. It does not reject a structurally valid value merely because confidence is conflicting/unresolved or warnings remain; the human decision is final. Remove the `requireSafe` argument and `IngredientEnrichmentBatchItem::safeApprovalBlockers()` with the safe-batch action. `EditIngredientEnrichmentProposal` clears any prior approval/rejection. `RejectIngredientEnrichmentItem` sets Rejected and reviewer audit without mutating the ingredient. Batch refresh includes `rejected_count` but does not classify rejected rows as failures.

- [ ] **Step 4: Remove safe-batch action and copy**

Delete the class, header action, translations, imports, and tests. Keep `Apply approved` because it executes already reviewed items; label it plainly and explain that it does not approve anything.

- [ ] **Step 5: Run GREEN and Filament checks**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentBatchReviewTest.php tests/Feature/IngredientEnrichmentProposalEditingTest.php tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
```

## Task 9: Build the Filament Ingredient Intake workflow

**Files:**

- Create: `app/Filament/Resources/IngredientIntakeBatches/IngredientIntakeBatchResource.php`
- Create: `app/Filament/Resources/IngredientIntakeBatches/Pages/ListIngredientIntakeBatches.php`
- Create: `app/Filament/Resources/IngredientIntakeBatches/Pages/CreateIngredientIntakeBatch.php`
- Create: `app/Filament/Resources/IngredientIntakeBatches/Pages/ViewIngredientIntakeBatch.php`
- Create: `app/Filament/Resources/IngredientIntakeBatches/Schemas/IngredientIntakeBatchForm.php`
- Create: `app/Filament/Resources/IngredientIntakeBatches/Schemas/IngredientIntakeBatchInfolist.php`
- Create: `app/Filament/Resources/IngredientIntakeBatches/Tables/IngredientIntakeBatchesTable.php`
- Create: `app/Filament/Resources/IngredientIntakeBatches/RelationManagers/ItemsRelationManager.php`
- Create: `lang/en/ingredient_intake_admin.php`
- Create: `tests/Feature/Filament/IngredientIntakeBatchResourceTest.php`
- Modify: `app/Filament/Resources/IngredientEnrichmentBatches/RelationManagers/ItemsRelationManager.php`

- [ ] **Step 1: Inspect version-specific Filament docs and sibling resources**

Search installed documentation for create-page custom record creation, private file uploads, relation-manager actions, action modal closure, repeaters, and table polling. Follow current app navigation conventions.

- [ ] **Step 2: Generate resource files using Filament commands where supported**

```bash
php artisan make:filament-resource IngredientIntakeBatch --view --no-interaction
```

Move/refine generated classes only within the existing resource structure convention.

- [ ] **Step 3: Write failing Filament tests**

Test navigation visibility for Admin, paste and CSV conditional fields, preview validation, inline draft row edits/removal, exact duplicate resolution actions, family hint, explicit gap-research toggle, start action, counts, links to possible/existing ingredients, and links to the resulting enrichment batch.

- [ ] **Step 4: Run RED**

```bash
php artisan test --compact tests/Feature/Filament/IngredientIntakeBatchResourceTest.php
```

- [ ] **Step 5: Implement the three-step UI**

1. **Create:** name, notes, optional family hint, explicit gap toggle, Paste/CSV method, private upload.
2. **Preview:** editable accepted rows, row-level errors, exact and possible duplicate badges, remove row.
3. **Research:** start eligible rows, resolve paused exact rows independently, show progress and link to review.

Use responsive Filament grids only where fields fit; do not reproduce the cramped ingredient edit form. Keep original input read-only once research starts.

Update enrichment item presentation so `subjectLabel()` displays intake current name/INCI until a catalogue key exists.

- [ ] **Step 6: Run GREEN and Filament checks**

```bash
php artisan test --compact tests/Feature/Filament/IngredientIntakeBatchResourceTest.php tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
```

## Task 10: Promote approved intake items atomically

**Files:**

- Create: `app/Actions/IngredientIntake/PromoteIngredientIntakeItem.php`
- Modify: `app/Actions/IngredientEnrichment/ApplyApprovedIngredientEnrichment.php`
- Modify: `app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php`
- Modify: `app/Services/IngredientDataEntryService.php`
- Modify: `app/Services/IngredientIdentitySynchronizer.php`
- Modify: `app/Services/IngredientFunctionAssignmentService.php`
- Modify: `app/Services/IngredientMarketLabelService.php`
- Modify: `app/Services/IngredientTranslationService.php`
- Create: `tests/Feature/PromoteIngredientIntakeItemTest.php`
- Modify: `tests/Feature/PlatformIngredientEnrichmentImportTest.php`
- Modify: `tests/Feature/IngredientIdentitySynchronizerTest.php`
- Modify: `tests/Feature/IngredientFunctionAssignmentTest.php`
- Modify: `tests/Feature/IngredientMarketLabelTest.php`

- [ ] **Step 1: Write failing promotion tests**

Prove:

- approval alone creates/changes no ingredient;
- new promotion requires reviewed display identity, category, and compatible subcategory;
- promotion generates a unique catalogue key only at apply time;
- all canonical fields, aliases, identifiers/evidence, functions, translations, and market labels apply;
- existing-resolution intake applies to the linked ingredient and creates no duplicate;
- taxonomy reviewer/time comes from the human approval and `requires_admin_review` is false;
- optional unresolved fields do not block;
- any failure rolls back the ingredient and every relationship;
- retry succeeds without duplicates;
- an already promoted item is idempotent.

- [ ] **Step 2: Add provenance safety regressions**

Assert absent/malformed identifier evidence cannot delete existing reviewed evidence; a proposal may replace evidence only for the exact scheme/value/source record it explicitly supplies. Assert imported market labels keep `reviewed_by_user_id` and `reviewed_at` null until actual human approval.

- [ ] **Step 3: Run RED**

```bash
php artisan test --compact tests/Feature/PromoteIngredientIntakeItemTest.php tests/Feature/IngredientIdentitySynchronizerTest.php tests/Feature/IngredientFunctionAssignmentTest.php tests/Feature/IngredientMarketLabelTest.php
```

- [ ] **Step 4: Refactor the applier into a reusable locked core**

Keep `ApplyPlatformIngredientEnrichment` as the direct-existing entry point, but extract a method/service that applies a validated plan to an already locked ingredient with an explicit review context. Do not nest independent target lookups that can change between validation and apply.

For a new intake target, `PromoteIngredientIntakeItem` must in one retried transaction:

1. lock enrichment item, intake item, batch, and reviewer-relevant rows;
2. revalidate approved result, subject fingerprint, duplicate resolution, and taxonomy;
3. create a platform ingredient inactive with a generated key;
4. apply all approved scalar and relationship data through domain services;
5. record `taxonomy_source = admin_reviewed_enrichment`, reviewer id/time, and `requires_admin_review = false`;
6. link the promoted ingredient and mark item applied;
7. activate only after all invariants pass.

For `ExistingIngredient`, lock and apply to the linked ingredient, then mark the intake row `LinkedExisting`.

While touching `IngredientTranslationService`, move its remaining inline validation messages to dotted keys in the appropriate language file.

- [ ] **Step 5: Correct imported market review metadata**

Change `IngredientMarketLabelService::rowAttributes()` so it does not substitute `now()` for a null imported `reviewed_at`. Only `replaceReviewed()` writes the human reviewer and timestamp. Preserve reviewed market labels unless replacement was explicitly approved.

- [ ] **Step 6: Run GREEN**

```bash
php artisan test --compact tests/Feature/PromoteIngredientIntakeItemTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php tests/Feature/IngredientIdentitySynchronizerTest.php tests/Feature/IngredientFunctionAssignmentTest.php tests/Feature/IngredientMarketLabelTest.php
vendor/bin/pint --dirty --format agent
```

## Task 11: Make planning semantic and cleanup post-commit

**Files:**

- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentPlanner.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentBatchDeletionService.php`
- Create: `app/Services/IngredientIntake/IngredientIntakeBatchDeletionService.php`
- Modify: `app/Actions/IngredientEnrichment/DeleteIngredientEnrichmentBatch.php`
- Create: `app/Actions/IngredientIntake/DeleteIngredientIntakeBatch.php`
- Modify: `tests/Feature/PlatformIngredientEnrichmentImportTest.php`
- Modify: `tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php`
- Modify: `tests/Feature/Filament/IngredientIntakeBatchResourceTest.php`

- [ ] **Step 1: Write failing semantic-plan and deletion tests**

Assert identical content with a different retrieval timestamp is unchanged, while a changed value/source/version is a real plan change. For deletion, fake storage and Bus, force DB deletion failure, and prove files/batch metadata remain; on success prove DB deletion commits before artifact cleanup runs.

- [ ] **Step 2: Run RED**

```bash
php artisan test --compact tests/Feature/PlatformIngredientEnrichmentImportTest.php tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php tests/Feature/Filament/IngredientIntakeBatchResourceTest.php
```

- [ ] **Step 3: Compare semantic content only**

Planner equality compares proposed values, source identity/version, applicable dates, and reviewed provenance. Volatile retrieval timestamps and formatting-only changes cannot create replacement prompts or writes.

- [ ] **Step 4: Move external deletion after commit**

In the transaction, lock/validate/delete DB records and capture artifact paths/Laravel batch ids. After the transaction succeeds, delete private artifacts and Bus metadata. Report cleanup failures server-side and show a safe actionable notification; never retry external deletion inside a database retry loop.

Deleting an intake batch must first delete its linked enrichment audit through the domain deletion service, then intake rows/batch, then the original private upload after commit.

- [ ] **Step 5: Run GREEN**

```bash
php artisan test --compact tests/Feature/PlatformIngredientEnrichmentImportTest.php tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php tests/Feature/Filament/IngredientIntakeBatchResourceTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
```

## Task 12: Complete end-to-end intake, trust, and regression coverage

**Files:**

- Create: `tests/Feature/IngredientIntakeEndToEndTest.php`
- Modify: `tests/Feature/HybridIngredientEnrichmentPipelineTest.php`
- Modify: `tests/Feature/IngredientEnrichmentRetryTest.php`
- Modify: `tests/Feature/Filament/IngredientEnrichmentBulkActionTest.php`
- Modify: relevant language files for any final missing dotted keys

- [ ] **Step 1: Write the end-to-end acceptance test**

Use faked deterministic sources/editorial client and a mixed intake containing:

- one name-only ordinary oil;
- one supplied INCI;
- one colour with `Colourants` hint;
- one exact duplicate resolved to existing;
- one possible duplicate left distinct;
- one source failure;
- one AI-proposed soap salt.

Assert independent research outcomes, exact evidence correlation, explicit reviewer edits/approval/rejection, apply behavior, generated key, localized proposal content, cost metrics, and no pre-promotion catalogue visibility.

- [ ] **Step 2: Add the seventy-row capacity test**

Use fakes and assert seventy jobs/items are created and processed independently. Do not require seventy rows anywhere else.

- [ ] **Step 3: Run the focused feature matrix**

```bash
php artisan test --compact \
  tests/Feature/IngredientIntakeSchemaTest.php \
  tests/Feature/IngredientIntakeParserTest.php \
  tests/Feature/CreateIngredientIntakeBatchTest.php \
  tests/Feature/IngredientIntakeDuplicateTest.php \
  tests/Feature/IngredientEnrichmentSubjectBuilderTest.php \
  tests/Feature/StartIngredientIntakeResearchTest.php \
  tests/Feature/IngredientEnrichmentEvidenceCorrelationTest.php \
  tests/Feature/IngredientEnrichmentBatchReviewTest.php \
  tests/Feature/PromoteIngredientIntakeItemTest.php \
  tests/Feature/IngredientIntakeEndToEndTest.php \
  tests/Feature/Filament/IngredientIntakeBatchResourceTest.php \
  tests/Feature/Filament/IngredientEnrichmentBatchResourceTest.php
```

Expected: all focused intake/enrichment tests pass with no real network calls.

- [ ] **Step 4: Run existing enrichment and catalogue regressions**

```bash
php artisan test --compact \
  tests/Feature/HybridIngredientEnrichmentPipelineTest.php \
  tests/Feature/IngredientEnrichmentPipelineTest.php \
  tests/Feature/IngredientEnrichmentRetryTest.php \
  tests/Feature/PlatformIngredientEnrichmentExportTest.php \
  tests/Feature/PlatformIngredientEnrichmentImportTest.php \
  tests/Feature/IngredientIdentitySynchronizerTest.php \
  tests/Feature/IngredientFunctionAssignmentTest.php \
  tests/Feature/IngredientMarketLabelTest.php \
  tests/Feature/Filament/CatalogResourcesTest.php \
  tests/Feature/Filament/IngredientEnrichmentBulkActionTest.php
```

Expected: the direct existing-ingredient workflow and catalogue editing continue to pass.

- [ ] **Step 5: Run final quality gates**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
graphify update .
git diff --check
git status --short
```

Review every modified file against the approved spec and `.ai/rules`. Confirm no secrets, provider payloads, original uploads, or raw third-party errors are logged.

## Completion checklist

- [ ] An Admin can intake one, ten, or at least seventy rows by paste or CSV.
- [ ] Every accepted row contains current name, INCI, or both.
- [ ] Intake rows never appear in catalogue/formula/compliance queries before promotion.
- [ ] Exact matches pause only their row; possible matches only warn.
- [ ] Every enrichment item has exactly one database-enforced subject.
- [ ] Every result cross-checks a subject type/public id; intake results never invent a catalogue key.
- [ ] Candidate selection evaluates all candidates and exact evidence supports the exact value.
- [ ] Source tier, field confidence, value provenance, and reviewer decision remain independent.
- [ ] AI-proposed soap declarations are visibly unverified and review-gated.
- [ ] Approve/reject/edit are per-item and `Approve safe batch` is gone.
- [ ] Conflicting/unresolved confidence remains visible but does not overrule explicit human approval of a valid proposal.
- [ ] Promotion is atomic, idempotent, reviewer-attributed, and creates no second review cycle.
- [ ] Imported provenance does not invent human review metadata.
- [ ] Deletion cleans private artifacts only after database success.
- [ ] Existing direct enrichment behavior remains covered and green.

## Execution handoff

Execute this plan task-by-task with either:

1. **Subagent-driven development (recommended):** one fresh implementation agent per task plus specification and code-quality review gates; or
2. **Executing plans:** work through the checklist sequentially in one task, stopping at each failing verification rather than carrying defects forward.

Because the work changes persistence, queues, regulatory evidence, and Filament review behavior, do not implement it as one unreviewed patch. Finish and verify each task before beginning the next.
