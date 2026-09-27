# Multi-user workspace rollout plan

> **For agentic workers:** Use superpowers:subagent-driven-development or superpowers:executing-plans for each implementation tranche. The user selected Luna max for bounded implementation and Astra medium for review or difficult work. This is the delivery roadmap; the first executable tranche is linked below. The two product decisions were confirmed on 2026-09-25; implement their dependent paths with the agreed access and attribution rules.

**Goal:** Launch private shared workspaces while preserving tester access, production configuration and company records.

**Architecture:** Keep Workspace as the ownership boundary, actual owner as subscription authority, and individual users as actors. Reuse owner-backed plan resolution and separate Production Bench entitlements. Introduce membership access only after role checks, shared data and conflicting-write handling are consistent.

**Tech stack:** Existing Laravel, Livewire, Filament, Pest and PostgreSQL; confirm installed package versions before implementation. No new dependencies proposed.

**Design:** `docs/superpowers/specs/2026-09-25-multi-user-workspace-beta-design.md`.

## Scope and confirmed decisions

The online target is approximately two weeks. Public launch includes self-service registration and paid checkout from the WordPress site. Deliver in independently testable stages and activate these through the launch checklist; never as seeding side effects. Aggregate storage quotas, remaining index investigations and public collaboration are deferred.

The user confirmed both recommendations on 2026-09-25:

1. Viewers may export/download data they can view, subject to the same workspace isolation and live-membership checks.
2. Saved formula costing is shared by the workspace: one canonical costing with actor attribution, rather than a costing selected by the viewing user. Preserve conflicting historical scenarios for explicit reconciliation.

Keep existing formula-lock semantics until characterised. Explicitly distinguish formulation changes, saved costing changes, current material prices and immutable production snapshots. Preserve the agreed price precedence: latest eligible receipt supplies the price; a manual costing price override wins according to the existing implementation. Do not refactor that rule into a different precedence while enabling membership.

## Implementation checkpoint — 2026-09-25

Implemented catalogue preservation, nullable capability flags and admin controls, explicit beta-plan provisioning, fresh actual-owner authorization, owner protection, and owner-only Production Bench activation/cancellation, and Owner/Admin-only production record deletion with matching UI controls. Billing now defaults off through BILLING_AVAILABLE; configured provider keys cannot open checkout, while webhook reconciliation remains active. The create-only catalogue is an explicit deployment step, not part of DatabaseSeeder. Existing beta rows retain their legacy limits and unset capability flags until a deliberate separate upgrade.

Local development has received the additive migration and four absent inactive public plans. Before/after checksums confirmed the existing Free beta row (apart from the two new nullable columns), its limits, user entitlements and Production Bench grants are unchanged. Production is untouched. The PostgreSQL migration was also rolled back and reapplied in a disposable database.

Formula lock/unlock now uses its own Owner/Admin policy, including UI guards. General formula access is still owner-only pending the shared-data and concurrency tranche; adding the lock ability alone does not enable Admin workbench access.

The broader multi-user feature is not yet available: member invitations/selection, shared formula permissions, conflicting-edit protection, existing-beta capability rollout and pilot activation remain outstanding. Viewer export and shared-costing decisions are confirmed. Payment provider selection remains open; no paid offers or public registration have been activated.

Canonical costing storage is now implemented and applied locally: one costing per version, nullable original-author attribution and a nullable last explicit editor. Parent formula authority replaces author-based access. The migration refuses duplicate costings and inconsistent ownership before altering schema. All 20 local costings, their children and current material-price records retained identical checksums; no historical editor was invented. Production remains untouched. Verification: 36 costing/migration tests passed on SQLite; 190 broader affected tests passed with 20 existing skips; PostgreSQL preservation/rollback test passed, followed by 173 affected tests with 20 existing skips. General formula access remains owner-only until the remaining safeguards are complete.

Costing loads and production previews now project defaults in memory without creating or reconciling costing records. Explicit saves retain persistence, and recording a production snapshot remains an authorized write with frozen values. Existing saved currencies, zero prices, duplicate packaging rows and manual-price precedence are preserved. Saved views, print outputs and streamed CSV/Excel downloads remain read-only with both absent and existing costing; their empty-costing behavior is unchanged. PostgreSQL validation of costing, snapshots and output paths passed 141 tests (764 assertions). These are read-safety prerequisites; they do not yet grant Viewer access to formulas.

