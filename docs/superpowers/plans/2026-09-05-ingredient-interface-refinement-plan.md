# Ingredient interface refinement plan

**Status:** Plan only. No application changes authorized by this document.

**Goal:** Make every user-facing ingredient field, action, and state understandable while retaining the detail needed for professional formulation.

**Baseline:** `codex/ingredient-editor-ux`, commit `2bf21562`, reviewed on 5 September 2026. Implementation work belongs in the existing ingredient-editor worktree, not an unrelated checkout.

**Re-evaluation:** 6 September 2026, same code baseline. One source-based review corrected the fatty-acid empty-profile exception, the aromatic setting's wider effects, destination fallbacks and missing workflow checks. Application code remains untouched. The corrected plan is ready for implementation; browser acceptance remains part of implementation, not a completed result of this review.

**Original reference:** [2026-09-04 ingredient editor UX audit](2026-09-04-ingredient-editor-ux-audit.md). This is a follow-up to its implemented three-journey design. Historical findings in that document are not automatically current defects. In particular, platform references now use a reference view, composition is already conditional, and save surfaces have separate scopes.

**Approach:** Keep the current Livewire editor, Filament schemas, reference partial, duplication dialog, and shared controls. Improve hierarchy, terminology, contextual explanations, and feedback. Change backend behavior only where the interface currently reports a different contract from the existing validation; do not widen chemistry permissions or tolerances.

**Review lenses:** `better-writing`, `better-layout`, `better-accessibility`, and the project's Laravel conventions. This is a UX specification with sequenced implementation work, not a prewritten code patch.

## 1. Scope and verification

Included: platform reference and workspace overrides; manual creation; workspace editing; read-only reference; duplicating platform and workspace ingredients; all editor tabs; composition quick-create; the media picker as embedded in these forms; save, validation, empty, loading, and recovery states.

Excluded: app-admin ingredient resource redesign, ingredient deletion/replacement, pricing, formula locking, new scientific capabilities, schema changes, another query-count optimization campaign, and a global design-system rewrite.

| Surface | Evidence reviewed | Limit of this review |
| --- | --- | --- |
| Create Overview | Current schema, English copy, live browser accessibility text, desktop screenshot | Expanded Debugbar obscured the lower screenshot; full-page visual clearance was not verified |
| Platform reference | Reference construction and complete Blade partial; available rendered reference evidence | Not every possible related-record combination was rendered |
| Workspace edit, chemistry, composition and regulatory tabs | Field definitions, custom markup, relevant validation and state handlers | End-to-end editing of each tab was not exercised in this planning pass |
| Duplication | Dialog markup, focus/state code, copy, preview builder and duplication/trust rules | No ingredient was duplicated during this audit |
| Guidance, code and save feedback | Blade conditions, scope state code and copy | Save failures and concurrent edits were not injected in the browser |
| Media picker | Field configuration, picker markup and its exposed interaction states | No upload, conversion, or document download was performed |
| Accessibility and responsive layout | Source inspection for labels, error relationships, semantics, focus handlers and wrapping | Keyboard-only, screen-reader, measured contrast, mobile and zoom checks remain **Not verified** |

The source review supports the specific findings below. It does not establish that every rendered state is accessible or visually correct.

## 2. Preserve the product model

1. **Platform ingredient:** technical reference remains platform-maintained. Authorized workspace members can change their material code and guidance. Those two forms retain independent save states.
2. **Duplicate ingredient:** users can copy an eligible platform ingredient or their own accessible workspace ingredient. Show the source, destination workspace, copying exclusions and inherited chemistry restrictions before creation.
3. **Create/edit workspace ingredient:** users edit identity, classification, composition, guidance and supporting data within the current rules. Manual creation does not grant trusted soap chemistry.
4. **Read-only access:** omit mutation controls when the current policy denies them. Keep authorized reference data readable. Never reveal private workspace data to explain why it is missing.

Workspace Owner, Admin and Editor are the relevant writing roles, subject to current policies and destination workspace. App-administrator status is a separate concern and must not become an implicit user-side bypass. Preserve read-only role tests, including Viewer where the code supports it; do not base UI behavior on the old assertion that a Viewer cannot exist.

Preserve existing personal-owner and first-workspace paths too. `IngredientPolicy::duplicateIntoWorkspace()` accepts an eligible user-owned source as well as a destination-workspace source. The authoring service handles a missing workspace through its existing locked workspace-creation path. Display a real workspace name only when resolved; otherwise use **Your ingredient library**. Do not invent a named destination or imply that these paths bypass permissions or quotas.

Keep **Duplicate ingredient** next to **Add ingredient**, with the matching button shape/style already requested. Keep composition hidden for single ingredients such as coconut oil. Formula lock/unlock permissions are outside this plan.

## 3. Prioritized findings

