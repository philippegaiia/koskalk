# Admin-Managed Workbench Contextual Help Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the owner draft, edit, preview, translate, publish, and recover contextual help for the Soap and Cosmetic Workbenches without deploying each editorial change.

**Architecture:** Stable topic keys connect application controls to database-owned content. Each language has immutable revisions and separate editing/published pointers. One shared browser-side panel presents only published, appropriately localized topics; administration, translation generation, and recovery use explicit authorized Actions and focused Services.

**Tech Stack:** PHP 8.5, Laravel 13.30.1, Filament 5.7.8, Livewire 4.4.3, Alpine, Tailwind 4, PostgreSQL, SQLite feature tests, Pest 4.7.8, Laravel HTTP client and queues, existing CommonMark/Symfony HTML sanitizer, private Cloudflare R2 storage.

---

## Status and scope

Planning only. The user approved the direction through the September 22 discussion and requested an implementation plan. No application code, schema, content publication, production data, or deployment is changed by this document.

This plan supersedes the August 21 foundation and workbench implementation approach wherever they prescribe help prose in `lang/en/help_*.php`, the `language_lines` table, or WordPress publishing as a release prerequisite. The old panel accessibility and density principles remain useful. Production Bench, a full documentation website, external guide publishing, videos, attachments, AI chat, mandatory tours, and global help search are outside this implementation.

Initial surfaces: authenticated soap/cosmetic workbenches and the existing public soap calculator's Formula/Output surfaces. Guests receive only topics matching visible controls; account-only topics are excluded. The help catalogue itself contains no private workspace or formula data.

Working tree at review: HEAD `9fe77874`; unrelated workbench/output/INCI changes and other files were already modified. Reinspect before implementation; do not revert or stage unrelated changes. No isolated checkout is necessary for writing this plan. Use an isolated worktree for implementation if simultaneous work still touches these surfaces.

## Implementation record — 2026-09-22

Implemented in isolated branch `codex/contextual-help` based on `9fe77874`, preserving unrelated changes in the main checkout. The application database has not been migrated, no content has been published, and no paid translation or production operation has run. The English seed contains all 32 topics as drafts.

Tasks 1–11 are implemented: schema/policies, validated rendering, revision/publication workflow, portable imports/exports, admin editor and translation review, queued translation, verified snapshots/recovery, maintenance UI, responsive help panel, workbench triggers, and initial content. Task 12 has automated verification and deployment/recovery documentation; real-browser review and installation into the running app remain release checks.

Verification completed: 160 contextual-help tests / 580 assertions passed on SQLite; 103 schema, translation, snapshot, and import/export tests / 330 assertions passed on disposable PostgreSQL. A separate top-level PostgreSQL capture/restore reproduced populated content and audit history. Workbench/translation regressions: 139 passed / 781 assertions, 20 pre-existing skipped media-upload cases. Filacheck passed all 17 rules, Pint formatted dirty PHP, and the Vite production build passed. No real R2 upload, provider request, or live-browser inspection was performed.

Implementation refinements from testing and review:

- The existing sanitizer strips safe relative links, so help has a narrow sanitizer using the installed CommonMark/Symfony libraries. No dependency was added.
- Publisher methods return their committed lock version, so the editor does not accidentally adopt a subsequent writer's version.
- Translation requests retain immutable requested `model` and a nullable provider `response_model` separately.
- Export rows retain `requires_admin_authorization` so deletion of the requesting administrator cannot turn a manual request into a trusted unattended export.
- Snapshot object keys include an attempt token to prevent late-worker overwrites.
- Users have no portable UUID; manifest author/publisher/requester references are optional and exported as null. The source DB retains attribution. Full database backups restore account relationships.
- PostgreSQL snapshots use repeatable-read. Nested callers must already have repeatable-read or serializable isolation.
- This checkout has no public calculator route; guest topic filtering is implemented and tested, but no new calculator route was introduced.
- Panel chrome has six locale catalogue entries; article content remains English until explicitly translated and reviewed.

## Focused strategy review

The editor and database ownership are justified by the owner's expected frequent revisions. Keep the architecture, with these refinements:

1. **Review and publication are distinct.** Saving English cannot change live help or invalidate live translations. Only publishing a new English revision changes which translations are current.
2. **Fallback is a whole-topic decision.** A missing or outdated published translation displays the current published English topic. Never mix a translated title with an English body, and never show a draft to ordinary users.
3. **Revision identity survives separate databases.** Add immutable UUIDs to revisions. Topic key + locale + local revision number is readable but is not a safe cross-database identity: two databases can independently create revision 4 with different content.
4. **Generated translations are proposals.** Add one operational `help_translation_requests` table beyond the four previously discussed tables. It preserves job state, the source revision, the target editor state, provider metadata, failures, and the candidate result. Acceptance creates a content revision; generation never overwrites an editor's work.
5. **Help works on locked formulas.** The workbench wraps its content in a disabled fieldset when locked. Native buttons placed inside it would stop working. Preserve formula locking and use the explicit read-only trigger treatment in Task 10.
6. **Publishing is visible on the next page load/navigation.** Topics are included in page data. No polling or live push into already-open workbenches is required. The admin preview updates immediately. This still requires no deployment.
7. **Imports preserve existing authoring.** Installation is additive. Exact disaster restoration is available only when the help tables are empty. A nonempty catalogue never receives an automatic destructive restore.

## Existing sources and conventions verified

- `.ai/rules/index.md`, matching rules, and `CONTEXT.md` govern implementation. User-facing vocabulary is Product, Formula, Saved formula, Manufacturing procedure, and Compliance guidance.
- Boost reports no existing help tables. Existing `ingredient_translations` uses `(ingredient_id, locale)` uniqueness and a foreign key to `supported_locales.code` (varchar 16).
- `app/Services/IngredientTranslationService.php` and `IngredientTranslationSourceFingerprint.php` demonstrate per-locale freshness tracking. Reuse the workflow, not ingredient-specific data models or chemical prompt instructions.
- `app/Services/IngredientEnrichment/OpenAiIngredientGuidanceLocalizationClient.php`, `OpenAiStructuredOutputTransport.php`, and `tests/Feature/IngredientGuidanceLocalizationClientTest.php` demonstrate structured AI responses and provider metadata handling.
- `app/Services/WorkspaceIngredientGuidanceContent.php` provides the existing CommonMark + restrictive HTML sanitizer pattern.
- `app/Services/RecipeWorkbenchViewDataBuilder.php` assembles family, capabilities, and translations; `resources/views/livewire/dashboard/recipe-workbench.blade.php` controls guest and locked states.
- `resources/views/livewire/dashboard/partials/recipe-workbench/navigation.blade.php` defines actual tabs: `formula`, `packaging`, `costing`, `output`, `instructions`.
- `resources/js/recipe-workbench/sections/presentation-section.js` contains existing quality interpretation and some untranslated prose. Calculations and threshold selection remain unchanged.
- `app/Services/PostgreSqlBackupService.php`, `routes/console.php`, and `docs/developer/storage-and-backups.md` describe daily production DB backups at 02:30 UTC to `r2_backups`. Configuration was inspected; live production backup health was not verified during planning.
- `tests/Feature/RecipeWorkbenchMassInteractionTest.php` runs actual JavaScript through Node from Pest. The repository has no Pest browser plugin; do not add a test dependency.
- Official documentation checked through Boost: Filament 5 MarkdownEditor toolbar/security, custom action authorization, Laravel after-commit queue dispatch, and Livewire 4 navigation cleanup. Recheck APIs at implementation time if installed versions change.

## Data model