Integrated validation after both costing steps: 3,959 passed / 29 existing skips (65,787 assertions). The ingredient-replacement fixture now uses separate formula versions instead of obsolete per-user costings and confirms workspace prices win over a different author's personal price. Pint and diff checks passed; Graphify refreshed. The task-owned PostgreSQL test database was removed after verification.

Validation for this checkpoint: full suite 3,932 passed / 29 skipped (65,644 assertions); core plan/beta/admin tests on disposable PostgreSQL 89 passed; additive migration rollback/reapply verified. Pint, Filacheck and diff checks passed; Graphify refreshed. Later team concurrency work still requires its own PostgreSQL concurrency tests.

## Editing-protection checkpoint — 2026-09-26

The implemented package combines per-tab editing reservations, visible external-change warnings, and atomic revision checks. Reading a formula never acquires a reservation; entering edit mode does. One editor holds the reservation, renewed approximately every 15 seconds with a 90-second expiry. Same-user second tabs must not silently take over. Owner/Admin takeover is explicit and audited. Expiry, takeover, demotion and workspace changes preserve entered input but prevent stale writes. Polling/focus checks report changes without replacing the browser's loaded revision or reloading price history. Permanent formula approval locks remain distinct from temporary editing reservations. Background material-price propagation remains permitted and invalidates affected costing revisions without changing human authorship. Adapt Cosmood's pattern, but enforce current authority and reservation ownership under the save transaction, never through cached browser flags.

Launch onboarding is one account per email and at most one owned company per account; invited membership in other companies remains supported. A second owned company under the same login is deferred. Each selected company resolves its own allowances and subscription authority. Team's target initial seat allowance is now 10 including the owner; this is a future explicit catalogue/rollout change, not permission to overwrite existing production plan values. No checkout or collaboration activation is included in this editing-protection package.

The additive editing migration is applied locally. Checksums confirm all 15 existing recipes, 20 costings, 109 ingredient costing rows and 8 packaging costing rows were preserved. PostgreSQL verification used independent database sessions: seven contention cases passed, including actual publish/restore, permanent lock, takeover, automatic price propagation and competing lease acquisition. General formula permissions remain owner-only; production deployment and membership rollout are still separate. Final verification: 3,994 tests passed / 36 skipped (66,089 assertions), with the seven PostgreSQL contention cases verified separately. Build, Pint and diff checks passed; Graphify refreshed. An authenticated two-tab visual check remains for the user because the verification browser was signed out.

## Shared authorization checkpoint — 2026-09-27

The authorization tranche now uses native Laravel policies and a shared WorkspaceAuthorization service. Operational authority requires a fresh selected workspace, current membership, and an explicit collaboration entitlement on the actual owner's plan for nonowners. Application administration grants no company permissions. Ordinary company() memoization is presentation-only; sensitive checks use fresh selection. Owner/Admin can manage operational settings and delete records; Editor can perform operational writes but cannot delete or change permanent formula locks; Viewer can search, read and export visible records. Owner alone appoints/manages Admins; Admin may manage Editor/Viewer. Nobody assigns Owner through ordinary membership changes. Invitation issuance, acceptance and seat enforcement remain tranche 5.

Formulation and Production Bench have separate module authorization boundaries. Production Bench retains its existing read/discovery contract for authorized company members; an active grant is required for operational writes and cancellation preserves read/export access. The new module helper is an extension boundary, not a new per-member module restriction. A future Sales module and per-member module selector are not implemented. New modules must add an explicit module gate and distinguish common product data from formula composition.

Mounted operational forms retain a locked original workspace and reject company switches. Unsaved formula creation, duplication and packaging creation also reject silent retargeting; guest calculations bind to the signed-in company on their first authenticated write. Recipe reservations/revisions remain in place. Material services now authorize writes/deletions under workspace locks, including pricing, ingredient codes/guidance and formula-wide ingredient/packaging removal. Reversible production cancellations and reversals remain Editor operations with retained evidence; destructive deletions and production setup require Owner/Admin.

