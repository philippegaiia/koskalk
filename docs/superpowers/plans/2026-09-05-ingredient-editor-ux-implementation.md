# Ingredient Editor UX Implementation Plan

> **Historical implementation record:** Tasks 1–7 were implemented on `codex/ingredient-editor-ux` and merged into `main` on 2026-09-07. The checkboxes below preserve the original implementation instructions; they are not current progress markers. Section 12 remains a separate, unimplemented formula-permission workstream.

**Goal:** Give users clear experiences for customizing platform ingredients, duplicating them, and creating their own, with consistent permissions, readable reference information, and protection against losing edits.

**Architecture:** Keep the existing controller-mounted Livewire editor and Filament form components. Separate reference rendering from editable forms, reuse existing workspace capabilities and dirty-state semantics, and retain authoring services as the persistence boundary. Preserve current chemistry and duplication rules; improve their presentation rather than redesigning the domain.

**Tech Stack:** PHP 8.5, Laravel 13.30.1, Filament 5.7.8, Livewire (verify installed version before implementation), Alpine, Tailwind 4, Vite 8, Pest 4.7.8. No new dependencies or schema changes.

**Source:** [Original ingredient-editor UX audit](2026-09-04-ingredient-editor-ux-audit.md), especially §4. This implementation plan supersedes conflicting recommendations and factual claims in that audit, including its later Viewer-role correction. The supplied reachability diagram is not an authoritative permission specification.

**Status:** Ingredient-editor work implemented and merged into `main` on 2026-09-07. The separate formula lock/unlock workstream in §12 remains pending.

---

## 1. Verified baseline and decisions

### Corrections carried into implementation

1. `WorkspaceMemberRole::Viewer` exists, is assignable, and is exercised by ingredient tests. Keep Viewer coverage; do not remove the role or assume that a missing invitation UI makes it impossible.
2. Platform guidance/material-code cards and the main ingredient Save bar are mutually exclusive. Keep the two local save actions for platform customization and one main save for authored ingredients.
3. Workspace-owned ingredients already have a material-code field, loaded and persisted through `WorkspaceIngredientCodeService`. Preserve it. The platform-only capability method describes the separate card, not the entire material-code feature.
4. Composition visibility is already driven by `ingredient_structure === 'blend'`. On load, `UserIngredientAuthoringService::formData()` derives that state from existing component relations; new ingredients default to single.
5. Saving a non-blend explicitly sends `components => []`, and `IngredientDataEntryService::syncComponents()` deletes the saved constituent relations. Temporary form-state retention does not survive that save.
6. Installed Filament 5.7.8 already validates the active tab at initialization and after Livewire updates. Verify it in the browser; do not add a parallel tab state machine or vendor patch without a reproduced failure.
7. Platform domain data belongs in the application. WordPress ownership of long-form documentation does not justify removing composition, documents, chemistry, or compliance facts.
8. `isReadOnly()` is private. Expose a deliberate capability accessor for the view; do not call the private method from Blade or make origin a proxy for edit permission.
9. Keep server-side authorization failures. The goal is that normal UI controls do not invite unauthorized writes, not that crafted requests can never return 403.
10. The workbench dirty-state registry includes navigation protection, but its event handlers live inside the workbench component. Reuse those semantics through an editor adapter; do not mount the whole workbench or enable autosave.
11. `hasInheritedSoapChemistry()` checks trust and inherited numeric KOH SAP. It does not itself read the fatty-acid baseline; the authoring service validates that baseline separately.
12. Both Filament ingredient resources resolve authorization against the same `Ingredient` model. Introducing standard policy methods can change catalogue list/create/update/delete behavior even without editing an admin resource. Workspace authoring needs dedicated abilities; do not replace platform administration with `isEditableBy()`.
13. `currentIngredient()` returns null for both a new draft and an existing record that has become unavailable. Only a locked, null `ingredientId` means create mode. An unavailable existing record must never fall through to creation.
14. Workspace selection can change while an editor or duplication preview remains open. Capture the intended destination, recheck current membership at submission, and reject stale destination context rather than silently writing elsewhere.
15. Public ingredient access does not authorize private workspace overrides or attached documents. Filter data before it enters Livewire public state as well as before rendering.

### Product choices for this plan

