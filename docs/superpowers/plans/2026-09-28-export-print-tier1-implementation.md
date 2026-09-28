# Contextual Print and Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Use subagent-driven-development only if the user requests delegated execution.

**Goal:** Put a small set of useful print and export actions beside the work they support, with accurate current data and no production requirements imposed on people who only formulate or maintain a product portfolio.

**Architecture:** Keep the existing Formula Sheet as the shared formula document for reviewing, scaling, printing and reuse, with reusable sections and sensible print selections for each entry point. Current workbench outputs feed that document a read-only snapshot of the screen through authenticated POST requests; saved formula and inventory outputs use scoped GET requests. Reuse the calculation services, workspace queries and production snapshots; prints remain HTML with browser Print / Save PDF and spreadsheets use the installed OpenSpout writer.

**Tech Stack:** PHP 8.5.10, Laravel 13.30.1, Livewire 4.4.3, Filament 5.7.8, Alpine, Tailwind 4, OpenSpout 4.32.0, Pest 4.7.8.

---

## Status and delivery order

This is the authoritative implementation plan following the user's review on 28 September 2026, including the clarification to reuse and improve the existing Formula Sheet and permission to make it more modular. Preserve its useful capabilities while allowing better grouping and optional sections. It replaces the earlier per-view design and comparison where they disagree. Implementation is in progress on `codex/formula-outputs` in the managed `formula-outputs` worktree. Baseline: 116 tests and 580 assertions passed. Task 1 is split into the saved-sheet modular sections and the current-input transport; both remain part of Stage A.

Deliver three independently usable stages, in this order:

1. **Formula Bench and saved Formula Sheet:** formula print/export, costing export, composition export.
2. **Ingredients and inventory:** ingredient price book, materials and stock-lot exports, stock count sheet.
3. **Production Bench:** batch sheet/record and picking list.

Finish each stage's UI and verification before starting the next. Do not ship an empty action, or move useful actions into a central reports page.

## The UI contract

| Where the user is | Visible action | What it produces |
| --- | --- | --- |
| Formula Bench — Formula | **Print sheet** · **Export formula** | The Formula Sheet using the current displayed formula and quantity, with its usual working-copy sections selected |
| Formula Bench — Costing | **Print sheet** · **Export costing** | The same sheet with costing selected, or the displayed costing as Excel data |
| Formula Bench — Composition & labeling / existing Output tab | **Print sheet** · **Export composition** | The same sheet with incorporated/calculated composition selected, or those data as Excel |
| Formula Bench — Instructions | **Print sheet** | The same Formula Sheet with formula and procedure selected |
| Formula Bench — Packaging | No extra output action | Packaging quantities and prices are already in costing export |
| Saved or historical Formula Sheet | **Print sheet** · **Export formula** | The existing sheet and workbook, preserving their useful contents at the selected version and scale |
| Saved Formula Sheet — labeling section | **Export composition** | The same composition workbook for that selected saved formula and scale |
| Ingredients list | **Export ingredients** | The filtered ingredient list with current workspace prices |
| Inventory — Materials | **Export materials** | The filtered stock position and material requirements |
| Inventory — Stock lots | **Export stock lots** · **Print count sheet** | The filtered lots, or a count sheet with blank counted quantities |
| Stock preparation | **Print picking list** | The displayed proposed/manual allocations and existing reservations, with status distinguished |
| Production run before/during manufacture | **Print batch sheet** | Planned formula and quantities, procedure, allocated lots and space for actuals |
| Completed production run | **Print batch record** | Recorded formula, material consumption, lots, output and dates |
| Purchase order / quotation | Existing **Print / Save PDF** | Preserve the current document and placement |

**Placement:** put the Formula Bench action row immediately below its tab navigation, aligned right within the active tab's content. Keep that location stable between tabs. A single small partial switches actions by active tab; do not repeat actions inside every ingredient card or in the global header. Every Print sheet entry point invokes the same renderer; the current tab selects the relevant sections initially. The document title remains Formula Sheet.

For lists, place actions in the existing table toolbar next to search/filter controls, on the same side consistently. For stock preparation and a production run, use the existing page action area. Use visible text labels; printer/download icons can accompany them. On narrow screens the row wraps without hiding actions in an overflow menu.

**Formats:** Export formula, ingredients, materials and stock lots are small labeled menus: Excel (.xlsx) first, CSV second. Export costing and Export composition directly download Excel because they contain several related tables. Print sheet opens the existing printable document with Print / Save PDF and a compact Include control. No format-selection modal.

Keep existing INCI/plain-language copy buttons next to their text. Do not rename the entire Output tab as part of this work.

## Output content and boundaries

### Formula Sheet — preserve and improve the existing document

The Formula Sheet remains the compact working view for reviewing, scaling, printing and reusing a formula. Keep its saved/history navigation and scale controls. It is also the foundation for Print sheet from the workbench: feed the existing document the current input, without introducing another formula document or requiring a visit to the saved sheet first.

Preserve the useful content already present across its screen, print and workbook:

- Formula settings, phase/ingredient tables, weights, percentages with their basis, row notes, calculated alkali/dilution liquids and calculated results.
- Description, manufacturing procedure and label text when present.
- Printable observations/results areas and compact optional handwritten reference/date/prepared/checked spaces. These can support a formulation trial without a Production Bench run, operator setup or any required UI fields.
- The existing optional soap-analysis print page, including qualities and fatty-acid profile.
- The formula workbook's ingredient table and existing calculated soap-output worksheet, including its basis, residual-water assumption and INCI text. CSV remains the simple ingredient table.

Improve the existing document where it helps use: clearly identify current versus saved source, saved version when applicable, scale and generation time; carry the sheet's compact formula-settings summary into print; preserve readable small quantities rather than rounding a nonzero dose to zero; improve long-table/procedure page breaks and spacing. Offer the optional sections in the preview itself rather than in a separate setup step.

Costing and composition exports are contextual ways to obtain specific data. They reuse the same document/calculation data and do not require removing useful sections from the Formula Sheet or its workbook. Share the calculated-output row projection and writer between formula and composition exports so the two cannot drift. Composition export adds the explicit incorporated-data handoff described below; costing stays separate and is not appended to ordinary formula outputs.

### Modular printing, with no document designer

The preview header contains **Print / Save PDF** and **Include**. Include reveals a short checkbox list of sections available for this formula. Identity, source/version, quantity basis and generation time always remain visible; the document sections have a fixed readable order:

| Section | Content | Initial selection from Formula / saved sheet |
| --- | --- | --- |
| Formula | Settings, phased ingredients, quantities and calculated results | On |
| Procedure | Existing manufacturing instructions | On when present |
| Label text | Existing ingredient declaration | On when present |
| Composition tables | Incorporated ingredients and calculated soap output, with their different bases | Off |
| Costing | Ingredient/packaging costing and totals | Off |
| Soap analysis | Existing qualities and fatty-acid analysis | Off; preserve existing include-analysis links |
| Trial notes | Optional handwritten reference/date/prepared/checked, observations and results spaces | On |

