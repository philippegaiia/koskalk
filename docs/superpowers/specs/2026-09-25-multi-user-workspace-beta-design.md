# Multi-user workspace beta — design and delivery proposal

Date: 2026-09-25
Status: role model, separate tester plan, adjustable initial allowances and create-only production seeding agreed. Implementation roadmap prepared; Viewer exports and shared costing remain open product decisions. No application or production changes have been made during planning.

## Goal

Let an organisation work in one private workspace with individual logins and clear operational authority. Design for organisations generally; the owner's three-person factory is the first real-use pilot, not a hardcoded membership size.

## Agreed role model

| Capability | Owner | Admin | Editor | Viewer |
| --- | --- | --- | --- | --- |
| Read workspace data, search and filter | Yes | Yes | Yes | Yes |
| Create and edit unlocked content | Yes | Yes | Yes | No |
| Routine purchasing, inventory and production operations | Yes | Yes | Yes | No |
| Lock and unlock formulas | Yes | Yes | No | No |
| Delete eligible records | Yes | Yes | No | No |
| Manage members and operational workspace settings | Yes | Yes | No | No |
| Subscription authority, ownership transfer, workspace deletion | Yes | No | No | No |

The actual workspace owner is authoritative; ownership must not be grantable by assigning an ordinary membership role. Admins may manage Admin/Editor/Viewer memberships but cannot remove/demote the owner or transfer ownership. Existing transaction, posting, lifecycle and retention rules still restrict Owner/Admin operations. Operational corrections such as receipt reversal need an explicit action-by-action classification; do not implement a blanket delete restriction that accidentally forbids all valid corrections.

Platform administration (`users.is_admin`) is separate. It grants administration-panel access, not an implicit workspace permission bypass. No per-workspace custom permission editor is proposed for the first release.

Viewer export/download rights remain a product decision. Proposed default: Viewers may read all workspace data, including costs, but downloads/exports require explicit agreement before implementation. A cost-hidden production role is a separate later feature, not something the four-role model claims to provide.

## Verified current state (local database only)

- `philippe@soapkraft.com` is user 1, `is_admin=true`.
- This user is already owner of workspace 1, Soapkraft, and has an Owner membership.
- `active_workspace_id` is null; `User::company()` currently falls back to the owned workspace. Null does not mean ownership is absent.
- The user has an active Free beta entitlement, source registration, with no end date.
- Soapkraft has a separate active Production Bench entitlement.
- The Free beta plan's legacy `production_batches` limit is 0; this is distinct from the active `WorkspaceProductionEntitlement` used by Production Bench. Do not interpret one counter as proof that the other feature is disabled, or change the limit blindly.
- Current beta invitation acceptance creates a new user, owned workspace, and default user entitlement. It does not join an existing organisation and does not explicitly activate Production Bench.
- Billing checkout routes exist and check active/billable plans plus provider configuration. An explicit beta policy should disable checkout server-side; hiding subscription links alone is insufficient.
- These observations do not establish the state of a separately hosted deployment.

## Recommended beta policy

Keep Free beta as a separate hidden, invitation-only tester plan with Team-level capabilities, including Production Bench. Preserve existing testers on it when the public Free plan launches; do not assign an automatic expiry or convert testers to Free. Do not create a paid subscription or require payment details. Keep the existing distinction between plan/usage entitlements and workspace Production Bench access; reconcile their presentation and provisioning rather than rebuilding billing during team rollout.

Keep public registration closed. Separate two invitations:

1. Platform beta invitation: a platform administrator approves a new organisation and provisions its owner/workspace with the beta grant and Production Bench access.
2. Workspace member invitation: an authorised workspace Owner/Admin invites a colleague into that existing workspace with an allowed role. Acceptance must not automatically create a personal workspace or a new commercial entitlement.

Support both an existing authenticated user and a newly invited user. Tokens must be hashed, expiring, revocable and single-use; acceptance must verify the intended identity and current invitation authority. Existing membership acceptance is idempotent. Role changes, removal and pending invitation management must protect the actual owner and recheck authorization at execution time.

Members consume the workspace's shared entitlement; they do not each obtain a separate free allowance. Preserve the current owner-backed entitlement storage where adequate, but consistently resolve authorization and usage through the selected workspace. Paid seat pricing and checkout activation are future commercial decisions.