Use integer primary keys; UUID `public_id` values wherever records are externally referenced. Use string-backed PHP enums, not database enums. Timestamp columns follow existing migrations. All user foreign keys are nullable with `nullOnDelete`; deleting an administrator must not delete content or audit history. All content is platform-owned, without a workspace column.

### `help_topics`

| Column | Type / constraint |
| --- | --- |
| id | bigint primary key |
| public_id | UUID, unique |
| key | varchar(160), unique, immutable after creation |
| domain | varchar(40); `shared_workbench`, `soap_workbench`, `cosmetic_workbench` |
| archived_at | nullable timestamp |
| created_at, updated_at | timestamps |

Key syntax: lowercase letters/digits/underscores separated by dots. Domain membership is checked against `config/contextual-help.php`. Topics are created by catalogue registration/import, not a free-form admin Create screen. The owner edits content for registered topics; a new trigger/key remains a code change. Archive suppresses all locale publications without destroying them. Unarchive retains them and requires an explicit admin action.

### `help_topic_locales`

| Column | Type / constraint |
| --- | --- |
| id | bigint primary key |
| help_topic_id | indexed FK to help_topics; restrict deletion |
| locale | varchar(16), FK to supported_locales.code; restrict deletion |
| latest_revision_id | nullable FK to help_topic_revisions |
| published_revision_id | nullable FK to help_topic_revisions |
| lock_version | nonnegative integer, default 0 |
| published_by | nullable FK to users |
| published_at | nullable timestamp |
| created_at, updated_at | timestamps |

Unique `(help_topic_id, locale)`. Both pointers must reference a revision belonging to this exact locale row. `lock_version` increments on accepted saves, publication, withdrawal, restore, and translation acceptance. The editor submits its observed value; mismatches fail without writes. A successful no-change save creates no revision and does not increment it.

Publication states are derived: no published pointer = Draft; latest equals published = Published; different pointers = Unpublished changes. Translation freshness is a separate derived dimension. Published metadata is null when the publication is withdrawn.

### `help_topic_revisions`

| Column | Type / constraint |
| --- | --- |
| id | bigint primary key |
| public_id | UUID, unique, preserved in exports |
| help_topic_locale_id | indexed FK; restrict deletion |
| revision_number | positive integer, unique with help_topic_locale_id |
| title | varchar(160), required |
| summary | text, required; max 600 characters |
| body_markdown | nullable text; max 12,000 characters |
| source_english_revision_id | nullable indexed self-FK; restrict deletion |
| origin | varchar(24): `human`, `ai`, `import` |
| ai_model | nullable varchar(160) |
| prompt_version | nullable varchar(100) |
| created_by | nullable FK to users |
| created_at | timestamp |

English revisions have no English-source reference. Every non-English revision references an English revision of the same topic. A human review of unchanged translated words against newer English creates a new revision with the new source reference. Provenance from an AI proposal is preserved; later human edits have `origin=human`. A pure restore of an export preserves original revision provenance, rather than falsely marking everything as newly AI-generated or human-authored.

Content rows are append-only. No `updated_at`, no update/delete UI. Revision restore copies old content into a new revision; it does not rewind or delete history. An archived topic retains every revision.

### `help_translation_requests`

| Column | Type / constraint |
| --- | --- |
| id, public_id | integer PK; unique UUID |
| help_topic_locale_id | target non-English locale FK |
| source_english_revision_id | English source FK |
| expected_target_lock_version | integer captured at request time |
| status | varchar(24): `pending`, `running`, `completed`, `failed`, `accepted`, `dismissed` |
| result | nullable JSON/JSONB: title, summary, body_markdown |
| accepted_revision_id | nullable FK, set only when candidate accepted |
| requested_by | nullable users FK |
| model, prompt_version | strings recording actual generation configuration |
| reasoning_effort | varchar(32), resolved at request creation |
| processing_token | nullable UUID identifying the worker's current claim |
| response_id, request_id | nullable provider identifiers |
| input_tokens, output_tokens | nullable nonnegative bigints; null means unavailable |
| error_code, error_message | nullable sanitized diagnostics |
| started_at, completed_at | nullable timestamps |
| created_at, updated_at | timestamps |

One active request per target language, enforced under a locale-row lock; suppress duplicate clicks. Source and target freshness are rechecked at acceptance. A stale candidate remains viewable and can be dismissed or copied into a newly reviewed manual draft; it cannot be accepted over intervening work. Repeating acceptance returns the already accepted revision without another write.

### `help_content_exports`

| Column | Type / constraint |
| --- | --- |
| id, public_id | integer PK; unique UUID |
| status | varchar(24): `pending`, `running`, `succeeded`, `failed` |
| processing_token | nullable UUID identifying the worker's current claim |
| disk, path | nullable strings; private storage location |
| format_version | positive integer, initially 1 |
| checksum | nullable SHA-256 varchar(64) |
| size_bytes | nullable nonnegative bigint |
| requested_by | nullable users FK |
| reason | varchar(24): `manual`, `publication`, `scheduled`, `pre_import` |
| captured_at, started_at, completed_at | nullable timestamps |
| error_code, error_message | nullable sanitized diagnostics |
| created_at, updated_at | timestamps |

All foreign keys need indexes, and pointer ownership/source-language constraints must have meaningful integrity tests on SQLite and PostgreSQL. Create tables before adding cyclic pointer foreign keys; remove those constraints before reversing the migration. Do not put deferred foreign-key assumptions into SQLite tests.

**Constraint implementation:** use a composite FK from `(latest_revision_id, id)` and `(published_revision_id, id)` to revisions `(id, help_topic_locale_id)`, with a corresponding unique index. Validate source English/topic membership in the single write service and enforce it in DB insert triggers on revisions and translation requests (PostgreSQL and SQLite versions). Prevent revision content UPDATE/DELETE with DB triggers; user-attribution nulling is the only permitted update. Test the `nullOnDelete` exception. Protect topic key/domain and locale ownership/language against reassignment once revisions exist. Translation request target, source, captured lock version, and generation configuration cannot change after insertion; accepted_revision_id must belong to the target locale. Register all new triggers inside the reversible migration.

## Operational contracts

### Publishing and reading

- Save, publish, archive, withdraw, restore, accept translation, import, export, and generate translation are admin-only. Gate inside each Action; hiding a Filament control is insufficient. Recheck the requesting administrator before queued work that sends content externally or applies a candidate.
- Lock topic first, then locale rows in ascending ID order, in `DB::transaction(..., attempts: 5)`. Use the same lock order for all writes, archive, import, and snapshot capture. Network/storage calls happen outside DB transactions.
- Publish only the latest saved revision visible in the preview, with matching `lock_version`. A translated revision can publish only if based on the current published English revision. English publication makes older-source translations stale; English draft editing does not.
- Locale read order: active requested locale's current published revision; otherwise complete published English topic. If English is unpublished or topic archived, omit the topic in every locale. No incomplete-field fallback. Preview may show drafts only after admin authorization.
- Do not cache mutable publication pointers in v1: batch-read them for the declared page subset. Cache only sanitized rendered revision bodies by revision UUID + renderer version. This avoids an invalidation race while still reusing expensive rendering. Draft saves cannot affect a published response.
- Do not put help payloads into formula saves, dirty-state fingerprints, recovery drafts, calculations, or browser localStorage.
- Essential safety, compliance, validation, and immediate corrective text remains inline as ordinary interface copy, available even when there are no published help topics.

### Safe content

