# Multi-user workspace rollout plan

> **For agentic workers:** Use superpowers:subagent-driven-development or superpowers:executing-plans for each implementation tranche. The user selected Luna max for bounded implementation and Astra medium for review or difficult work. This is the delivery roadmap; the first executable tranche is linked below. Resolve the two product decisions before implementing their dependent paths.

**Goal:** Launch private shared workspaces while preserving tester access, production configuration and company records.

**Architecture:** Keep Workspace as the ownership boundary, actual owner as subscription authority, and individual users as actors. Reuse owner-backed plan resolution and separate Production Bench entitlements. Introduce membership access only after role checks, shared data and conflicting-write handling are consistent.

**Tech stack:** Existing Laravel, Livewire, Filament, Pest and PostgreSQL; confirm installed package versions before implementation. No new dependencies proposed.

**Design:** `docs/superpowers/specs/2026-09-25-multi-user-workspace-beta-design.md`.

## Scope and unresolved decisions

The online target is approximately two weeks. Deliver in independently testable stages; do not open registration, collect payment or seed production merely because that date arrives. Aggregate storage quotas, remaining index investigations and public collaboration are deferred.

Two questions have been sent to the user:

1. Can Viewers export/download what they can view? Reading/search/filtering are agreed. This blocks the final export authorization contract only.
2. Should saved formula costing be shared by the workspace? Recommended: one shared costing, with actor attribution. This blocks costing conversion and release of team formula editing, not catalogue safety work.

Keep existing formula-lock semantics until characterised. Explicitly distinguish formulation changes, saved costing changes, current material prices and immutable production snapshots. Preserve the agreed price precedence: latest eligible receipt supplies the price; a manual costing price override wins according to the existing implementation. Do not refactor that rule into a different precedence while enabling membership.

## Tranche 1 — protect catalogue deployment

- [ ] Execute `2026-09-25-plan-seeding-safety.md` first. Existing plans must be skipped entirely, including missing limit keys.
- [ ] Add a dedicated `database/seeders/WorkspacePlanCatalogSeeder.php` for absent Free/Maker/Studio/Team plans. New future plans are inactive, non-default, without Paddle identifiers. Use the design's allowance table; preserve unreviewed legacy history/batch keys rather than inventing semantics.
- [ ] Keep existing Free beta unchanged. New beta defaults can use the agreed larger allowances only in the explicit new-catalogue path; do not make compatibility fixtures silently alter live rows.
- [ ] Expose `workspace_members` alongside existing keys in `app/Filament/Resources/Plans/Schemas/PlanForm.php`. Remove implicit missing-limit creation on an ordinary EditPlan save; initialise defaults only during deliberate creation. Review `app/Services/PlanLimitDefaultsService.php` and both plan page hooks.
- [ ] Add explicit feature capability storage/controls for collaboration and Production Bench; inspect schema and package documentation first. Missing capability means not granted, independently of a null/unlimited count. Existing Production Bench grants remain authoritative for existing access, including cancelled states. A plan declaration must not silently reactivate a cancelled workspace grant.
- [ ] New plan rows alone do not grant features to existing beta workspaces. Provide a separate previewable, idempotent capability rollout with expected-value checks; leave production execution for the reviewed deployment step.

Tests: `EntitlementLimitsTest.php`, `PlanFormulaItemLimitMigrationTest.php`, `Filament/CatalogResourcesTest.php`, and new catalogue tests. Verify whole-row preservation including timestamps, default, billing identifiers, zero/null and absent limits; failure rollback; no entitlement reassignment. Test fresh and populated disposable PostgreSQL databases.

Acceptance: rerunning the dedicated catalogue seeder after production admin edits changes nothing on existing plans. No active public offer, payment or account move is introduced.

## Tranche 2 — explicit beta provisioning and closed commerce

Files: `app/Services/BetaInviteService.php`, `WorkspaceProvisioner.php`, `EntitlementService.php`, `app/Http/Controllers/BillingController.php`, `app/Services/Billing/PaddleBillingService.php`, `routes/web.php`; inspect `app/Console/Commands/ProvisionWorkspaceOwner.php` and admin user creation for duplicate provisioning.

