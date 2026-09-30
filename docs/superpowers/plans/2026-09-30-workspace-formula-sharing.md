# Workspace Formula Sharing Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task. Steps use checkboxes for tracking. Do not start implementation until the user approves this plan. Do not interpret this document as authorization to send messages, publish issues, change dependencies, or deploy.

**Goal:** Let a workspace Owner or Admin send a Saved formula to another workspace, whose Owner or Admin can accept an independent Product, with reusable private ingredients and preserved technical data.

**Architecture:** A recipient-bound share stores an immutable, explicitly allow-listed snapshot. Acceptance resolves the ingredient dependency graph into the destination workspace and imports the formula in one transaction. Ingredient lineage and versioned technical fingerprints enable repeated and onward sharing without making one workspace depend on another workspace's live private records.

**Tech stack verified on 2026-09-30:** PHP 8.5 project target, Laravel 13.30.1, Livewire 4.4.3, Filament 5.7.8, Pest 4.7.8, PostgreSQL live database, existing SQLite test suite, Blade/Alpine/Tailwind 4. No new dependencies.

**Status:** Approved on 2026-09-30; implementation in progress. Approval accepts all first-version defaults below. Implementation proceeds with independent review checkpoints after tasks 1–4, 5–8, and 9–10.

---

## 1. Decisions and scope

### Confirmed in the discussion

- A sends a copy; B owns its accepted copy and keeps it after A withdraws sharing.
- Only A's actual workspace Owner or workspace Admin can send. Application administration is not a workspace permission.
- A's prices and costing do not transfer.
- Platform ingredients remain platform references.
- Private ingredients must not be copied again on every exchange when a suitable local match exists.
- Ingredient ancestry must survive A → B → C and return journeys.
- Essential oils carry allergen concentrations and substance data when present.
- Duplicated saponifiable oils carry their current SAP/fatty-acid data, valid eligibility, and original trusted baseline. Sharing must not reset that baseline or widen edit limits.
- Technical differences require review; sharing never silently overwrites an existing local ingredient.

### Proposed first-version defaults, not previously approved requirements

1. No images or binary attachments, including technical PDFs, in this release. Structured IFRA data still transfers. Optional product photograph support is a separate extension with destination-owned media and failure cleanup.
2. Onward sharing is allowed. Accepted copies are independent; a UI-only prohibition cannot prevent someone reproducing accessible data manually.
3. Both sending and receiving management require Owner/Admin. After acceptance, the new Product follows normal B workspace permissions, including Editor access.
4. A chooses optional manufacturing procedure, Product description, and formula-line notes; all three are off by default. Required technical ingredient data cannot be selectively omitted while keeping that ingredient in the formula.
5. B supplies A its workspace sharing address, represented by the workspace's existing public UUID. This addresses a recipient; it is not an access credential. No searchable directory of customer workspaces and no public share links.
6. Pending shares expire after 14 days. Inbox/outbox are in-app only; no email or external notification in this delivery.
7. Each separately issued share creates a new Product when accepted. Retrying acceptance of the same share returns the original result. Updating an existing Product from a later share is outside this release.
8. Sharing is behind `workspaces.formula_sharing.enabled`, off by default. Existing workspace access and destination creation limits apply; this plan does not introduce paid-tier eligibility or change plan records.
9. First release shares finished-product formulas only. A formula configured to produce a manufactured ingredient is rejected with an explanation; no silent conversion to a finished product. Manufactured ingredients used as input materials can transfer their technical identity without their production or stock links.

Approval of this plan accepts these defaults unless the user amends them. Media inclusion and onward sharing were asked separately in the chat and must be reconciled with any subsequent answer before execution.

### Explicit exclusions

Live cross-workspace editing, automatic updates, public publication, anonymous viewing, bulk exchange, external file imports, merge into an existing Product, automatic update of existing ingredient facts, production configuration, packaging, supplier records, stock, private guidance/notes, historical formula versions, and binary media transfer.

## 2. Findings from the second review

| Existing implementation | Consequence for sharing |
| --- | --- |
| `Recipe::latestPublishedVersion()` selects the latest non-current version; a current version can contain unsaved changes. | Capture the latest Saved formula, never the current draft as an implicit fallback. |
| `CONTEXT.md` says a Saved formula freezes composition, not ingredient or regulatory facts. | Capture ingredient facts separately at send time. Explain that ingredient data is current as of sharing, not necessarily as of the formula's original save. |
| `RecipeWorkbenchService::duplicateRecipe()` copies costing. `RecipeDraftSaver` can copy media when given a source Product. | Never implement sharing by calling ordinary Product duplication or passing A's Product as `sopSourceRecipe`. |
| `UserIngredientAuthoringService::duplicateInLockedWorkspace()` retains original trusted chemistry, but rejects inaccessible blend children. | Reuse chemistry rules, not the whole duplication operation. Import the complete authorized dependency graph into B. |
| `IngredientDataEntryService::syncSubstanceEntries()` defaults new concentration provenance to `supplier`. Its authoring projections also round some data and expose only a subset of certificate state. | Build a full-precision transfer projector and explicit relation writer; do not round-trip through ingredient form state. |
| `WorkspaceAuthorization` deliberately checks the selected workspace and fresh collaboration access. | A operates while A is selected; B operates while B is selected. A grant is the only authority to read the projected snapshot across that boundary. Do not weaken normal ingredient or Product policies. |
| `RecipeVersionRecordService::fillVersion()` sets `catalog_reviewed_at` and the publication path accepts final ingredient lists. | Imported versions must have review state and generated/final declaration hashes cleared. Import is not evidence of a completed ingredient-change review. |
| Substance concentrations are nullable; allergens/components/fatty acids have precise decimal storage. | Preserve null versus zero and database precision in payloads and fingerprints. |
| Products and ingredients have public UUIDs, but no general sharing lineage. | Do not infer historical private-ingredient ancestry from names, identifiers, or chemistry baselines. Start lineage at the first known private ingredient unless an explicit imported lineage exists. |

Live table structures were read through Boost and a read-only Truss export. The source facts above were checked against current services and models, not only old specs.

