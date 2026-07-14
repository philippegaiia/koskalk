# Koskalk Pre-deployment Security Implementation Plan

> For agentic workers: REQUIRED SUBSKILL: Use superpowers:executing-plans to implement this plan task by task. Use superpowers:test-driven-development for every behavior change and superpowers:verification-before-completion before claiming completion.

**Goal:** Ship Koskalk as an invite-only, private professional formulation application whose formulas are isolated by workspace, addressed publicly by UUIDv4, and stored with confidential media, while leaving only infrastructure-provider actions for deployment day.

**Architecture:** A workspace is the authorization boundary and owns every formula. The initial subscriber owns one workspace and is its sole member; `created_by` records authorship only. Numeric primary and foreign keys remain internal, while routed business records use UUIDv4 `public_id` values. Formula data and media are private by default. Public publishing, community features, share links, collaboration, invitations, and the public calculator remain outside the MVP.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, PostgreSQL, Pest 4, Cloudflare, Laravel Forge, Hetzner.

## Implemented outcome (2026-07-14)

- Launch blockers and repository-controlled MVP hardening in this plan are implemented and covered by focused tests.
- `is_private` and `workspace_invitations` were removed because they were genuinely obsolete for the private MVP.
- `owner_type`, `owner_id`, `workspace_id`, and `visibility` remain on the formula graph for now. They are redundant in the approved workspace-only model, but current global scopes and normalization services still actively depend on them; dropping them in this migration would create avoidable upgrade risk. Authorization ignores `is_private`, visibility, membership roles, and `created_by`, and grants formula access only to the workspace owner.
- Formula, version, workspace, and production-batch public identifiers are UUIDv4 and database-enforced unique/non-null; numeric keys remain internal.
- Formula media uses private storage and an owner-authorized delivery route. Platform ingredient media remains separate from confidential formula media.
- Existing formula media is migrated with the idempotent `app:migrate-recipe-media-to-private` command into a per-recipe UUID namespace before launch; crafted cross-formula paths are rejected server-side.
- The current 1 vCPU / 2 GB hosting is limited to trial deployment and operational learning. Upgrade to at least 2 vCPU / 4 GB before production traffic; a separate private PostgreSQL host remains an operational option, not a launch requirement.

## Security priorities

### Launch blockers

- Remove public registration and the public calculator/draft endpoints and calls to action.
- Provide an explicit, auditable command for provisioning the initial verified workspace owner.
- Remove user-ID authentication fallbacks and authorize every formula mutation against the current session user.
- Make workspace ownership the single formula authorization boundary.
- Add UUIDv4 public route identifiers without replacing numeric internal keys.
- Make formula featured images and rich-content attachments private and authorization-gated.
- Remove incomplete workspace invitation/member management from the MVP surface.
- Expose platform ingredient regulatory records to authenticated users as read-only data, while restricting all platform-record mutations to administrators.
- Remove only formula ownership/privacy fields proven obsolete after safe backfill and reference checks; retain actively referenced compatibility columns until their scopes and synchronizers are simplified separately.

### MVP hardening

- Require verified accounts on private application routes and keep the provisioned owner verified from creation.
- Rate-limit sensitive authentication and write endpoints.
- Apply secure cache and browser response headers to authenticated and exported formula content.
- Lock or derive Livewire identity properties and validate all client-controlled payloads.
- Bound uploads and exports, prevent Filament file-path tampering, and keep queued work tenant-aware.
- Add deployment-safe environment examples for secure cookies, HTTPS/proxy handling, logging, queues, and database TLS/networking.
- Document backup, restore, secrets, Cloudflare origin, Forge, Hetzner firewall, database, and deployment checks.

### Later improvements

- Multi-factor authentication and recovery codes.
- Member invitations, roles, workspace switching, and collaboration audit history.
- Public publishing, community profiles, and revocable confidential share links.
- Separate app/database hosts when observed load or operational isolation justifies it.
- Centralized security-event monitoring, alerting, and automated disaster-recovery drills.

## Task 1: Lock the launch surface

**Files:**
- Modify: `tests/Feature/AuthFlowTest.php`
- Modify: `tests/Feature/PublicCalculatorTest.php`
- Modify: `tests/Feature/PublicShellPagesTest.php`
- Modify: `routes/web.php`
- Modify: `resources/views/welcome.blade.php`
- Modify: `resources/views/auth/login.blade.php`
- Modify: locale files containing registration/calculator calls to action only when no longer used
- Delete only after reference checks: `app/Http/Controllers/PublicSoapCalculatorController.php`, `app/Http/Controllers/Auth/RegisteredUserController.php`, `app/Http/Requests/Auth/RegisterUserRequest.php`, and calculator/register views

