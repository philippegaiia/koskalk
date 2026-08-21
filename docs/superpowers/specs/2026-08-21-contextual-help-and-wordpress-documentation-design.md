# Contextual Help and WordPress Documentation Design

**Date:** 2026-08-21

## Purpose

Add contextual help to Koskalk without making its dense formulation and production surfaces heavier. The application must answer three questions close to the current task:

1. What does this mean?
2. What should I do next?
3. Why did this result, warning, or status appear?

Complete methods, examples, and editorial explanations remain in WordPress. The application provides the concise answer and a precise link to the relevant WordPress article or article section.

## Audience

The help system serves a mixed audience, from beginners to experienced formulators and production operators. Experienced users must be able to ignore help without losing workbench space. Beginners must be able to discover definitions, next actions, and result explanations without leaving the current task immediately.

## Content Ownership

Koskalk owns:

- concise task-focused interface copy;
- contextual definitions and next-action guidance;
- state-aware explanations for results and statuses;
- visible safety and compliance warnings;
- localized links to published WordPress documentation.

WordPress owns:

- complete methods and workflows;
- worked examples;
- scientific and compliance methodology;
- assumptions, limitations, and editorial material;
- long-form getting-started and task-based guides.

The side panel does not reproduce full WordPress articles. WordPress availability never controls whether concise in-app help can render.

## Interaction Model

Use one shared contextual-help panel across the authenticated application.

On desktop, the panel opens from the right and overlays the page without shrinking tables or workbench columns. It is non-modal, so the underlying page remains interactive. Opening another help topic replaces the panel content without closing it.

On mobile, the same content opens as a full-screen modal sheet. The close action remains visible while its content scrolls.

Each topic may contain:

- a title;
- a concise summary;
- an optional `What to do` section;
- an optional `Why this happens` section;
- an optional `Read the full guide` link.

The panel displays only fields that contain content. It does not show empty headings.

## Trigger Hierarchy

Use help at four levels:

1. **Page or tab:** a visible Help action lists topics relevant to the current page or active workbench tab.
2. **Section:** one Help action beside a consequential section heading, such as Saponification, Soap qualities, Formula phases, or Stock preparation.
3. **Field:** a selective trigger for an unfamiliar or consequential choice, such as water mode, lye purity, formula basis, or product application context.
4. **State:** a `Why?` action attached to a result, warning, shortage, or production status opens the precise explanation for that state.

Do not add a question-mark icon beside every label. Do not use hover-only tooltips for substantive help. Critical warnings and immediate corrective actions remain visible in the workflow.

## Topic Identity

Every topic has a stable semantic key. Keys describe the user concept rather than a Blade file or database column.

Examples:

```text
help_soap_workbench.saponification.water_mode
help_soap_workbench.quality.dos_risk
help_cosmetic_workbench.formula.phase_basis
help_cosmetic_workbench.labeling.ingredient_order
help_production_planning.status.scheduled
help_production_execution.stock_preparation.shortage
```

Dynamic interfaces select a precise static topic key from their current state. Translation content does not calculate business state.

## Domain-Scoped Translation Groups

Keep contextual help separate from ordinary interface copy and divide it by user domain. Do not create one global `help.php` file.

Initial groups:

```text
lang/en/help_workbench.php
lang/en/help_soap_workbench.php
lang/en/help_cosmetic_workbench.php
lang/en/help_production_planning.php
lang/en/help_production_execution.php
lang/en/help_production_inventory.php
lang/en/help_production_purchasing.php
lang/en/help_production_setup.php
lang/en/help_ingredients.php
lang/en/help_compliance.php
lang/en/help_settings.php
```

`help_workbench.php` contains only content genuinely shared by soap and cosmetic workbenches, including ingredient selection, units, packaging, costing, saving, locking, instructions, and media.

Soap help owns saponification, lye, water, superfat, fatty acids, soap qualities, cured-basis output, and soap-specific labeling. Cosmetic help owns phases, total-formula basis, ingredient functions, product application context, cosmetic output, and cosmetic-specific labeling.