Existing baseline verification: `UserIngredientAuthoringServiceDuplicationTest`, `UserIngredientAuthoringTest`, `RecipeFormulaItemLimitTest`, and `WorkspaceMembershipTest`: **117 tests passed, 765 assertions**. These are foundation tests, not tests of the unimplemented sharing feature.

## 3. Transfer contract

### Formula snapshot

Use a versioned schema, beginning at `schema_version = 1`. Explicitly construct the payload; no model `toArray()`, `replicate()`, browser draft payload, unrestricted JSON merge, or generic `source_data` copy.

Include:

- Product name, Product family/type references, selected Saved formula identifier for internal audit, and capture time.
- Ordered phases and formula lines with stable snapshot-local keys, ingredient references, exact persisted percentages/weights, batch size/unit, and calculation basis.
- Soap manufacturing mode, alkali type, KOH purity, dual-alkali split, superfat, dilution-liquid mode/value and ingredient allocations. Cosmetic phase structure and calculation settings.
- Exposure mode, selected regulatory regime, and IFRA selection context. Re-resolve these reference rows and recompute current guidance in B; never transfer a claim of approval.
- Optional procedure from the selected Saved formula, optional current Product description, and optional line notes. Identify these different sources clearly in A's preview.

Exclude:

- Prices, all costing records/settings, margins, supplier names/SKUs/links, material codes, Product reference, stock/lot identifiers, purchasing, production records, production locations, output links, brand, task sets and scheduling defaults.
- Packaging, saved history, current draft state, formula locks/edit leases, authors' private notes and workspace guidance.
- Existing final INCI/plain-language declarations and their basis hashes, reviewed timestamps, computed compliance outcomes, generated calculation output caches.
- File identifiers, URLs, paths, media metadata and attachments. Never fetch referenced URLs while building or accepting a snapshot.

Use `RecipeWorkbenchDraftPayloadMapper` and `RecipeWorkbenchPayloadNormalizer` as a checklist of supported calculation settings, not as the export serializer. Declare every supported nested key in `FormulaShareSnapshotBuilder`. An unrecognized calculation mode or missing required field rejects capture instead of silently defaulting it.

### Ingredient snapshot

Capture the following technical fields and relations with database precision:

| Group | Included |
| --- | --- |
| Identity | Display/saponification names, INCI and soap INCI names, category/subcategory, unit, aliases and identifier scheme/value/primary status, available localized identity names, market-specific declaration names and applicability dates |
| Capabilities | Saponification eligibility and aromatic-compliance requirement; imported material is a private material in B, without manufacturing/stock configuration |
| Soap chemistry | Current KOH SAP, iodine/INS data, fatty-acid percentages, original trusted KOH/fatty-acid baseline when applicable |
| Composition | Component ingredient references, order and percentages; recursively include required private children |
| Allergens | Reference identity and exact concentration; empty data stays unknown, not a claim that allergens are absent |
| Substances | Reference identity, nullable concentration and `concentration_source`; preserve unknown, inferred or supplier-derived classification accurately |
| IFRA | All applicable structured certificate records, amendment identity, reference label subject to preview, limits, category references and peroxide value where stored |

Do not transfer arbitrary notes, URLs, identifier evidence URLs, source JSON, reviewer identities, guidance text or document metadata. Preserve machine-readable technical provenance only through an explicit whitelist, including the trusted chemistry baseline and substance concentration source. Product/ingredient names and optional authored text can themselves contain confidential details: A's preview must show all selected text. No claim that automatic filtering can detect prices or supplier information typed into free text.

Platform ingredients resolve to the same platform records, including canonical alkalis. Record their technical fingerprints at capture for change detection. Never turn a missing, merged or inactive platform ingredient into a private clone automatically. Offer review against the current accessible platform catalogue, or require A to resend after fixing the source.

### Plain-text treatment of optional content

`FormulaShareContentSanitizer` parses supported rich content and emits plain text with paragraph/list boundaries. Drop images, attachments, scripts, embedded objects, styles and link targets; retain human-readable text. Do not use a regex to sanitize HTML and do not strip only `<img>` while leaving attachment IDs or signed links. Preview and import exactly the same stored sanitized text. Escape it when rendering; import into existing rich-content fields only through their supported escaped text representation.

If A opts to include a procedure containing media, show that illustrations are excluded before sending. Do not imply that the remaining text is a complete manufacturing procedure.

## 4. Ingredient identity, matching and changes

### Three separate concepts

1. **Lineage key:** server-controlled opaque UUID identifying known ancestry. For an existing private ingredient without imported lineage, use its existing `public_id`. Imported exact copies carry that key in a new nullable `ingredients.share_lineage_key` column. It is never an authorization credential and never exposed as a source-record link. Keep lineage keys and mapping rows out of every browser-facing serialization (Livewire state, JSON responses, Blade projections); internal stored snapshots and mappings retain them for onward sharing.
2. **Technical fingerprint:** SHA-256 over a versioned canonical technical projection. Includes identity relevant to formulation/declaration, capabilities, chemistry/trust baseline, composition, allergen/substance/IFRA data and child technical fingerprints. Excludes names used only for display, prices, media, private notes and storage IDs. Preserve the full display metadata in the snapshot separately.
3. **Workspace mapping:** a remembered choice from `(destination workspace, lineage key, incoming technical fingerprint)` to one local ingredient, including the local fingerprint at confirmation and whether the decision was an exact match or an explicit substitution.

The fingerprint is a comparison tool, not proof of authenticity. Only server-captured grant contents can supply lineage and trusted baseline data to the importer.

Canonicalization: sort object keys and unordered relation sets by stable catalogue identity; preserve meaningful component order; represent decimals in fixed canonical storage-scale strings; preserve null and booleans; include a fingerprint version prefix. Hash children bottom-up. Detect cycles by recursion stack; deduplicate repeated nodes with a visited map. A changed child must change its parent's effective fingerprint. Version changes invalidate automatic matching until the stored projection is compared using its supported version.

### Resolution rules