From **Instructions**, select Formula and Procedure. From **Costing**, select Costing; retain identity and costing quantity/currency without repeating an additional ingredient table. From **Output**, select Composition tables and Label text, including the incorporated raw-material table needed to understand the calculation. Users can change the selection in that same preview. Prices appear only when the user enters from Costing or selects that section.

Show only relevant sections: no soap analysis for cosmetics, no empty procedure block and no costing section when costing is unavailable for that source. Incomplete known data remains clearly marked within a usable section. Reject an empty print selection. Section choices are local to the open preview; they do not save the formula, create production records or require account-level preferences.

This adds optional printable costing/composition to the existing sheet, not new document routes or a new report builder. Keep the standard formula XLSX contents and the focused data exports; preview checkboxes explicitly control Print / Save PDF and do not silently change CSV/XLSX exports elsewhere. Reuse their data builders for the printable sections. No rearranging sections, saved templates or preset-management UI in this implementation.

### Costing

Export ingredient rows and packaging rows, quantity basis, expected units, currency, unit prices, line costs, subtotals, total and cost per finished unit. Keep repeated occurrences of the same ingredient or packaging item separate when the workbench does.

An unknown price is blank and identified as missing; an explicit zero price is zero. Totals must carry the same incomplete-pricing indication as the view. Do not silently fill missing saved prices from today's catalogue or convert currencies. Reuse these same rows for the optional Costing section of the Formula Sheet; there is no separate costing-document implementation.

### Composition for sharing with an assessor

One **Export composition** action produces a self-contained workbook:

- **Soap — Incorporated sheet:** raw materials as added, with phase, ingredient identity, recorded INCI name, quantity/unit and percentage of the total fresh mixture. Keep calculated alkali and dilution liquids explicit. Below that table, include the existing incorporated-INCI breakdown with its source ingredients; this preserves the information visible in the incorporated section without losing the underlying raw-material rows.
- **Soap — Calculated output sheet:** the existing calculated finished-composition rows, percentages on the cured basis, calculated quantities when available, roles and source ingredients. Include the calculation settings and the residual-water assumption taken from the existing service constant.
- **Cosmetics — Composition sheet:** the existing incorporated/formula composition and recorded ingredient identities. Do not manufacture a second, identical “finished output” table. Include an existing constituent breakdown only when it contributes distinct information already calculated by the app.

Use the existing chemistry and INCI services. Do not invent constituent percentages for undisclosed blends or normalize missing data into apparently complete composition. Keep allergen contribution information separate from the mass-total rows if included: it must not be counted twice.

Include formula identity, current/saved source, generation time, quantity basis, selected labeling context and known missing-data messages. Saved formula composition uses the selected formula with the linked reference data available at generation time; it is not a historical compliance snapshot. A short note states: **“Calculation support for independent review. The assessor must verify inputs and recalculate.”** No certification, toxicological assessment, PIF builder or compliance-document bundle is introduced.

### Inventory and production

Exports cover **all matching rows across pagination**, using the same filters and ordering as the displayed list. A shortage-filtered Materials export is the shortage report; do not create another action.

The count sheet contains material/code, lot identifiers, quantity unit, a blank Counted column and a small Notes column. Include storage location only when the workspace uses it. The print header identifies the filters and generation date. No stock adjustment or re-import workflow.

A picking list identifies the run, material, lot, quantity/unit and location when enabled. Distinguish existing reservations from proposals. Printing does not reserve stock, confirm preparation or start production.

A completed record uses recorded manufacturing facts. It must not recalculate from today's formula, ingredient data or prices. No stored PDF, signatures feature or new record-finalization workflow. Later release tasks and live stock balances are not part of this initial completed manufacturing record.

## Grounding that changes the earlier plan

- `RecipeController::accessibleCurrentVersion()` returns a database version. It does **not** capture unsaved Alpine input. Exporting that version from the workbench would silently export older numbers.
- `recipes/print.blade.php` already includes the procedure. Reuse it; there is no separate method card.
- The existing Formula Sheet and formula workbook are valuable outputs in their own right. Retain their sections and improve source clarity, precision and layout; adding contextual actions does not justify reducing their content.
- Five saved print URLs are intentionally retained compatibility aliases, covered by `RecipeVersionPagesTest`. Keep them resolving correctly, including historical-version and scale parameters. Use `recipes.print.recipe` for new visible links; route deletion is not a prerequisite.
- `RecipeVersionCostPreviewBuilder` already authorizes view access and projects costing without saving it. Its current row builder expects persisted version relationships; a transient browser snapshot needs a small read-only adapter, not a fake saved version.
- `ProductionFormulaLine`, `ProductionRequirement` and `ProductionConsumption` already retain manufacturing facts. `StockLot` guards acquisition identifiers only for receipt-linked lots; opening lots can change. Capture consumed lot code/batch/expiry in the run's existing JSON context at completion or abort, under the existing lot locks, so records do not depend on today's lot labels.
- `ProductionFormulaSnapshotBuilder` does not currently snapshot procedure content. Capture procedure text/formatting in its existing JSON context when creating new run snapshots. Do not fill historical gaps from the current recipe during a print request.
- Production Bench runs and legacy `ProductionBatch` documents are distinct. Leave the latter's existing output path intact.

## Shared implementation rules

### Read-only source and authorization

Current-workbench requests carry the formula state captured once at click time, the originating workspace public ID for comparison, optional recipe public ID, product family, current procedure content, available current costing state and the print entry context/section selection. Costing captures current edited prices whenever loaded; otherwise the server uses the same read-only defaults projection as the Costing view against the captured formula. It must not substitute an older saved ingredient list or erase missing prices. The client must not submit trusted chemistry results, permission flags or catalogue prices as authoritative reference data.

The server resolves the selected workspace through fresh `WorkspaceAuthorization`, compares it with the originating workspace, and checks every referenced recipe, ingredient and packaging record. Use existing view permission; Viewers and locked-formula readers can export data they may view. A read-only export does not require an editing lease. Reject an old tab after a workspace switch or revoked membership.

Capture current quantities, notes and prices without calling `save`, `publish`, `persist`, `flushCostingSave`, rich-editor dehydrate/upload persistence, or stock mutations. Leave dirty flags and revision baselines unchanged. Already queued autosave behavior remains independent of this action.

No persisted draft, new Product, formula version, costing or media usage is created to print or export. New unsaved soap/cosmetic formulas can print/export when their inputs are calculable. Costing retains its existing availability rules.

### Delivery, limits and file shape