Legacy batch storage has an additive nullable workspace identifier and nullable author attribution. Author deletion no longer destroys shared snapshots, and deleting source formulas cannot erase workspace provenance. New batches record the company and share its quota under a workspace lock. Existing unresolved batches remain private to their original actor; unresolved owner batches conservatively count against that owner's selected company allowance. They are never assigned using current membership. Run `php artisan production-batches:backfill-workspaces` to preview unambiguous surviving formula/version provenance, then deliberately run with `--apply`; unresolved/conflicting rows and timestamps remain unchanged. This command does not alter plans or activate collaboration. Production deployments must review this preview separately.

The batch migration is applied locally. Read-only checksums confirm all 15 recipes, 20 costings, five plans and 29 plan limits are unchanged. There are no legacy batches locally, so its backfill preview assigns zero rows. Truss confirms only the batch workspace column/index/FK and nullable author/null-on-delete FK changed. Existing unindexed batch recipe/version foreign keys remain part of the deferred index assessment. Production has not been connected to or modified by this tranche.

Final validation: 4,131 tests passed / 37 skipped (66,744 assertions). Disposable PostgreSQL verified 144 authorization/material/batch/snapshot/numbering tests (760 assertions), including populated migration rollback/reapply, plus all seven independent-session editing-contention cases (43 assertions). The disposable database was removed afterward. Independent backend/security review, final UI review, Pint, the production asset build and diff checks passed; Graphify was refreshed. An authenticated multi-person visual pilot remains part of tranche 6. Do not roll back the provenance migration after reconciliation without reviewing loss of the new workspace column; rollback refuses missing authors.

## Tranche 1 — protect catalogue deployment

- [x] Execute `2026-09-25-plan-seeding-safety.md` first. Existing plans must be skipped entirely, including missing limit keys.
- [x] Add a dedicated `database/seeders/WorkspacePlanCatalogSeeder.php` for absent Free/Maker/Studio/Team plans. New future plans are inactive, non-default, without Paddle identifiers. Use the design's allowance table; preserve unreviewed legacy history/batch keys rather than inventing semantics.
- [x] Keep existing Free beta unchanged. New beta defaults can use the agreed larger allowances only in the explicit new-catalogue path; do not make compatibility fixtures silently alter live rows.
- [x] Expose `workspace_members` alongside existing keys in `app/Filament/Resources/Plans/Schemas/PlanForm.php`. Remove implicit missing-limit creation on an ordinary EditPlan save; initialise defaults only during deliberate creation. Review `app/Services/PlanLimitDefaultsService.php` and both plan page hooks.
- [x] Add explicit feature capability storage/controls for collaboration and Production Bench; inspect schema and package documentation first. Missing capability means not granted, independently of a null/unlimited count. Existing Production Bench grants remain authoritative for existing access, including cancelled states. A plan declaration must not silently reactivate a cancelled workspace grant.
- [ ] New plan rows alone do not grant features to existing beta workspaces. Provide a separate previewable, idempotent capability rollout with expected-value checks; leave production execution for the reviewed deployment step.

Tests: `EntitlementLimitsTest.php`, `PlanFormulaItemLimitMigrationTest.php`, `Filament/CatalogResourcesTest.php`, and new catalogue tests. Verify whole-row preservation including timestamps, default, billing identifiers, zero/null and absent limits; failure rollback; no entitlement reassignment. Test fresh and populated disposable PostgreSQL databases.

Acceptance: rerunning the dedicated catalogue seeder after production admin edits changes nothing on existing plans. No active public offer, payment or account move is introduced.

## Tranche 2 — explicit beta provisioning and closed commerce

Files: `app/Services/BetaInviteService.php`, `WorkspaceProvisioner.php`, `EntitlementService.php`, `app/Http/Controllers/BillingController.php`, `app/Services/Billing/PaddleBillingService.php`, `routes/web.php`; inspect `app/Console/Commands/ProvisionWorkspaceOwner.php` and admin user creation for duplicate provisioning.

- [x] Beta invitation acceptance selects `free-beta` explicitly, regardless of the current default. Fail atomically if the intended tester plan is unavailable.
- [x] Provision new beta workspace Production Bench access explicitly and idempotently. Existing cancelled/restricted grants need separate deliberate approval, never an automatic activation on login or seeding.
- [x] Introduce one server-side billing-availability setting checked before any checkout/subscription mutation. Keep provider event processing coherent; do not disable webhook reconciliation blindly.
- [ ] Preserve `/register` being unavailable before launch, then enable public registration through the launch workstream below. Keep public onboarding, platform beta invitations and workspace member invitations as distinct workflows.
- [ ] Update account UI to show the selected workspace's plan and shared allowances; do not offer member checkout. Owner subscription authority is distinct from routine Editor operations.