- [ ] Beta invitation acceptance selects `free-beta` explicitly, regardless of the current default. Fail atomically if the intended tester plan is unavailable.
- [ ] Provision new beta workspace Production Bench access explicitly and idempotently. Existing cancelled/restricted grants need separate deliberate approval, never an automatic activation on login or seeding.
- [ ] Introduce one server-side billing-availability setting checked before any checkout/subscription mutation. Keep provider event processing coherent; do not disable webhook reconciliation blindly.
- [ ] Preserve `/register` being unavailable. Keep platform beta invitations and workspace member invitations as different workflows.
- [ ] Update account UI to show the selected workspace's plan and shared allowances; do not offer member checkout. Owner subscription authority is distinct from routine Editor operations.

Tests: `BetaInviteAcceptanceTest.php`, `BetaInvitesResourceTest.php`, `AuthFlowTest.php`, `BillingFlowTest.php`, `Console/ProvisionWorkspaceOwnerTest.php`, `ProductionBenchEntitlementTest.php`. Include switching the default to Free while a pending beta invite still provisions Free beta; failed provisioning consumes no invitation; checkout stays blocked even with configured Paddle IDs.

## Tranche 3 — permissions and tenant boundaries

Files: `app/Policies/Concerns/HandlesWorkspaceAuthorization.php`, `WorkspacePolicy.php`, `WorkspaceMemberPolicy.php`, `RecipePolicy.php`, `RecipeVersionPolicy.php`, `app/Models/Workspace.php`, `User.php`, `app/Models/Scopes/OwnedByCurrentTenantScope.php`, `app/Http/Controllers/RecipeController.php`; locate all callers of ProductionBenchAccess before editing.

- [ ] Derive Owner authority only from `workspaces.owner_user_id`. Reject Owner as an invite or ordinary role-change value. An Owner-labelled membership for a nonowner must confer no ownership powers.
- [ ] Align discovery scopes and record policies together. Membership permits access within the selected workspace; retain intentional personal records and reject foreign identifiers, including nested IDs, downloads and duplicate/import targets.
- [ ] Add dedicated lock/unlock abilities. Existing controller authorization uses `update`, which must not become an Editor route to unlocking when update access expands.
- [ ] Separate view, operational write, operational settings, delete, membership management and commercial control. Classify cancellation/reversal per action; preserve posted-record and traceability restrictions.
- [ ] Fix Editor deletion through `app/Actions/Production/DeleteEmployee.php`, `DeleteDepartment.php`, `DeleteProductionRun.php` and audit sibling destructive actions.
- [ ] Resolve plan and usage against the selected workspace. Audit the legacy `productionBatchCount(User)` user-level count; do not multiply a shared allowance by number of members.
- [ ] Recheck live membership at every sensitive write/download. Cached accessible workspace IDs and an earlier page mount are not authority. Bind forms to their original workspace and reject stale submissions after a context switch.

Tests: update owner-private expectations in `RecipePrivacyTest.php`; extend `RecipeVersionPagesTest.php`, `RecipeDeletionTest.php`, `RecipeMediaAccessTest.php`, `ProductionBenchProductionSettingsTest.php`, `ProductionBenchEntitlementTest.php`, `EntitlementLimitsTest.php`. Matrix includes all four roles, unrelated user and platform-admin nonmember. Test forged Owner membership, demotion/removal after page load and cross-workspace nested IDs.

## Tranche 4 — shared costing and conflicting edits

Files: `app/Services/RecipeVersionCostingSynchronizer.php`, `RecipeDraftSaver.php`, `RecipeVersionPublisher.php`, `RecipeVersionRecordService.php`, `RecipeWorkbenchService.php`, `app/Livewire/Dashboard/RecipeWorkbench.php` and corresponding workbench state/views.

- [ ] After the shared-costing decision, define canonical costing identity for workspace-owned versions; retain user identity as attribution rather than selecting a different costing by the viewer's login.
- [ ] Preview existing costing rows grouped by version in the target database. Locally no duplicates were found; that is not production evidence. Preserve conflicting historical scenarios and require explicit reconciliation instead of picking/deleting one silently.
- [ ] Use a persisted revision token for the recipe aggregate and a common transaction row lock across save/publish/restore/lock operations. Compare expected revision under the lock, recheck authority/lock state, then write and increment revision atomically.
- [ ] Conflict response preserves the submitted form and asks the user to reload/reconcile; never silently retry by overwriting the other person's version. Ensure media changes roll back with failed saves.
- [ ] Keep production snapshots immutable and current material price precedence intact. Audit attribution records both workspace and actor.