Title/summary are plain text. Markdown permits paragraphs, h2/h3, emphasis, lists, line breaks, and links; HTML, scripts, styles, embeds, attachments, and images do not render. Use the existing `WorkspaceIngredientGuidanceContent::fromPlatformMarkdown()` sanitation capability behind a HelpContentRenderer adapter, and test its exact output before relying on it. Reject unsupported Markdown constructs at authoring validation rather than silently hiding them in preview. Links accept only HTTPS or safe app-relative paths; relative navigation retains the existing unsaved-work guard. No CMS link field, runtime article fetch, executable Blade, or formula interpolation in authored content.

The editor's preview and user panel use the same renderer. The initial authoring target is 60–150 words, not a rigid publishing minimum; task guides may be longer. Hard character limits prevent accidental article-sized payloads. A required summary cannot contain only whitespace or invisible/markup-only text.

### Translation generation

Use the existing structured-response approach with a new help-specific prompt/client; do not pass help through ingredient research, normalizing headings, or chemistry enrichment. Input is the published English topic, the selected target locale, and the reviewed glossary. No workspace, formula, ingredient record, admin email, or secret is part of the request.

Generate one locale per job. The provider returns exactly `title`, `summary`, `body_markdown`, no extra keys. Preserve meaning, quantities, identifiers, negation, and warnings. Translate existing structure naturally without adding scientific advice. Default model/reasoning inherit the current ingredient localization configuration at dispatch; record the resolved values and use them for that request even if configuration later changes. Owner can configure help-specific overrides.

Use the Laravel HTTP client; follow the existing Responses API parsing/error conventions but keep a bounded help-specific timeout (connect 10s, request 90s, no hidden HTTP retries). Job timeout 120s, one paid attempt; failed requests have explicit Retry (a new request with a new ID). A repeated delivery after completion never calls the provider again. A crash during an uncertain paid request is marked failed for review; do not promise exactly-once billing. Reconcile requests stuck running for more than 10 minutes as failed. Keep provider usage when available, including when returned content fails local validation.

Claim requests atomically with a fresh processing_token. Completion must match both `status=running` and the worker's token; recovery clears the token. A delayed worker cannot replace a recovered failure or complete a different attempt. Apply the same ownership check to snapshot workers.

### Export/import and backup

Manifest v1 includes `format_version`, `export_uuid`, `captured_at`, topics (including archived state), locale editing/publication heads, all revisions (including original numbers/UUIDs and English-source UUIDs), and translation-request candidates/audit metadata. Exclude credentials, exception dumps, storage configuration, and numeric user IDs. Optional author UUIDs may be resolved to existing users during restore; otherwise attribution becomes null. Never create users during import.

Capture the entire help graph consistently in a short transaction using the shared write-lock order; include a content digest. Write immutable JSON objects under `help-content/YYYY/MM/DD/<export-uuid>.json` on `r2_backups`, then verify remote size and SHA-256 before marking success. R2 lifecycle retention follows the backup bucket's existing 60-day policy; manual downloads/committed release packages provide independent retained copies. Daily PostgreSQL backups remain complementary.

After publication, archive, unarchive, or withdrawal, create an export request in the same DB transaction. Dispatch its job after commit. A scheduled recovery command dispatches pending requests missed by a queue outage; ensure only one worker owns an export row. Failed snapshots do not roll back publication; the admin sees "Published; backup failed" and can retry. Daily snapshots at 02:45 UTC include saved drafts and completed AI candidates. Unsaved browser typing is not exported; saved drafts may have up to a daily snapshot gap unless manually exported.

Modes:

- `bootstrap`: register missing topics and missing English drafts from the packaged seed; leave every existing topic, locale, revision, and publication untouched, including archived topics.
- `restore-empty`: require all help content and translation-request tables empty; restore a validated full manifest with heads and revision UUIDs in one transaction. Existing export-log rows are allowed. Do not restore pending/running AI jobs as active: mark them failed with recovery-required diagnostics. They must never trigger a paid call automatically.
- `merge-drafts`: on a nonempty DB, preview UUID/hash conflicts and changes; copy selected incoming locale content into new local draft revisions. Preserve local live publications and history. Identical UUID/content is a no-op; same UUID with different content is a hard error. Imported translations retain their source reference; if it is not current locally, show Needs review. No automatic publication or archive-state changes.

For merge-drafts, retain imported revision UUIDs for previously unseen immutable content, allocate local revision numbers, and add any required English-source revision as history without changing its locale heads. This makes repeated imports identifiable and preserves translation lineage. Identity comparisons exclude local revision numbers and resolved author IDs; compare topic key, locale, title, summary, body, source UUID, origin, model/prompt metadata, and original creation time. A matching revision already present is a no-op and never rewinds a newer local draft. Request audit rows merge by UUID only when their referenced content is included; their accepted revision references are remapped, and pending/running requests become inactive failures.

Admin apply binds the reviewed manifest hash, selected rows, and observed target `lock_version` values; revalidate all before writing. Pre-import snapshot must succeed for a nonempty merge. Parse JSON only (maximum 10 MiB), validate lengths, counts, unique identities, known locales, all cross-references and acyclic English-source references; reject the whole file before any write if invalid. Never resolve a file path or fetch a URL supplied inside the manifest. Export contents are portable data, never executable seed code.

## Delivery sequence and file boundaries

Deliver four ordered increments: A) authoring/read model; B) translation/recovery; C) workbench integration; D) English content and owner review. Deploy A/B before enabling C for ordinary users. No new dependencies, no production migrations, no paid generation, and no publication merely from executing automated tests.

### Task 1: Create schema, models, factories, and authorization

**Create:** migration via `php artisan make:migration create_contextual_help_tables --no-interaction`; `app/Models/HelpTopic.php`, `HelpTopicLocale.php`, `HelpTopicRevision.php`, `HelpTranslationRequest.php`, `HelpContentExport.php`; matching factories; `app/Enums/HelpTopicDomain.php`, `HelpContentOrigin.php`, `HelpTranslationRequestStatus.php`, `HelpContentExportStatus.php`, `HelpContentExportReason.php`; `app/Policies/HelpTopicPolicy.php`, `HelpContentExportPolicy.php`.

**Tests:** `tests/Feature/ContextualHelpSchemaTest.php`, `ContextualHelpAuthorizationTest.php`.

- [ ] Read current schema with Boost and `php artisan truss:export --format=llm --focus=ingredient_translations --depth=1`. Run `php artisan truss:doctor` before writing the migration. Truss was blocked by shell networking during planning; Boost schema reads succeeded. Use approved read access if this recurs; do not infer tables from old migrations alone.
- [ ] Scaffold with Artisan (`make:model --factory`, `make:enum`, `make:policy`, `make:test --pest`) after checking each command's `--help`. Every command uses `--no-interaction`; use no `Feature/` prefix for test names.
- [ ] Write failing integrity tests with factories and raw writes only at the integrity boundary: duplicate key/locale/number; wrong-locale head; cross-topic/non-English translation source; deleting a referenced revision; changing revision prose; deleting an author while retaining content; rollback/reapply of this migration.
- [ ] Implement the tables and constraints above; integer PK + HasPublicId where specified, `#[Fillable]`, casts in `casts()`, enum values in `app/Enums`. Disable model deletion as well as resource deletion.
- [ ] Implement admin-only policy abilities for `viewAny`, `view`, `update`, `publish`, `translate`, `archive`, `export`, and `import`. Ordinary workspace Owner/Admin roles do not grant content-administration rights. Generated models/factories must not use workspace ownership scopes.
- [ ] Run `php artisan test --compact tests/Feature/ContextualHelpSchemaTest.php tests/Feature/ContextualHelpAuthorizationTest.php`. Expected: all pass. Run the integrity test against a dedicated empty PostgreSQL test DB too; assert database safety before enabling RefreshDatabase. Never point tests at the application's working DB.
- [ ] Apply only to the intended development database once ready, run `php artisan truss:diff`, inspect only expected additions, format, and commit these scoped files as `feat: add contextual help content schema`.