Severity: **HIGH** means a misleading outcome, hidden recovery, or incorrect displayed editing contract. **MEDIUM** means meaningful ambiguity, hierarchy or accessibility friction. **LOW** means polish. These are UX priorities, not security severity ratings.

Source locations below are relative to the worktree root. Line numbers refer to the baseline commit.

### Truthful feedback and consequences

| Severity | Location | Before | After | Why |
| --- | --- | --- | --- | --- |
| HIGH | `resources/js/ingredient-editor.js:313`, `:509`; `resources/views/livewire/dashboard/ingredient-editor.blade.php:219` | All scopes start as saved; untouched creation displays **All changes saved** | Creation initially says **Not created yet**. After input: **Unsaved changes**. Saving and failure retain their existing meanings | A clean initial form is not a persisted ingredient. Do not mark the form dirty merely to change its label |
| HIGH | `lang/en/ingredients.php:943`; `app/Services/UserIngredientAuthoringService.php:990` | **Recommended total: 80–100%** | **Required total: 80–100%** when either the inherited or entered profile is nonempty. When both are empty: **No fatty-acid profile recorded. If you add one, its total must be 80–100%.** | The range is enforced when a profile exists; validation explicitly permits both profiles to be empty. Do not turn that exception into a new requirement |
| HIGH | `resources/views/livewire/dashboard/partials/ingredient-composition-rows.blade.php:10`, `:21`; `app/Services/IngredientDataEntryService.php:658` | Total rounds to one decimal; validity is communicated by color. 99.96 can look like 100.0 while failing validation. UI and server also use different strictness at the tolerance boundary | Display sufficient precision and explicit **Complete**, **Add :remaining%**, or **Remove :excess%**. Derive status using the same tolerance as validation | The visible result must agree with whether saving is allowed. Do not silently rebalance percentages |
| HIGH | `app/Livewire/Dashboard/IngredientEditor.php:715`; composition quick-create partial; `lang/en/ingredients.php:906` | **Add ingredient**, with no explanation that quick-create persists a separate ingredient immediately | **Create and add ingredient**; helper: **Creates an ingredient in :workspace immediately, then adds it to this blend. It stays in the library if you cancel this blend.** | The parent Save/Cancel bar does not describe this separate write. Preserve current persistence semantics |
| HIGH | `resources/views/livewire/dashboard/partials/ingredient-classification-prompt.blade.php:45`; `resources/js/classification-prompt.js:13` | Clipboard failure text is inside a closed preview disclosure; the copy handler does not open it | Show **Could not copy. Open the prompt and copy the text manually.** beside Copy, with an announced error and an accessible route to the text | Recovery must be visible even when preview is collapsed |
| HIGH | `app/Services/UserIngredientAuthoringService.php:905`, `:1024`, `:1043`, `:1093` | Duplication preview calculates ranges around the source's current SAP/fatty-acid values. Copying a workspace ingredient retains the original trusted baseline | Preview the same inherited ranges the new copy will actually enforce. Distinguish **Current value** from **Inherited reference** | A previously edited copy can advertise limits different from the editor's real limits. This is a confirmed source-level mismatch, not a request to relax the guardrails |
| MEDIUM | `resources/views/livewire/dashboard/ingredient-editor.blade.php:86` | A link called **Duplicate ingredient** navigates to the ingredient list | For the existing destination, label it **Find an ingredient to duplicate** | Accurately describe navigation. Opening a duplication dialog directly would be a separate interaction change |

### Hierarchy and terminology

| Severity | Location | Before | After | Why |
| --- | --- | --- | --- | --- |
| MEDIUM | `lang/en/ingredients.php:603`; `app/Livewire/Dashboard/IngredientEditor.php:828` | Introduction emphasizes name and INCI, and makes classification sound optional; Category is required and INCI is optional | **Enter a name and choose a category. Add an INCI name and supporting details when available.** | Introductions should match actual required fields |
| MEDIUM | `app/Livewire/Dashboard/IngredientEditor.php` Overview schema | Prominent AI research card precedes structure/category | Required basics first; optional **Help classify this ingredient** disclosure afterward | Supporting work should not dominate the primary task |
| MEDIUM | `resources/views/livewire/dashboard/ingredient-editor.blade.php:93`, `:97`, `:163` | Complete reference renders before editable guidance and code | Header and identity summary, then workspace customizations, then detailed technical reference | Platform customization is a primary journey and should not require scrolling through all chemistry and regulatory data |
| MEDIUM | `lang/en/ingredients.php:615`, `:631`, `:643` | Generic page title; **Approved guidance**, **Authorized documents** | Ingredient name as primary heading; provenance as secondary text. **Ingredient guidance**, **Documents** | Permission to view and editorial approval should not be inferred from a section heading |
| MEDIUM | `lang/en/ingredients.php:679`, `:884` | **Documents** tab contains source notes, images and formulation guidance | **Guidance & files**, containing distinct **Ingredient guidance**, **Source notes**, and **Images & documents** groups | The label should predict the content; source evidence and practical guidance have different purposes |
| MEDIUM | `lang/en/ingredients.php:703`, `:625`; `app/Models/Ingredient.php:365`; `app/Services/IngredientDataEntryService.php:225`, `:481` | **Requires aromatic compliance** / **Not required** gives little explanation of its operational effects | Editing: **Treat as an aromatic ingredient**. Helper: **Uses the fragrance phase in the formula workbench and enables allergen and IFRA records.** Reference: **Aromatic handling**, **Enabled / Not enabled** | This is a domain setting, not merely a field-visibility preference or a legal conclusion. The earlier proposed **Include allergen and IFRA data** label is withdrawn |
| MEDIUM | `lang/en/ingredients.php:483` | **workspace writers**, **Legacy ingredient images**, **media usages**, **limited editable chemistry** | **Workspace owners, admins and editors**; **Images and documents are not copied**; **Soap values can be edited within inherited limits** | Internal vocabulary makes a straightforward copying decision harder |
| LOW | `app/Forms/Components/IngredientIdentityFields.php:54`; reference alias heading | Collapsed identifiers use a raw scheme value; **Aliases** versus **Alternative names** | Localized identifier scheme label; **Alternative names** throughout | Keep advanced identity information readable and consistent |