- **Blend → single:** retain constituent draft rows until save, hide Composition immediately, and show a persistent warning in Details. Saving requires explicit acknowledgement that constituents will be removed. Switching back to Blend before saving restores the draft. Keep current persistence behavior after confirmation; no hidden saved constituents or new storage model.
- **Duplication:** keep current private ownership, quota, category and missing-SAP restrictions. Keep existing media-copy behavior, but disclose omissions accurately. Do not relax trust or duplication blockers.
- **Saving:** explicit saves everywhere. Each saved section clears only its own dirty state. Failed saves retain input and navigation protection.
- **Read-only access:** preserve existing route visibility. Render useful read-only information for accessible ingredients, including Viewers and non-members accessing public authored ingredients. Do not add publishing controls merely to reproduce that case.
- **Naming:** use “Private ingredient”, “Workspace”, “Internal material code”, “SAP profile”, and “Fatty-acid profile” from `CONTEXT.md`. Keep administrative catalogue keys such as `USR…` out of user-facing copy.
- **Permissions for new records:** the actual workspace owner and members with Owner/Admin/Editor roles may author in the destination workspace; Viewer may not. The actual owner remains authorized even when no `workspace_members` row exists because `Workspace::roleFor()` and `User::workspaceRoleFor()` resolve `owner_user_id` as Owner. A user without a workspace may retain the existing first-workspace provisioning path. Permission in one workspace is not permission to create in another.

### Existing verification