## Future plan names and pricing targets — user clarification

The established ladder is Free, Maker, Studio, Team. The August 1 homepage design explicitly described its higher displayed prices as prototype figures; the targets below supersede those figures for this proposal, without enabling billing or changing the public pricing page.

| Plan | Monthly target | Annual target | Product boundary |
| --- | --- | --- | --- |
| Free | No paid price specified in this clarification; existing concept is free | Not applicable | Entry offering; precise limits remain to be defined |
| Maker | 5.99, with currency-specific catalogue entries to confirm | EUR 39 / USD 39 per year | Solo formulation offering; precise feature/usage limits remain to be defined |
| Studio | EUR 19 per month | Not specified | One user, with Production Bench |
| Team | EUR 29 per month | Not specified | Studio capabilities plus shared workspace and roles |

The Team price unit (per workspace versus per member) and any extra-seat pricing need explicit agreement before billing activation. Five seats, counting every role, are the adjustable initial capacity chosen below. Studio/Team USD and annual prices are not established by this clarification. Do not infer them from the old prototype's monthly/annual toggle. Maker's EUR 39 yearly is an alternative to EUR 5.99 monthly, not twelve monthly payments of EUR 5.99.

Keep beta access separate from the permanent Free tier: approved beta workspaces receive Production Bench and team capabilities through their separate tester plan without being charged or advertising those capabilities as permanently included in Free. Member permissions determine what an individual may do; workspace entitlements determine which features and shared limits are available. The multi-user implementation must support this separation without activating subscriptions.

The user authorised choosing adjustable starting limits. The following are initial catalogue defaults, not promises about production scale, and remain editable by platform administrators:

| Limit | Free | Maker | Studio | Team | Free beta |
| --- | ---: | ---: | ---: | ---: | ---: |
| Workspace members including Owner | 1 | 1 | 1 | 5 | 5 |
| Saved formulas | 15 | 100 | 500 | 1,000 | 1,000 |
| Private ingredients | 20 | 100 | 500 | 1,000 | 1,000 |
| Ingredient lines per formula | 30 | 100 | 200 | 200 | 200 |
| Media assets | 100 | 500 | 2,000 | 4,000 | 4,000 |
| Media labels | 20 | 50 | 100 | 200 | 200 |

All membership roles consume a seat, including Owner and Viewer. Pending valid invitations reserve seats; acceptance converts the reservation into membership without double counting. Expiry/revocation releases the reservation. Existing member reinvitations consume no extra seat. Invitations, membership changes and acceptance enforce capacity transactionally. A plan limit reduction never removes members or deletes records; it blocks further additions until usage is within the limit. Read/export and permitted changes to existing records remain available unless a distinct feature entitlement changes.

These counts are per workspace, except ingredient lines which are per formula. Keep current counting semantics for existing counters and document them in the implementation plan before expanding them. Member capacity is a NEW limit requiring implementation and admin-form support; merely seeding a key would not enforce it. Media asset count is not a storage-byte quota. Production Bench access is a feature grant, not the legacy production_batches counter. Preserve unlisted existing limits until their semantics are reviewed. No automatic history pruning or new production-history cap is introduced here.

The proposed Team price unit remains EUR 29 per workspace per month, subject to the user's pricing confirmation; the five-seat initial limit is editable and does not create extra-seat charges.

## Production-safe catalogue rollout

Use a dedicated catalogue seeder, not the general DatabaseSeeder. Match plans by stable slug and limits by (plan_id, key), with database uniqueness and a transaction. Never truncate, delete/recreate a plan, change existing plan IDs or detach entitlement relations.

Catalogue seeding is strictly create-only by plan slug: if the plan already exists, skip the entire plan, including its limits. Create initial limit rows only when creating a new plan. Missing keys on existing plans require a separately reviewed capability rollout and must not silently acquire development defaults. Preserve every existing limit value, including null/unlimited and zero, plus edited names/descriptions, billing identifiers, prices, active flags and default-plan choice. New future public plans start inactive and non-default; seeding must not trigger Plan::saved() to replace the current default. Seeder reruns must not overwrite values later changed in the administration interface.