- [ ] Add failing assertions that GET/POST registration and calculator routes return 404 and that the homepage offers sign-in without public-access calls to action.
- [ ] Run `php artisan test --compact tests/Feature/AuthFlowTest.php tests/Feature/PublicCalculatorTest.php tests/Feature/PublicShellPagesTest.php` and confirm the new assertions fail for the expected routes/content.
- [ ] Remove the routes, controllers, pending-formula session flow, and public calls to action; preserve login and authenticated workflows.
- [ ] Search for stale named-route and class references with `rg`.
- [ ] Rerun the three tests and confirm they pass.

## Task 2: Provision a verified workspace owner

**Files:**
- Create with Artisan: `app/Console/Commands/ProvisionWorkspaceOwner.php`
- Create with Artisan: `tests/Feature/Console/ProvisionWorkspaceOwnerTest.php`
- Modify: `app/Models/User.php`
- Modify: authenticated route middleware in `routes/web.php`
- Modify: workspace creation service/model only as required by existing conventions

- [ ] Write command tests for creating one user, one owned workspace, one owner membership, default entitlement, a hashed password, and `email_verified_at`.
- [ ] Write failure/idempotency tests for duplicate email and partial creation; require a transaction.
- [ ] Add a hidden interactive password prompt with confirmation; never accept plaintext passwords as command-line options or log them.
- [ ] Implement `MustVerifyEmail` and apply `verified` middleware to private application routes while leaving login/logout and verification recovery reachable.
- [ ] Run the command test file and relevant auth tests.

## Task 3: Remove identity fallbacks and enforce authorization

**Files:**
- Modify: `app/Services/CurrentAppUserResolver.php`
- Modify: controllers and Livewire components that pass `actorUserId` or `currentUserId`
- Modify: `app/Livewire/Dashboard/RecipeWorkbench.php`
- Modify: `app/Policies/RecipePolicy.php`, nested policies, and policy registration if needed
- Modify/create focused tests under `tests/Feature/` for resolver, controller, and Livewire authorization

- [ ] Add failing tests proving a caller-provided numeric user ID cannot authenticate a request or Livewire action.
- [ ] Add failing tests proving a different-workspace user cannot view, mutate, publish, duplicate, restore, delete, print, or export a formula or version.
- [ ] Make `CurrentAppUserResolver` resolve only the authenticated session user and fail closed otherwise.
- [ ] Remove public identity properties where possible; mark unavoidable internal identifiers locked and re-query records server-side.
- [ ] Call policy authorization at every controller and Livewire mutation boundary; nested records must be scoped through the authorized parent.
- [ ] Run focused authorization tests, then all recipe/production/ingredient authoring tests in bounded groups.

## Task 3A: Make the platform ingredient catalog read-only for users

**Files:**
- Modify: `app/Http/Controllers/IngredientController.php`
- Modify: `app/Livewire/Dashboard/IngredientEditor.php` and its Blade view/schema as applicable
- Modify: `app/Policies/IngredientPolicy.php` or create it if missing
- Reuse the user ingredient form structure in a disabled/read-only presentation mode
- Modify/create focused tests under `tests/Feature/` for platform ingredient visibility and mutation denial

- [ ] Add failing tests proving authenticated users can open platform ingredients and inspect allergens, CAS, EC/EINECS, composition, and regulatory details.
- [ ] Add failing tests proving non-admin users cannot update, delete, replace, upload media for, or otherwise mutate platform ingredients through controllers, Livewire actions, mass assignment, or crafted requests.
- [ ] Render the existing ingredient form structure in read-only mode for platform records so the information hierarchy stays consistent without submitting disabled controls.
- [ ] Keep user-owned ingredients editable by their owner and preserve administrator catalog authoring in the admin panel.
- [ ] Authorize on the server for every mutation; disabled fields are presentation only and never the security boundary.
- [ ] Run focused ingredient visibility/authoring tests and the relevant Filament catalog tests.

## Task 4: Establish workspace tenancy and UUID public IDs

**Files:**
- Create migrations with Artisan for UUID columns, data backfill, tenancy normalization, constraints, and obsolete-column removal
- Modify: `app/Models/Workspace.php`, `Recipe.php`, `RecipeVersion.php`, `ProductionBatch.php`, `RecipePhase.php`, `RecipeItem.php`
- Modify: recipe/version/batch factories and services
- Modify: `routes/web.php` and routed controllers
- Modify/create migration, model-binding, route, duplication, and privacy tests