Tests: `BetaInviteAcceptanceTest.php`, `BetaInvitesResourceTest.php`, `AuthFlowTest.php`, `BillingFlowTest.php`, `Console/ProvisionWorkspaceOwnerTest.php`, `ProductionBenchEntitlementTest.php`. Include switching the default to Free while a pending beta invite still provisions Free beta; failed provisioning consumes no invitation; checkout stays blocked in prelaunch mode even with configured provider IDs; launch mode enables only approved public offers.

## Tranche 3 — permissions and tenant boundaries

Files: `app/Policies/Concerns/HandlesWorkspaceAuthorization.php`, `WorkspacePolicy.php`, `WorkspaceMemberPolicy.php`, `RecipePolicy.php`, `RecipeVersionPolicy.php`, `app/Models/Workspace.php`, `User.php`, `app/Models/Scopes/OwnedByCurrentTenantScope.php`, `app/Http/Controllers/RecipeController.php`; locate all callers of ProductionBenchAccess before editing.

- [x] Derive Owner authority only from `workspaces.owner_user_id`. Reject Owner as an invite or ordinary role-change value. An Owner-labelled membership for a nonowner must confer no ownership powers.
- [x] Align discovery scopes and record policies together. Membership permits access within the selected workspace; retain intentional personal records and reject foreign identifiers, including nested IDs, downloads and duplicate/import targets.
- [x] Add dedicated lock/unlock abilities. Existing controller authorization uses `update`, which must not become an Editor route to unlocking when update access expands.
- [x] Separate view, operational write, operational settings, delete, membership management and commercial control. Classify cancellation/reversal per action; preserve posted-record and traceability restrictions.
- [x] Fix Editor deletion through `app/Actions/Production/DeleteEmployee.php`, `DeleteDepartment.php`, `DeleteProductionRun.php` and audit sibling destructive actions.
- [x] Resolve plan and usage against the selected workspace. Audit the legacy `productionBatchCount(User)` user-level count; do not multiply a shared allowance by number of members.
- [x] Recheck live membership at every sensitive write/download. Cached accessible workspace IDs and an earlier page mount are not authority. Bind forms to their original workspace and reject stale submissions after a context switch.

Tests: update owner-private expectations in `RecipePrivacyTest.php`; extend `RecipeVersionPagesTest.php`, `RecipeDeletionTest.php`, `RecipeMediaAccessTest.php`, `ProductionBenchProductionSettingsTest.php`, `ProductionBenchEntitlementTest.php`, `EntitlementLimitsTest.php`. Matrix includes all four roles, unrelated user and platform-admin nonmember. Test forged Owner membership, demotion/removal after page load and cross-workspace nested IDs.

## Tranche 4 — shared costing and conflicting edits

Files: `app/Services/RecipeVersionCostingSynchronizer.php`, `RecipeDraftSaver.php`, `RecipeVersionPublisher.php`, `RecipeVersionRecordService.php`, `RecipeWorkbenchService.php`, `app/Livewire/Dashboard/RecipeWorkbench.php` and corresponding workbench state/views.

- [x] Define canonical costing identity for workspace-owned versions; retain user identity as attribution rather than selecting a different costing by the viewer's login.
- [ ] Preview existing costing rows grouped by version in the target database. Locally no duplicates were found; that is not production evidence. Preserve conflicting historical scenarios and require explicit reconciliation instead of picking/deleting one silently.
- [x] Use a persisted revision token for the recipe aggregate and a common transaction row lock across save/publish/restore/lock operations. Compare expected revision under the lock, recheck authority/lock state, then write and increment revision atomically.
- [x] Conflict response preserves the submitted form and asks the user to reload/reconcile; never silently retry by overwriting the other person's version. Ensure media changes roll back with failed saves.
- [x] Keep production snapshots immutable and current material price precedence intact. Audit attribution records both workspace and actor.