Policy behavior example (test each explicit ability):

```php
$admin = User::factory()->admin()->create();
$user = User::factory()->create();
$topic = HelpTopic::factory()->create();
expect(Gate::forUser($admin)->allows('publish', $topic))->toBeTrue()
    ->and(Gate::forUser($user)->allows('publish', $topic))->toBeFalse();
```

### Task 2: Implement content validation, rendering, and immutable saves

**Create:** `app/Data/HelpContentInput.php`; `app/Services/ContextualHelp/HelpContentValidator.php`, `HelpContentRenderer.php`, `HelpContentEditor.php`; `app/Actions/ContextualHelp/SaveHelpTopicDraft.php`, `RestoreHelpTopicRevision.php`; `lang/en/help_admin.php`.

**Tests:** `tests/Feature/ContextualHelpEditingTest.php`, `ContextualHelpRenderingTest.php`.

Contracts:

```php
// HelpContentInput: readonly DTO; data only, no authorization or persistence.
public function __construct(
    public string $title,
    public string $summary,
    public ?string $bodyMarkdown,
) {}

// SaveHelpTopicDraft Action; restore uses the same write service.
public function handle(
    User $actor,
    HelpTopicLocale $locale,
    HelpContentInput $content,
    int $expectedLockVersion,
    ?int $sourceEnglishRevisionId = null,
): HelpTopicRevision;

// HelpContentRenderer; returns only validated plain text and sanitized HTML.
public function render(HelpTopicRevision $revision): array;
// return shape: array{title: string, summary: string, body_html: ?string}
```

- [ ] Write the save/restore cases first: draft save, no-change save, invalid content, stale editor submission, unauthorized actor, cross-topic restore, archived topic edit rejection, and restore preserving every earlier revision. Declare `uses(RefreshDatabase::class)` per DB test file.
- [ ] Implement validator limits and normalization (trim, normalize newlines; preserve Markdown structure). Store only authored Markdown, never pre-rendered HTML. Render through the shared sanitizer adapter; reject unsupported authoring constructs including raw HTML and image syntax. Preserve usable emphasis, lists, and safe links.
- [ ] Lock topic/locale, compare expected version, calculate next revision number under lock, create the immutable row, advance only latest pointer, increment lock version. No-change save returns the existing latest revision. A manual translated save requires an explicit English source revision belonging to this topic.
- [ ] Implement restore as a new draft with historical content/source and current actor. If its source is outdated, keep it visibly outdated; restoration alone is not translation approval.
- [ ] Prove scripts, javascript/data URLs, inline styles, event attributes, embeds, and malformed Markdown cannot execute in preview or user output. Link rendering must not bypass the app's dirty-navigation behavior.
- [ ] Run `php artisan test --compact tests/Feature/ContextualHelpEditingTest.php tests/Feature/ContextualHelpRenderingTest.php`; format and commit as `feat: add reviewed help draft editing`.

### Task 3: Implement publication, fallback, and topic registration

**Create:** `config/contextual-help.php`; `app/Services/ContextualHelp/HelpTopicRegistry.php`, `HelpTopicResolver.php`, `HelpContentPublisher.php`; `app/Actions/ContextualHelp/PublishHelpTopic.php`, `WithdrawHelpTopic.php`, `SetHelpTopicArchived.php`.

**Tests:** `tests/Feature/ContextualHelpPublicationTest.php`, `ContextualHelpResolutionTest.php`.

```php
// Action contract: publishing requires the exact revision and editing state previewed.
public function handle(
    User $actor,
    HelpTopicLocale $locale,
    int $revisionId,
    int $expectedLockVersion,
): void;

// Resolver is read-only; order follows the requested registry keys.
public function resolve(array $topicKeys, string $locale): array;
// return: array<string, array{key: string, locale: string, title: string,
// summary: string, body_html: ?string, revision: string}>
```

- [ ] Write tests proving a saved draft cannot leak, English edits do not stale translations until published, stale/unpublished translations use the full published English topic, missing English/archived topics disappear, and unknown keys are omitted with a development diagnostic.
- [ ] Implement the publication contracts and whole-topic fallback above. Withdrawing English suppresses every locale; withdrawing another locale falls back to English. Prevent translated publication without current English approval.
- [ ] Implement explicit registration by stable key/domain and per-family/tab arrays. A user payload exposes only the selected publication's UUID and rendered content, never revision history, author IDs, requests, audit details, or drafts. Admin preview uses a separate authorized path.
- [ ] Batch fetch all locale/publication relationships for one page; avoid per-trigger queries. Cache sanitized revision HTML only, not mutable locale resolution. Test that a second request sees a new publication while the first response remains a consistent snapshot.
- [ ] Create a pending export row in the same transaction for publication/withdrawal/archive changes; the worker arrives in Task 7. Do not mark a snapshot successful here.
- [ ] Run `php artisan test --compact tests/Feature/ContextualHelpPublicationTest.php tests/Feature/ContextualHelpResolutionTest.php`; commit as `feat: publish contextual help by locale`.

Required behavior test outline using the Task 2 and Task 3 contracts:

```php
$english = HelpTopicLocale::factory()->create(['locale' => 'en']);
$admin = User::factory()->admin()->create();
$save = app(SaveHelpTopicDraft::class);
$first = $save->handle($admin, $english, new HelpContentInput('Water mode', 'First answer', null), 0);
app(PublishHelpTopic::class)->handle($admin, $english->refresh(), $first->id, $english->lock_version);
$save->handle($admin, $english->refresh(), new HelpContentInput('Water mode', 'Unpublished answer', null), $english->lock_version);
$resolved = app(HelpTopicResolver::class)->resolve([$english->topic->key], 'en');
expect($resolved[$english->topic->key]['summary'])->toBe('First answer');
```

### Task 4: Implement portable manifest validation and import/export services

**Create:** `app/Services/ContextualHelp/HelpContentManifest.php`, `HelpContentImporter.php`, `HelpContentSnapshot.php`; `app/Actions/ContextualHelp/ImportHelpContent.php`; `app/Enums/HelpContentImportMode.php`; `app/Console/Commands/ImportHelpContent.php`, `ExportHelpContent.php`, `RegisterHelpTopics.php`.

**Tests:** `tests/Feature/ContextualHelpImportExportTest.php`, `ContextualHelpCommandTest.php`.

- [ ] Define format v1 exactly from the operational contract. JSON keys use snake_case. Revision references are UUIDs; local revision numbers are display metadata. Capture original revision numbers in exact restore; allocate new numbers for merge-drafts. Refer to topic keys and locale codes, never cross-database integer IDs.
- [ ] Implement `HelpContentManifest::validate(array $payload): array`, `HelpContentSnapshot::capture(): array`, and `HelpContentImporter::preview(array $manifest, HelpContentImportMode $mode): array`. Preview reports creates, unchanged rows, incoming draft candidates, and conflicts without writes.
- [ ] Implement Action `handle(User $actor, array $manifest, HelpContentImportMode $mode, array $selection, string $expectedManifestHash): array`. Selection contains locale key and observed lock_version for every affected existing row. Validate the full payload before starting writes, then recheck selections under locks. A mismatch rolls back the entire apply.
- [ ] Add commands with exact signatures: `help:register`; `help:import {path} {--mode=bootstrap} {--apply} {--force}`; `help:export {--disk=} {--output=}`. Use framework global `--no-interaction` support without redeclaring it. `help:import` is preview-only by default; `--apply` performs writes, with `--force` required for production. CLI execution is a trusted deployment boundary; call the importer service without fabricating a User. Admin calls remain authorized Actions.
- [ ] Test export → isolated empty help catalogue → restore returns identical content, revision UUIDs, archive states, editing/publication heads and English-source links. Include a draft newer than published, a stale translation, completed candidate, and missing original author. Include adversarial unresolved refs, wrong-topic sources, duplicate UUIDs, oversized payload, unsupported version/locale, same UUID/different bytes, and partial-failure rollback.
- [ ] Prove bootstrap repeat is a no-op after owner edits, archived records are not resurrected, merge preserves publication, and old/branching revision numbers cannot collide. Pending AI requests restore as inactive failures. Manifests never schedule generation.
- [ ] Run `php artisan test --compact tests/Feature/ContextualHelpImportExportTest.php tests/Feature/ContextualHelpCommandTest.php`; commit as `feat: add portable help content recovery`.