On 2026-09-05, these files passed together: **55 tests, 423 assertions**:

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientEditorLocalizationTest.php
```

The final review additionally passed **178 tests, 1,008 assertions**: the eight feature files `UserIngredientAuthoringTest`, `IngredientEditorLocalizationTest`, `IngredientsIndexDuplicationTest`, `UserIngredientAuthoringServiceDuplicationTest`, `WorkspaceIngredientCodeTest`, `WorkspaceIngredientGuidanceTest`, `RecipePrivacyTest` and `RecipeVersionPagesTest` (173 tests), plus five selected `Filament/CatalogResourcesTest.php` regressions for catalogue lists, admin creation/name handling, protected field handling, anonymous user-ingredient listing and unused platform-ingredient deletion. These results predate implementation.

This is a baseline, not evidence that the proposed behavior is implemented. Browser navigation, responsive presentation, and the reported coconut-oil screen still require live verification.

## 2. Experience and permission matrix

### Workspace-role contract

| Actor in the relevant workspace | Read private workspace ingredient | Edit private workspace ingredient | Customize platform code/guidance for that workspace | Create or duplicate into that workspace |
| --- | --- | --- | --- | --- |
| Actual workspace owner (`owner_user_id`) | Yes | Yes | Yes | Yes |
| Member with Owner role | Yes | Yes | Yes | Yes |
| Member with Admin role | Yes | Yes | Yes | Yes |
| Member with Editor role | Yes | Yes | Yes | Yes |
| Member with Viewer role | Yes | No | No | No |
| Non-member | Public authored ingredients only | No | Not for that workspace | Only into another active workspace where eligible, or through first-workspace provisioning |

This follows the current role checks in `Ingredient::isEditableBy()`, `WorkspaceIngredientCodeService`, `WorkspaceIngredientGuidanceService`, and `HandlesWorkspaceAuthorization::canEditWorkspaceRecords()`. “Workspace member” alone never grants write access; the member's role does. Owner/Admin/Editor share the ingredient-authoring capability, while Viewer remains read-only.

“Admin” in this matrix means `WorkspaceMemberRole::Admin`, the role assigned inside a workspace. It is separate from `User::$is_admin`, which grants application-administration and Filament-panel access. Application-admin status alone does not grant customer-workspace write authority. Existing platform catalogue administration and the existing anonymous user-ingredient admin overview must remain available under their current rules.

For platform ingredients, the relevant workspace is the user's active workspace returned by `User::company()`. It determines which workspace code/guidance is read or written and where a duplicate is created. A user who owns workspace A but has Viewer access in active workspace B must remain read-only while B is active; ownership of A must not grant writes in B. Opening a fresh editor or preview after switching to A changes the destination and permissions deliberately. An already-open draft stays bound to its original context and refuses a stale-context submission; it must never silently retarget to A.

| Surface / actor | Ingredient data | Workspace guidance and code | Main action |
| --- | --- | --- | --- |
| Platform reference, Owner/Admin/Editor in current workspace | Readable reference | Editable, separate local saves | Customize the relevant section; optionally duplicate |
| Platform reference, Viewer in current workspace | Same readable reference | Read-only values for that workspace | Back to ingredients |
| Editable private ingredient, including eligible duplicate | Editable relevant sections | Included in main form | Save ingredient / Cancel |
| Private ingredient, Viewer in owning workspace | Readable reference | Read-only permitted values | Back to ingredients |
| Public authored ingredient, non-member of owning workspace | Readable permitted reference | Do not disclose owning-workspace private overrides | Back to ingredients |
| Inaccessible private ingredient | No editor | None | Existing not-found response |
| New ingredient, eligible author | Editable relevant fields; no trusted soap chemistry | Included in main form | Add ingredient / Cancel |

Membership is evaluated against the workspace relevant to each operation. “Non-member” is not a global role: someone outside the source workspace may still author in their own workspace. Platform ingredients have no owning workspace, so platform customization and duplication use the active destination workspace rather than a fictitious source membership.

### Page hierarchy

1. Breadcrumb with recoverable full ingredient name.
2. Ingredient name, platform/private provenance, reference/edit/create heading, and a concise permission explanation when needed.
3. Platform reference: existing summary followed by clearly labelled workspace customization sections, then readable technical sections. Editable ingredients: existing Filament editor, with basic fields first and conditional sections.
4. Reference sections: identity/classification, blend composition only when applicable, guidance/media/documents, soap chemistry when permitted, and available compliance data. Avoid repeating identifiers already displayed in the summary.
5. Each editable scope has a visible saved/unsaved/saving/failed status beside its own actions. Main form actions remain in the existing sticky workflow bar.

Use existing design tokens and components. Disclosure is allowed for secondary technical detail; it must not remove the data or hide relevant warnings. Tabs are allowed for reading; disabled editing widgets are the problem, not tabs themselves.

## 3. Files and boundaries

All paths are relative to `/Users/philippe/Herd/koskalk`.

| File | Responsibility |
| --- | --- |
| `app/Livewire/Dashboard/IngredientEditor.php` | Capability/read-only view state, confirmation before constituent removal, form-save lifecycle |
| `resources/views/livewire/dashboard/ingredient-editor.blade.php` | Journey layout, headings, workspace scope, actions, dirty-state integration |
| New `resources/views/livewire/dashboard/partials/ingredient-reference.blade.php` | Readable ingredient data; reuse existing projections and display helpers |
| `resources/views/livewire/dashboard/partials/ingredient-composition-rows.blade.php` | Existing blend interaction; preserve its behavior |
| New `resources/js/ingredient-editor.js` | Editor-only draft baselines, section state, conditional cancellation and navigation guard |
| `resources/js/dirty-state-registry.js` | Reused registry; keep its public contract stable |
| `resources/js/app.js` | Register the editor Alpine component beside existing component registrations |
| `resources/views/livewire/dashboard/partials/duplicate-ingredient-modal.blade.php` | Select/preview/confirm duplication, reason and failure display, focus behavior |
| `app/Http/Controllers/IngredientController.php` | Read-only duplication preview metadata and authorized duplicate endpoint |
| `app/Services/UserIngredientAuthoringService.php` | Shared duplication eligibility, author authorization and existing persistence/chemistry guardrails |
| New `app/Policies/IngredientPolicy.php` | Dedicated createInWorkspace/duplicateIntoWorkspace/editWorkspaceIngredient abilities; preserve existing Filament standard authorization behavior |
| `app/Livewire/Dashboard/IngredientsIndex.php` and `resources/views/livewire/dashboard/ingredients-index.blade.php` | Create/duplicate entry-point capabilities |
| `lang/en/ingredients.php` | Short dotted keys and English source copy; retain DB translation override behavior |
| Existing tests named below; new `tests/Feature/IngredientEditorAccessTest.php` | Behavior and authorization regressions |
| New `tests/Unit/ingredient-editor.test.mjs` | Dependency-free Node tests of editor state and navigation decisions |

No changes planned to models, migrations, admin resources, catalogue taxonomy, formula calculations, public documentation, or the workbench save flow. Admin catalogue regression tests are required because policy discovery can affect those resources indirectly. Formula lock/unlock is a separate workstream described in §12, not part of the ingredient implementation. Rendering documents must use existing authorized media access, not new public file paths.

## 4. Task 1 — Establish the access contract and fix misleading edit controls

**Files:** editor component/template; new access test; ingredient policy; controller; authoring service; index entry points; English strings.

- [ ] Read the current versions of `.ai/rules/{app,dashboard,livewire,views,policies,controllers,http,services,tests,lang}.md`, the policy trait, and sibling tests. Recheck package versions and Boost docs before using framework APIs.
- [ ] Create the access test with `php artisan make:test --pest IngredientEditorAccessTest --no-interaction`. Use `RefreshDatabase` in the file. Create the policy after checking command help, retaining only the dedicated workspace abilities described below. Do not leave generated standard ability stubs: they would change Filament authorization.
- [ ] Add an HTTP/render test for each access-matrix row. Seed a Viewer membership with the factory; seed public/non-member cases directly in test fixtures, not by inventing a publishing UI. Assert the heading and action visibility, then separately assert a crafted save cannot mutate a protected record.
- [ ] Exercise the complete role matrix at the policy/service boundary: actual owner without a membership row, member with Owner role, Admin, Editor, Viewer and non-member. Assert Owner/Admin/Editor can edit, customize, create and duplicate; Viewer can read workspace records but cannot perform any of those writes; a non-member cannot read a private record or its workspace overrides.
- [ ] Add a multi-workspace case: a user owns workspace A and is Viewer in active workspace B. On a platform ingredient, assert B's code/guidance is shown read-only and duplicate/create into B is denied. After activating A, assert A's independent values and authoring controls are used. This proves permissions and customization scope follow the active destination workspace rather than the user's strongest role anywhere.
- [ ] Use this complete regression case as the first failing test (imports belong at the top of the new test file):

```php
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows a workspace ingredient as reference to a viewer', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $viewer = User::factory()->create(['active_workspace_id' => $workspace->id]);
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create([
        'role' => WorkspaceMemberRole::Viewer,
    ]);
    $ingredient = Ingredient::factory()->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($viewer)->get(route('ingredients.edit', $ingredient))
        ->assertSuccessful()
        ->assertSeeText(__('ingredients.editor.reference.heading'))
        ->assertDontSeeHtml('data-ingredient-save-bar');
});
```

- [ ] Implement a public `canEditIngredientData(): bool` accessor. Resolve the current user; guests are denied. Branch on locked `ingredientId`, not on the nullable result of `currentIngredient()`: null ID uses the dedicated workspace-create capability; non-null ID requires a freshly resolved, accessible ingredient and the dedicated workspace-edit capability. Keep `canEditWorkspaceGuidance()` and `canEditWorkspaceMaterialCode()` independent. Use the accessor for the form, Save action, heading and intro.
- [ ] Define only these dedicated methods on `IngredientPolicy`: `createInWorkspace(User $user, ?Workspace $workspace): bool`, `duplicateIntoWorkspace(User $user, Ingredient $ingredient, ?Workspace $workspace): bool`, and `editWorkspaceIngredient(User $user, Ingredient $ingredient): bool`. Create uses `canEditWorkspaceRecords()` for the validated destination; null permits only the verified no-workspace provisioning case. Duplicate requires that create capability and an active platform source. Edit requires a non-platform ingredient and `isEditableBy($user)`. Domain category/SAP/quota refusals keep their explanatory validation messages. Do not introduce standard `viewAny`, `view`, `create`, `update`, `delete` or bulk-action policy methods in this change; preserve the installed Filament fallback behavior already verified for the existing resources. Do not add an `is_admin` policy `before()` bypass.
- [ ] Authorize with `Gate::forUser($user)->authorize('createInWorkspace', [Ingredient::class, $destinationWorkspace])`, `authorize('duplicateIntoWorkspace', [$source, $destinationWorkspace])`, or `authorize('editWorkspaceIngredient', $ingredient)` at the respective service write boundaries. UI capability checks use the same abilities. Resolve and validate destination context before authorization; never trust an arbitrary client workspace ID. Authorize before provisioning or any writes.
- [ ] Bind editor destinations with a locked workspace ID captured on mount, and duplication destinations with a server-validated context captured when preview opens. Platform customization and new/duplicate creation must compare that context with a freshly resolved current workspace before submission; on mismatch show “Your workspace changed. Reload this page to review the destination before saving.” Preserve the draft and write nothing. Existing workspace-owned ingredient edits remain bound to the ingredient's owning workspace and recheck membership there. Include the same validation for local guidance/code saves and reset actions. A no-workspace draft may provision only if the user still has no workspace; otherwise require reload. Carry the validated destination through persistence and quota handling rather than letting the service resolve a new destination later.
- [ ] Cover an open editor/preview while another request changes active workspace A to B, including a user eligible in both. Assert no records or overrides change in either workspace and the draft/selection remains available for review. Cover membership removal and role downgrade after mount, plus attempts to tamper with the bound destination.
- [ ] Make save mode explicit before starting a transaction. For non-null locked `ingredientId`, reload and authorize the existing target; deletion, platform deactivation, or lost access returns the appropriate unavailable/forbidden result with zero writes. Never call create when that lookup fails. Add regressions for each transition after mount, asserting no replacement ingredient, guidance, code or media usage is created.
- [ ] Preserve app-admin catalogue behavior with `tests/Feature/Filament/CatalogResourcesTest.php`: lists, platform creation/editing, protected fields, anonymous user-ingredient overview and permitted deletion must still pass after policy discovery. Add a separate app-admin-without-workspace-membership case proving that the user-side create/customize/edit/duplicate abilities do not inherit an application-admin bypass.
- [ ] Characterize and cover member create/duplicate requests: current `create()`/`duplicate()` do not themselves check workspace roles. Assert Admin and Editor succeed in their active workspace; Viewer is rejected without side effects, including no new ingredient, guidance, media usage or material code. Preserve legitimate first-workspace provisioning and actual-owner-without-membership-row behavior.
- [ ] Run `php artisan test --compact tests/Feature/IngredientEditorAccessTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientsIndexDuplicationTest.php tests/Feature/Filament/CatalogResourcesTest.php` after the implementation. Expected: reference headers/actions agree with capabilities, valid writes pass, rejected writes leave no data changes.

**Done when:** actual owners and Owner/Admin/Editor members can complete the intended writes in the relevant workspace; Viewers and public non-members can read only permitted information, never receive an enabled ingredient Save action, and cannot bypass restrictions by calling a write method. The intended workspace remains stable for the lifetime of an open draft; stale context and unavailable existing records cannot produce writes or accidental new records. Existing application-admin catalogue workflows still pass.

## 5. Task 2 — Replace disabled reference forms without losing useful data

**Files:** editor component/template; new reference partial; localization/access tests; `lang/en/ingredients.php`.

- [ ] Extend `IngredientEditorLocalizationTest.php` with a platform fixture containing identifiers, blend constituents, SAP/fatty-acid data, allergen/substance data, IFRA limits, and available documents. Assert representative values remain visible in reference mode. Test missing-data and single-ingredient fixtures separately.
- [ ] Inventory the actual data rendered by every current form section before removing its reference rendering. Include classification/functions, aliases, identity, constituent percentages/source notes, approved guidance, document links, soap values, allergen concentrations/source notes, substances and IFRA context/limits. Use `formData()`, existing loaded relations and media-usage services; do not duplicate identity resolution or infer “none present” from missing records.
- [ ] Build an authorized reference projection before populating Livewire public properties or form state. A public non-member must receive no owning-workspace code, private guidance, private source metadata or unauthorized media descriptors in the serialized component payload. Blade conditionals alone are insufficient. Inspect mount, hydration and refresh-after-save paths. Test both rendered output and exposed component state with distinct private sentinel values; retain authorized technical reference facts.
- [ ] Resolve each document through its existing media authorization independently of ingredient visibility. Omit unauthorized document descriptors/links rather than generating public URLs. Test an authorized workspace member and a public non-member against the same attachment.
- [ ] Extract reference presentation to the new partial. In the editor use this layout boundary:

```blade
@if ($this->canEditIngredientData())
    <form wire:submit="save" class="space-y-4 pb-24">
        {{ $this->form }}
        {{-- Keep the existing workflow action bar here. --}}
    </form>