Production help is split by workflow because Production Bench is both dense and stateful. It must not become another monolithic translation group.

## Translation Workflow

English remains version-controlled in `lang/en/<group>.php`. Add each help group to `config/interface-translations.php` so the existing translation synchronizer owns its keys.

Non-English help uses the existing DB-backed `language_lines` workflow and the Filament Interface Translations resource. Do not create parallel `lang/<locale>/` help files.

`translations:sync` remains additive. It creates missing help keys without translating them, clearing existing locale values, or treating an English wording change as a new key. A minor English edit that preserves meaning keeps the same key and requires review of the existing translations. A change to the meaning, instruction, warning, or consequence uses a new key or deliberately clears every affected locale value before retranslation.

Draft translations for every configured catalogue locale only after the English topic is approved. Save drafts only into blank locale values, review them in the rendered panel, export the reviewed catalogue, and commit it with the English source. The translation catalogue import follows the existing deployment mode: authoritative during the current pre-launch phase and preserve-existing after production edits become authoritative.

The current application locale resolves the panel content. Missing values use the existing English fallback. An untranslated topic never produces an empty or broken panel.

The topic's WordPress URL is locale-specific. It may be translated alongside the topic so each locale can target its published article permalink. The guide link remains absent until that URL exists.

Ordinary help text continues to use the catalogue's complete-locale rule. `article_url` is the only partial-locale exception: its catalogue row may contain only the locales whose WordPress articles are published. Every URL that is present must pass the same Soapkraft HTTPS validation. An empty or missing locale value does not fall back to English.

### Translation style

- Translate the intended meaning and action rather than the English sentence structure.
- Maintain a reviewed glossary for soapmaking, cosmetic formulation, compliance, inventory, purchasing, and production terminology.
- Keep the same domain term across interface copy, contextual help, and WordPress documentation in each locale.
- Preserve placeholders, quantities, negations, warning severity, and regulatory meaning.
- Keep INCI names, CAS and EC identifiers, SAP values, formulas, stable topic keys, and controlled nomenclature unchanged.
- Do not invent scientific, safety, production, or regulatory claims.
- Review every locale in the actual panel and task state rather than as an isolated translation list.

The Humanizer skill may be used as a final editorial pass on approved English help and English WordPress drafts. It may remove robotic structure, repetition, or promotional language, but it must preserve controlled vocabulary, scientific meaning, safety warnings, and production consequences. Do not apply the English-focused skill mechanically to non-English translations.

## WordPress Linking

`Read the full guide` targets the precise article or section, never the documentation homepage.

Examples:

```text
formula water mode
→ https://soapkraft.com/docs/soap/water-calculation-modes/

lye concentration
→ https://soapkraft.com/docs/soap/water-calculation-modes/#lye-concentration
```

Several help topics may link to different anchors in the same article. The link opens in a new tab so unsaved application work remains in place. Its label and external-link treatment must make that behavior clear.

Resolve a guide URL only for the active locale, without Laravel's English fallback. If the localized article is unpublished, omit the guide link rather than sending the user to the English article. The concise app topic may still fall back to English and remains available.

## Delivery Architecture

Mount one shared help panel in the authenticated app shell. A trigger opens the panel with its semantic topic key.

Each page declares only the topics it can open. Workbench tab configuration also declares the topics listed by its page-level Help action. The server resolves current-locale content for those keys and includes only that subset in the page payload.

Opening and switching topics is client-side. It requires no Livewire request and no WordPress request. Opening or closing the panel never mutates formula state, production state, inventory, or unsaved-change tracking.

Conceptual flow:

```text
page or tab topic declaration
    → localized topic subset
    → semantic help trigger
    → shared contextual-help panel
    → optional localized WordPress deep link
```

Koskalk adopts the stable topic-ID and per-surface association principles used by Cosmood's contextual knowledge base. It does not copy Cosmood's local Markdown knowledge base or Filament knowledge-base plugin because WordPress is Koskalk's long-form documentation authority and Koskalk's customer application is not a Filament panel.