### Task 5: Build the admin content editor and revision preview

**Create:** `app/Filament/Resources/HelpTopics/HelpTopicResource.php`; `Pages/ListHelpTopics.php`, `Pages/EditHelpTopic.php`; `Schemas/HelpTopicForm.php`; `Tables/HelpTopicsTable.php`; `resources/views/filament/help-topics/preview.blade.php`, `revision-history.blade.php`.

**Tests:** `tests/Feature/ContextualHelpAdminTest.php`.

- [ ] Use Filament-specific resource/page generators after inspecting installed command options. Follow InterfaceTranslationResource's structure; resource navigation is English-only, label `Contextual Help`, group `Content`. Do not introduce a new panel.
- [ ] List registered topics with English title, domain, English publication state, number of current translations, number needing review, last edit, and archived filter. Default to active workbench topics. Topic key/domain are read-only in the editor; show page/tab associations as reference.
- [ ] Editor: language selector, published English reference beside translated editing, editable title/summary/body, Save draft, Preview, Publish, Withdraw, Revision history, Restore as draft, Archive/Unarchive. Empty locales can be opened before first save. Keep generated-candidate review for Task 6.
- [ ] Use a custom save handler backed by SaveHelpTopicDraft; do not let Filament mass-save revision pointers from form data. Keep expected lock_version in server-validated state. All custom actions enforce the policy through their Action. Editor must warn before leaving with unsaved changes.
- [ ] Configure the Markdown toolbar explicitly and use the application's sanitized preview, rather than assuming the editor's browser preview is safe:

```php
MarkdownEditor::make('body_markdown')
    ->toolbarButtons([
        ['bold', 'italic', 'link'],
        ['heading', 'bulletList', 'orderedList'],
        ['undo', 'redo'],
    ])
    ->maxLength(12000)
    ->columnSpanFull();
```

- [ ] Preview displays summary/body in a constrained panel-sized container using HelpContentRenderer; indicate Draft/Published and source-language freshness outside the preview content. A plain admin preview is sufficient; no customer-workspace impersonation or draft-bearing public endpoint.
- [ ] Test actual resource/action behavior through `Livewire::test()` using existing installed tools: admin save without publication, publish selected revision, two-tab conflict message, restore, locale switch, non-admin denial, and malicious revision IDs. Use `call('save')` for edit pages, not `create`, and no edit redirect assertion.
- [ ] Run `php artisan test --compact tests/Feature/ContextualHelpAdminTest.php`; then `vendor/bin/filacheck --fix`, resolve every remaining issue, Pint, and commit as `feat: add contextual help administration`.

### Task 6: Add translation proposals and explicit acceptance

**Create:** `app/Contracts/HelpTranslationClient.php`; `app/Data/HelpTranslationResponse.php`; `app/Services/ContextualHelp/HelpTranslationPrompt.php`, `OpenAiHelpTranslationClient.php`, `HelpTranslationService.php`; `app/Actions/ContextualHelp/RequestHelpTranslation.php`, `AcceptHelpTranslation.php`, `DismissHelpTranslation.php`; `app/Jobs/TranslateHelpTopic.php`; `app/Console/Commands/RecoverHelpContentJobs.php`.

**Modify:** `config/contextual-help.php`, `app/Providers/AppServiceProvider.php`, HelpTopic admin editor; `config/queue.php` only if a dedicated connection is necessary after checking current retry_after.

**Tests:** `tests/Feature/ContextualHelpTranslationTest.php`, `ContextualHelpTranslationClientTest.php`, `ContextualHelpAdminTest.php`.

```php
// Explicit immutable input to a paid translation call.
interface HelpTranslationClient
{
    public function translate(
        HelpContentInput $english,
        string $locale,
        string $model,
        string $reasoningEffort,
    ): HelpTranslationResponse;
}
// DTO: content, actual model, responseId, requestId, nullable inputTokens/outputTokens.
// Request Action returns HelpTranslationRequest; its parameters are actor, target locale,
// published English revision ID, and expected target lock_version.
// Acceptance Action takes actor + request + expected target lock_version.
```

- [ ] Write fake-client/queue tests first: no English publication, duplicate request, unsupported target, administrator removed, failed provider, wrong response keys, preserved candidate on stale target/source, repeated completion, repeated acceptance, and unchanged published content after generation/acceptance.
- [ ] Implement the bounded HTTP/queue contract above. `HelpTranslationResponse` is a readonly DTO with title/summary/body and provider metadata; validate using HelpContentValidator before a candidate is completed. Store candidate JSON on the request, not on the locale's latest/published pointers.
- [ ] Store resolved `model`, `reasoning_effort`, and prompt_version in the Task 1 request columns at request creation; source revision is immutable. Bind HelpTranslationClient in AppServiceProvider using the existing dependency-injection convention. No change to ingredient translation behavior.
- [ ] Add Generate translation / Generate missing translations controls. Batch action queues one request for each selected target; present target languages before submission. A completed candidate offers English/candidate/current comparison, Accept as draft, and Dismiss. Acceptance checks both English publication and target lock_version, then creates an immutable `origin=ai` revision and updates only latest pointer. A repeated accept returns accepted_revision_id.
- [ ] Add Mark reviewed against current English as an explicit editor action: preview current English and existing translated words, then call SaveHelpTopicDraft with the current English revision ID. This creates a new human revision even if words are unchanged because its source changed; publication stays explicit.
- [ ] Add recovery command `help:recover-jobs`, scheduled in Task 7: dispatch missed pending rows, mark expired running translations failed, and leave completed/accepted/dismissed jobs untouched. No automatic repeat of an uncertain paid request. Scope errors to the request and allow other languages to finish.
- [ ] Run `php artisan test --compact tests/Feature/ContextualHelpTranslationTest.php tests/Feature/ContextualHelpTranslationClientTest.php tests/Feature/ContextualHelpAdminTest.php`; use Http::preventStrayRequests and exact endpoint fakes. No real paid calls in validation. Run filacheck/Pint; commit as `feat: generate reviewable help translations`.

### Task 7: Add verified offsite snapshots and recovery scheduling

**Create:** `app/Actions/ContextualHelp/RequestHelpContentExport.php`; `app/Services/ContextualHelp/HelpContentExportService.php`; `app/Jobs/ExportHelpContent.php`; `app/Console/Commands/SnapshotHelpContent.php`.

**Modify:** `routes/console.php`, `config/contextual-help.php`, Task 6 recovery command.

**Tests:** `tests/Feature/ContextualHelpBackupTest.php`, `ContextualHelpCommandTest.php`.