Tests: `RecipeVersionCostingTest.php`, `RecipeWorkbenchPersistenceTest.php`, `RecipeWorkbenchDraftLifecycleTest.php`. Two editors load revision N: first saves; second receives conflict and cannot overwrite. Owner locks between Editor load/save: Editor write is denied. Concurrent publish/restore remains coherent. All members see the same canonical costing under the confirmed shared-costing model. Run actual multi-connection PostgreSQL concurrency tests, not only sequential SQLite simulations.

### Bounded costing sequence after decision confirmation

1. Canonical storage first: one costing per recipe version, preserving costing IDs, children, prices and ManualCosting source references. Reject ambiguous duplicate versions before schema changes. Keep original `user_id` as nullable author attribution with null-on-delete; add nullable `updated_by_user_id` for explicit future edits, leaving historical last-editor identity unknown. Costing policies delegate to the parent formula/version, never to authorship. Keep general formula access owner-only.
2. Completed: make workbench costing reads and production previews read-only, including defaults when no costing exists. Preserve the already read-only saved-formula, print and export paths, including their empty-costing behavior when nothing has been saved. Explicit saves initialise records instead.
3. Add recipe aggregate and costing revision protocols through server, client hydration/payload and conflict UI. Lock workspace → recipe → version → costing, recheck authorization and expected tokens under the lock, and reject stale/missing tokens without losing submitted input. Automatic receipt/manual-price propagation must also bump affected costing revisions while retaining its existing price provenance. It must not claim the previous human costing editor authored an automatic update.
4. Only then widen workspace scopes/role permissions and enable collaboration. Verify PostgreSQL contention, not just sequential stale-object tests.

Local read-only audit on 2026-09-25 found zero duplicated costing versions, zero recipe/version workspace inconsistencies and zero duplicated current versions. Repeat these checks on each target database; this is not production evidence.

### Legacy production batch ownership prerequisite

Before this tranche, `production_batches` had actor `user_id` but no durable workspace identifier. Its nullable recipe/version references use `ON DELETE SET NULL`. Counting all current members' batches would misattribute personal and former-workspace history and would change when members leave. Do not implement that shortcut. Implemented on 2026-09-27: explicit workspace provenance for new records and a preview-first historical backfill command; unresolved records stay private for explicit reconciliation. Run the preview separately on each deployment target before applying its assignments. Production Bench runs already have their own workspace ownership and entitlement model and must remain distinct.

## Tranche 5 — member invitations and workspace selection

The additive invitation migration is implemented and applied locally. `PrivateMvpSchemaTest` now expects the invitation table. The implementation reuses `WorkspaceMember`, `WorkspaceMemberRole` and existing membership uniqueness.

- [x] Add invitation model/service, acceptance request/controller and user-interface member management following the existing BetaInviteService conventions without reusing its owner-provisioning path. Store workspace, normalised recipient email, allowed role, hashed token, inviter, expiry, acceptance and revocation state.
- [x] Issue only for current Owner/Admin with collaboration capability and spare capacity. All roles count; valid outstanding invites reserve seats. Existing members do not reserve an extra seat. Reject duplicate outstanding recipient invitations or rotate/revoke the existing token on resend.
- [x] Serialise issue/accept/role-change/removal using a workspace transaction lock; check current entitlement, actor authority, expiry and capacity inside that lock. Acceptance converts one reservation into one membership. Never remove existing members after a limit reduction.
- [x] Require matching verified identity for an existing account. New-user acceptance verifies possession of the invited email, creates only the invited membership, and does not create an owned workspace or user entitlement.
- [x] Guard the `Filament\\Auth\\Events\\Registered` → `CreateDefaultCompany` listener path. Member onboarding must not accidentally trigger owner provisioning. Recheck invitation authority if the inviter has since lost permission.
- [x] Add revocation/resend, clear role descriptions and owner protection. Rate-limit send/resend per actor and workspace, add per-recipient cooldown, and bound outstanding invitations. Never log raw tokens.
- [ ] Add selected-workspace UI for multi-workspace members. Do not expose arbitrary workspace creation. Removal of the active membership clears/falls back to a genuinely accessible workspace; all stale forms remain invalid.

New tests: member invitation service/controller, member management and workspace selection. Existing targets: `SettingsSecurityTest.php` (currently expects no member controls), `AuthFlowTest.php`, `BetaInviteAcceptanceTest.php`. Cover wrong identity, expired/revoked/replayed token, existing membership idempotence, no accidental workspace/entitlement, concurrent final-seat acceptance, concurrent issue, owner protection, and old links after resend.