- Use direct HTTP downloads, not Livewire's base64 file-download transport.
- Apply existing `workspace-exports` / `workspace-prints` throttles. Budgets come from the authorized workspace, never the submitted workspace ID.
- Add explicit engineering limits in `config/workspaces.php`: 50,000 rows per list export, 1,000 rows per stock-count/picking print, 100 selected runs per picking request, and 1,000 total submitted formula/packaging rows. Validate text lengths and reject payloads over 2 MiB before calculation. These are resource bounds, not subscription tiers.
- Check the filtered count before generation. Above the limit return a useful “Narrow the filters” error; never download a silently truncated report. Check the row bound while iterating too.
- Write larger exports to a private temporary file using OpenSpout/fputcsv, then return a download and remove it after sending; delete it on failure. Do not materialize the entire inventory in a PHP array or base64 string.
- Use numeric spreadsheet cells, separate unit/currency columns and ISO dates. Percentage columns contain numbers on the 0–100 scale with an explicit `%` heading; do not apply an Excel fractional-percentage format to those values. Write user-entered strings as text, including formula-like names. Preserve significant decimal precision. Export values, not a new editable Excel calculator.
- XLSX has a short context block and clearly labeled tables. CSV starts with a single header row; repeat source, basis, currency or generation-date fields where needed instead of prepending prose.
- Filename pattern: `product-slug-current-formula-YYYY-MM-DD.xlsx`, `product-slug-saved-vN-composition-YYYY-MM-DD.xlsx`, or `stock-lots-YYYY-MM-DD.csv`. Avoid internal numeric IDs.
- Empty and invalid-input states explain what is missing beside the action. A failed download must never trigger a save or fall back to an older formula. Handle 403/404, 409 workspace/source mismatch, 422 input/size errors and 429 throttling in the source view.

## Task 0 — Confirm the checkout and baseline

**Read:** `.ai/rules/index.md` and the rules for every path below; `graphify-out/GRAPH_REPORT.md`; `docs/agents/domain.md`; `CONTEXT.md` at the repository root. Use the installed versions, not the old plan's Laravel/Filament assumptions.

- [x] Inspect `git status --short` and attached worktrees. Preserve unrelated work. For implementation use a suitable managed worktree and a `codex/` branch; do not manually create `.worktrees`.
- [x] Confirm packages with `composer show --direct`, `composer show livewire/livewire`, `composer show openspout/openspout` and `package.json`.
- [x] Read the relevant Laravel Boost documentation before framework-dependent changes. For this plan, request validation, streamed downloads and HTTP testing were checked; inspect OpenSpout 4.32's installed APIs before changing its writer.
- [x] Run the existing baseline:
  ```sh
  php artisan test --compact tests/Feature/RecipeVersionPagesTest.php tests/Feature/RecipeCostingQueueTest.php tests/Feature/WorkspaceMaterialInventoryQueryTest.php tests/Feature/ProductionFormulaSnapshotTest.php
  ```
  Record actual failures. PHP is on PATH in the reviewed checkout; do not inherit the old plan's unconditional OOM workaround.
- [ ] Use Artisan `make:class`, `make:controller`, `make:request`, and `make:test --pest` with `--no-interaction` for new PHP files. Feature tests declare `uses(RefreshDatabase::class)` individually.
- [ ] Review one populated soap and one cosmetic Formula Sheet, their print previews and their current workbook outputs. Use the preservation list above as the baseline; do not replace them with an ingredient-only document. Record any actual rendering or data mismatch in the implementation work rather than assuming the existing outputs need redesign.

## Stage A — Formula Bench and saved Formula Sheet

### Task 1 — Extend the existing Formula Sheet to current workbench input

**Progress:** Saved-sheet modularization (Task 1a) is committed in `0ca4197e` and has passed independent specification and quality reviews. Validation: 98 page tests / 547 assertions, 50 translation tests / 37,366 assertions, three Node section-control tests through a Pest wrapper, build and Pint. Desktop and narrow-screen synthetic previews were checked. The in-app browser did not expose the native print dialog and native Codex UI access was denied by the computer-use tool, so actual A4/Letter pagination remains unverified. Current-input capture and transport (Task 1b), committed in `d5832860` with review fixes through `5bf0cccc`, has now passed independent specification and quality reviews. Fixes cover parsed HTML attachment authorization, malformed rich-text documents and legacy IFRA settings. The latest focused regression set passed 161 tests / 945 assertions, the catalogue passed 50 tests / 37,464 assertions, and the reviewer independently reran 15 output/capture tests / 98 assertions. Build, Pint and diff checks passed. Formula and Instructions action placement (Task 4a) is also complete and independently approved; its details and browser checks are recorded under Task 4. The authenticated browser currently opens at login, so no authenticated end-to-end browser session has been verified.

**Create:**
- `app/Http/Requests/RecipeWorkbenchOutputRequest.php` — bounded input validation and normalized output selection.
- `app/Http/Controllers/RecipeWorkbenchOutputController.php` — current formula print/download HTTP entry points.
- `app/Services/RecipeWorkbenchOutputBuilder.php` — authorized transient source, server calculations and document data.
- `app/Services/FormulaDocumentOptions.php` — the fixed section IDs, availability and entry-point defaults; no persistence or arbitrary template schema.
- `resources/js/recipe-workbench/outputs.js` — capture and transport, independent of persistence.
- `resources/views/components/formula-document/print-options.blade.php`, `resources/views/components/formula-document/settings.blade.php`, `resources/views/components/formula-document/procedure.blade.php`, `resources/views/components/formula-document/label-text.blade.php`, `resources/views/components/formula-document/soap-analysis.blade.php`, `resources/views/components/formula-document/trial-notes.blade.php` — extract existing useful content and the compact preview controls into reusable sections.
- `tests/Feature/RecipeWorkbenchOutputTest.php`, `tests/Feature/RecipeWorkbenchOutputCaptureTest.php`.

**Modify:** `routes/web.php`, `config/workspaces.php`, `resources/js/recipe-workbench/component.js`, `resources/js/recipe-workbench/payload.js`, `app/Services/RecipeWorkbenchViewDataBuilder.php`, `app/Services/RecipeVersionViewDataBuilder.php`, `app/Http/Controllers/RecipeController.php`, `resources/views/recipes/print.blade.php`, `resources/views/recipes/partials/version-sheet.blade.php`, `resources/views/components/formula-document/table.blade.php`, `resources/views/components/formula-document/results.blade.php`, `tests/Feature/RecipeVersionPagesTest.php`.

- [x] Add failing endpoint tests for an unsaved formula, an existing product with newer submitted quantities/procedure, cosmetic phases, NaOH/KOH/dual soap, wrong-workspace references, an authorized Viewer and a locked formula. Assert domain-table rows and recipe/version/costing revisions do not change.
- [x] Extend existing Formula Sheet regression tests to preserve procedure, label text, results, observations and optional analysis for current and saved sources. Add a nonzero quantity below the present two-decimal display precision and assert it remains meaningful on the printed sheet. Keep the existing trial-writing spaces usable without creating a production run.
- [x] Add behavior coverage for section selection: Formula and Instructions defaults, selecting/deselecting available content, no empty print, no soap-analysis option for cosmetics, and no prices in default formula print. Legacy `include_analysis` links must still select the analysis section. Choosing a section must not change the formula source, quantity or saved version.
- [x] Add a Node scenario using the harness pattern in `RecipeCostingQueueTest.php`. Capture an edited quantity, pending procedure and costing price; assert no save/publish/flush calls and no dirty-state reset. Changing the workbench after capture must not mutate the captured request.
- [ ] Run the two new test files; the missing route/capture behavior must fail before implementation. The final passing results are recorded above; the original red-run evidence has not been retained in this status summary.
- [x] Add separate print/download routes within the existing authenticated route group:
  ```php
  Route::post('/dashboard/formula-outputs/print', [RecipeWorkbenchOutputController::class, 'print'])
      ->middleware('throttle:workspace-prints')->name('recipes.workbench.print');
  Route::post('/dashboard/formula-outputs/export', [RecipeWorkbenchOutputController::class, 'export'])
      ->middleware('throttle:workspace-exports')->name('recipes.workbench.export');
  ```
  The request contract is `{workspace, recipe?, product_family, kind: formula|costing|composition, format: xlsx|csv, draft, manufacturing_instructions, costing?, print_context?, sections?}`. The print endpoint always renders the Formula Sheet. Validate `print_context` as `formula|instructions|costing|output|sheet` and `sections` against `formula|procedure|label|composition|costing|analysis|notes`; availability comes from authorized source data. The builder derives identity/reference data from authorized records and the submitted draft name; source is labeled “Current formula — working copy”.