Tests: `RecipeVersionCostingTest.php`, `RecipeWorkbenchPersistenceTest.php`, `RecipeWorkbenchDraftLifecycleTest.php`. Two editors load revision N: first saves; second receives conflict and cannot overwrite. Owner locks between Editor load/save: Editor write is denied. Concurrent publish/restore remains coherent. All members see the same canonical costing if that option is approved. Run actual multi-connection PostgreSQL concurrency tests, not only sequential SQLite simulations.

## Tranche 5 — member invitations and workspace selection

Create a new invitation migration: the live table is absent. Update `tests/Feature/PrivateMvpSchemaTest.php`, which currently expects absence. Reuse `WorkspaceMember`, `WorkspaceMemberRole` and existing membership uniqueness.

- [ ] Add invitation model/service, acceptance request/controller and user-interface member management following the existing BetaInviteService conventions without reusing its owner-provisioning path. Store workspace, normalised recipient email, allowed role, hashed token, inviter, expiry, acceptance and revocation state.
- [ ] Issue only for current Owner/Admin with collaboration capability and spare capacity. All roles count; valid outstanding invites reserve seats. Existing members do not reserve an extra seat. Reject duplicate outstanding recipient invitations or rotate/revoke the existing token on resend.
- [ ] Serialise issue/accept/role-change/removal using a workspace transaction lock; check current entitlement, actor authority, expiry and capacity inside that lock. Acceptance converts one reservation into one membership. Never remove existing members after a limit reduction.
- [ ] Require matching verified identity for an existing account. New-user acceptance verifies possession of the invited email, creates only the invited membership, and does not create an owned workspace or user entitlement.
- [ ] Guard the `Filament\\Auth\\Events\\Registered` → `CreateDefaultCompany` listener path. Member onboarding must not accidentally trigger owner provisioning. Recheck invitation authority if the inviter has since lost permission.
- [ ] Add revocation/resend, clear role descriptions and owner protection. Rate-limit send/resend per actor and workspace, add per-recipient cooldown, and bound outstanding invitations. Never log raw tokens.
- [ ] Add selected-workspace UI for multi-workspace members. Do not expose arbitrary workspace creation. Removal of the active membership clears/falls back to a genuinely accessible workspace; all stale forms remain invalid.

New tests: member invitation service/controller, member management and workspace selection. Existing targets: `SettingsSecurityTest.php` (currently expects no member controls), `AuthFlowTest.php`, `BetaInviteAcceptanceTest.php`. Cover wrong identity, expired/revoked/replayed token, existing membership idempotence, no accidental workspace/entitlement, concurrent final-seat acceptance, concurrent issue, owner protection, and old links after resend.

## Tranche 6 — pilot and production rollout

- [ ] Audit existing task/task-set/run payload guards. Add bounds only where a verified gap permits unbounded work; keep historical production records. Aggregate workspace throttles prevent multiplying workload by member count.
- [ ] Run affected tests after each tranche. For PHP run Pint; for Filament run Filacheck; after code changes refresh graphify. Run the full suite before rollout and ask the user to run `php artisan test --compact` as required by project rules.
- [ ] Exercise an ordinary Owner/Admin/Editor/Viewer through formula → purchasing → receipt → production → inventory. Verify manual price override, lock protection, attribution, member removal and plan limit errors.
- [ ] Rehearse migrations and dedicated seed against a disposable PostgreSQL restore containing admin-edited plans. Review data changes before touching the hosted database; obtain a restorable backup.
- [ ] Deploy behind a collaboration availability switch. Existing single-user beta behavior must remain usable with the switch off. Do not expose invitations before tranches 1–5 pass.
- [ ] Apply additive schema changes, then the dedicated create-only catalogue seed. Separately review/apply the exact beta capability/seat additions needed for the existing Soapkraft workspace. No development-to-production limit sync and no user/account transfer.
- [ ] Enable for the factory pilot, verify real workflows, then expand to other invited testers. Keep existing testers on Free beta when a future public Free default is deliberately activated.

Rollback: turn off new invitations/team UI, retain records and memberships, and use the tested access fallback. Do not run destructive down migrations against accepted invitations, audit records or reconciled costing data. A code rollback that would restore obsolete costing identity needs a separate compatibility check.

## Delivery ownership

Luna max: bounded catalogue, admin form, invitation and UI tasks with explicit tests. Astra medium: tenant authorization, canonical costing/concurrency design, migration review and each integrated tranche. Escalate a task when Luna encounters repeated test failures or architectural ambiguity; do not spend credits repeating an unsuccessful approach. Keep each tranche in a reviewable commit; no broad cosmetic refactor.