### Accessible interaction and technical clarity

| Severity | Location | Before | After | Why |
| --- | --- | --- | --- | --- |
| MEDIUM | `resources/views/livewire/dashboard/ingredient-editor.blade.php:172`; composition quick-create and percentage inputs | Some custom errors have `role=alert` and `aria-invalid`, but no ID association from the field | Stable error IDs included in `aria-describedby` alongside helper text | An announcement does not replace reading the error when the user returns to the field |
| MEDIUM | `resources/views/livewire/dashboard/partials/ingredient-reference.blade.php` soap/IFRA sections | SAP and peroxide values lack explicit units in the reference view; peroxide input does show its unit | Use the same unambiguous unit/notation in read-only reference, editable fields and duplication preview | Readers should not have to infer the scale from a number |
| MEDIUM | `app/Livewire/Dashboard/IngredientEditor.php:1055` onward | Repeated generic concentration labels; optional substance concentration has no explicit unknown-value instruction | **Concentration in ingredient (%)** for composition records; **Leave blank if unknown** only where nullable. Keep IFRA use-limit wording distinct | Unknown must not be confused with zero or with a finished-product use limit |
| MEDIUM | `resources/views/components/workflow-action-bar.blade.php:15`; repeated/truncated ingredient and asset names | Non-wrapping action bar and truncated status/name text can lose meaning under narrow widths or long translations | Verify small screens and zoom; allow full critical labels/status and distinguishing names to be read | Source indicates a layout risk; actual overflow is **not yet verified** |

## 4. Target layouts

### Platform and read-only reference

Use the ingredient name as H1, with **Soapkraft ingredient** or the authorized workspace provenance below it. Keep the breadcrumb concise; let the heading wrap. Display a compact identity summary with INCI and category, followed by CAS/EC where present.

For platform references with workspace customization access, put **In :workspace** immediately after identity. Inside it, give **Internal material code** a compact form and **Ingredient guidance** a larger area with its active source badge. Keep their separate Save buttons and save status; do not add a global Save bar to this page. Guidance can be long: collapse the saved preview while its editable version is open, or label both explicitly if comparison is needed.

Follow with the detailed reference sections. Preserve all authorized technical content. An **On this page** list is optional polish: add it only if a populated reference remains hard to navigate after reordering. If added, link only to existing authorized sections. Do not build a second tab system or hide core scientific data behind arbitrary size limits.

For read-only workspace ingredients, keep only data allowed by the existing reference builder. Permission explanations should describe the current action, not disclose another workspace's hidden content.

### Create and edit

Keep the existing tabbed form. Proposed order: **Overview → Composition (blends only) → Guidance & files → Soap chemistry (eligible only) → Regulatory data**. Give tabs stable identifiers so a label change does not break bookmarked query-string state. Confirm installed Filament behavior before choosing an API; do not assume label-generated tab IDs stay stable.

Overview order:

1. **Basics:** Name, Category, Ingredient type, optional INCI, optional Internal material code.
2. **Classification:** Subcategory, workspace functions, read-only verified functions, allergen/IFRA setting and inherited-chemistry summary where applicable.
3. **Identifiers and alternative names:** visible CAS/EC; optional additional identifier and alternative-name repeaters.
4. **Help classify this ingredient:** optional research-prompt disclosure, with explicit external-AI instructions and manual review.

Use one column on narrow screens. On wider screens, use two columns for short related fields, not for competing full-length tasks. Keep helper text adjacent to its field. Use existing spacing, buttons, color tokens and Filament fields; no new visual language is needed.