- [x] Reuse `serializeDraft` for its snake-case request shape, and explicitly capture the pending rich-editor content omitted by that serializer. Preserve null prices in an output-specific costing capture. Deep-copy the payload. Pass it through a read-only adapter to the existing calculation/INCI services and `FormulaDocumentBuilder`; do not pass snake-case input directly to the camel-case snapshot API.
- [x] Validate ingredients in **all** phases, including dilution liquids, and use server-resolved identity and chemistry. Existing preview methods alone do not establish this authorization boundary. Keep current formula row limits separate from save-completeness requirements.
- [x] Render through the existing print view using `formulaDocument`, source metadata and an optional return URL. Remove its mandatory `$recipe` dependency for new formulas. Preserve its procedure, label text, calculated results, trial-writing spaces, observations and optional analysis. Sanitize content and reuse authorized attachment rendering without dehydrating/persisting uploads.
- [x] Reuse the existing table/results components and extract the other sections listed above from existing Blade content. Make source/version/scale clear, add the compact settings summary to print and apply the established adaptive quantity formatting to small doses. Improve table/procedure page breaks without dropping useful content. Add Include checkboxes to the printable view using `FormulaDocumentOptions`; toggling them changes document presentation only and retains a fixed source snapshot. Map legacy `include_analysis` to the same selection.
- [x] For print, synchronously open the document tab from the click, fetch the POST result and render it there; propagate failures to the originating view and close the empty tab. Retain a clear link if a browser blocks opening it. For downloads, fetch the binary HTTP response, respect the filename header, save via a temporary object URL and revoke it. Keep the captured state unchanged on failure.
- [x] Rerun the new tests and `RecipeVersionPagesTest`; all existing saved/historical print aliases must still pass. Commit this complete slice.

**Acceptance example:** the saved formula is 1,000 g, the user changes the workbench to 750 g and edits the procedure, then clicks Print sheet. The document uses 750 g and the edited procedure. They can remove Trial notes or add Soap analysis in the preview. No formula, costing or media record is saved.

### Task 2 — Reuse the formula workbook and add costing export

**Progress:** Task 2 is committed in `da007fe1` (typed writer), `b92b13d0` (current and saved costing outputs), and review fixes through `b99af2c1`. Independent specification and quality reviews passed; Task 2 is complete. Current and saved output adapters share decimal arithmetic, including derived alkali and plain water, missing/zero prices, duplicate packaging, selected saved scale and batch context, renamed packaging identity and blank-quantity unit conversion. Review fixed cleared packaging prices becoming zero and aligned missing-price aggregates: individual missing costs stay blank, known costs are summed with an incomplete indicator, and explicit zero remains priced. Existing save serialization is preserved. Costing actions, the selectable Formula Sheet section and six-locale catalogue entries are implemented. Verification: 297 tests / 39,373 assertions initially, 204 / 1,196 related regressions, 189 / 1,153 after Pint, and 135 / 39,525 after the review fixes. The spec reviewer independently passed 10 tests / 45 assertions. Vite build, Pint, graphify update and diff checks passed. The separate production-preview adapter retains its existing derived-row inclusion behavior. Quality review added selected KOH purity to current and saved costing labels; four targeted tests / 47 assertions passed independently. No authenticated browser or native PDF check is claimed.

**Create:**
- `app/Services/TabularFileExporter.php` — only typed cell writing, worksheet/table layout and temporary-file cleanup; no business queries.
- `app/Services/RecipeCostingExportDataBuilder.php` — current costing rows and context.
- `resources/views/components/formula-document/costing.blade.php` — the same costing rows and totals as a selectable sheet section.
- `tests/Feature/RecipeCostingExportTest.php`, `tests/Feature/TabularFileExporterTest.php`.

**Modify:** `app/Services/RecipeExportDataBuilder.php`, `app/Services/RecipeWorkbookExporter.php`, `app/Services/RecipeCsvExporter.php`, `app/Services/RecipeVersionCostPreviewBuilder.php`, `app/Services/RecipeVersionCostingSynchronizer.php`, `resources/js/recipe-workbench/sections/costing-section.js`, `app/Services/RecipeWorkbenchOutputBuilder.php`, `app/Services/RecipeVersionViewDataBuilder.php`, `app/Services/FormulaDocumentOptions.php`, `app/Http/Controllers/RecipeWorkbenchOutputController.php`, `resources/views/recipes/print.blade.php`, `tests/Feature/RecipeVersionPagesTest.php`, `tests/Feature/RecipeWorkbenchOutputTest.php`.

- [ ] Write failing costing tests with known independent expectations: 500 g at 10 currency/kg costs 5; two finished units using one 0.50 packaging component each cost 1; total 6 and per-unit cost 3. Repeat with a zero price, a missing price, duplicate packaging occurrences, different display units and an unsaved price change.
- [x] Add file tests that read the actual XLSX/CSV: numeric values are numeric, currency/units are separate, text resembling a formula stays text, long lists do not stop at a page, and temporary files are removed on success/failure.
- [x] Preserve the existing workbook-content tests: soap formula XLSX retains both ingredient inputs and calculated output, with basis/assumptions and INCI; cosmetics retain their relevant formula data. A current-input workbook and saved-input workbook with equivalent sources must produce equivalent values.
- [ ] Run:
  ```sh
  php artisan test --compact tests/Feature/RecipeCostingExportTest.php tests/Feature/TabularFileExporterTest.php
  ```
  Confirm the new behavior fails.