- [ ] Write Storage::fake('r2_backups') and Queue::fake tests proving a rollback has no pending export, publication commits even if dispatch/upload fails, retry never claims success for a corrupt object, and daily snapshots include saved unpublished content.
- [ ] Implement snapshot capture through Task 4. `RequestHelpContentExport::handle(User $actor): HelpContentExport` authorizes manual requests; trusted publication and scheduler services create requests directly. Worker claims pending/failed rows under a lock, captures immutable bytes, uploads outside DB locks, verifies size/hash, and records captured_at distinctly from requested/finished times.
- [ ] Export job timeout 120s, three attempts, backoff 10/60/180 seconds. Object path is derived from export UUID, never user-supplied. Duplicate delivery of a succeeded export verifies/returns existing success. Interrupted running exports are recoverable because no paid provider call is involved. Diagnostics must not expose content, credentials, or signed links.
- [ ] Add `help:snapshot` (requests a complete export) at 02:45 UTC, production only, `withoutOverlapping()` and `onOneServer()`. Schedule `help:recover-jobs` every five minutes. Recovery is idempotent and uses row/worker ownership to avoid simultaneous writes.
- [ ] Use queue name `content` and the database connection. Confirm database `retry_after` is greater than worker timeout. Operational defaults: HTTP 90s < job 120s < worker 150s < retry_after (at least 180s). Inspect current settings before any configuration edit; preserve the larger timeout required by ingredient jobs. The snapshot command returns request identity, not an unverified success message.
- [ ] Run `php artisan test --compact tests/Feature/ContextualHelpBackupTest.php tests/Feature/ContextualHelpCommandTest.php`; commit as `feat: back up contextual help content to R2`.

### Task 8: Expose export, import preview, and backup health in admin

**Create:** `app/Filament/Pages/HelpContentMaintenance.php`; `resources/views/filament/pages/help-content-maintenance.blade.php`; `app/Http/Controllers/HelpContentExportDownloadController.php`.

**Modify:** `routes/web.php`, HelpTopics list header actions.

**Tests:** `tests/Feature/ContextualHelpMaintenanceTest.php`.

- [ ] Implement policy-protected maintenance page with last successful snapshot/captured time, pending/failed snapshot rows, Retry, Export now, and Download. Download streams a verified private object through an authenticated authorized controller route named `admin.help-content.exports.download`; never expose an R2 public URL or raw object path in a link.
- [ ] Implement temporary private JSON upload (10 MiB max), validate through Task 4, show import mode and change/conflict preview. `restore-empty` is unavailable unless empty. `merge-drafts` requires selected rows, captured locks/hash, and successful pre-import backup. Apply returns per-topic draft results and never publishes.
- [ ] Treat a preview as untrusted input on subsequent Livewire calls. Re-read/validate the uploaded bytes, bind the checksum to the preview, reauthorize, and recheck target versions. Do not deserialize PHP or trust incoming author IDs/storage paths. Clean temporary uploads after apply/cancel/expiry.
- [ ] Test non-admin direct download/action denial, altered uploaded content after preview, a newer edit before apply, snapshot failure blocking merge, bad checksum, failed/absent object download, idempotent successful import, and original live content preservation.
- [ ] Run `php artisan test --compact tests/Feature/ContextualHelpMaintenanceTest.php`; filacheck/Pint; commit as `feat: manage help backups and imports in admin`.

### Task 9: Build shared help payload and panel

**Create:** `resources/js/contextual-help.js`; `resources/views/components/contextual-help/panel.blade.php`, `trigger.blade.php`; `lang/en/contextual_help.php`.

**Modify:** `resources/js/app.js`, `resources/views/layouts/app-shell.blade.php`, `resources/css/app.css`, `config/interface-translations.php`, `database/seeders/data/interface-translations.json`.

**Tests:** `tests/Feature/ContextualHelpPanelRenderingTest.php`, `ContextualHelpInteractionTest.php` (Node-backed).

Public client contract:

```ts
// Contract notation only; implement as plain JavaScript in contextual-help.js.
type HelpTopicPayload = {
    key: string; locale: string; title: string; summary: string;
    body_html: string | null; revision: string;
};
type HelpScope = {
    topics: Record<string, HelpTopicPayload>;
    tabs: Record<string, string[]>;
};
declare function createContextualHelp(environment: { document: Document; window: Window }): {
    mount(): void;
    destroy(): void;
    register(scope: HelpScope): void;
    replaceScope(scope: HelpScope): void;
    openTopic(key: string, trigger: HTMLElement): void;
    openIndex(tab: string, trigger: HTMLElement): void;
    close(options?: { restoreFocus: boolean }): void;
    readonly isOpen: boolean;
    readonly view: 'index' | 'topic';
    readonly currentTopic: HelpTopicPayload | null;
    readonly currentKeys: string[];
};
// Topic event: CustomEvent('contextual-help:open', { detail: { key }, bubbles: true }).
// Index event: CustomEvent('contextual-help:index', { detail: { tab }, bubbles: true }).
```

- [ ] Write Node-backed interaction tests using the existing Process/Node pattern, importing the actual ES module with injected DOM-like collaborators. Cover topic/index open, replacement, back, unknown key, cleanup, and unchanged external formula state. Real focus/layout is checked in Task 13; do not claim Node stubs prove accessibility.
- [ ] Implement one shell panel, mounted outside forms and disabled fieldsets. Desktop at min-width 1024px: width `min(26rem, 100vw)`, non-modal complementary region, no page dimming/inert/scroll lock, tables retain width. Smaller viewports: full-screen dialog, focus trap, inert background, body scroll lock, sticky close. Handle breakpoint changes while open and restore original inert/scroll attributes on close/destroy.
- [ ] Use fixed overlay positioning and existing design tokens. Topic heading receives focus on user-initiated open; announce replacements politely. Escape closes only the topmost active overlay; opening a workbench modal closes help without moving focus away from that modal. Do not automatically open help or remember it across visits. Reduced motion removes transitions.
- [ ] Register/dispose listeners once via the current app.js convention. `livewire:navigating` closes and cleans the old scope; `livewire:navigated` registers only the new page's data. Back/forward navigation must not reuse another family's topics. Existing open pages keep their captured publication versions until navigation/reload.
- [ ] Localize panel chrome through `lang/en/contextual_help.php` and existing Interface Translations. Admin chrome stays English. Database help prose is never added to language_lines or the authoritative interface catalogue.
- [ ] Run `php artisan test --compact tests/Feature/ContextualHelpPanelRenderingTest.php tests/Feature/ContextualHelpInteractionTest.php`; run `npm run build`; commit as `feat: add shared contextual help panel`.

### Task 10: Connect workbench controls without changing formula behavior

**Create:** `app/Services/ContextualHelp/WorkbenchHelpTopics.php`.

**Modify:** `app/Services/RecipeWorkbenchViewDataBuilder.php`; `resources/views/livewire/dashboard/recipe-workbench.blade.php`; existing partials `header.blade.php`, `formula-settings.blade.php`, `reaction-core.blade.php`, `post-reaction.blade.php`, `cosmetic-formula.blade.php`, `formula-analysis.blade.php`, `ingredient-list-preview.blade.php`, `output-tab.blade.php`, `packaging-tab.blade.php`, `costing-tab.blade.php`, `instructions-media.blade.php`; `resources/js/recipe-workbench/sections/presentation-section.js` only for help-key associations.

**Tests:** `tests/Feature/ContextualHelpWorkbenchTest.php`; existing `RecipeWorkbenchPersistenceTest.php`, `RecipeWorkbenchNumericFormattingTest.php`, `RecipeWorkbenchMassInteractionTest.php`.