Show the resolved destination workspace on creation as well as editing, with **Your ingredient library** as the unresolved-destination fallback. Create heading: **Add ingredient**. Edit heading: **Edit :ingredient**. Introductory copy should be one sentence, with no repeated explanation in every section.

## 5. Element-by-element specification

“Keep” means the current design has a useful role; it is not a claim that all responsive or assistive-technology behavior was tested.

### Overview and identity

| Element | Decision and proposed behavior |
| --- | --- |
| Breadcrumb | Keep navigation to Ingredients and current identity. Full name remains available in the heading |
| Page title and intro | Use the task/ingredient title described above; remove final periods from headings; correct required-field instructions |
| Workspace scope | Show destination/owning workspace beside the task. For overrides, say changes affect that workspace. Do not describe shared workspace edits as personal-only |
| Name | Keep required input. Label **Ingredient name** and allow long text without losing the cursor or validation message |
| Category | Keep required taxonomy selector. Place it among basics. Use existing authorable options; do not expose platform-only alkalis |
| Ingredient structure | Label **Ingredient type**; **Single ingredient / Blend**. Helper: **Choose Blend when this material contains other ingredients.** Keep blend-to-single removal confirmation |
| INCI | Label **INCI name (optional)**. Helper: **Use the INCI name supplied for this ingredient, when available.** Do not suggest a chemical identifier is an interchangeable substitute |
| Internal material code | Keep optional, workspace-unique mnemonic, e.g. RM-OLIVE. Remove technical catalog-key explanation from task copy. Never auto-generate the user's code |
| Subcategory | Keep dependent searchable choices. Check switching Category with an existing subcategory; preserve a valid value and provide clear feedback for an incompatible one. This interaction was not reproduced as a current defect |
| Allergen/IFRA setting | Use **Treat as an aromatic ingredient** and explain fragrance-phase handling as well as the two data sections. Turning it off skips allergen/IFRA synchronization rather than deleting their saved records. Do not promise hidden unsaved edits will be saved. Verify off → save → reopen → on, and warn that formula behavior changes without adding a new confirmation flow |
| Inherited soap summary | Keep read-only provenance near classification. Say **Soap data inherited from Soapkraft. Editable values stay within inherited limits.** It must also make sense for a copy of a copy |
| Verified functions | Keep visibly separate, read-only and attributed. Use one consistent spelling for the catalog name |
| Workspace functions | Label **Workspace functions (optional)**. Keep searchable multi-select, existing maximum 10 and distinction from verified functions |
| CAS and EC | Keep visible, optional fields with examples and existing identity synchronizer. Do not change stored formats or reintroduce model columns |
| Additional identifier scheme/value | Keep optional collapsed rows, localized scheme name in row summary, explicit **Add identifier** action and existing maximum 10 |
| Primary identifier toggle | Preserve per-scheme semantics. Explain **Use as the primary identifier for this scheme** rather than implying one primary identifier across all schemes |
| Alternative name, language and kind | Keep name/language/type choices and maximum 5 for workspace use. Preserve **Language-neutral**. Use readable summaries, not raw enum values |
| Research helper | Initially collapsed and after required work. **Create research prompt**, **Copy prompt**, **View prompt**. Helper: **Copy this prompt into an external AI assistant. Review its suggestions before entering them here. This does not update the form.** |
| Prompt loading/copy feedback | Keep disabled copy until generated. Show generation state and announced **Prompt copied**. Clipboard failure remains visible outside collapsed content; copying should not dirty the ingredient. Describe the generated content accurately: entered identity details and source notes can be included; inspect the preview before sharing it with an external assistant |

### Composition

| Element | Decision and proposed behavior |
| --- | --- |
| Tab visibility | Keep absent for single ingredients. After blend → single, use a valid remaining tab; preserve current confirmed-removal safeguards |
| Section heading/helper | **Blend composition**; **Add the ingredients in this blend and enter their percentages.** Keep |
| Total indicator | Match validation tolerance and precision. Include a textual result; color is supplementary. Test 99.96, 100, and both tolerance boundaries |
| Search and existing-item add | Keep name/INCI search and accessible combobox. Differentiate **Add to blend** from creating a new library record |
| No results and empty blend | Keep actionable empty instructions. Offer **Create a new ingredient** without making it look equivalent to merely selecting one |
| Quick-create name/category | Keep only required basics. Make immediate creation and destination explicit. Errors attach to their fields. Prevent duplicate submissions while creating |
| Quick-create completion/cancel | Announce creation and return focus to a useful blend control. Cancel closes only the unfinished quick-create entry; it must not imply deletion of an already created ingredient |
| Component identity | Show readable name and INCI; allow wrapping or deliberate expansion on touch. Do not rely on a hover title to distinguish long similar names |
| Component percentage | Label **Share in blend (%)** with the ingredient in the accessible name. Retain localized numeric entry and inline validation; no automatic normalization |
| Remove component | Keep named action **Remove :ingredient from blend**. Clarify that it does not delete the ingredient. After removal, move focus predictably if the focused control disappeared |
| Missing/unavailable component | Keep an explicit unavailable state and recovery via replacement/removal, without revealing inaccessible record details |
| Composition source | Keep one optional source for the whole blend with supplier-specification/report example |
| Change to single | Keep explicit confirmation before saved constituents are removed. Show the consequence beside the control; verify cancel and reload paths |