- [x] Give `RecipeExportDataBuilder` a `fromDocument(array $document): array` entry point. Keep its existing saved-version `build()` as a wrapper. Both sources then produce the same ingredient-row schema.
- [x] Preserve supported missing prices through the existing projection/UI path: distinguish an existing null ingredient price from an absent row (`Map.has` / `Object.hasOwn` / row existence), instead of falling back to the catalogue. Keep unpriced projected packaging nullable in memory; stored packaging prices are non-nullable, so existing saved zeroes remain zero and blank-to-zero persistence is not redefined. Preserve the current workbench's tested displayed-currency behavior and the saved sheet's saved currency; add no FX conversion.
- [x] Add a read-only transient-row entry point to `RecipeVersionCostPreviewBuilder` and share the actual costing arithmetic with the persisted path. Build rows from the current normalized formula at the current costing quantity, matching prices by phase/position/ingredient and packaging occurrence. Do not construct or save placeholder Eloquent versions.
- [x] Include synthetic alkali and the implicit-water remainder in both costing projections, using canonical alkali identities, actual KOH-to-weigh, the liquid allocator and authorized ingredient resolution. The current saved preview omits these rows. Do not count dilution-liquid replacements twice. Keep cosmetic versus oil percentage bases explicit.
- [x] `RecipeCostingExportDataBuilder` produces three worksheet sections: ingredients, packaging, summary. `TabularFileExporter` accepts already-authorized context, columns and iterable typed rows. It knows nothing about workspaces, products, prices or models. Keep existing small recipe exporter entry points compatible while reusing the writer.
- [x] Wire `kind=formula` to the existing formula XLSX/CSV writers, preserving the soap-output worksheet, and `kind=costing` to the costing workbook. Reject costing CSV and missing/unloaded costing state explicitly. Keep formula export free of prices.
- [x] Feed the same costing rows to the selectable Costing print section. From the Costing tab select that section by default; from other tabs keep it off. Preserve edited current prices and use existing read-only default projection only where the view would do so. Keep the saved source's empty-costing behavior. Label the costing quantity and currency explicitly, especially when costing quantity differs from the formula scale. Test that print and workbook totals/missing-price indications agree and that adding the section performs no writes.
- [x] Rerun the new tests plus `RecipeWorkbenchCostingAlkaliTest.php`, `RecipeWorkbenchCostingContentTest.php` and `RecipeVersionPagesTest.php`. Preserve saved empty-costing behavior and existing no-write tests. Commit.

### Task 3 — Incorporated and calculated composition export

**Progress:** Task 3a's composition projection is independently approved through `fd3f9646` (initial `0973e9c6`, specification fixes `795013e9`, small-float fix `fd3f9646`). Review preserved distinct cosmetic breakdowns, authored percentage bases versus actual incorporated mass, missing derived INCI, KOH purity, constant-backed assumptions and tiny quantities. Four focused tests / 63 assertions passed independently; the catalogue passed 50 / 38,073, and Pint, diff checks and graph refresh passed. Task 3b is committed in `7c990d3a`, with print heading/warning fixes in `a8af3225`, and has passed specification review. It implements workbook generation, the optional print section, current/saved endpoints, canonical derived identity resolution, Output-tab actions and the saved-sheet composition entry point. Initial integration verification passed 201 tests / 39,505 assertions; the print fixes passed 4 / 75 independently. Quality review independently passed 23 / 242 and found missing incorporated-kind labels plus a raw variant key; those presentation fixes are committed in `b808a2b0` and approved. The final focused composition/catalogue run passed 55 tests / 38,562 assertions; the reviewer independently passed the presentation regression (1 / 13). Actual rendered synthetic costing/composition previews were checked at desktop and 390px widths; Include toggles work and prevent an empty selection, while wide tables scroll within the document. All temporary public fixtures were removed. Authenticated downloads and native A4/Letter print remain unverified. Stage A implementation and independent reviews are complete; the manual limitations above remain for final acceptance.

**Create:** `app/Services/RecipeCompositionExportDataBuilder.php`, `resources/views/components/formula-document/composition.blade.php`, `tests/Feature/RecipeCompositionExportTest.php`.

**Modify:** `app/Http/Controllers/RecipeWorkbenchOutputController.php`, `app/Http/Controllers/RecipeController.php`, `app/Services/RecipeWorkbenchOutputBuilder.php`, `app/Services/RecipeVersionViewDataBuilder.php`, `app/Services/RecipeWorkbookExporter.php`, `app/Services/FormulaDocumentOptions.php`, `resources/views/recipes/print.blade.php`, `routes/web.php`, `tests/Feature/RecipeVersionPagesTest.php`, `tests/Feature/RecipeWorkbenchOutputTest.php`.

**Reuse:** `FormulaDocumentBuilder`, `SoapCuredOutputBuilder`, `InciGenerationService`, `LyeLiquidAllocationService`, `TabularFileExporter`.

- [x] Test a soap with an oil, an addition and a dilution-liquid replacement. Assert that the as-added quantities account for the actual inputs once, incorporated percentages use total fresh weight, cured percentages use the existing cured basis, and the two bases are labeled distinctly. Test KOH purity and dual alkali.
- [x] Test cosmetics, shared INCI names from distinct materials, an undisclosed blend and missing INCI/chemistry. Preserve raw-material identity; never imply an undisclosed constituent percentage. Assert there is no redundant cured-output worksheet for cosmetics.
- [x] Test a current changed formula, a historical saved formula at a different display scale, calculation settings/assumptions, correct source/date, no prices, and advisory wording. Independently compare selected rows against the established calculation services and the Output-tab data, including percentage totals; do not only assert worksheet names.
- [ ] Run `php artisan test --compact tests/Feature/RecipeCompositionExportTest.php` and confirm the missing behavior fails. (Original red-run evidence not retained here.)
- [x] Build the workbook contract described above from the same transient source as formula export. Read the incorporated variant explicitly; the selected label-text variant must not accidentally replace the incorporated table. Read residual-water percentage from the existing constant. Do not add new reaction, evaporation or regulatory models.
- [x] Derive fresh raw-material mass from the authored rows plus actual NaOH/KOH-to-weigh and allocated dilution liquids. The INCI service can expand a partly disclosed blend to less than its parent mass; reuse its breakdown weights but divide by the actual fresh mass and report the accounted portion, never normalize the disclosed rows to 100%. Keep calculated soap output on its established cured basis, including visible incomplete totals where KOH purity or missing data leave unrepresented material.
- [x] Describe the existing 11% assumption accurately: it is retained dilution liquid as a proportion of calculated cured mass, shared across plain water and substitutions. It is not measured water content. Keep generated calculated-output INCI distinct from an authored final label list, and do not put overlapping allergen declaration rows into mass totals.
- [x] Use the typed writer and connect `kind=composition, format=xlsx`. Keep unavailable calculated results visibly incomplete or return a specific calculability error; never substitute the saved formula.
- [x] Add `RecipeController::exportSavedComposition` and the export-throttled GET route `recipes.export.composition` beside the existing saved export routes. Resolve `accessibleSheetVersion()` and the displayed scale exactly as the current saved exports do, then pass `RecipeVersionViewDataBuilder`'s snapshot/document into the same composition builder. Do not restore the historical version into the current formula to export it.
- [x] Keep the calculated soap-output worksheet in the formula workbook. Reuse its calculated row data and writing logic for composition export, extending the shared data with the incorporated table and clear assumptions. Test that the same source/scale produces the same calculated output in both workbooks; preserve the existing formula-workbook content assertions.
- [x] Render those same composition data in the optional Formula Sheet section. From Output select Composition tables and Label text initially. Use clear Incorporated / Calculated output headings, separate percentage bases and the same independent-review note as Excel. Test selection from current and historical sources, cosmetics without a redundant output table, and agreement between printed and exported rows. Keep it optional from Formula/Instructions entry points.
- [x] Rerun composition and saved export tests; commit.