| Incoming case | Resolution |
| --- | --- |
| Active unchanged platform ingredient | Reuse platform record; no private ingredient quota consumed |
| Remembered exact mapping, local ingredient active and current technical fingerprint unchanged | Reuse automatically |
| One same-lineage candidate with exactly matching technical state, including A receiving its own ingredient back | Reuse automatically and establish a destination mapping |
| Several exact same-lineage candidates and no remembered choice | Ask B to choose; never pick the lowest ID silently |
| Local ingredient changed, deleted, inaccessible or inactive | Mark mapping stale; require review |
| Incoming private ingredient changed technically | Show differences; create an independent ingredient or explicitly choose an eligible local substitute |
| Same name, CAS, INCI or technical fingerprint without known ancestry | Suggest only; no automatic merge |
| Previously confirmed substitution, both sides still unchanged | Show remembered substitution prominently, but require explicit confirmation on each acceptance; do not present it as an exact match |

A substitution changes the received formula's effective ingredients. Recalculate with B's selection and show that the result differs; keep an import receipt of the decisions. Never transfer trust from A into an existing substitute. If the role needs saponification eligibility, B's selected ingredient must already be eligible under its own baseline.

An exact import carries the incoming lineage. Choosing an existing unrelated substitute does not rewrite that ingredient's lineage. Onward sharing projects the actual local ingredient and its own lineage, not a historical mapping alias. Otherwise B could falsely present its replacement as A's original material.

Display-only changes do not require another copy; preserve B's chosen local display name and show A's incoming name in the review. If a name is used as a declaration fallback because canonical INCI is absent, its change is technically significant and must enter the fingerprint. Automatic matching is about technical suitability, not forced name synchronization.

### Dependency graph

Walk every private component needed by formula inputs. Every referenced private node must belong to A or already be an independent A-owned imported ingredient. Never grant access to a third workspace through a raw component ID. Reject null/missing children, cycles and inaccessible references with a path to the offending material. Shared children appear once in the snapshot and once in the destination import.

When B chooses a substitute for a parent blend, descendants needed only for that parent are not imported. Calculate quota requirements after resolution. Changing any selected dependency after preview invalidates that preview.

### Chemistry boundary

Extract the existing trusted chemistry checks into `IngredientSoapTrustValidator` without changing their behavior. Both normal authoring and sharing import call it. Rules remain KOH ±3% of original; fatty acids below 5% have range 0–5%, others ±20% capped at 100%; required profiles total 80–100%.

Sharing provides two separate inputs: current chemistry and the server-captured original baseline. Never derive the baseline from current modified values on acceptance. A private ingredient claiming eligibility without a usable stored baseline is blocked as inconsistent data, not silently trusted or silently made ineligible. Plain non-eligible ingredients do not gain eligibility by being shared.

## 5. Persistence and lifecycle

Create the following using Artisan generators and the repository's model conventions. Migration filenames receive generated timestamps at execution time.

### `formula_shares`

- `id`, unique `public_id`, nullable `source_workspace_id`, required `recipient_workspace_id`.
- Nullable `source_recipe_id`, `source_version_id`, `sent_by_user_id`, `accepted_by_user_id`, `accepted_recipe_id`.
- `status` string cast to `FormulaShareStatus`: `Pending`, `Accepted`, `Declined`, `Revoked`, `Expired`.
- `schema_version`, nullable `snapshot` JSON, `snapshot_hash`, nullable `options` JSON, `import_receipt` nullable JSON. Snapshot/options are required by application validation while pending; nullable storage supports terminal-state retention cleanup.
- `request_key` UUID for idempotent sending, `sent_at`, `expires_at`, nullable `accepted_at`, `closed_at`, `payload_purged_at`, nullable `accepted_product_deleted_at`, timestamps.
- Minimal sender workspace display-name snapshot; no member roster or earlier workspace identity chain.
- Unique `(source_workspace_id, request_key)`; indexes `(recipient_workspace_id, status, created_at, id)`, `(source_workspace_id, status, created_at, id)`, `(status, expires_at)`.
- Source links use `nullOnDelete`; recipient workspace uses `cascadeOnDelete`; accepted Product link uses `nullOnDelete`. Do not cascade accepted Products or B ingredients from source deletion.
- Exclude raw snapshot, receipt and internal IDs from automatic serialization. Use separate sender and recipient projections. A sees acceptance status, not B's local ingredient IDs, substitutions, edits or Product URL.

### `ingredient_share_mappings`

- `id`, `workspace_id`, `lineage_key` UUID, `fingerprint_version`, `incoming_fingerprint`, nullable `ingredient_id`, `local_fingerprint`, `resolution` string (`exact` or `substitution`), timestamps.
- Unique `(workspace_id, lineage_key, fingerprint_version, incoming_fingerprint)` with an explicit short index name.
- Workspace cascades on deletion; ingredient uses `nullOnDelete` to leave an invalidatable mapping. Matching always verifies the current ingredient and workspace.
- No foreign key to A's private ingredient or any dependency on the continued existence of A.

### Ingredient addition

Add nullable indexed `share_lineage_key` to `ingredients`. Keep it out of authoring state and mass-assignable fields. Add an index beginning with `workspace_id` for local lineage lookup. Original native ingredients use their existing `public_id` as the fallback key; no backfill or writes during preview are needed. Normal duplication of an imported ingredient retains this value; platform duplication must not inherit a platform key as if it were an exact private copy.

### State transitions and retention

`Pending → Accepted | Declined | Revoked | Expired`. Accepted is terminal. Every transition rechecks state under lock; exact expiration (`now >= expires_at`) is rejected even if cleanup has not run.

The grant belongs to the sending workspace after valid issuance. The original sender leaving the team does not automatically revoke it; any current A Owner/Admin can revoke it. This intentionally differs from membership invitations. B never needs the sender to select A to accept.

Deleting A's Product or the selected historical version after sending does not rewrite the captured offer. Deleting A's workspace makes pending grants unusable. Accepted B copies survive all of these events. Source-version pruning must not remove the only copy of the offered technical data.