## Production Bench Requirements

Production Bench is part of the first contextual-help release stream. Its help must explain operational consequences, not merely define labels.

### Planning

Cover draft versus scheduled state, production dates, batch sizing, flash planning, calendar capacity, planning references, assigned batch numbers, and reservation readiness.

### Execution

Cover stock preparation, automatic and manual lot allocation, shortages, starting work, consumption, tasks, journal entries, completion, aborting, readiness delay, and output release.

### Inventory

Cover physical stock, reserved stock, available stock, lots, expiry, adjustments, negative stock, manufactured output, and the difference between a requirement and an allocation.

### Purchasing

Cover suppliers, supplier listings, purchase units, supplier currencies, procurement, purchase orders, goods receipts, conversions, and inventory costing consequences.

### Setup

Cover batch-size presets, numbering, task sets, departments, employees, task types, and production calendars.

For a state-aware production topic, explain:

1. what the current state means;
2. what action is available next;
3. what that action changes in stock, tasks, or production state;
4. what cannot be reversed safely.

Consequential actions keep their visible confirmation and consequence copy. The help panel supplements those controls.

## Accessibility and Responsive Behavior

Desktop behavior:

- expose the panel as a named complementary region rather than a modal dialog;
- keep the page interactive while the panel is open;
- announce topic changes to assistive technology;
- close on Escape and return focus to the last trigger;
- animate with transform and opacity only;
- honor reduced-motion preferences.

Mobile behavior:

- expose the full-screen sheet as a modal dialog;
- trap focus until it closes;
- keep its close control visible;
- return focus to the trigger after closing.

All triggers require accessible names and visible keyboard focus. Icon-only triggers require at least a 44 by 44 pixel touch target. Section-level triggers may use the visible word `Help` when that improves clarity.

## Failure Handling

- Missing translated content falls back to English.
- Missing WordPress URLs hide the guide link.
- Unknown topic keys do not open a blank panel and are reported in development logs.
- WordPress failures do not affect in-app help because the application does not fetch articles at runtime.
- Safety and compliance warnings remain inline even when help content is unavailable.

## Rollout

Implement the shared infrastructure once, then add content in bounded slices:

1. shared contextual-help panel, triggers, topic declarations, translation ownership, and WordPress link behavior;
2. Soap workbench;
3. Cosmetic workbench;
4. Production planning and execution;
5. Production inventory and purchasing;
6. Production setup;
7. Ingredients and compliance;
8. remaining shared workbench and settings surfaces.

Each content slice includes its concise application topics and matching WordPress articles. The initial infrastructure must support every domain, but the first release does not wait for every application field to receive help.

## Verification

Automated coverage must prove:

- every registered topic resolves in English;
- active locales resolve translated content or the English fallback;
- domain groups cannot declare duplicate semantic topics;
- pages send only their declared topics to the browser;
- Soap and Cosmetic workbenches cannot resolve each other's specialized topics accidentally;
- production states and warnings select the correct state-aware topics;
- missing WordPress URLs hide the guide link;
- localized guide links target the expected article or anchor;
- opening help does not mutate formula, production, inventory, or unsaved state;
- keyboard and pointer triggers open and replace topics correctly;
- Escape, close controls, focus restoration, and mobile focus trapping work;
- reduced-motion behavior removes the transition;
- headings, live announcements, labels, and external-link treatment are accessible.

Content validation must require a title and summary, enforce concise length limits, validate published guide links as HTTPS URLs on `soapkraft.com`, and reject empty published topics.

## Non-Goals

- No WordPress API or runtime article fetch.
- No local Laravel copy of the full WordPress documentation.
- No AI assistant or documentation search in the first release.
- No tooltip beside every field.
- No permanent third workbench column.
- No change to formula calculations, production lifecycle rules, inventory logic, or authorization.
- No requirement to finish documentation for every application surface before shipping the first contextual-help slice.