### Invitation and member management checkpoint — 2026-09-27

The Settings Team tab supports Owner/Admin member management, invitations, resend (including expired invitations), revocation, role changes and confirmed removal. Owner alone manages Admins; Admin manages Editor/Viewer. The actual Owner is shown even without a membership row. Member details require current company authority, and mounted forms reject changed selection or lost authority. Acceptance supports existing verified accounts and new member-only accounts; it never grants an owned company or subscription. Inviter authority is checked independently of the inviter's currently selected company.

Seat enforcement reads the actual owner's existing plan limit without changing plans or limits. Pending valid invitations reserve seats; the Owner counts once. Missing limits allow only the Owner, while explicit null means unlimited only when collaboration is granted. Workspace locks serialize mutations; operations that update an existing user lock that user before the workspace. Invitations expire after seven days, resend rotates tokens, recipient cooldown is 60 seconds, sends are limited to 20 per actor/company and 60 per company per minute, and at most 100 valid pending invitations are allowed. Notifications run after the outer transaction commits.

`WORKSPACE_COLLABORATION_ENABLED` defaults to false for deployment and is enabled only in the local development environment after migration. PHPUnit explicitly defaults it to false; collaboration tests opt in. No seed, production connection, real invitation or account creation was performed. Nine before/after aggregate checksums confirm all existing local users, companies, memberships, entitlements, plans, limits, formulas, costings and production batches were preserved. Truss reports only the new invitation table. The company switcher remains the next implementation step; finish it and the pilot checks before enabling this flow in production.

Validation: 4,180 tests passed / 39 skipped (66,908 assertions). Both real PostgreSQL contention cases passed separately (13 assertions): competing invitations for the final seat and simultaneous acceptance of one token. The disposable database was removed. Targeted member UI tests passed (15 cases, 62 assertions), including expired resend and all acceptance identity branches. Root backend/security review and Astra UI integration review found no remaining blocking issues; Pint, the production asset build and diff checks passed, and Graphify was refreshed. Real email delivery and the authenticated multi-person pilot remain unexercised.

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

## Public launch workstream — added after user clarification

This workstream is required before the public launch, alongside the workspace tranches. The homepage is WordPress. Public registration/payment are no longer deferred scope.

- [ ] Select one provider. Prefer evaluating the existing Paddle integration first; confirm merchant approval and sandbox checkout-first provisioning. Creem is an alternative, not a second simultaneous integration.
- [ ] Wire public Free registration to the app, with email verification and idempotent owner/workspace provisioning. Keep Free beta unavailable for public self-selection, even through forged plan parameters.
- [ ] Wire paid WordPress buttons to checkout, optionally via a server-side app checkout-intent endpoint. Resolve approved provider price IDs on the server; preserve plan/currency/interval selection without trusting a submitted price amount.
- [ ] Build checkout-first purchase claiming: persist verified provider purchases before an app user exists, then securely associate the purchase with an authenticated/verified owner and intended workspace. Existing accounts must not acquire duplicate workspaces or subscriptions automatically.
- [ ] Review Cashier's customer/model association expectations before choosing the implementation. Reuse authenticated checkout for existing owners; do not assume the current billing service supports anonymous purchases.
- [ ] Handle success return before webhook with a pending screen, and webhook before return/account creation with durable unclaimed purchase state. Signed, idempotent provider events drive entitlements; browser redirects cannot activate paid access.
- [ ] Test failed/abandoned checkout, duplicate purchases, webhook replay/order/concurrency, wrong-account claims, existing-owner upgrades, renewal/cancellation and preserved beta grants. Prevent replay from creating additional workspaces.
- [ ] Review WordPress plan names/prices against approved provider offers. Free bypasses payment. Do not publish unconfirmed annual or currency prices.
- [ ] At launch explicitly activate public Free/default selection, approved paid offers, registration and checkout settings. Test a complete sandbox journey beforehand and verify live configuration separately. Existing testers retain their existing Free beta entitlement and limits.

The product flow is settled; provider selection and checkout-first account-linking implementation still require their own bounded technical plan. No live payment/signup setting is changed by this documentation update.