- [ ] Build `WorkbenchHelpTopics::forSurface(string $family, bool $canPersist): array` with shape `array{keys: list<string>, tabs: array<string, list<string>>}`. Use actual family slugs `soap`/`cosmetic` and real tab names. Resolve the union once in the view-data builder; header indexes select the active tab in Alpine without another request. Strip empty/unpublished keys from both indexes and direct triggers.
- [ ] Render scoped JSON with Laravel's safe JS/JSON encoding. Help data stays outside formula serialization and dirty-state snapshots. Runtime has no global catalogue endpoint and makes no AI/CMS requests.
- [ ] Add a text Help control in the header and restrained section/direct triggers according to the content map below. Do not change row spacing, layout grids, sticky ingredient rail, or formula settings visibility.
- [ ] For triggers within the existing disabled formula fieldset, render an accessible non-form anchor styled as the help control: `<a role="button" tabindex="0">` with click, Enter, and Space handlers, visible focus, and an accessible label. It performs only the help event. Outside that fieldset use native `<button type="button">`. This narrow exception keeps read-only help operable without unlocking inputs or restructuring formula permissions. Test it with a real locked formula in Task 13.
- [ ] Add state-specific keys alongside existing quality flags/interpretation results; never duplicate threshold calculations inside content or change quality math. A displayed warning stays inline and can offer additional explanation. Move duplicated long educational prose into editor-managed topics only after that topic is published and the screen still retains the actionable short message. The seed being draft must not remove existing guidance.
- [ ] Preserve data labels, calculations, safety warnings, validation, unsaved navigation, and role permissions. Guest Formula/Output show relevant soap/common help only; no saving, history, locking, packaging, costing, or instructions topics.
- [ ] Run `php artisan test --compact tests/Feature/ContextualHelpWorkbenchTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchNumericFormattingTest.php tests/Feature/RecipeWorkbenchMassInteractionTest.php`; build assets; commit as `feat: connect workbench contextual help`.

### Task 11: Author and package all initial English workbench topics

**Create:** `database/seeders/data/contextual-help.en.json`; `tests/Feature/ContextualHelpSeedContentTest.php`.

**Modify:** `config/contextual-help.php`; existing `lang/en/workbench.php` only where duplicated educational text is deliberately reduced after preserving inline meaning; English help content remains in the JSON installation package plus the authoritative DB, not language files.

Author the complete first workbench catalogue below as English **drafts**. This is an implementation deliverable, not a request for the user to write every article. The owner edits/previews/publishes through admin before translations. Stable keys omit `help_` translation-group prefixes because these are database topics.

| Key | Main entry point | English content must explain |
| --- | --- | --- |
| shared.formula_basics | Formula tab Help | Product versus Formula; family determines calculation basis; editable working state |
| shared.ingredient_selection | Ingredient selector heading | Finding/adding available ingredients, platform versus private data, ingredient detail |
| shared.quantities_and_units | Quantity controls | Percentage/weight entry, unit conversion, display rounding versus stored precision |
| shared.saving_and_history | Header | Current formula, Saved formula, Saved history; what save preserves |
| shared.formula_lock | Header | Read-only formula and who can unlock; help remains available |
| shared.packaging | Packaging heading | Packaging quantities and their role in product costing |
| shared.costing | Costing heading | Ingredient/packaging costs, missing prices, displayed currency and formula basis |
| shared.manufacturing_procedure | Instructions heading | Procedure belongs to Product; editable instructions versus calculation results |
| shared.media | Instructions/media heading | Attaching and reusing existing files; reference current Media Library behavior |
| shared.compliance_guidance | Output guidance heading | Guidance is not approval; current reference data and user review responsibility |
| shared.ingredient_change_review | Output review notice | A Saved formula does not freeze ingredient or regulatory data |
| soap.reaction_core | Reaction core heading | Saponifiable ingredients, alkali, dilution liquids; what drives saponification |
| soap.alkali_and_purity | Alkali settings | NaOH/KOH and actual supported purity choices; no invented unsupported values |
| soap.water_mode | Water mode control | Available calculation modes, their denominators, and what changing mode changes |
| soap.dilution_liquids | Dilution liquids heading | Dilution liquid versus completed alkali solution; composition and water contribution |
| soap.superfat | Superfat control | Calculation adjustment and relevant limits, including existing negative-superfat warnings |
| soap.post_reaction_additions | Additions heading | How these additions differ from reaction-core ingredients and their percentage basis |
| soap.fatty_acids | Fatty-acid profile heading | Weighted profile, coverage, and limits of interpretation |
| soap.qualities | Soap qualities heading | Estimates from the current calculation, uncertainty, hardness versus longevity |
| soap.qualities.cure | Existing cure flag | Meaning of the current cure-related explanation; avoid treating predictions as measurements |
| soap.qualities.dos | Existing DOS flag | Meaning and limitations of current oxidation-risk indication; no invented protection factors |
| soap.qualities.liquid | Existing liquid-soap flags | Why bar-soap target interpretation differs; retain applicable warnings inline |
| soap.output_basis | Output/composition heading | Incorporated versus resulting composition; wet/cured basis and displayed estimates |
| soap.labeling | Ingredient lists | Generated alternatives, common/INCI naming, review and final user-owned text |
| cosmetic.formula_basis | Formula settings | Total-formula percentage basis and what the displayed total means |
| cosmetic.phases | Phases heading | Organizing, renaming, ordering, and choosing destination phase |
| cosmetic.ingredient_functions | Ingredient detail/Formula Help | Functions as formulation context; do not imply function guarantees |
| cosmetic.application_context | Product/IFRA context | Product use, exposure mode, and actual selectable context |
| cosmetic.preservation_and_ph | Relevant context Help | Limits of app guidance; do not claim preservative efficacy or measured pH |
| cosmetic.output_basis | Output/composition heading | Total-formula output and how it differs from soap output |
| cosmetic.labeling | Ingredient lists | Ingredient order, declarations, and editable final text |
| shared.ifra_context | IFRA category selector | Optional category/certificate context, source data, and limits of guidance |

Initial topic count: **32** (12 shared, 13 soap, 7 cosmetic). Shared IFRA/compliance topics can appear in either family where their controls exist. Page indexes contain only relevant subsets; the full catalogue is not sent to every page.

Content evidence: inspect the current associated partial, translation strings, `RecipeWorkbenchPayloadNormalizer`, preview/calculation services, and matching behavior tests before writing each draft. Use `docs/developer/soap-quality-calibration.md` for quality limitations and `CONTEXT.md` for domain terms. If a listed concept has no current control, keep it in the relevant tab index rather than adding a fictional UI feature. Record source paths in seed metadata for author review; do not send internal paths to user payloads.

- [ ] Write tests validating the package against HelpContentManifest, the registry coverage, required English fields, visible summary, character limits, valid links, no HTML/images, and exactly the three initial domains. No test should assert arbitrary prose verbatim unless it protects a critical warning or domain distinction.
- [ ] Draft all 32 topics with title/summary/optional body; include one concrete example for confusing denominators or state distinctions. No marketing introductions, unsupported efficacy claims, regulatory approval claims, or invented timings. Humanize English only after verifying technical meaning, then recheck identifiers/numbers/negations.
- [ ] Use the bootstrap package format with stable deterministic topic keys and fixed revision UUIDs created once in the package. Include `publication=null` for every locale and English-only initial content. The package must be importable by Task 4, not a separate undocumented seeding path.
- [ ] Import locally with `php artisan help:import database/seeders/data/contextual-help.en.json --mode=bootstrap --apply --no-interaction`. Rerun and prove no changes. Edit one draft in admin and rerun; prove the edit survives.
- [ ] Preview representative soap/cosmetic topics in the actual panel-sized admin preview. Test a locally published topic on each workbench; do not publish every draft automatically or publish production content during implementation validation.
- [ ] Run `php artisan test --compact tests/Feature/ContextualHelpSeedContentTest.php tests/Feature/ContextualHelpImportExportTest.php tests/Feature/ContextualHelpWorkbenchTest.php`; commit as `feat: seed editable English workbench help drafts`.