### Guidance, source notes and files

| Element | Decision and proposed behavior |
| --- | --- |
| Tab/section names | **Guidance & files** tab. Separate practical guidance, source evidence and files rather than one undifferentiated card |
| Ingredient guidance editor | Use **Ingredient guidance** across platform overrides and workspace forms. Helper gives practical formulation/storage examples. Retain formatting and sanitized 10,000-visible-character limit |
| Guidance source badge | **Soapkraft guidance / Workspace guidance**. Preserve active-source switching and retained workspace version |
| Customize/edit/save guidance | Keep explicit actions and separate scope state. When editing, show the editor before any long saved preview |
| Use platform/workspace guidance | Keep consequence-specific confirmation. State that switching to Soapkraft keeps the workspace version available for restoration |
| No guidance | For a viewable empty source, **No guidance added yet**. Add a customization action only when permitted. Do not equate missing guidance with permission denial |
| Source notes | Keep plain textarea labeled **Source notes**, with supplier/source-identification helper. Do not call this private personal notes or formulation guidance |
| Ingredient image | Keep optional main-image picker, thumbnail and use-context helper |
| Ingredient icon | Keep optional compact image and existing main-image fallback explanation. Do not force users to upload two images |
| Documents | **Certificates and technical documents**; helper **Attach up to 8 PDFs, such as certificates of analysis, safety data sheets or technical data sheets.** |
| Picker selection/clear | Keep library selection and clear action. Clarify **Remove selection** means unlinking from the form, not deleting the library asset |
| Picker Library/Upload tabs | Retain existing tab keyboard handlers, focus trap and focus return. Keep accepted formats and configured size limit visible before upload |
| Search, empty/loading/error/processing | Retain distinct states and retry/manual recovery. Ensure file names can be read, including on touch. Do not label an upload ready while it is still processing |
| Upload versus form Save | Verify and explain separate upload persistence: distinguish saving a file in the library from linking it when the ingredient is saved. Do not imply parent Cancel deletes uploaded assets |
| Read-only documents | Keep authorized named download links. Use **Documents**, not **Authorized documents**. Expose file type when available without an extra query |

Shared media/identity controls also serve other forms. Prefer ingredient-specific configuration/copy where possible; any necessary shared change requires a focused regression check of another consumer.

### Soap chemistry

| Element | Decision and proposed behavior |
| --- | --- |
| Eligibility/tab | Keep limited to inherited trusted chemistry. Manual oils retain explanatory carrier-oil guidance; no trust checkbox or unlock action |
| KOH SAP | Keep accepted decimal/professional input and blur normalization. Display unit-aware example and actual inherited allowed range. Make the currently displayed notation explicit |
| NaOH SAP | Keep derived read-only value, with **Calculated from KOH SAP**. Show its unit/notation; do not style it as editable |
| Iodine value and INS | Keep optional technical fields, visibly labeled. Provide concise contextual definitions if needed; do not invent validation bounds in this UX work |
| Soap source notes | Rename generic **Soap notes** to **Soap data source notes**, consistent with stored provenance |
| Fatty-acid total | If an inherited or entered profile exists: **Required total: 80–100%**, with actual total and text state. If neither exists: **No fatty-acid profile recorded. If you add one, its total must be 80–100%.** Preserve the existing empty-profile exception |
| Fatty-acid selector/percentage | Keep searchable acid selector and per-acid limits. Add explicit **Add fatty acid** and readable row summaries. Preserve inherited baseline identity and restrictions |
| Range explanations | Separate current value from inherited reference and allowed range. Avoid repeating a long trust explanation below every input |
| Read-only chemistry | Keep SAP, iodine, INS, acids and source notes where authorized. Preserve zero versus unknown, useful precision and units |

### Regulatory data