Daily cleanup marks pending shares expired and purges snapshot/options/receipt content from declined, revoked or expired records 30 days after closure. Keep minimal status metadata only. Accepted snapshots/receipts remain while B's imported Product exists for traceability; once that Product is deleted, purge their content 30 days later. Recipe deletion is permanent (no soft deletes: `RecipeController::destroy` hard-deletes; archive/restore only toggles `archived_at`). Archiving B's Product must not stamp `accepted_product_deleted_at` or start the purge clock. Stamp `accepted_product_deleted_at` when B's accepted Product is actually deleted — hook the Recipe deletion event so controller and service deletions stamp it inside the same transaction; a nulled foreign key alone cannot date the deletion, so the column is required. An accepted tombstone must never become pending or create a new Product on retry.

## 6. Authorization, concurrency and bounds

- Dedicated `RecipePolicy::share` and `FormulaSharePolicy` abilities for `view`, `accept`, `decline`, `revoke`; no platform-admin bypass.
- A workspace's actual Owner can send and receive without any collaboration entitlement: the Owner role derives from `owner_user_id` alone (`WorkspaceAuthorization`). Admin/Editor/Viewer member roles additionally require the workspace owner's active collaboration entitlement; an entitlement-less workspace's members cannot act.
- Before every UI hydration, preview or action, reload the authenticated user and verify the original selected workspace. Use Locked IDs but treat every submitted decision as untrusted input.
- Snapshot content can be read only by current Owner/Admin of the sending or recipient workspace. Other workspaces receive 404. After acceptance, the actual new Product uses existing B Product policies.
- Do not use `HasTenantOwnership` on a grant spanning two workspaces. Use explicit authorized sender/recipient queries; never broaden `OwnedByCurrentTenantScope`.
- Sending authorizes A's Saved formula and projects only allowed source records. No direct access to A's private ingredient endpoints is granted to B.
- For writes: actor user lock first when checking selected-workspace stability, then involved workspaces in ascending ID order, then existing share row, then source recipe/version where needed, then ingredient rows in ascending ID order. Preserve workspace → recipe → version order. Capture, preview and acceptance use a PostgreSQL `REPEATABLE READ` transaction so platform child-relation reads also see one coherent database snapshot. Laravel starts the transaction before invoking the closure, so inside the outer `DB::transaction(..., attempts: 5)` closure, issue `SET TRANSACTION ISOLATION LEVEL REPEATABLE READ` as the first statement when the driver is PostgreSQL (PostgreSQL accepts it any time before the transaction's first query). Guard this statement by driver: the SQLite test suite keeps normal transaction semantics and must never execute it. Do not change isolation inside existing nested transactions; PostgreSQL nested callers must already provide REPEATABLE READ or SERIALIZABLE, otherwise reject the operation before reading actor or source records. Laravel's concurrency detector already recognizes SQLSTATE `40001`, so `attempts: 5` automatically retries serialization failures. Every retry attempt is a fresh transaction: re-set the isolation level, reload every record, rerun the entire operation, and recompute the expected preview hash; never retain mutated Eloquent models across attempts. Do not assume locking an ingredient row alone locks its child relations. A platform edit committed after acceptance remains an ordinary live ingredient change, not a frozen-data guarantee.
- **PostgreSQL review correction (confirmed with two real sessions):** merely waiting on an unchanged workspace row lock does not refresh a `REPEATABLE READ` snapshot. `WorkspaceWriteLock` therefore performs a query-builder no-op `UPDATE updated_at = updated_at` after locking the workspace, without Eloquent events or business timestamp changes. Sharing calls this directly in its outer write transaction before checks so a stale snapshot raises `40001` and reaches the outer retry. The same fence is used by the common quota lock and locked-Product editing path, including archive/restore; existing restoration allowances remain unchanged. Sharing also locks the actor’s relevant membership row before reauthorization. Task 8 must verify distinct-actor races, ordinary creation versus acceptance, restoration versus acceptance, and demotion with the real disposable PostgreSQL connection.
- `DB::transaction(..., attempts: 5)` for outer operations. No network calls, notifications or media I/O inside the acceptance transaction. Acquire locks before nested creation services to avoid lock-order inversion.
- Accept locks both workspaces and the grant, rechecks recipient authority/state/expiry, and re-resolves all candidates against the submitted expected preview hash. A stale preview returns a review-required error with zero writes.
- Acceptance is one transaction for private ingredients/children, mappings, new Product/versions and accepted status. Quota or validation failure rolls back everything and leaves the share pending.
- Sending twice with the same request key returns the same grant only if recipient, snapshot and options agree; a different request with the same key is rejected. Concurrent acceptance returns one Product. Different incoming shares accepted concurrently into B serialize through B's workspace lock and reuse the same matching ingredient.
- Do not update an existing Product or ingredient during acceptance. Normal later B editing continues to use the existing formula mutation guards.

Proposed operational limits in `config/workspaces.php`: 100 pending outgoing shares per workspace; 20 pending per A/B pair; send 5/minute per actor and 20/minute per workspace; preview/accept 10/minute per actor and 30/minute per workspace; maximum 200 distinct ingredient nodes, 1,000 edges, depth 12, 10,000 total relation rows and 2 MiB serialized snapshot. Enforce these at service entry points, not only GET route middleware, so Livewire calls share the budgets. These are safety limits, separate from commercial quotas, and must produce translated actionable errors.

Destination Product quota, private-ingredient quota and formula-line limits are checked under B's workspace lock. Every newly created dependency counts; reused ingredients do not. A legacy over-limit formula cannot bypass B's new-Product limit by sharing.

## 7. Implementation tasks

Each task is a small reviewable change. Generate new PHP files with the appropriate `php artisan make:* --no-interaction` command after inspecting its help. Generate Pest tests with `make:test --pest Name` (without `Feature/` in Name) and declare `uses(RefreshDatabase::class);` in every test file that touches the database (the global binding in `tests/Pest.php` is commented out). Run the listed focused tests after each test edit, observe failure before implementation, then pass them. Commit only task-owned paths after inspecting the staged diff; never stage the unrelated workbench changes present during this planning session.

### Task 1 — Protect the existing chemistry contract

**Create:** `app/Services/IngredientSoapTrustValidator.php`.

**Modify:** `app/Services/UserIngredientAuthoringService.php`.

**Tests:** extend `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php` and `tests/Feature/UserIngredientAuthoringTest.php`.

- [x] Add behavior tests for forged `source_data` on update, disabling the trust flag while submitting out-of-range chemistry, forged `_original_percentage`, nonfinite numeric input, and duplication of a modified duplicate. Assert database state is unchanged on rejection.
- [x] Run the two files and observe the new tests' outcomes. Report an actual defect if discovered before changing behavior.
- [x] Extract validation/range calculation without changing current public authoring contracts. Validator accepts server-owned baseline and proposed state separately; it does not load baseline from submitted state.
- [x] Add a transfer-facing assertion that rejects eligible private material with no trusted baseline. Leave legacy authoring behavior unchanged unless a separately demonstrated defect requires repair.
- [x] Run the two full files and Pint; review exact diff and commit.

Required boundary example in the service tests:

```php
$state = $service->formData($copy);
$state['sap_profile']['koh_sap_value'] = '0.195';
$state['source_data']['user_authoring']['trusted_koh_sap_value'] = '0.195';
expect(fn () => $service->update($copy, $state, $owner))
    ->toThrow(\Illuminate\Validation\ValidationException::class);
expect((float) $copy->fresh('sapProfile')->sapProfile->koh_sap_value)->toBe(0.188);
```

### Task 2 — Add grant and mapping persistence

**Create:** migrations named `create_formula_shares_table`, `create_ingredient_share_mappings_table`, `add_share_lineage_key_to_ingredients_table`; `app/Models/FormulaShare.php`; `app/Models/IngredientShareMapping.php`; `app/Enums/FormulaShareStatus.php`; corresponding model factories; `tests/Feature/FormulaSharePersistenceTest.php`.

**Modify:** `app/Models/Ingredient.php` for the server-owned lineage accessor/cast only.

- [x] Run read-only `php artisan truss:export --format=llm --focus=ingredients --depth=1 --no-interaction` and `php artisan truss:doctor --no-interaction`. Do not fix unrelated doctor findings.
- [x] Add tests for unique request/mapping identities, null-on-delete mapping behavior, accepted Product survival after source deletion, and absence of snapshot/internal receipt in serialization.
- [x] Generate migrations/models/factories and implement section 5 exactly, using `#[Fillable]`, `casts()`, `HasPublicId`, enum string columns, named short indexes and reversible `down()` methods. Snapshot fields are only assigned explicitly by the issuer service.
- [x] Test forward/reverse migrations on the test database. Run `truss:diff` only against a database where this task's migrations were intentionally applied; never migrate the user's working database just for planning/verification.
- [x] Run `php artisan test --compact tests/Feature/FormulaSharePersistenceTest.php`, Pint and commit.

### Task 3 — Build technical projection, fingerprints and dependency traversal

**Create:** `app/Services/IngredientShareProjector.php`, `app/Services/IngredientShareFingerprint.php`, `app/Services/IngredientShareGraph.php`, `tests/Feature/IngredientShareProjectionTest.php`, `tests/Unit/IngredientShareFingerprintTest.php`.

- [x] Add projection tests using real ingredient relations: allergens, nullable substances with non-supplier provenance, all IFRA certificates, market declarations, precision, modified trusted oils, and one private blend sharing a child with another blend.
- [x] Add pure fingerprint tests: array order normalization, null versus zero, locale-independent decimals, display-name changes not changing technical hash, child chemistry changes changing parent hash, trusted-baseline changes changing hash, unsupported version rejection.
- [x] Implement `IngredientShareProjector::project(Ingredient $ingredient): array` with the whitelist in section 3 and no caller-provided data. Keep machine provenance separate from display/evidence metadata.
- [x] Implement `IngredientShareGraph::capture(User $actor, Workspace $source, array $rootIds): array` with bounded eager loading, authorized closure traversal, recursion-stack cycle detection and deterministic snapshot-local keys. Project platform nodes too for drift detection, but do not copy them on import.
- [x] Implement `IngredientShareFingerprint::forProjection(array $projection): string`, recursively combining child projections already resolved by the graph. Prefix schema version in the digest input; never hash localized formatted numbers.
- [x] Run `php artisan test --compact tests/Feature/IngredientShareProjectionTest.php tests/Unit/IngredientShareFingerprintTest.php`, Pint and commit.

Security acceptance: insert sentinel secrets into every excluded field/relation and assert they are absent from serialized snapshots. Explicitly cover `source_data`, nested component notes and IFRA document paths; absence from the visible screen alone is insufficient.

### Task 4 — Capture and issue recipient-bound shares

**Create:** `app/Services/FormulaShareSnapshotBuilder.php`, `app/Services/FormulaShareContentSanitizer.php`, `app/Services/FormulaShareBudget.php`, `app/Actions/FormulaSharing/SendFormulaShare.php`, `app/Policies/FormulaSharePolicy.php`, `tests/Feature/FormulaShareIssueTest.php`, `tests/Feature/FormulaShareAuthorizationTest.php`.

**Modify:** `app/Policies/RecipePolicy.php`, `config/workspaces.php`, `phpunit.xml`.

**Correctness additions from independent PostgreSQL review:** `FormulaShareTransaction`, `WorkspaceWriteLock`, `FormulaShareTransactionTest`, `FormulaSharePostgresIsolationTest`; a separate explicit PostgreSQL test opt-in requires a strict disposable name and expected/live database identity before migration. Use the shared write lock in `EntitlementService::withinWorkspaceQuotaLock` and `RecipeEditingService::withLockedRecipe`. Checkpoint review additionally requires IFRA mapping Product-type identity to agree with the validated Product classification, and strict private-baseline checks to use the same all-null platform ownership classification as the graph. Validation copy in `lang/en/sharing.php` is introduced now; the complete interface catalogue registration and six-locale rows remain Task 9.

- [x] Add the role matrix for Owner/Admin/Editor/Viewer/nonmember/platform admin, workspace switching, inactive collaboration entitlement, no Saved formula, manufactured-output formula, invalid/same recipient, expired-source references and forged options.
- [x] Add tests where the draft and Saved formula differ; confirm only the saved composition/procedure is captured. Change source ingredients after send and confirm the captured offer remains unchanged.
- [x] Implement the `share` policy and explicit grant policy queries. Resolve recipients by exact workspace UUID only, through an exact lookup with `withoutGlobalScopes()` — `OwnedByCurrentTenantScope` restricts Workspace queries to the actor's accessible workspaces, so A cannot discover B through the normal scope and must not need to. Authorize A's sending action; A is not required to be a member of B. Return only the minimal recipient identity needed for A's final preview.
- [x] Pin `workspaces.formula_sharing.enabled` to `false` in `phpunit.xml` and enable it per test via `config()`; add a test that the feature is unavailable with the flag off.
- [x] Implement `FormulaShareSnapshotBuilder::build(User $actor, Recipe $recipe, array $options): array`. Read the latest saved version and its graph coherently; whitelist formula settings; sanitize optional content; clear all excluded data. Return a preview hash over the complete offered snapshot and recipient.
- [x] Implement `SendFormulaShare::handle(User $actor, Recipe $recipe, Workspace $recipient, array $options, string $expectedPreviewHash, string $requestKey): FormulaShare`. Rebuild under locks and reject changed preview; persist the exact snapshot and 14-day expiry. A new request key is needed for a deliberate resend.
- [x] Enforce budgets in `FormulaShareBudget`, deriving workspace identity from fresh authorization, and enforce node/edge/relation/byte caps during traversal as well as at serialization.
- [x] Run both new test files plus `tests/Feature/WorkspaceResourceThrottleTest.php`, Pint and commit.

### Task 5 — Resolve local ingredients without writes

**Create:** `app/Services/IngredientShareResolver.php`, `app/Services/FormulaSharePreview.php`, `app/Services/FormulaShareReferences.php`, `tests/Feature/IngredientShareResolutionTest.php`.

- [x] Cover exact repeat import, A → B → C then A → C, B → A, two modified branches, stale/deleted mappings, multiple exact candidates, same-name unrelated ingredients, previously confirmed substitutes, changes only in nested children, substituting a parent blend whose child is shared with another imported blend, substituting a dilution-liquid ingredient, and substituting into a saponification-required role.
- [x] Implement `IngredientShareResolver::resolve(Workspace $destination, array $graph, array $decisions): array`. Each decision references a snapshot-local key and contains only `mode` (`import`, `reuse`, `substitute`) plus an optional destination ingredient public UUID. Reject unknown keys, duplicate decisions and out-of-workspace candidates.
- [x] Match exact mappings, then same-lineage exact candidates. Resolve substitutes explicitly. Recompute active dependency closure after parent substitution and count only missing private nodes for quota preview.
- [x] Implement `FormulaSharePreview::build(User $actor, FormulaShare $share, array $decisions): array` returning display rows, technical differences, warnings, remaining decisions and an expected hash. Bind the hash to the share snapshot, recipient workspace, chosen local technical states, the technical state of the platform ingredients and reference rows actually used by this share (including their dependencies), and decisions — an unrelated catalogue edit must not invalidate a preview. Keep trust/lineage fields server-side.
- [x] Ensure preview performs no writes, never updates existing local material data, never reveals unrelated candidates and never probes another workspace through lineage IDs.
- [x] Run `php artisan test --compact tests/Feature/IngredientShareResolutionTest.php`, Pint and commit.

### Task 6 — Import private technical material faithfully

**Create:** `app/Services/IngredientShareImporter.php`, `tests/Feature/IngredientShareImportTest.php`.

**Reuse:** `IngredientIdentitySynchronizer`, the extracted `IngredientSoapTrustValidator`, existing catalogue-key allocation and model relations. Do not call `createInWorkspace()` with a forged trust baseline or broaden its accepted state.

- [x] Test exact technical round-trip for a private essential oil, valid modified saponifiable oil, nullable substance, multi-certificate IFRA data, market declaration and nested blend. Assert original trust baseline unchanged and data precision retained.
- [x] Implement `IngredientShareImporter::import(User $actor, Workspace $destination, FormulaShare $share, array $resolution): array`, callable only after authorization inside the acceptance transaction. Load the persisted snapshot from `$share`; the browser never supplies it.
- [x] Create dependencies bottom-up and check destination private-ingredient quota before each creation. Populate new destination-owned private records with fresh `public_id` and `catalog_key` (existing allocation) and the incoming lineage. Set `owner_type = Workspace`, `owner_id` and `workspace_id` to B, `visibility = Private`, `requires_admin_review = false` and `is_manufactured = false`; no source production, media, source-data blob or workspace code.
- [x] Explicitly write technical child relations with remapped component IDs. Preserve substance `concentration_source` and nulls; do not use the authoring form projection that defaults new provenance. Copy all allowed IFRA structured records, not merely the form's selected one.
- [x] Validate current chemistry against the captured original baseline before insertion. Never change baseline or eligibility on an existing reused/substituted ingredient. Resolve platform catalogue references server-side and reject unavailable required rows.
- [x] Create/update workspace mappings only for resolved nodes actually used. Return snapshot-local key → local ingredient ID, keeping decisions for the recipient-only import receipt.
- [x] Run `php artisan test --compact tests/Feature/IngredientShareImportTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/UserIngredientAuthoringTest.php`, Pint and commit.

### Task 7 — Accept atomically and create the independent Product

**Create:** `app/Actions/FormulaSharing/AcceptFormulaShare.php`, `app/Services/FormulaShareRecipeImporter.php`, `tests/Feature/FormulaShareAcceptanceTest.php`.

**Reuse:** `RecipeWorkbenchService::publish`, normalizer/validation, destination quota services and existing Product lifecycle. No changes to ordinary duplication semantics.

- [x] Add acceptance tests for soap and cosmetic formulas, same-grant replay, different shares reusing one private ingredient, source mutation/deletion independence, substitutions requiring fresh review, destination quotas and rollback after a late validation error.
- [x] Implement `AcceptFormulaShare::handle(User $actor, FormulaShare $share, array $decisions, string $expectedPreviewHash): Recipe` with the lock/recheck sequence in section 6. After checking B's authority, handle an already-accepted grant before pending-only expiry checks: return its still-existing destination Product; if deleted, throw a translated `ValidationException` explaining that the share was already accepted and its Product was deleted. Never recreate it.
- **Precision review correction:** captured formulation percentages and weights are independently rounded storage facts. A server-only formulation callback restores validated pairs before total checks, dilution allocation and downstream publication checks, using the original four-decimal calculation-context batch scale and preserving editing preference. Rebuild derived totals; ordinary browser payloads cannot activate this path. Dilution percentages remain captured inputs, while dilution-liquid weights are recalculated from B’s actual selected chemistry with the normal conserved allocation, so SAP-changing choices can legitimately change those outputs. Optional omitted text remains null.
- [x] Rebuild resolution/hash while locked. Reject changed destination/platform technical data and expiry/revocation. Do not silently rebase decisions. Import the active ingredient graph only after these checks.
- [x] Implement `FormulaShareRecipeImporter::import(User $actor, Workspace $destination, FormulaShare $share, array $ingredientMap): Recipe`. Reconstruct an explicit normal authoring payload from the snapshot; remap every ingredient reference, including dilution-liquid substitutions. Use destination Product classification/normalization and calculation checks, not raw inserts into recipe items.
- [x] Publish as a new Product with both a Saved formula and current working version using the existing lifecycle. Pass no A Product/version/media argument. Set packaging to empty, output to finished product and operational metadata to empty. Recheck selected workspace before existing provisioning services and assert the resulting `workspace_id` equals B.
- [x] Clear final declaration text/hashes and `catalog_reviewed_at` on both created versions **after publication, inside the same acceptance transaction** — `RecipeVersionRecordService::fillVersion()` sets `catalog_reviewed_at = now()` and the `final_*` lists unconditionally, so clearing must be an explicit post-lifecycle step, not an omission; assert the cleared values. Store optional sanitized Product description through the existing supported content path without media. Preserve the received formula's family/type and calculation settings. Do not retain source locks.
- [x] Assert no costing records were created by import. Existing costing projections can later show B's prices; missing prices remain null. Mark accepted, link the new Product and record recipient-only decisions within the same transaction.
- [x] Run `php artisan test --compact tests/Feature/FormulaShareAcceptanceTest.php tests/Feature/RecipeFormulaItemLimitTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php`, Pint and commit.

Core acceptance invariants to assert with model factories and real services:

```php
expect($accepted->workspace_id)->toBe($destination->id);
expect($accepted->latestPublishedVersion)->not->toBeNull();
expect($accepted->currentVersion)->not->toBeNull();
expect($accepted->locked_at)->toBeNull();
expect($accepted->latestPublishedVersion->catalog_reviewed_at)->toBeNull();
expect(\App\Models\RecipeVersionCosting::query()
    ->whereIn('recipe_version_id', $accepted->versions()->pluck('id'))->exists())
    ->toBeFalse();
```

### Task 8 — Pending lifecycle, retention and race tests

**Create:** `app/Actions/FormulaSharing/CloseFormulaShare.php`, `app/Console/Commands/PruneFormulaShares.php`, `tests/Feature/FormulaShareLifecycleTest.php`, `tests/Feature/FormulaSharePostgresConcurrencyTest.php`.

**Modify:** `routes/console.php` for daily scheduling after checking existing scheduling conventions; `app/Models/Recipe.php` for a `deleting` event that stamps `accepted_product_deleted_at` before `nullOnDelete` clears the link.

- [ ] Implement `CloseFormulaShare::handle(User $actor, FormulaShare $share, FormulaShareStatus $status): void` allowing only revoke by A and decline by B while pending. Same-state retry is harmless; accepted shares cannot be revoked or declined.
- [ ] Implement scheduled expiry/payload retention using bounded chunks and row-state rechecks. Use the timestamps/rules in section 5 and keep accepted tombstones after Product deletion. Never delete B's ingredients or Products.
- [ ] Test expiry at the exact boundary with frozen time, revoke/decline authorization, source workspace deletion, source Product/version deletion, original sender departure, accepted Product deletion and retention after each terminal state.
- [ ] Test that hard deletion stamps the accepted Product deletion time, archive/restore leaves it unset, and transaction rollback resets the stamp together with the Product deletion.
- [ ] Use the existing `WorkspaceInvitationPostgresConcurrencyTest.php` pattern with two real database sessions in an explicitly disposable PostgreSQL test database. Require an explicit test-only opt-in and a verified test database name before migrating; a PostgreSQL driver check alone is insufficient. Cover accept/accept, accept/revoke, two different shares importing the same private ingredient, competing final quota slots, and a platform child-relation edit during capture. SQLite success is not evidence of row-lock or snapshot-isolation behavior.
- [ ] Run lifecycle tests locally. Run concurrency tests only on the authorized disposable PostgreSQL test connection and report skips honestly. Run Pint and commit.

### Task 9 — In-app send, inbox/outbox and review screens

**Create:** `app/Http/Controllers/FormulaShareController.php`; `app/Livewire/Dashboard/FormulaShareCreate.php`, `app/Livewire/Dashboard/FormulaSharesIndex.php`, `app/Livewire/Dashboard/FormulaShareReview.php`; `resources/views/livewire/dashboard/formula-share-create.blade.php`, `resources/views/livewire/dashboard/formula-shares-index.blade.php`, `resources/views/livewire/dashboard/formula-share-review.blade.php`; `resources/views/formula-shares/create.blade.php`, `resources/views/formula-shares/index.blade.php`, `resources/views/formula-shares/show.blade.php`; `lang/en/sharing.php`; `tests/Feature/FormulaSharePagesTest.php`.

**Modify:** `routes/web.php`, `resources/views/recipes/version.blade.php` (rendered by `RecipeController::renderSavedVersion`), `resources/views/layouts/app-shell.blade.php`, `config/interface-translations.php`, `database/seeders/data/interface-translations.json`.

- [ ] Add the share action beside the existing duplicate action in `resources/views/recipes/version.blade.php`, conditional on the feature flag, latest Saved formula availability and `share` ability. Hide it on historical views in this first release. Add an Owner/Admin sharing inbox link beside formulas in `resources/views/layouts/app-shell.blade.php`. Read all matching view rules and invoke the frontend domain skill before editing classes/templates; do not redesign the workbench.
- [ ] Add controller-rendered named routes: `formula-shares.index`, `.create` under a Product, and `.show` for a share public UUID. Use existing auth/verified/workspace middleware and controller-embedded multi-file Livewire conventions.
- [ ] Create sender flow: recipient address → resolved workspace name → content choices → full disclosure preview, including all required private ingredients/children → send. A source change invalidates confirmation. Include literal consequence: “The recipient keeps an independent copy after accepting.”
- [ ] Create inbox/outbox with pagination 10/25/50/100 and default 25, current workspace address for copying, sender/recipient, Product name, state and expiration. Authorized server projections differ for sender/recipient.
- [ ] Create B's review: incoming formula, private materials to create/reuse, technical differences, substitutions and quota count. Require all ambiguous decisions and explicit substitution confirmations, then accept. A revoked/expired/stale share shows a recoverable explanation without partial imports.
- [ ] Keep public Livewire properties to Locked IDs, permitted UI options/choices and sanitized display projections. Never hydrate raw snapshots, private source models or trusted baselines for resubmission. Reauthorize every public action and hydration against current membership/selected workspace. Build the new Livewire forms with Filament form/schema components (`HasForms`/`HasActions`), the repository's public form substrate. Put custom validation messages under a `validation` key in `lang/en/sharing.php`; do not touch the framework `lang/*/validation.php` files.
- [ ] Add six-locale translation catalogue rows and register the new group. User copy says Product, Formula, Saved formula, Workspace and Ingredient; avoid internal “recipe” vocabulary.
- [ ] Test guest redirect, wrong workspace 404, Owner/Admin matrix at policy level, one denied-role endpoint proof, switched/deleted membership, forged IDs/options/baselines, XSS/embedded media filtering, no sender price leakage in Livewire state, retry and quota messages.
- [ ] Run `php artisan test --compact tests/Feature/FormulaSharePagesTest.php tests/Feature/FormulaShareAuthorizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php`; build assets only if frontend assets changed; run Pint and commit.

### Task 10 — Integration review and controlled release

**Tests:** all new sharing tests plus affected existing ingredient, formula and workspace tests.

- [ ] Exercise the full A → B → C → A loop with an essential oil containing allergens/substances, a modified trusted oil, a shared nested blend child, a platform oil and a dilution-liquid substitute. Confirm unchanged ancestry reuses material and modified branches trigger review.
- [ ] Repeat with a cosmetic formula and a previously chosen local substitute. Verify that onward sharing carries the actual substituted ingredient's lineage and never fabricates A ancestry.
- [ ] Fill every excluded source field with recognizable sentinel values and inspect stored snapshot, initial HTML, Livewire state, preview, accepted Product, print/export and sender status projection. No excluded structured data or source private media reference may survive.
- [ ] Confirm platform reference drift is visible, existing B ingredient edits invalidate a preview, saved-history pruning does not invalidate captured offers, and imported review state is unset.
- [ ] Run focused sharing/regression tests, `vendor/bin/pint --dirty --format agent`, and `vendor/bin/filacheck --fix` only if execution actually touched `app/Filament`. Run `graphify update .` after application code changes. Do not run these formatters against unrelated user edits without inspecting their diff impact.
- [ ] Ask the user to run the full suite with `php artisan test --compact`. Record focused pass counts and any skipped PostgreSQL tests distinctly; do not call concurrency verified when it was skipped.
- [ ] Keep the feature flag off until review and PostgreSQL concurrency verification pass. Enable only through the normal approved release process. Disabling the feature hides/stops new share operations but leaves accepted Products and ingredients usable.

## 8. Completion criteria and review checklist

| Requirement | Owning tasks |
| --- | --- |
| Owner/Admin authority, recipient isolation and fresh selection | 4, 7, 9 |
| Stable offer from Saved formula with technical ingredient snapshot | 3, 4 |
| No prices/costing/media/operational data leakage | 3, 4, 7, 9, 10 |
| Repeat/onward/return matching without silent overwrites | 3, 5, 6, 10 |
| Allergens, substances, IFRA, nulls and precision | 3, 6, 10 |
| Saponification trust and fixed original baseline | 1, 3, 6 |
| Nested private dependencies and bounded work | 3, 4, 5, 6 |
| Destination quotas and no partial imports | 5, 6, 7, 8 |
| Accept retry, competing accept/revoke and final-slot races | 2, 7, 8 |
| Source deletion independence, expiry and payload retention | 2, 8 |
| Recomputed guidance and no inherited review claims | 7, 10 |
| Localized, reviewable in-app workflow | 9 |

Before execution, resolve any user response to the first-version defaults and update this file accordingly. Then implement task 1 onward; do not expand scope to live collaboration or public publishing.

## 9. References

- `CONTEXT.md`: domain vocabulary and live ingredient/reference-data policy.
- `docs/specs/future-public-community-and-collaboration.md`: snapshot isolation, attribution, and separate private share grants.
- `.ai/rules/{app,actions,services,models,models-services,policies,policies-views,livewire,migrations,tests,routes,views,lang}.md`: applicable repository constraints; consult the full index again for actual files edited.
- `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`: inherited baseline, precise duplication and trust boundaries.
- `tests/Feature/WorkspaceInvitationPostgresConcurrencyTest.php`: real-session concurrency test pattern.
- [Laravel 13 pessimistic locking](https://github.com/laravel/docs/blob/13.x/queries.md#pessimistic-locking).
- [Livewire 4 public-property security](https://livewire.laravel.com/docs/4.x/properties#security-concerns).

This is a local implementation plan, consistent with the existing `docs/superpowers/plans` layout. No GitHub issue/spec has been published and no messages have been sent to other people.