Package example (use the actual version-1 manifest shape implemented in Task 4; the abbreviated topic below shows authored content, not a second file format):

```json
{
  "key": "shared.formula_lock",
  "domain": "shared_workbench",
  "title": "Locked formulas",
  "summary": "A locked formula can be reviewed but cannot be edited until an authorized workspace member unlocks it.",
  "body_markdown": "The workspace Owner or an Admin can unlock the formula. Contextual help remains available while the formula is locked."
}
```

### Task 12: Verify the editorial round trip and translation workflow

**Tests:** extend `tests/Feature/ContextualHelpAdminTest.php`, `ContextualHelpTranslationTest.php`, `ContextualHelpImportExportTest.php` only for uncovered end-to-end seams.

- [ ] With faked provider responses, complete English draft → preview → publish → generate French candidate → review → accept as draft → publish. Confirm the ordinary French workbench sees French only after publication.
- [ ] Edit English without publishing: French stays current. Publish English: French falls back to the new complete English topic and admin shows Needs review. Review/revise French against new English and republish: French returns.
- [ ] Demonstrate that a late AI candidate cannot overwrite a newer manual translation, and an old import preview cannot overwrite a newer draft.
- [ ] Export saved drafts/publications/candidates, restore into empty isolated help tables, and verify the same editor state and rendered workbench responses. Repeated restore into nonempty tables must refuse; bootstrap must preserve edits.
- [ ] Run the three affected test files. Do not execute live AI requests as a substitute for deterministic coverage. Real translations follow the owner's English approval by topic or bounded slice; the app-wide English catalogue does not need to finish review first.
- [ ] Commit seam coverage as `test: verify help editorial and recovery workflows`.

### Task 13: Responsive, keyboard, and locked-workbench verification

**Modify:** only files needed to fix observed defects from Tasks 9–10; add a focused regression for each discovered behavior defect.

- [ ] Use the running Herd application; resolve its URL with Boost get-absolute-url. Do not start a development web server. Inspect current browser state before navigating. Use browser tools for these checks; screenshots alone cannot prove keyboard/focus behavior.
- [ ] Test soap and cosmetic, new and saved Products, unlocked and locked formulas, and public calculator. Open section help, replace topic, return to tab index, switch tab, and close. Help must not submit forms, trigger recalculation/persistence requests, change quantities, alter dirty state, or enable disabled fields.
- [ ] On desktop, verify tables do not resize and underlying controls remain usable. Open a workbench modal while help is open: modal owns focus/Escape and help closes cleanly. Verify dropdown/ingredient popover layering without hiding essential controls permanently.
- [ ] On mobile/coarse pointer, verify full-screen sheet, trapped focus, inert background, visible close, body-scroll restoration, and no accidental formula autofocus. Test live resize across 1024px while help is open.
- [ ] Test keyboard-only Enter/Space on read-only anchor triggers inside disabled fieldsets, Escape, focus return when the trigger still exists, logical fallback when navigation removed it, visible focus, and reduced-motion behavior. Check headings and announced topic changes with available accessibility tools.
- [ ] Navigate soap → cosmetic → unrelated page → browser Back. Verify no stale topic scope, duplicate listeners, persistent inert attributes, or duplicate panel instances. Confirm private admin drafts are absent from ordinary page source/payloads.
- [ ] Record observed results in the implementation completion report. Run affected regression tests after each fix; no new browser dependency without user approval.

### Task 14: Deployment and recovery handoff

**Modify:** `docs/developer/deployment-readiness.md`, `docs/developer/storage-and-backups.md`; add a durable rule through Boost record-rule for the paths actually created, covering database-owned help and non-overwriting bootstrap. Do not use personal memory as the shared record.

- [ ] Document new content queue worker: `php artisan queue:work database --queue=content --timeout=150 --tries=1`. Export jobs carry their own three-attempt setting. Supervisor stopwaitsecs must exceed worker timeout; 180 seconds is the minimum for this queue. Confirm DB retry_after >=180 and preserve larger values required by existing workers. No application serving command is required.
- [ ] Document release order: back up current DB; deploy migration/code/assets; run help registration/bootstrap; start content worker; verify scheduled recovery/snapshots; review drafts through admin; enable published topics. Authoritative interface translation imports remain applicable to panel chrome only, never help prose.
- [ ] Verify `php artisan help:import ... --mode=bootstrap` preview before apply. Do not run the full DatabaseSeeder, migrate:fresh, or a help restore on a populated production DB. Actual deployment remains a separately authorized operation.
- [ ] Verify a manual snapshot and downloaded SHA-256 in the target environment before the owner relies on recovery. Perform an isolated restore drill; document last successful captured time, expected daily gap for drafts, and 60-day R2 retention. A successful local Storage fake is not a production backup verification.
- [ ] Run the bounded verification suite below, check diff, update graph only after code changes, and commit the implementation's deployment notes as `docs: document help publishing and recovery`.

## Required verification before completion

Each task runs its narrow tests after every change to those tests. At the end:

```bash
php artisan test --compact --filter=ContextualHelp
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchNumericFormattingTest.php tests/Feature/RecipeWorkbenchMassInteractionTest.php tests/Feature/IngredientGuidanceLocalizationClientTest.php tests/Feature/InterfaceTranslationFoundationTest.php
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
npm run build
git diff --check
graphify update .
```

Expected: all selected tests pass, no unresolved Filament issues, formatter completes, Vite builds, diff check passes. Rerun only tests affected by formatter/filacheck changes. Also run ContextualHelpSchemaTest against an explicitly isolated PostgreSQL test database; do not substitute SQLite success for PostgreSQL trigger/FK validation. Ask the user to run `php artisan test --compact` for the complete suite per repository policy.

## Acceptance checklist

- [ ] All 32 English workbench drafts exist, can be reviewed/edited in admin, and survive rerunning installation content.
- [ ] Saved drafts, published content, history restoration, and two-editor conflict handling behave as specified.
- [ ] Only admins edit/publish/translate/import/export; ordinary and guest payloads contain published content only.
- [ ] Translation generation produces reviewable candidates, tracks source versions, and preserves manual work.
- [ ] Missing/outdated translations resolve as one complete current English topic; no unpublished/archived English leaks.
- [ ] Help works on both workbenches and locked formulas without changing formula state, calculations, layout density, or permissions.
- [ ] The actual desktop/mobile/keyboard/navigation checks pass.
- [ ] Export verification, safe merge preview, and empty-database round trip are proven.
- [ ] Backup failures are visible and recoverable; publication does not falsely claim a successful snapshot.
- [ ] No WordPress dependency, production-help content, automatic publication, or new package was introduced.

## Plan self-review (2026-09-22)

Checked coverage of editor, initial English authoring, translation proposal safety, publication/fallback, revisions, database reconstruction, private snapshots, bench scope, guest scope, locked fields, and accessibility. Refined the original schema with revision UUIDs, lock_version, and a translation-request audit table. Fixed the draft-versus-live translation distinction and avoided a cached-publication invalidation race. The exact schema and command contracts above supersede earlier conversational sketches; no implementation has started.