| Element | Decision and proposed behavior |
| --- | --- |
| Tab | **Regulatory data** communicates recorded inputs more accurately than an implied completed compliance check |
| Allergen section | Keep conditional visibility and supplier-declaration context. Empty means no declaration recorded, not allergen-free |
| Allergen/percentage rows | Explicit **Add allergen** action; **Concentration in ingredient (%)**; preserve required values and catalog choices |
| Declaration source | Keep source for the full declaration. Use clear supplier-document wording without inventing scientific evidence requirements |
| Restricted/prohibited substances | Keep available independently of the aromatic setting and retain existing screening/assessment distinction |
| Substance/percentage rows | Explicit **Add substance**; nullable concentration remains **Leave blank if unknown**. Zero is a real entered value |
| IFRA reference label | Keep **Reference label (optional)** in this pass. The field also has a generated current-guidance default, so **Document reference** would overstate its meaning. Do not infer a certificate from every record |
| IFRA amendment | Keep catalog selection and separate read-only original supplier wording. Make unmatched source wording understandable without silently mapping it |
| Peroxide value | Keep nonnegative input and `meq O2/kg`; reproduce the same unit in read-only output |
| IFRA source notes | **IFRA source notes**, rather than generic **Notes** |
| IFRA category and maximum | Explicit **Add category limit**. Retain full category descriptions and the existing **Maximum concentration (%)** wording for this pass. Adopt **Maximum use level** only if its calculation/declaration basis is established; an unresolved semantic rename is not a prerequisite for the rest of this work. Do not reuse the ingredient-composition helper here |
| Repeater ordering | Remove reorder handles where order has no domain effect, using existing component support. Keep meaningful row summaries and accessible removal labels |
| Reference tables | Keep headers/captions and genuine tabular comparisons. Allow contained horizontal scrolling on small screens and keep identity/source information readable |

### Duplication and shared feedback

| Element | Decision and proposed behavior |
| --- | --- |
| List entry actions | Preserve matching **Add ingredient / Duplicate ingredient** buttons |
| Dialog title and search | Keep generic duplicate wording, two-character instruction, search by name/INCI/alternative name/identifier and distinct loading/no-results/error states |
| Search result source | Distinguish **Soapkraft**, **Workspace ingredient**, and **Your ingredient** according to actual ownership. Do not relabel a supported user-owned source as workspace-owned |
| Available/blocked result | Keep a readable reason and choose-another recovery for blocked candidates; do not hide all blocked results without explanation |
| Identity preview | Name first, then INCI/category and optional identifier details. Full names remain available on small screens |
| Destination | Name a resolved destination once prominently: **Private to :workspace. Workspace owners, admins and editors can edit it.** When unresolved, keep the existing **Your ingredient library** fallback and omit named-workspace role claims. Avoid repeating the same destination card and sentence |
| Copy consequences | Show concise carry-over/exclusion list before confirmation. Combine legacy-image/media jargon into **Images and documents are not copied**. Preserve guidance-copy explanation |
| Inherited chemistry preview | Give a short visible restriction summary; optional detailed acid ranges below. All ranges must match the inherited baseline for both platform copies and workspace copies |
| No inherited chemistry | Explain that a permitted copy will not gain soap-calculation eligibility. Keep current hard blockers and reasons intact |
| Confirmation | Keep **Create private copy** and **Creating private copy…**; disable repeat submission and preserve existing guarded redirect behavior |
| Close/back/focus | Retain Escape, focus trap, initial search focus, preview heading focus and return to opener. Verify them rather than replacing existing code wholesale |
| Save status and navigation | Preserve independent scopes, failure state, edits made during an in-flight save, pending-input handling, browser unload and in-app navigation protection |
| Validation across tabs | On failed Save, make the first invalid section reachable and identify tabs containing errors. Keep field errors and focus support; first verify what Filament/current JS already provides |
| Session/workspace failure | Explain inability to save and how to recover. Do not promise a retained draft unless verified, or offer unsafe persistence after access is revoked |
| Save/Cancel controls | Keep creation/edit wording distinct; busy text reports the action. Ensure mobile/zoom does not hide status or make Cancel/Save unreachable |

## 6. Implementation sequence after approval

Each task is bounded. Complete its focused checks and review the diff once. Do not run repeated broad reviews or performance sweeps after unchanged checks pass.

The tables distinguish three types of work: confirmed defects must be fixed; specified copy/grouping changes form the core refinement; items worded **check**, **verify** or **if needed** authorize inspection, followed by a change only if a gap is demonstrated. In particular, do not rebuild shared media/dialog controls or add section navigation merely to tick an audit row. Verify package versions/documentation **before the first dependent change**, not at the end of Task 6. This plan does not select an orchestration model or authorize parallel-agent execution.

### Task 1 — Align displayed state with the existing contract

**Modify:** `lang/en/ingredients.php`, `app/Livewire/Dashboard/IngredientEditor.php`, `resources/views/livewire/dashboard/ingredient-editor.blade.php`, `resources/js/ingredient-editor.js`, `resources/js/classification-prompt.js`, `resources/views/livewire/dashboard/partials/ingredient-classification-prompt.blade.php`, `resources/views/livewire/dashboard/partials/ingredient-composition-rows.blade.php`, `app/Services/UserIngredientAuthoringService.php`.