### Task 4 — Put the Formula Bench actions in their final places

Deliver this UI incrementally as the underlying outputs become ready: Formula and Instructions actions after Task 1b, then Costing and Output actions with Tasks 2 and 3. Keep the one stable action row and never expose an unsupported action. Stage A is complete only when all four applicable tabs and saved-sheet composition are finished and verified.

**Progress:** Formula and Instructions actions (Task 4a) are approved by independent specification and quality reviews, in `57afa2a2` and `8575961c`. The initial focused suite passed 163 tests / 38,298 assertions; the teardown follow-up passed 15 tests / 116 assertions. Build, Pint and diff checks passed. Workbench teardown now revokes a blocked-popup document URL and prevents a late response from allocating an unreachable one, while an already-open print tab still receives its result. Quality review independently passed the three capture tests and verified all 20 new translation keys across six locales. An isolated browser fixture using the actual navigation/action markup, installed Alpine and the built application stylesheet passed desktop/390px layout, Formula/Instructions switching, enabled actions outside a disabled fieldset, keyboard opening, arrow navigation and Escape returning focus. Mouse automation timed out, so outside-click dismissal remains unverified. The fixture did not exercise an authenticated workbench or live downloads; all temporary public fixture files were removed and the viewport reset. Task 2 has completed the Costing actions and shared spreadsheet writer. Task 3 has implemented Output actions and saved-sheet composition; both are now independently approved through `b808a2b0`. Stage A implementation is complete, with authenticated browser/native print checks still outstanding.

**Create:** `resources/views/livewire/dashboard/partials/recipe-workbench/output-actions.blade.php`.

**Modify:** `resources/views/livewire/dashboard/recipe-workbench.blade.php`, `resources/views/recipes/version.blade.php`, `resources/views/recipes/partials/version-sheet.blade.php`, `resources/js/recipe-workbench/outputs.js`, `lang/en/workbench.php`, `lang/en/formula_documents.php`, `database/seeders/data/interface-translations.json`, `tests/Feature/RecipeWorkbenchContractTest.php`, `tests/Feature/RecipeWorkbenchOutputCaptureTest.php`.

- [x] Add action-visibility and behavior coverage for the UI contract: Formula, Costing and Output have Print sheet plus their contextual export; Instructions has Print sheet; Packaging has none. All print actions use the same document with the specified section defaults. No production action appears.
- [x] Implement one action row after the tab navigation. Use ordinary buttons/links and the existing accessible dropdown pattern. Print sheet invokes one function, passing the active tab as the entry context. Keep source selection automatic; there is no current-vs-saved chooser in the workbench. The preview's Include controls reveal the section selection and let users adjust it without returning to the bench.
- [x] On the saved Formula Sheet, retain version/scale parameters in Print sheet and the Excel/CSV menu. Put Export composition beside its existing labeling section, using the same version/scale parameters. Pass that URL explicitly to the partial; keep composition available with a clear missing-data indication even when label text is absent. Move the separate analysis checkbox into the preview's Include list while preserving the old URL parameter; do not send users through the workbench to print/export an older saved formula.
- [x] Show pending/error state near the action. Preserve available read actions for Viewers. Hide these authenticated additions on the public calculator in this release; do not change its existing behavior or add an account gate elsewhere.
- [x] Add strings to the existing translation groups and all six catalogue locales. Preserve administrator overrides and existing INCI copy actions.
- [x] Run the contract/capture tests, `RecipeVersionPagesTest.php` and `InterfaceTranslationCatalogueTest.php`, then `npm run build`.
- [ ] Manually walk soap and cosmetics at desktop and mobile widths: keyboard access, menu focus/Escape, pending edits, empty formula, long procedure, failed download and blocked popup. Compare the improved Formula Sheet with the baseline from Task 0, including the existing review/scale workflow and workbook contents. Print to A4 and Letter; check small quantities, wrapping, page breaks and section selection. Verify Formula never includes prices by default, Costing opens with costing selected, and Output opens with the incorporated/calculated data selected. Commit Stage A only when the existing sheet and all contextual outputs are usable.

## Stage B — Ingredients and inventory

### Task 5 — Export the ingredient price book from Ingredients

**Progress:** Task 5a shared query extraction is committed in `3bea6bcd` (28 query/price/localization tests passed). Task 5b CSV/XLSX endpoint is committed in `064a4b32`; 82 focused tests including catalogue coverage, Pint and diff checks passed. It uses fresh workspace authorization, all filtered rows in stable order, private temporary files, shared 50,000-row limits and source/filter/date metadata. Task 5c toolbar is committed in `8112b763`, with accessibility/mobile fixes in `bd8eb492`; independent specification and quality reviews pass. Review corrected stored price currencies, reactive empty-result state after Livewire morphs, and pending-download focus. The combined ingredient/query/catalogue run passed 83 tests / 38,943 assertions; final client-wrapper/export checks passed 6 / 62. Independent quality review passed 7 / 66. Build, Pint, graphify and diff checks passed. Browser checks on the actual rendered toolbar using synthetic in-memory data passed desktop/390px layout, Enter/arrow/Escape navigation, mobile menu alignment and focus returning to the trigger during a deliberately pending download. The browser automation would not activate the aria-disabled pending trigger; its activation guard is covered by the Node harness. Temporary public files/tabs were removed and viewport reset. Authenticated real downloads remain unverified. Task 5 implementation is complete; Stage B is not complete.

**Create:**
- `app/Services/IngredientCatalogQuery.php` — the dashboard ingredient query shared by display and export.
- `app/Http/Requests/IngredientExportRequest.php`, `app/Http/Controllers/IngredientExportController.php`.
- `tests/Feature/IngredientExportTest.php`.

**Modify:** `app/Livewire/Dashboard/IngredientsIndex.php`, `resources/views/livewire/dashboard/ingredients-index.blade.php`, `routes/web.php`, `lang/en/ingredients.php`, `database/seeders/data/interface-translations.json`.