- [ ] Add failing tests for automatic UUIDv4 generation, numeric-ID route rejection, UUID route binding, nested version scoping, and workspace-only access.
- [x] Add nullable unique `public_id` columns to `workspaces`, `recipes`, `recipe_versions`, and `production_batches`; backfill existing records in an isolated data migration; then enforce non-null constraints.
- [x] Ensure recipe creation and duplication always set `workspace_id` and `created_by`; duplication remains independent inside the same workspace and credits the duplicator.
- [x] Route-bind public records by `public_id`; retain numeric IDs for internal relations and Livewire server-side state.
- [x] Backfill recipe tenancy from existing ownership and fail clearly on ambiguous data before dropping anything.
- [x] Remove the obsolete `is_private` field. Retain `owner_type`, `owner_id`, `workspace_id`, and `visibility` compatibility columns because current scopes and structure synchronizers actively reference them; they do not grant authorization. Preserve ingredient polymorphic ownership because it remains active.
- [x] Remove the unused `workspace_invitations` table/model and the incomplete settings member UI; retain `workspace_members` with one owner row for forward-compatible membership.
- [ ] Run migration tests on a fresh database and upgrade-path tests with representative legacy records.

## Task 5: Protect confidential formula media

**Files:**
- Modify: `config/media.php`
- Modify: `app/Services/MediaStorage.php`
- Modify: `app/Services/RecipeRichContentAttachmentProvider.php`
- Modify: `app/Models/Recipe.php`
- Modify: recipe Livewire/Filament upload fields and render views
- Create an authorized media delivery controller/route if private temporary URLs cannot express record authorization
- Modify/create: `tests/Feature/MediaStorageTest.php`, `tests/Feature/RecipeContentMediaContractTest.php`, and authorization tests

- [ ] Add failing tests proving formula media is written privately, cannot be accessed anonymously or across workspaces, and can be rendered by an authorized owner.
- [ ] Split formula-private storage behavior from public catalog media; do not accidentally privatize platform ingredient/product imagery.
- [ ] Use private visibility and temporary/authorized URLs for featured images and rich-content attachments.
- [ ] Add `preventFilePathTampering()` to relevant Filament uploads and validate MIME type, size, and server-generated paths.
- [x] Add a tested migration command for existing formula media; copy and hash-verify all targets before changing references, then remove and verify the absence of legacy public paths.
- [ ] Run media, recipe rendering, upload, and Filament resource tests; run `vendor/bin/filacheck --fix` for Filament changes.

## Task 6: Apply application and deployment hardening

**Files:**
- Create/modify security response middleware and `bootstrap/app.php`
- Modify sensitive route middleware/rate limits
- Modify `.env.example` and relevant configuration only where safe defaults are repository-controlled
- Create: `docs/deployment/pre-deployment-security-checklist.md`
- Create/modify tests for headers, throttling, cache control, and payload limits

- [ ] Add failing tests for `Cache-Control: no-store` on authenticated pages, prints, and exports and for baseline security headers.
- [ ] Add scoped throttles to expensive or sensitive writes and bound public/client payloads.
- [ ] Configure trusted proxy/host behavior through environment values suitable for Cloudflare/Forge without hardcoding changing IP ranges.
- [ ] Ensure production cookies are secure, HTTP-only, and appropriately SameSite; ensure debug is off and logs exclude formula payloads/secrets.
- [ ] Record deployment-only actions: Cloudflare Full (strict), Authenticated Origin Pulls or equivalent origin restriction, Hetzner firewall/private network, non-public PostgreSQL, separate least-privilege DB user, TLS when DB traffic leaves localhost, Forge daemon/queue/scheduler configuration, encrypted off-site backups, restore drill, secrets rotation, health checks, and rollback.
- [x] State the capacity decision: the current 1 vCPU/2 GB server is trial-only and must be upgraded to at least 2 vCPU/4 GB before production traffic; retain the option to separate the database later. Do not expose PostgreSQL publicly.

## Task 7: Final schema audit and verification

**Files:**
- Modify only fields proven unused by code, migrations, seeders, exports, and production data checks
- Update this plan/checklist with verified outcomes

- [ ] Inventory every table/column and classify it as active, retained-for-approved-future, or obsolete; do not drop `regulatory_regime` or ingredient ownership fields while active references remain.
- [ ] Add a migration test for each genuinely obsolete field removed.
- [ ] Run `vendor/bin/pint --dirty --format agent`.
- [ ] Run `vendor/bin/filacheck --fix` if any Filament files changed, then rerun affected tests.
- [ ] Run the test suite in bounded groups to avoid the repository's current 128 MB aggregate-suite ceiling.
- [ ] Run `composer audit` and `npm audit --omit=dev`.
- [ ] Run `graphify update .` and inspect `graphify-out/GRAPH_REPORT.md` for unexpected new god nodes or cross-module coupling.
- [ ] Review `git diff --check`, migration reversibility, route list, schema state, and the deployment checklist before handoff.