@else
    @include('livewire.dashboard.partials.ingredient-reference')
@endif
```

- [ ] Render plain values using `<dl>`, lists or tables with semantic headings. Keep Composition conditional on blend structure and chemistry subject to the existing trust/access rules. Platform reference chemistry remains readable; do not accidentally apply an authored-duplicate-only condition to platform data.
- [ ] Keep the workspace customization cards only on the platform branch. Use each card's capability independently. Show code as text for an unauthorized visitor, with no disabled input. Preserve the workspace-owned material-code field and guidance in the editable main form.
- [ ] Add `editor.workspace_scope` = “Changes are shared with everyone in :workspace.” Add `editor.read_only_description` = “You can view this ingredient, but you do not have permission to edit it.” Resolve the named workspace from the same context used by each save method.
- [ ] Keep technical facts separate from private overrides: a non-member viewing a public ingredient must not see the owning workspace's material code or custom guidance merely because reference rendering now reads relations directly.
- [ ] Fix the summary heading from h4 to h2; keep visible ingredient name available outside the truncated breadcrumb. Retain a breadcrumb title as a supplementary fallback, not the only way to recover the name on touch.
- [ ] Use explicit guidance labels: “Customize guidance”, “Edit workspace guidance”, “Use platform guidance”, “Use workspace guidance”, “Save guidance”, “Cancel”. Keep an explicit “Save material code” action. Do not collapse the two local saves into one accidental global save.
- [ ] Run `php artisan test --compact tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/IngredientEditorAccessTest.php tests/Feature/WorkspaceIngredientCodeTest.php tests/Feature/WorkspaceIngredientGuidanceTest.php`.

**Done when:** reference pages contain useful readable data and no disabled technical editor, retain authorized document access, and allow only permitted workspace customizations. A single ingredient has no Composition section.

## 6. Task 3 — Make blend-to-single saves explicit

**Files:** editor component/template, existing composition partial, authoring tests, English strings. Preserve service deletion semantics.

- [ ] Add a regression fixture for a saved blend with two component relations. Change the form structure to single and attempt save without acknowledgement. Assert the relations and other edited values remain unchanged and a specific confirmation requirement is returned.
- [ ] Add a second case that acknowledges removal and saves successfully: assert zero component relations, cleared composition source notes and single structure after reload. Add a third case that switches back to blend before saving and retains both constituent draft rows/percentages.
- [ ] Add `public bool $confirmCompositionRemoval = false` to the editor. Compute the need for acknowledgement from the current single state plus persisted or draft constituents; use locked ingredient identity and server-loaded relations for persisted data, not a client-supplied original-count flag.
- [ ] Show this warning in Details only when removal is pending: “Saving as a single ingredient will remove its blend composition. Switch back to Blend to keep it.” Provide “Remove blend composition when saving” as an explicit acknowledgement, outside the hidden Composition section. Reset acknowledgement on a structure change; clear it after a successful save.
- [ ] In `save()`, after authorization and before the transaction, add an error and return without writing when removal needs acknowledgement but it is false. Reuse the same predicate for the warning. Do not clear constituent rows merely when the type selector changes.
- [ ] Use a Filament Checkbox or an equivalent existing form control for acknowledgement; keep it UI-only and outside persisted domain payload. Ensure its interaction participates in dirty-state handling without being mistaken for a saved ingredient property.
- [ ] Keep `Tab::make(...)->visible(...)` intact. In the installed browser build verify: single coconut oil, existing blend, changing blend to single, switching back, and reloading a URL with the Composition tab key. Rely on Filament fallback when it works. If a failure is reproduced, record the exact state and fix only that failure; no speculative tab guard is part of the baseline change.
- [ ] Run `php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientEditorLocalizationTest.php`.

**Done when:** Composition is absent for singles, fatty-acid profile remains separate, and saving never silently removes constituents. Existing service behavior after explicit confirmation is preserved.

## 7. Task 4 — Track independent drafts and protect navigation

**Files:** new editor JS module and Node test, `resources/js/app.js`, editor template/component, existing registry. Read workbench handlers as a reference without changing their behavior.

- [ ] Implement and test three scope identifiers: `ingredient`, `guidance`, `material-code`. Capture baselines after initial form hydration and after acknowledged successful saves; include nested composition, rich-editor guidance and media selections. Read-only scopes never register as dirty.
- [ ] Use the existing `createDirtyStateRegistry()` with the states `dirty`, `saving`, `failed`, `saved`. Add an editor adapter with `init()` and `destroy()` for Alpine lifecycle, stable baseline comparison per scope, scoped save-result handling, and a synchronous check before navigation.
- [ ] Make the initial pure state decision executable in a dependency-free Node test:

```javascript
import test from 'node:test';
import assert from 'node:assert/strict';
import { createDirtyStateRegistry } from '../../resources/js/dirty-state-registry.js';