- [x] Write a test with more rows than the current list page. Apply a search, ownership filter and descending order; assert exported IDs/names match the entire filtered query in that order. Use another workspace's private ingredient to prove exclusion.
- [x] Test current workspace prices, alternate price units, missing price versus zero, localized display names, Viewer access, revoked membership and stale originating workspace. The export must not use the Filament administrator exporter.
- [ ] Run `php artisan test --compact tests/Feature/IngredientExportTest.php`; confirm failure. (Original missing-endpoint red-run evidence not retained here.)
- [x] Extract the current ingredient-list query into `IngredientCatalogQuery` without changing filter or sort behavior. Both the component and controller use it, with an explicit actor/workspace context. Keep pagination exclusively in the component.
- [x] Add an authenticated, export-throttled GET route named `ingredients.export` with a validated `xlsx|csv` format. Export ingredient display name, recorded INCI, optional workspace material code, category/subcategory, current price, price unit and currency. Use the same current-price selection and mass conversion as Ingredients; preserve each price record’s own currency, since changing the workspace default does not convert existing prices. Use a currency-neutral amount header and do not parse formatted UI strings.
- [x] Enforce the shared list-row bound and generate through `TabularFileExporter`. Add source/date and applied-filter context. Blank optional identifiers remain blank.
- [x] Add **Export ingredients** beside search/filter controls, passing the active normalized search/filter/sort values and originating workspace. Disable with explanatory empty-state text only when there are no matching rows.
- [x] Capture the visible filter state at click, including pending debounced search text; a server-rendered URL alone can lag behind the input. Add a focused client behavior check for typing a search and immediately exporting. Keep the file transfer as an ordinary HTTP download.
- [x] Run the new test plus `IngredientsIndexPriceTest.php`, `IngredientsIndexLocalizationTest.php` and `InterfaceTranslationCatalogueTest.php`. Actual file content is covered by HTTP tests; authenticated browser downloads from page two remain unverified. Commit completed.

### Task 6 — Materials, stock lots and the count sheet

**Progress:** Task 6a shared inventory queries and raw export iteration are in progress. No inventory output is claimed complete yet.

**Create:**
- `app/Services/Inventory/WorkspaceStockLotQuery.php` — shared stock-lot filter/sort query and read projection.
- `app/Http/Requests/InventoryOutputRequest.php`, `app/Http/Controllers/InventoryOutputController.php`.
- `resources/views/production-bench/inventory-count-print.blade.php`.
- `tests/Feature/InventoryOutputTest.php`.

**Modify:** `app/Services/Inventory/WorkspaceMaterialInventoryQuery.php`, `app/Livewire/ProductionBench/InventoryIndex.php`, `resources/views/livewire/production-bench/inventory-index.blade.php`, `routes/web.php`, `lang/en/production_bench.php`, `database/seeders/data/interface-translations.json`.

- [ ] Add failing Materials tests covering more than one page, search, type/category, stock state, demand/shortage and sort. Assert exported physical, quarantined, reserved, available, incoming, required and forecast quantities agree with independently seeded movements, reservations and receipts. Reuse the existing forecast (available + incoming − remaining demand); do not introduce a second shortage definition.
- [ ] Add stock-lot tests covering open/exhausted scope, Released/Quarantined handling, supplier, origin, date basis/range, expiry, material selection, lot order and optional storage location. A zero-physical lot with an active reservation must not become “exhausted”.
- [ ] Add count-sheet tests: same filtered lots and order, blank Counted cells, quantity units, no location column/filter when the workspace feature is off. Cover read-only access, wrong workspace, zero results and over-limit rejection without a partial file.
- [ ] Run `php artisan test --compact tests/Feature/InventoryOutputTest.php`; confirm failure.
- [ ] Add an iterable export projection to `WorkspaceMaterialInventoryQuery`, reusing its query/filter/order/hydration logic. Extract the stock-lot query from `InventoryIndex` into `WorkspaceStockLotQuery` and have both the UI and outputs call it. Do not duplicate large WHERE clauses in the controller.
- [ ] Add named GET routes `production-bench.inventory.materials.export`, `production-bench.inventory.stock-lots.export` and `production-bench.inventory.count.print`. Resolve current workspace/read entitlement through the existing access services; apply the appropriate throttle.
- [ ] Materials columns: type, material/code, physical, quarantined, reserved, available, incoming, required, forecast and unit, matching the existing view's definitions. Stock-lot columns: material/code, internal lot, supplier batch, supplier, origin, stored/received date as applicable, expiry, handling state, physical/reserved/available quantity and unit. Include storage location only when enabled. Do not add inventory valuation.
- [ ] Generate XLSX/CSV with raw numeric values and separate units. Render the count sheet with `layouts.print`, repeat table headings across pages and identify the active filters. The print request performs no stock adjustment.
- [ ] Add only the relevant toolbar controls to the active Materials or Stock lots view. Changing a filter or sort immediately changes the outgoing request, including pending debounced input. Reuse the Ingredients capture pattern and verify the last typed search is included. Keep the shared pagination and sticky-table behavior.
- [ ] Run the new test plus `WorkspaceMaterialInventoryQueryTest.php`, `ProductionBenchInventoryModalTest.php`, `ProductionBenchInventoryMaterialDetailTest.php`, `ProductionWorkspaceAuthorizationTest.php` and translation tests. Check mixed mass/unit materials, A4/Letter page breaks and location on/off manually; commit Stage B.

## Stage C — Production Bench

### Task 7 — Batch sheet and stable completed record

**Create:**
- `app/Services/Production/ProductionRunPrintDataBuilder.php` — recorded run data, not live formula calculations.
- `app/Http/Controllers/ProductionRunPrintController.php`.
- `resources/views/production-bench/production/run-print.blade.php`.
- `tests/Feature/ProductionRunPrintTest.php`.

**Modify:** `app/Services/Production/ProductionFormulaSnapshotBuilder.php`, `app/Services/Production/ProductionCompletionService.php`, `app/Actions/Production/AbortProduction.php`, `app/Actions/Production/BackfillProductionFormulaSnapshot.php`, `app/Livewire/ProductionBench/Production/ProductionDetail.php`, `resources/views/livewire/production-bench/production/production-detail.blade.php`, `routes/web.php`, `lang/en/production_bench.php`, `database/seeders/data/interface-translations.json`, `tests/Feature/ProductionFormulaSnapshotTest.php`.

- [ ] Add planned/in-progress document tests using frozen formula lines, calculated alkali/water lines, requirement material-code snapshots and active lot reservations. Actual quantity cells remain blank where no actual was recorded; the procedure is present when captured.
- [ ] Add completed-record tests for actual consumption, lot identifiers, actual output and manufacture/completion dates. Change the source formula, current ingredient display name, opening-lot identifiers and ingredient price after completion; compare the printed manufacturing data before/after, excluding the generated-at timestamp. No recorded amount/name/cost may be regenerated from those current sources.
- [ ] Add tests for authorized Viewers, wrong workspace, deleted/unavailable recipe, legacy runs without complete formula snapshots, aborted runs and cancelled runs.
- [ ] Extend `ProductionFormulaSnapshotTest`: new snapshots include the selected version's procedure, preserving text, numbered steps and basic formatting. Changing the current recipe procedure afterwards does not change that captured content.
- [ ] Run:
  ```sh
  php artisan test --compact tests/Feature/ProductionRunPrintTest.php tests/Feature/ProductionFormulaSnapshotTest.php
  ```
  Confirm the new assertions fail before implementation.