Existing Free beta values remain unchanged by all seed reruns. Any deliberate increase is made in the production administration interface, or through a separately requested, narrowly scoped operation with a reviewed before/after preview. Development edits are never synchronised automatically. Such an explicit operation must check expected current values and abort on drift. Do not infer that a value is uncustomised merely because it equals an old seed default. Do not move existing testers, expire entitlements, create paid subscriptions, configure provider product IDs, open registration, or enable checkout.

Production Bench/team grants for existing beta workspaces are also a targeted, idempotent operation. Preview eligible workspaces; preserve explicit cancelled/restricted states unless individually approved for reactivation. Owner-based beta provisioning must select the tester plan explicitly rather than relying on whichever plan is globally default at public launch.

First validate against disposable PostgreSQL with realistic pre-existing plan rows, memberships and administrator customisations. Required tests cover empty catalogue, existing catalogue, rerun after admin edits, null/zero limits, unchanged entitlement assignments, unchanged default and billing IDs, rollback on failure, concurrent invitations and default-plan transition. Before production application, take a restorable database backup and review the exact change preview. A later switch of the default from Free beta to Free is a separate deliberate release operation; existing testers stay on Free beta.

Seed development and production independently through the same reviewed logic. Never copy the development database into production. No production seeding or account changes are authorised by this design document itself.

## Plan allowances and misuse safeguards

Keep plan allowances separate from platform safeguards. Plan values are managed independently in each environment; ordinary deployments do not update existing production plan rows. Existing UI limits cover several catalogue allowances, but not every safety control is database-backed: `FlashProductionLimits::MAX_BATCHES_PER_SUBMISSION` currently hardcodes 1,000. Workspace/location daily production capacity is a planning preference/warning, not a subscription quota or an abuse rate limit. Inventory and verify task/task-set/run safeguards before claiming complete coverage.

Prioritise these additional safeguards for multi-user rollout:

- Invitation send/resend rates by user and workspace, per-address cooldown, and a bound on pending invitations. Aggregate workspace limits prevent multiple members from multiplying allowances.
- Aggregate storage accounting is deferred from the initial rollout. Keep existing image input limits (10 MB before WebP optimisation), PDF limits (180 KB) and asset-count limits. Future byte quotas must count retained originals and variants, not the original upload size when that original is discarded.
- Bulk-operation payload/work bounds: imported rows, tasks generated per run/task set, runs created per request and duplication fan-out. Keep checks server-side and validate the resulting workload before creating records.
- Expensive job concurrency and request rates per workspace for imports, exports and media processing. Deduplicate repeated submissions and reuse existing throttles/idempotency where adequate.
- AI/research usage budgets only if those capabilities become available to ordinary workspace users; current platform-only research should not be advertised as part of Team or beta access merely because those plans include all user-interface features.

These are safeguards to scope and test, not newly implemented limits. Prefer bounded bursts and concurrent work over lifetime caps on production history. Never delete traceability records or remove members when a limit is lowered; reject the new work with a clear explanation. Do not allow ordinary workspace settings to disable platform safeguards. Proposed numeric thresholds need validation against realistic bulk operations; do not present an unmeasured threshold as established capacity.

## Account recommendation

No ownership or data transfer is necessary for philippe@soapkraft.com. Keep the existing Soapkraft workspace and its records. The same login can technically be both platform administrator and workspace Owner. A separate ordinary login is useful for realistic role testing; if the owner later wants to separate platform administration from daily factory work, that should be an explicit account decision, not an automatic migration. Do not remove the only platform administrator.

## Delivery sequence

### 1. Define and characterize the access contract

Map current owner-only policies and member-aware services, including `RecipePolicy`, `HandlesWorkspaceAuthorization`, `ProductionBenchAccess`, `User::company()`, tenant scopes and `EntitlementService`. Define the full role/action matrix, including exports, draft cancellation, posted-record reversal, formula deletion, and operational settings. Confirm employee scheduling records remain distinct from user identities.

Update the owner-only launch documentation deliberately: workspace team access becomes enabled; public community, public publication and confidential share links remain deferred.

### 2. Align server-side authorization

Make workspace membership and role checks consistent across formulas, versions, ingredients, purchasing, inventory, production, attachments, print/export and duplication. Preserve platform catalogue protections and strict cross-workspace isolation. Lock/unlock needs a dedicated ability; Editors must not alter locked formula content through alternate endpoints, version restore, imports, or nested changes. Existing locked-formula semantics and production snapshots must be characterised before changes.

