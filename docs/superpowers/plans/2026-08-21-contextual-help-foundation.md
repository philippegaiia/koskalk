# Contextual Help Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build one localized contextual-help panel that authenticated Soapkraft pages can populate without a runtime WordPress request or a Livewire mutation.

**Architecture:** Structured English topics live in domain language files. A source service validates them, a resolver localizes only each page's declared subset, and a Blade registry serializes that subset. One Alpine-powered panel in `app-shell` reads the registry and opens from reusable direct-topic or page-index triggers.

**Tech Stack:** Laravel 13, Blade, Laravel Translator, Spatie Translation Loader, Filament 5, Alpine.js, Tailwind CSS 4, Pest 4, Node.js, Vite 8.

---

## File map

**Create:**

- `config/contextual-help.php`
- `app/Services/ContextualHelp/ContextualHelpTopicSource.php`
- `app/Services/ContextualHelp/ContextualHelpTopicResolver.php`
- `app/Rules/SoapkraftDocumentationUrl.php`
- `app/View/Components/ContextualHelp/Topics.php`
- `resources/views/components/contextual-help/topics.blade.php`
- `resources/views/components/contextual-help/trigger.blade.php`
- `resources/views/components/contextual-help/panel.blade.php`
- `resources/js/contextual-help.js`
- `lang/en/contextual_help.php`
- `tests/Feature/ContextualHelpContentTest.php`
- `tests/Feature/ContextualHelpRenderingTest.php`
- `tests/Unit/ContextualHelpInteractionTest.php`

**Modify:**

- `config/interface-translations.php`
- `app/Services/Translations/InterfaceTranslationCatalogue.php`
- `app/Filament/Resources/InterfaceTranslations/Schemas/InterfaceTranslationForm.php`
- `resources/views/layouts/app-shell.blade.php`
- `resources/js/app.js`
- `resources/css/app.css`
- `database/seeders/data/interface-translations.json`

### Task 1: Define the English topic contract

**Files:** `config/contextual-help.php`, `app/Services/ContextualHelp/ContextualHelpTopicSource.php`, `tests/Feature/ContextualHelpContentTest.php`

- [ ] **Step 1: Generate the class and failing Pest test**

```bash
php artisan make:class Services/ContextualHelp/ContextualHelpTopicSource --no-interaction
php artisan make:test --pest ContextualHelpContentTest --no-interaction
```

- [ ] **Step 2: Test discovery and invalid content**

Use a fixture topic and assert:

```php
expect($source->all())
    ->toHaveKey('help_soap_workbench.saponification.water_mode')
    ->and($source->get('help_soap_workbench.saponification.water_mode'))
    ->toMatchArray([
        'title' => 'Water calculation mode',
        'summary' => 'Choose the relationship used to calculate the dilution liquid.',
    ]);
```

Add datasets rejecting missing `title`, missing `summary`, unknown fields, titles over 80 characters, summaries over 280 characters, and `what_to_do` or `why` over 600 characters.

- [ ] **Step 3: Verify the red test**

Run: `php artisan test --compact tests/Feature/ContextualHelpContentTest.php`

Expected: FAIL because the source contract is absent.

- [ ] **Step 4: Add configuration and implementation**

`config/contextual-help.php` must define these groups:

```php
'groups' => [
    'help_workbench',
    'help_soap_workbench',
    'help_cosmetic_workbench',
    'help_production_planning',
    'help_production_execution',
    'help_production_inventory',
    'help_production_purchasing',
    'help_production_setup',
    'help_ingredients',
    'help_compliance',
    'help_settings',
],
'required_fields' => ['title', 'summary'],
'optional_fields' => ['what_to_do', 'why', 'article_url'],
'limits' => ['title' => 80, 'summary' => 280, 'what_to_do' => 600, 'why' => 600],
'documentation_host' => 'soapkraft.com',
```

`ContextualHelpTopicSource` exposes:

```php
/** @return array<string, array{title: string, summary: string, what_to_do?: string, why?: string, article_url?: string}> */
public function all(): array;

/** @return array{title: string, summary: string, what_to_do?: string, why?: string, article_url?: string}|null */
public function get(string $topicKey): ?array;
```

It loads only configured groups, recursively finds topic arrays, validates exact fields and lengths, prefixes keys with the group, sorts the result, and memoizes it for the request. Invalid topics throw `InvalidArgumentException` naming the key and field.

- [ ] **Step 5: Verify green and commit**

Run: `php artisan test --compact tests/Feature/ContextualHelpContentTest.php`

Expected: PASS.

```bash
git add config/contextual-help.php app/Services/ContextualHelp/ContextualHelpTopicSource.php tests/Feature/ContextualHelpContentTest.php
git commit -m "feat: define contextual help topic source"
```

### Task 2: Resolve locale content without falling back guide URLs

**Files:** `app/Services/ContextualHelp/ContextualHelpTopicResolver.php`, `tests/Feature/ContextualHelpContentTest.php`

- [ ] **Step 1: Generate the resolver and test the locale rules**

Run: `php artisan make:class Services/ContextualHelp/ContextualHelpTopicResolver --no-interaction`