- [ ] Add `manufacturing_procedure_html` to the existing `formula_context_snapshot` at snapshot creation. Render/sanitize from the selected version and retain text/basic formatting without embedding expiring URLs or copying media. Keep a small note when a referenced illustration is not included. No schema migration or print-time backfill is needed.
- [ ] Capture `execution_lots` in the existing run context when completion/abort records each consumption, keyed by consumption ID and containing its lot ID, internal code, supplier batch and expiry. Merge rather than replace formula context, and preserve execution metadata in the existing explicit formula-backfill action. Legacy records lacking those facts identify them as not recorded; do not backfill them during print.
- [ ] Build the print from `ProductionRun` snapshots, `ProductionFormulaLine`, `ProductionRequirement`, `ProductionConsumption`, captured lot identifiers and recorded output. Follow the presenter's existing legacy requirement-snapshot fallback. When a historic value was never captured, identify it as unavailable instead of fetching today's recipe or inventing an actual quantity.
- [ ] Keep the record limited to manufacturing: formula, planned versus recorded quantities, consumed lots, recorded production notes/observations, output and dates. Do not include live task completion, current lot balances, current locations, current ingredient prices or a new signature workflow. A procedure-less legacy run stays printable with the gap identified.
- [ ] Add the authenticated, print-throttled GET route `production-bench.production.print`. Resolve the run inside the fresh selected workspace and assert readable access before loading print data.
- [ ] Use this status contract in both the endpoint and the one visible action:
  ```text
  Draft / Scheduled / Reserved / InProduction -> Print batch sheet
  Completed                                  -> Print batch record
  Aborted                                    -> Print batch record, prominently marked Aborted
  Cancelled                                  -> No manufacture print action; direct request rejected
  ```
  Use one template with planned/recorded sections. Do not combine legacy ProductionBatch routes with ProductionRun routes.
- [ ] Run the new test, `ProductionFormulaSnapshotTest.php`, `ProductionDetailPresenterTest.php`, `ProductionExecutionTest.php` and `ProductionWorkspaceAuthorizationTest.php`. Print one soap and one cosmetic run, then reprint after changing source data. Commit.

### Task 8 — Picking list from stock preparation

**Create:**
- `app/Http/Requests/ProductionPickingPrintRequest.php`.
- `app/Http/Controllers/ProductionPickingPrintController.php`.
- `app/Services/Production/ProductionPickingPrintDataBuilder.php`.
- `resources/views/production-bench/production/picking-print.blade.php`.
- `tests/Feature/ProductionPickingPrintTest.php`.

**Modify:** `app/Livewire/ProductionBench/Production/StockPreparation.php`, `resources/views/livewire/production-bench/production/stock-preparation.blade.php`, `routes/web.php`, `lang/en/production_bench.php`, `database/seeders/data/interface-translations.json`.

- [ ] Write failing tests for one run, several runs, manual lot choices, partial reservations, a shortage and locations disabled. Use different lots for the automatic proposal and manual selection so a stale/default print is detectable.
- [ ] Assert that printing changes no reservations, stock movements, run status or idempotency key. Reject foreign run/requirement/lot IDs and selections above the run/row limits. If stock changed and a displayed selection is no longer eligible, return a refresh message rather than silently switching lots.
- [ ] Run `php artisan test --compact tests/Feature/ProductionPickingPrintTest.php`; confirm failure.
- [ ] Capture the allocations actually displayed at the click: automatic proposed lot IDs/quantities plus current manual overrides. Submit those choices through the authenticated, print-throttled POST route `production-bench.production.picking.print`. Resolve existing reservations afresh from the server.
- [ ] Validate every requirement belongs to a selected run and every lot belongs to the same workspace/material and is eligible. Reuse `StockReservationProposalService`'s read logic. Aggregate captured quantities across the entire document per lot as well as per requirement; independently generated proposals can share the same available stock. Capture debounced manual quantities directly from the current browser input, so the last keystroke is included. Do not call `PrepareProductionStock`, use its idempotency key, or confirm anything as a side effect.
- [ ] Render one section per run with material/code, lot identifiers, quantity/unit and optional location. Clearly label proposed rows as unreserved and existing reservations as reserved; show unallocated shortages. Printing does not claim stock has physically been picked.
- [ ] Put **Print picking list** in the stock-preparation action area next to its existing preparation action. Preserve entered manual choices, and surface request errors there. No separate picking-report dashboard or required confirmation modal.
- [ ] Run the new test plus `ProductionStockPreparationTest.php`, `ProductionBenchStockPreparationTest.php` and workspace authorization tests. Manually check a mixed automatic/manual multi-run preparation and its printout. Commit Stage C.

## Task 9 — Final acceptance and implementation handoff

**Test/update only where a failure requires a change:** the test files introduced above; existing print layout/components; domain language files and translation catalogue.

- [ ] Run each stage's affected tests once after its final change. Cover response contents and domain side effects, not just 200 responses or button labels. Use the existing Pest-driven Node harness for capture behavior; add no browser-test dependency.
- [ ] Perform these end-to-end scenarios on the running Herd app:
  1. Formulator without Production Bench: create an unsaved soap and cosmetic formula, print/export them, edit a quantity immediately before export, and send composition data without saving a product.
  2. Portfolio user: use the existing Formula Sheet to review an older saved formula, change its display scale and print/export that exact version. Verify procedure, label text, calculated output and optional analysis remain available in their respective existing outputs.
  3. Costing user: change a price/quantity, leave a different price missing, export and reconcile ingredient plus packaging totals.
  4. Inventory user: filter results while on page two, export all matching rows, print the same lots for a count.
  5. Maker: print manual picking choices, print a planned batch sheet, complete a run and print its record.
  6. Shared-workspace Viewer: use authorized outputs; switch workspace in another tab and verify the original tab cannot download stale workspace data.
- [ ] Open actual XLSX files in a spreadsheet viewer and inspect numeric cells, units, row order, missing data and the two soap composition bases. Open CSV with a normal CSV reader. Print long ingredients/procedures to A4 and Letter, checking repeated headings and unclipped columns.
- [ ] Keep UI strings in existing groups; run `php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php`. Run `npm run build` after JS/Blade changes. Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- [ ] No Filament admin files are planned. If implementation changes any, run `vendor/bin/filacheck --fix` and resolve remaining issues, as required by this repository.
- [ ] Inspect the final diff for persistence in output paths, duplicated formulas, changed compatibility URLs, new dependencies and unrelated changes. Run `graphify update .` after code changes, using the repository's generated-file conventions.
- [ ] Report affected tests and manual checks actually performed, then ask the user to run the complete `php artisan test --compact` suite as required by the repository. Do not describe a partial stage as the completed feature.

## Explicitly outside this plan

No report center, arbitrary report designer, export-options wizard, separate method card, standalone INCI PDF, automatically assembled PIF, “approved/compliant” output, formula-portfolio catalogue, production-calendar/task printouts, production list export, stock-count import, Excel calculation engine, accounting integration, PDF-generation dependency or stored signed document system.

No new production controls appear in the Formula Bench. No new compliance gate is required to export. Existing purchase-order/quotation and legacy batch prints keep their current behavior.

## Completion criteria

A user can get the relevant document from the view containing its information, under a name that describes it. The existing Formula Sheet remains useful for reviewing, scaling, printing and reuse, with its valuable contents preserved and improved. Files reflect the displayed source, make quantities and assumptions understandable, and do not mutate the work. Every shipped action produces a useful finished artifact; nothing in this plan requires a formulator or portfolio user to adopt production management.