Audit stale open pages and concurrent writes. Revocation/demotion must take effect on subsequent requests, and a stale form must neither change destination workspace nor silently overwrite another person's work. Use existing locking/version mechanisms where sufficient; add focused conflict protection where a concrete gap is demonstrated. No live collaborative editor is required.

### 3. Add team invitations and member management

Build the user-facing member list, pending invitations, acceptance, role changes and removal. Keep platform beta provisioning separate from joining an existing workspace. Read the live `workspace_invitations` schema before designing changes: an old migration alone does not establish that its storage/security model is ready to reuse.

Add an explicit workspace context in the user interface and safe selection for users who legitimately belong to more than one workspace. Do not expose arbitrary workspace creation. Existing single-workspace users should retain a simple journey.

### 4. Make beta access explicit

Add a server-side commercial-access policy for invite-only beta and closed checkout. Reconcile beta provisioning, account copy, existing workspace grants, shared usage counters and Production Bench controls. Separate operational Editor authority from authority to activate/cancel commercial access. Existing beta workspaces keep access; any backfill must be scoped, idempotent and reviewed rather than run for every user indiscriminately.

### 5. Validate and pilot

Use ordinary authenticated users for Owner/Admin/Editor/Viewer tests, plus a platform-admin-only nonmember. Test invitation expiry/revocation/replay, wrong identity, existing users, concurrent acceptance, last-owner protection, role changes during open forms, workspace switching, cross-workspace identifiers/media, shared limits, locked formulas and disabled checkout.

Test first-use beta onboarding and existing-owner compatibility. Run affected SQLite tests and PostgreSQL migration verification in a disposable database, then the complete suite. Pilot the actual formulation, purchasing, receipt, production and inventory journeys with separate accounts. Preserve authorship on existing records and audit member/role/lock changes.

## Deferred scope

Aggregate storage quotas are deferred. The two-week online target does not itself activate public registration or billing. The implementation roadmap is `docs/superpowers/plans/2026-09-25-multi-user-workspace-rollout.md`.

Also deferred: remaining database-index investigations; configurable permission builders; production-only cost-hidden roles; public signup; payment collection; paid seat pricing; public community/sharing; live simultaneous document editing. Ownership-transfer and workspace-deletion UIs are separate lifecycle features; the role matrix reserves their authority without requiring them for team onboarding.

## Additional implementation findings

The live local database has no `workspace_invitations` table: the historical table was removed by `2026_07_14_102240_remove_deferred_collaboration_schema.php`. Use a new migration, not an alteration that assumes the old table exists.

Formula costings currently use `(recipe_version_id, user_id)` identity. Shared formula access alone will not guarantee shared costing. Recommended direction is one shared costing per workspace-owned version, retaining actor attribution. This requires product confirmation and an explicit reconciliation path for old rows. The local database currently has no versions with multiple costing rows; production has not been checked. Never discard conflicting old scenarios automatically.

Formula save/publish/restore transactions do not by themselves prevent stale edits. Protect the common recipe aggregate with a revision check and transaction lock, and reject conflicting saves while preserving the user's entered work. Recheck membership and formula lock status at the write boundary. Characterise formula-content locks separately from material price updates before changing their semantics.

Existing tests deliberately encode owner-only formula access and Editor activation/cancellation of Production Bench. Update those contracts intentionally; passing unchanged tests would not prove the new role model.

## Source decisions

- `CONTEXT.md`: Workspace is the private ownership/collaboration boundary; user and workspace member are distinct concepts.
- `docs/superpowers/plans/2026-07-14-predeployment-security.md`: owner-only launch and deferred team capabilities.
- `docs/developer/translation-handoff.md`: do not advertise invitations while formula access remains owner-only.
- `docs/superpowers/plans/2026-09-05-ingredient-editor-ux-implementation.md`, section 12: Owner/Admin formula locking and the owner-private policy conflict.
- `.ai/rules/policies-views.md`: formula-owning workspace authority and separation from platform administration.
- `docs/specs/future-public-community-and-collaboration.md`: public publishing and external collaboration remain separate from private organisation workspaces.