Create a French title translation but no French URL, then assert:

```php
$topics = $resolver->resolve([
    'help_soap_workbench.saponification.water_mode',
], locale: 'fr');

expect($topics['help_soap_workbench.saponification.water_mode']['title'])
    ->toBe('Mode de calcul de l’eau')
    ->and($topics['help_soap_workbench.saponification.water_mode']['summary'])
    ->toBe('Choose the relationship used to calculate the dilution liquid.')
    ->and($topics['help_soap_workbench.saponification.water_mode']['article_url'])
    ->toBeNull();
```

Add a French `article_url` and prove it is then returned. Also test de-duplication, unknown-key omission, and local/testing warning logs.

- [ ] **Step 2: Verify the red test**

Run: `php artisan test --compact tests/Feature/ContextualHelpContentTest.php --filter="resolves requested contextual help"`

Expected: FAIL because the resolver is missing.

- [ ] **Step 3: Implement the resolver contract**

Inject `ContextualHelpTopicSource` and `Illuminate\Contracts\Translation\Translator`. Expose:

```php
/** @return array<string, array{title: string, summary: string, what_to_do: ?string, why: ?string, article_url: ?string}> */
public function resolve(array $topicKeys, ?string $locale = null): array;
```

Resolve text fields with Laravel fallback. Resolve `article_url` with:

```php
$translatedUrl = $this->translator->get("{$topicKey}.article_url", [], $locale, false);
```

Convert a returned key to `null`. Never fetch WordPress content.

- [ ] **Step 4: Verify green and commit**

Run: `php artisan test --compact tests/Feature/ContextualHelpContentTest.php`

```bash
git add app/Services/ContextualHelp/ContextualHelpTopicResolver.php tests/Feature/ContextualHelpContentTest.php
git commit -m "feat: resolve localized contextual help topics"
```

### Task 3: Validate documentation links at every entry point

**Files:** `app/Rules/SoapkraftDocumentationUrl.php`, `ContextualHelpTopicSource.php`, `InterfaceTranslationCatalogue.php`, `InterfaceTranslationForm.php`, and their feature tests.

- [ ] **Step 1: Generate the rule and test its exact boundary**

Run: `php artisan make:rule SoapkraftDocumentationUrl --no-interaction`

The dataset accepts `https://soapkraft.com/docs/soap/water-calculation-modes/` and its anchor form. It rejects HTTP, external domains, `docs.soapkraft.com`, and lookalike hosts such as `soapkraft.com.example.org`. Add catalogue tests proving a guide-URL row may contain a sorted subset of configured locales while ordinary text rows still require every locale.

- [ ] **Step 2: Verify the red tests**

Run: `php artisan test --compact tests/Feature/ContextualHelpContentTest.php tests/Feature/InterfaceTranslationCatalogueTest.php`

Expected: FAIL for URL validation.

- [ ] **Step 3: Apply the rule consistently**

Require scheme `https` and host exactly `soapkraft.com`. Apply the rule to English `article_url` fields, catalogue values where the group starts with `help_` and the key ends with `.article_url`, and the same conditional fields in the Filament editor. Retain placeholder validation. In `InterfaceTranslationCatalogue`, allow a partial locale map only for those guide-URL keys. Update the catalogue-completeness test so ordinary content remains complete while guide URLs validate only locale values that are present.

- [ ] **Step 4: Verify and commit**

Run:

```bash
php artisan test --compact tests/Feature/ContextualHelpContentTest.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/InterfaceTranslationFoundationTest.php
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
```

Expected: PASS with no unresolved Filament issue.

```bash
git add app/Rules/SoapkraftDocumentationUrl.php app/Services/ContextualHelp/ContextualHelpTopicSource.php app/Services/Translations/InterfaceTranslationCatalogue.php app/Filament/Resources/InterfaceTranslations/Schemas/InterfaceTranslationForm.php tests/Feature/ContextualHelpContentTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
git commit -m "feat: validate contextual help guide links"
```

### Task 4: Render page registries and reusable triggers

**Files:** `app/View/Components/ContextualHelp/Topics.php`, two component views, `tests/Feature/ContextualHelpRenderingTest.php`

- [ ] **Step 1: Generate components and test**

```bash
php artisan make:component ContextualHelp/Topics --no-interaction
php artisan make:component ContextualHelp/Trigger --view --no-interaction
php artisan make:test --pest ContextualHelpRenderingTest --no-interaction
```

- [ ] **Step 2: Test the contracts before implementation**

Assert the registry has `type="application/json"`, `data-contextual-help-topics`, and only requested topics. Assert a direct trigger has `type="button"`, `data-contextual-help-topic`, `aria-controls="contextual-help-panel"`, and no `wire:click`. Assert an index trigger contains `data-contextual-help-index` plus its ordered JSON keys.

- [ ] **Step 3: Implement registry and trigger components**

The topics component injects the resolver and renders safe JSON using all four `JSON_HEX_*` flags plus `JSON_THROW_ON_ERROR`. The trigger supports exactly:

```blade
<x-contextual-help.trigger topic="help_soap_workbench.saponification.water_mode" />
<x-contextual-help.trigger :topics="$pageHelpTopics" variant="text" />
```

The icon variant has a localized name and 44 by 44 pixel target. Neither variant contains navigation, inline JavaScript, or Livewire mutation directives.

- [ ] **Step 4: Verify and commit**

Run: `php artisan test --compact tests/Feature/ContextualHelpRenderingTest.php`

```bash
git add app/View/Components/ContextualHelp/Topics.php resources/views/components/contextual-help/topics.blade.php resources/views/components/contextual-help/trigger.blade.php tests/Feature/ContextualHelpRenderingTest.php
git commit -m "feat: add contextual help registries and triggers"
```

### Task 5: Build the shared panel and interaction controller

**Files:** panel Blade view, `resources/js/contextual-help.js`, shell, `app.js`, `app.css`, rendering and interaction tests.

- [ ] **Step 1: Generate a Node-backed Pest test**

Run: `php artisan make:test --pest --unit ContextualHelpInteractionTest --no-interaction`

Follow `MediaAssetPickerAccessibilityContractTest`. Prove direct opening, ordered page index, Back behavior, topic replacement, unknown-key refusal, Escape focus restoration, mobile focus wrapping, and absence of `fetch()` and Livewire mutation calls.

- [ ] **Step 2: Verify the red tests**

Run: `php artisan test --compact tests/Unit/ContextualHelpInteractionTest.php tests/Feature/ContextualHelpRenderingTest.php`

- [ ] **Step 3: Implement the JavaScript public API**

```js
export function createContextualHelp() {
    return {
        isOpen: false,
        isMobileViewport: false,
        view: 'topic',
        activeTopic: null,
        pageTopicKeys: [],
        announcement: '',
        lastTrigger: null,
        init() {},
        openFromEvent(event) {},
        openTopic(topicKey) {},
        openIndex(topicKeys) {},
        selectTopic(topicKey) {},
        showIndex() {},
        close() {},
        syncViewport() {},
        handleKeydown(event) {},
        focusableElements() {},
    };
}

export function initializeContextualHelpTriggers() {}
```

The initializer installs one delegated listener for trigger data attributes. The state object reparses current topic-registry scripts when opening, so Livewire-rendered content remains current without another request.

- [ ] **Step 4: Build and mount the panel**

Mount `<x-contextual-help.panel />` once inside `[data-app-shell]`. Include desktop `complementary` and mobile `dialog` semantics, mobile-only `aria-modal`, visible Close, conditional Back, topic index, optional sections, optional external guide link, a polite atomic live region, and the mobile overlay. Add no form and no `wire:*` mutation.

Expose `window.contextualHelp` in `app.js` and initialize delegated triggers idempotently on `DOMContentLoaded` and `livewire:navigated`.

- [ ] **Step 5: Add responsive and reduced-motion styles**

Use a desktop overlay width of `min(24rem, calc(100vw - 2rem))` without changing the shell grid. Mobile fills the viewport. Animate only transform and opacity, and disable transitions for reduced motion.

- [ ] **Step 6: Verify and commit**

```bash
php artisan test --compact tests/Unit/ContextualHelpInteractionTest.php tests/Feature/ContextualHelpRenderingTest.php
npm run build
git add resources/views/components/contextual-help/panel.blade.php resources/views/layouts/app-shell.blade.php resources/js/contextual-help.js resources/js/app.js resources/css/app.css tests/Unit/ContextualHelpInteractionTest.php tests/Feature/ContextualHelpRenderingTest.php
git commit -m "feat: add accessible contextual help panel"
```

### Task 6: Localize panel chrome and register help groups

**Files:** `lang/en/contextual_help.php`, `config/interface-translations.php`, translation catalogue, content test.

- [ ] **Step 1: Test ownership and required chrome keys**

Require `contextual_help` plus every configured domain group in `interface-translations.sources`. Require actions for Help, Close, Back, Read the full guide, and opens in a new tab; an index title; What to do and Why headings; panel and topic-change accessibility text.

- [ ] **Step 2: Add English chrome and source ownership**

Write concise English values and add each source with `['*']`. Future domain files may be absent because `EnglishTranslationSource` skips missing files.

- [ ] **Step 3: Synchronize and review translations**

Run: `php artisan translations:sync`

Populate only blank values for `de`, `es`, `fr`, `it`, `nl`, and `pt_BR`. Review desktop and mobile rendering, then run `php artisan translations:catalogue:export`.

- [ ] **Step 4: Run final foundation verification**

```bash
php artisan test --compact tests/Feature/ContextualHelpContentTest.php tests/Feature/ContextualHelpRenderingTest.php tests/Feature/InterfaceTranslationFoundationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Unit/ContextualHelpInteractionTest.php
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
npm run build
git diff --check
```

Expected: all tests pass, checks are clean, and Vite builds.

- [ ] **Step 5: Commit localization**

```bash
git add lang/en/contextual_help.php config/interface-translations.php database/seeders/data/interface-translations.json tests/Feature/ContextualHelpContentTest.php
git commit -m "feat: localize contextual help panel"
```