test('saving guidance does not clear an unsaved material code', () => {
    const registry = createDirtyStateRegistry();
    registry.set('guidance', 'dirty');
    registry.set('material-code', 'dirty');
    registry.set('guidance', 'saved');
    assert.equal(registry.blocksNavigation(), true);
    registry.set('material-code', 'saved');
    assert.equal(registry.blocksNavigation(), false);
});
```

- [ ] Extend that file to exercise the adapter, not just the registry: edit→revert becomes clean; validation/network failure remains protected; input typed while saving remains dirty; read-only initialization is clean; listeners are removed on destroy and not duplicated on re-entry. Keep the test importable without a browser by injecting event targets/confirmation callbacks into the adapter.
- [ ] Bind the current form state using the installed Livewire client API and existing component conventions. A DOM input/change fallback must catch buffered text and rich-editor updates before blur; do not rely solely on a completed server request to mark a draft dirty.
- [ ] Install `beforeunload` and cancelable `livewire:navigate` handlers using the workbench's established pattern. Prompt only if the registry blocks navigation. Show status using `role="status" aria-live="polite"`; do not announce every keystroke.
- [ ] Emit scoped success only after persistence succeeds; validation failure must not emit success. Do not clear unrelated scopes. When a save redirects, acknowledge the submitted baseline before following the redirect; if newer edits exist, keep protection. Prevent additional edits while an initial-create save is committing if necessary to avoid losing them during the required redirect.
- [ ] Wire local Cancel actions to confirm only if their own scope differs from baseline. Discard only that scope on confirmation. Main-form Cancel uses the navigation guard once; do not stack two prompts. “Use platform guidance” retains its existing meaningful confirmation and must also account for an unsaved guidance draft.
- [ ] Register `window.ingredientEditor` from `resources/js/app.js`, following its existing component-registration style. Run `node --test tests/Unit/ingredient-editor.test.mjs` and `npm run build`.
- [ ] Verify in the browser: breadcrumb/sidebar/back/reload navigation, editing without blur, guidance and code dirty together, failed save, successful save, canceled discard, and leave/re-enter with no duplicate prompts.

**Done when:** every editable scope shows honest status, independent saves cannot erase another scope's warning, failures retain drafts, and ordinary navigation cannot silently discard changes.

## 8. Task 5 — Turn duplication into select, preview, confirm

**Files:** duplication partial, controller, authoring service, policy/entry points, `IngredientsIndexDuplicationTest.php`, `UserIngredientAuthoringServiceDuplicationTest.php`, English strings.

- [ ] Preserve existing duplication-service tests and add observable endpoint tests for insufficient role, non-platform source, inactive source, platform-only alkali, lipid missing SAP, quota exhaustion and valid duplication. Assert failed requests create no partial copy. Treat inactive source as unavailable, matching search behavior.
- [ ] Extract the source eligibility checks from `duplicate()` into a shared service method returning an optional translated blocking reason. Search/preview and execution call the same method. Keep permission and quota checks at execution time; preview is advisory and must not reserve or create anything.
- [ ] Extend existing platform-search results with bounded duplication metadata, such as `duplication.available`, `duplication.reason`, and `duplication.inherits_soap_chemistry`. Eager-load SAP only as required. Keep existing identifier/alias search and private-row isolation intact. Do not expose raw source-data JSON or administrative catalogue keys.
- [ ] Change result selection from immediate `duplicate(item.id)` to selection of a candidate and a preview. Preview copy: “Create a private copy in :workspace. You can edit its details. The platform ingredient stays unchanged.” Show source-specific restrictions and data omissions, then a separate “Create private copy” button.
- [ ] Disclose the verified media behavior. Legacy image fields are reset and document/media-usage relations are not copied by the currently inspected duplication routine. Add a fixture with actual media-usage records and assert the resulting behavior before choosing the final copy; make the disclosure match that test. Preserve current behavior rather than implementing a new media-copy workflow. Keep `info_markdown` as an internal mapping detail, not literal UI wording; approved guidance is copied into a workspace override.
- [ ] Explain chemistry only when relevant: trusted source with inherited SAP baseline → editable chemistry within displayed limits; untrusted lipid → no inherited soap-chemistry capability; source missing required SAP → explicit blocker. Do not show soap warnings for unrelated ingredients.
- [ ] Include the bound destination in preview and confirmation. Revalidate it, the source availability, the actor's current role and quota at confirmation; stale preview/destination must fail without creating a copy and offer review/reload.
- [ ] Add an in-flight state to prevent duplicate submissions. Handle non-2xx responses, validation messages, authentication expiry, network failure and non-JSON failure without closing the modal or losing selection. Preserve the existing successful redirect. Abort/ignore stale search responses so an older query cannot overwrite newer results.
- [ ] Add an accessible dialog name, labelled search field, initial focus, focus containment, Escape/Cancel behavior, return focus to trigger, and inline error announcements. Use the installed modal/focus primitives; do not introduce a dependency.
- [ ] Run `php artisan test --compact tests/Feature/IngredientsIndexDuplicationTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientEditorAccessTest.php` and manually exercise a refused and a successful duplication in the browser.

**Done when:** clicking a search result does not immediately create a record; the user understands the destination, editable scope and applicable guardrails before confirming; every failure is visible and retryable.

## 9. Task 6 — Explain creation and inherited chemistry limits in context

**Files:** editor component/template, authoring service display-range helpers (reuse), English strings, localization and authoring tests.

- [ ] Keep name and INCI first, then classification and relevant fields. Preserve the single-ingredient default. Do not expose the hidden trust flag or add a disabled Composition tab.
- [ ] For a manually created lipid, show before save: “This ingredient cannot be used for saponification calculations. To customize soap chemistry, duplicate a platform ingredient with trusted soap chemistry.” Link to the duplication flow using a named route, without implying the current record can be converted to trusted by a toggle.
- [ ] Preserve the alkali exclusion and quota rules. Display actionable quota errors at the attempted creation, retaining draft fields; do not invent an upgrade destination without checking the existing entitlement UI.
- [ ] On an eligible duplicate, explain that chemistry is inherited and edits are limited. Use `trustedKohSapRange()` and `trustedFattyAcidRange()` for displayed bounds; do not reimplement their arithmetic in Blade or JS. KOH bounds are ±3%; NaOH is derived. Fatty-acid total is 80–100%; each original below 5% allows 0–5%, otherwise ±20% bounded to 0–100%.
- [ ] Preserve locale-aware numeric parsing and the precision round-trip behavior. Keep the existing range/helper messages where they already work; add missing context only. Surface errors beside the affected field/group without partially saving another section.
- [ ] Extend service tests only for uncovered boundaries: accepted lower/upper KOH values and rejected outside values; fatty total and individual range failure; tampered trust on manual creation; unchanged source ingredient after editing a copy. Retain existing comma-decimal and precision tests.
- [ ] Run `php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientEditorLocalizationTest.php`.

**Done when:** creation and duplication explain their different chemistry capabilities, and guarded edits show the exact allowed range using existing domain calculations.

## 10. Task 7 — Integrate, verify and hand off

- [ ] Keep each task as a reviewable change. Before committing, stage only that task's files; do not include unrelated working-tree changes, generated vendor assets or the pre-existing dependency update.
- [ ] Run the focused suites from Tasks 1–6 after their changes. Run `vendor/bin/pint --dirty --format agent` after PHP edits. Do not run Filacheck unless implementation actually touches `app/Filament`; no such edits are planned.
- [ ] Run `npm run build` after frontend integration. Browser checks use Herd; resolve the project URL with Boost `get-absolute-url`. Do not launch another application server or install browser-test packages.
- [ ] Validate desktop and narrow/mobile widths for all three journeys plus read-only reference. Check keyboard traversal, focus restoration, headings, dialog behavior, readable values, sticky-bar overlap, long localized strings and error placement. Inspect recent browser errors using Boost `browser-logs`.
- [ ] Specifically open a real single coconut-oil record. Confirm its hydrated structure and visible sections. If it still shows Composition, record the exact ingredient ID, component state and asset version; investigate that concrete mismatch rather than changing classification generally.
- [ ] Recheck platform/customized guidance, private material-code persistence and tenant boundaries after the reference rewrite. Verify document access with the intended user rather than accepting a visible but unauthorized/broken link.
- [ ] Ensure no proposed acceptance criterion demands removal of server-side 403s; crafted unauthorized requests remain denied. Verify successful create redirects do not show a false unsaved-changes prompt.
- [ ] Run `graphify update .` after application code modifications, as required by the repository. Inspect generated changes separately; this plan-only turn does not require a graph refresh.
- [ ] Request the full-suite run with `php artisan test --compact` after the affected tests pass. Report test results and any browser behavior not verified, without describing the whole application as validated.

### Coverage ledger

| Requirement | Task |
| --- | --- |
| Three user journeys and destination-aware capabilities | 1, 2, 5, 6 |
| Viewer and non-member reference access; server protection | 1, 2 |
| Preserve application-admin catalogue authorization | 1, 7 |
| Bind destination; reject stale workspace and revoked access | 1, 5 |
| Unavailable existing record never becomes a new ingredient | 1 |
| Filter private data before Livewire serialization; authorize documents separately | 2 |
| Code and guidance editable in their proper scopes | 2, 4 |
| Preserve technical reference data and authorized documents | 2 |
| Composition absent for single ingredients | 3, 7 |
| Explicit constituent removal instead of silent save loss | 3 |
| Native Filament tab fallback, browser verification | 3, 7 |
| Unsaved state, independent saves, conditional Cancel | 4 |
| Duplication preview, blockers, media disclosure, error handling | 5 |
| Exact inherited SAP and fatty-acid limits | 6 |
| Creation restrictions, quota, localization | 6 |
| Accessibility, responsive layout, runtime verification | 7 |

## 11. Deliberately excluded changes

- Removing Viewer, building invitations/member management, or asserting production role counts from a local database snapshot.
- Allowing new trusted ingredients, loosening SAP/fatty-acid bounds, duplicating missing-SAP lipids, or enabling workspace-authored alkalis.
- Changing duplication to copy media automatically, adding a publication workflow, or storing hidden single-ingredient constituents.
- Introducing autosave, a new reference route, a new component framework, or a speculative Filament tab fix.
- Replacing the existing taxonomy-based lipid warning with a new classification abstraction solely because the taxonomy might change later.
- Showing catalogue keys or internal source-data paths in the user journey.

Any of those changes needs its own concrete product reason and review; none is necessary to deliver this plan.


## 12. Separate formula lock/unlock workstream

The agreed product rule is: **only the actual owner of the formula-owning workspace or a member with its Admin role may lock or unlock that formula**. Editor and Viewer may not. `User::$is_admin` alone does not grant this user-side permission. Resolve the workspace from the target formula, never from the actor's active workspace. This is a distinct formula-access change, excluded from Tasks 1–7.

The current `RecipePolicy::view/update/delete` and `RecipePrivacyTest.php` intentionally keep formulas owner-private, including denying workspace Admin discovery. `RecipeController::lock()` and `unlock()` first call `accessibleRecipe()` (which checks `view`), then authorize `update`, and redirect to the editor. Adding only a lock ability therefore cannot deliver the agreed Admin journey.

Before implementing that separate workstream:

- [ ] Define the smallest Admin viewing/entry surface needed to identify a formula and lock/unlock it. Update the affected privacy expectations explicitly; keep unrelated editing, deletion and discovery restrictions intact. Do not broaden general `update` to enable a lock action.
- [ ] Add dedicated lock/unlock authorization against the target formula's owning workspace, with matching UI controls and endpoint checks. Ensure the entry lookup allows the intended Admin action and the success redirect lands on a page that Admin can access.
- [ ] Cover actual owner, workspace Admin, Editor, Viewer, non-member and app-admin-only actors, including a different active workspace, revoked membership, direct endpoint calls and safe redirects. Retain formula calculation and ordinary edit protections.
- [ ] Correct the durable rule through `record-rule`: replace “active workspace” with “formula-owning workspace” and replace the single comma-separated path string in `.ai/rules/policies-views.md` with valid matching globs for `app/Http/Controllers/RecipeController.php`, `app/Policies/RecipePolicy.php` and `resources/views/recipes/**`. Reconcile the existing entry rather than recording contradictory rules. The present rule file has not been changed by this plan revision.

This section records the agreed boundary and prerequisites; it does not authorize or bundle formula implementation into the ingredient UX changes.