- [ ] Correct creation status without changing the dirty-state baseline or leave-page behavior.
- [ ] Correct conditional required-total and quick-create wording, including resolved destination/fallback. Preserve both-empty fatty-acid profiles as valid.
- [ ] Make copy failure visible and recoverable with preview closed.
- [ ] Align displayed blend total/status with the existing server tolerance; preserve numeric precision.
- [ ] For workspace-copy previews, use inherited SAP/fatty-acid baselines; retain current platform-source behavior. Keep current values separately if shown.
- [ ] Add focused regressions in `tests/Unit/ingredient-editor.test.mjs`, `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`, and `tests/Feature/UserIngredientAuthoringTest.php`. A clipboard-helper unit test alone cannot prove a message is visible outside a collapsed disclosure: verify that rendered failure state in the browser as well. Add `tests/Unit/classification-prompt.test.mjs` only if helper behavior changes need coverage.

**Acceptance examples:** untouched creation never claims persistence; edited trusted source shows the same allowed range before and after duplication; 99.96 never looks complete; a failed copy action is visible without opening preview; quick-create clearly discloses its independent save.

### Task 2 — Reorder the editor and unify vocabulary

**Modify:** `app/Livewire/Dashboard/IngredientEditor.php`, `resources/views/livewire/dashboard/ingredient-editor.blade.php`, `resources/views/livewire/dashboard/partials/ingredient-classification-prompt.blade.php`, `lang/en/ingredients.php`; ingredient-specific configuration of `app/Forms/Components/IngredientIdentityFields.php` only where needed.

- [ ] Move required basics before optional classification detail and research assistance.
- [ ] Add explicit creation workspace scope; use task/name headings and correct introductory text.
- [ ] Rename tabs/fields per §5, with stable tab identity and recovery for old query-string links.
- [ ] Group guidance, source notes and files; retain all existing fields and validation.
- [ ] Make advanced identity rows readable, with localized summaries and consistent terms.
- [ ] Verify category/subcategory changes. Preserve aromatic setting behavior and saved allergen/IFRA records through off/on transitions; use the corrected domain-setting copy rather than the withdrawn display-only wording.

**Checks:** extend `tests/Feature/IngredientEditorLocalizationTest.php` and relevant cases in `tests/Feature/UserIngredientAuthoringTest.php`. Verify no user-side schema option unintentionally changes the admin identity editor.

### Task 3 — Make platform customization easy to find

**Modify:** `resources/views/livewire/dashboard/ingredient-editor.blade.php`, `resources/views/livewire/dashboard/partials/ingredient-reference.blade.php`, `lang/en/ingredients.php`; adjust the existing reference builder only if its presentation data needs a small addition.

- [ ] Put ingredient identity and authorized workspace controls before the long reference sections.
- [ ] Keep guidance source and material-code save scopes independent; reduce duplicate guidance preview while editing.
- [ ] Inspect navigation after reordering; add section links only if still needed, using existing authorized data and unique heading IDs.
- [ ] Replace ambiguous reference headings/empty values and add units without hiding technical content.
- [ ] Keep all privacy filtering before rendering. Do not load new records merely to populate navigation links.

**Checks:** relevant cases in `tests/Feature/IngredientEditorAccessTest.php`, `tests/Feature/WorkspaceIngredientCodeTest.php`, `tests/Feature/WorkspaceIngredientGuidanceTest.php`. Browser checks include empty guidance, custom guidance, long platform data and public non-member reference.

### Task 4 — Refine composition, chemistry and regulatory entry

**Modify:** `app/Livewire/Dashboard/IngredientEditor.php`, composition partial, `lang/en/ingredients.php`, with minimal local JS support for focus only if required.

- [ ] Add explicit repeater actions, contextual concentration labels and understandable range/total feedback.
- [ ] Preserve blend-only visibility and confirmed constituent removal; ensure active-tab fallback is valid.
- [ ] Associate custom errors with inputs; preserve focus after adding/removing rows.
- [ ] Keep units and current/inherited values consistent across editor and reference.
- [ ] Keep existing IFRA maximum/reference terminology unless a verified semantic improvement is available; retain scientific rules and do not expand into a regulatory-model investigation.

**Checks:** add only changed behavior/failure cases to `tests/Feature/UserIngredientAuthoringTest.php` and existing ingredient JS tests. Test empty rows, duplicate selections, invalid/unknown percentages, valid zero, localized decimals and inherited limits. Do not expand this into new chemistry validation.

### Task 5 — Finish duplication, media and accessibility details

**Modify:** `resources/views/livewire/dashboard/partials/duplicate-ingredient-modal.blade.php`, `resources/js/ingredient-duplication.js` only for demonstrated interaction gaps, `lang/en/ingredients.php`, ingredient media configuration in `app/Livewire/Dashboard/IngredientEditor.php`; shared `resources/views/forms/components/media-asset-picker.blade.php` or `lang/en/media_library.php` only if a shared defect is reproduced.

- [ ] Simplify destination/copy-consequence text and disclose the correct inherited ranges from Task 1.
- [ ] Keep the two entry buttons' matching treatment and existing permission/blocked-source behavior.
- [ ] Check picker labels, file selection versus upload persistence, progress/error announcements and long names.
- [ ] Check keyboard focus, field errors, tab error recovery and action-bar wrapping in the complete form.
- [ ] Make the smallest scoped fixes; do not rewrite functioning dialog/focus/dirty-state machinery.

**Checks:** `tests/Feature/IngredientsIndexDuplicationTest.php`, `tests/Unit/ingredient-duplication.test.mjs`, and `tests/Unit/MediaAssetPickerAccessibilityContractTest.php` if that shared control changes. Preserve repeated-submit, stale workspace and redirect protections.

### Task 6 — Validate the finished interfaces once

- [ ] Confirm installed package versions and use scoped Boost documentation before version-sensitive component changes. Reuse adequate documentation already obtained.
- [ ] Use canonical English dotted keys in `lang/en/ingredients.php`; check DB translation overrides/localized output so revised copy actually appears. Do not edit framework JSON translation files.
- [ ] Run the narrowest affected PHP and Node tests. Run Pint for modified PHP. Run Filacheck only if `app/Filament` was actually modified. Follow the repository's graph-update instruction if application code changed.
- [ ] Build assets when frontend changes require it; verify the actual feature worktree is served. Do not start another HTTP server under Herd.
- [ ] Complete the scenario matrix below. Record only measured outcomes, unresolved defects and useful screenshots.
- [ ] Present the final diff and verification results. Do not deploy, merge or start another optimization pass as an implicit extension of this plan.

Representative commands, selecting only files affected by the task:

```bash
php artisan test --compact tests/Feature/IngredientEditorAccessTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/UserIngredientAuthoringTest.php
php artisan test --compact tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientsIndexDuplicationTest.php
php artisan test --compact tests/Feature/WorkspaceIngredientCodeTest.php tests/Feature/WorkspaceIngredientGuidanceTest.php
node --test tests/Unit/ingredient-editor.test.mjs tests/Unit/ingredient-duplication.test.mjs
vendor/bin/pint --dirty --format agent
```

No tests or builds were run for this document-only planning pass.

## 7. Acceptance matrix

| Scenario | Required result |
| --- | --- |
| Platform single oil | Name/provenance clear; code/guidance easy to find when writable; no Composition tab; no editable platform chemistry |
| Platform aromatic ingredient with many records | Long technical reference remains complete; any section links added work; concentrations and units readable |
| Manual ingredient creation | Required name/category obvious; INCI optional; destination named; initial status truthful; optional AI helper unobtrusive |
| Manual lipid | No new trusted-soap capability; explanation and accurately labeled duplication navigation |
| Workspace blend | Search/add/create distinctions clear; quick-create consequence visible; total matches validation; single/blend transition safe |
| Trusted platform duplicate | Source/destination and copying exclusions visible; shown ranges match editor enforcement |
| Duplicate of an edited trusted workspace copy | Original inherited baseline retained and shown accurately; current values not mislabeled as the source baseline |
| Trusted SAP with no inherited or entered fatty-acid profile | Empty profile remains valid and is not labeled an error; adding a profile activates the existing total/range rules |
| Aromatic setting off → save → reopen → on | Existing records retained; domain/workbench behavior preserved; no promise that hidden unsaved values were committed |
| No current workspace / eligible personal-owned source | Existing policy and workspace-creation path preserved; destination/source labels truthful; quota failures keep entered data as current behavior permits |
| Creation entered from supplier listing | Existing successful return-to-supplier flow and return parameters remain intact; ordinary creation still follows its existing destination |
| Permitted untrusted copy and blocked source | Correct eligibility explanation; useful choose-another recovery; no misleading chemistry promise |
| Owner/Admin/Editor and read-only cases | Controls follow existing policy in the relevant workspace; app-admin status alone adds no user-side bypass |
| Guidance editing/switching and material code | Independent save states; correct active source; preserved restore behavior and navigation protection |
| Error on a different tab | Invalid section opens or is immediately reachable; field/error relationship announced; draft remains as current behavior permits |
| Save in progress, failed save, edit during save | No duplicate action, false success or lost late edit; statuses describe the relevant scope |
| Media selection/upload | Clear attachment versus library state; processing/error state understandable; no implied deletion on Clear/Cancel |
| 360px/390px mobile, desktop, 200% zoom and narrow reflow | No lost Save/Cancel, clipped crucial status, unreadable distinguishing name or page-wide table overflow |
| Keyboard and screen reader | Logical heading/focus order, reachable disclosure/combobox controls, dialog focus return, associated errors and meaningful announcements |
| English and a longer supported translation | Consistent terminology; no raw keys/enums, hidden button text or sentence-fragment interpolation |

**Review decision: Ready to implement the corrected plan; not yet accepted as completed work.** Resolve the HIGH findings and exercise relevant visual/accessibility acceptance before declaring completion. One re-evaluation was performed on 6 September; it was a targeted source review, not a fresh full browser audit. No application files, tests or build artifacts changed.
