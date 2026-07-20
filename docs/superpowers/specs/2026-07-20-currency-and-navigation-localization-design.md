# Currency and Navigation Localization Design

Date: 2026-07-20

## Goal

Remove generic framework and currency data from the interface translation editor, use maintained currency metadata, and make the authenticated application side menu the first contextually translated interface surface.

The first translated locales are French, Spanish, German, Italian, and Dutch. English remains the canonical source and fallback. Filament remains English-only.

## Scope

This slice covers:

- replacing the manually maintained currency catalogue and currency-name translations;
- limiting interface synchronization to application-owned strings;
- explicitly pruning obsolete or framework-owned database rows;
- adding Dutch to the locale registry;
- reviewing the English side-menu terminology;
- translating the side menu contextually into the five non-English locales;
- verifying locale switching and fallback behavior locally.

It does not translate workbench pages, WordPress content, Laravel validation messages, or the rest of the authenticated application.

## Currency source

Add `symfony/intl` as a direct Composer dependency. A small application service will expose the currency codes, localized names, symbols, and fraction digits needed by the existing selectors and calculations.

The selectable list will contain currencies that Symfony's ICU data identifies as currently active legal tender. A currency already stored on an existing record remains displayable even if it later leaves the selectable list. Unknown or unsupported stored codes fall back safely to the three-letter code.

Currency names, symbols, and fraction digits are reference data, not editorial interface copy. They will not be synchronized into `language_lines`. The existing `lang/en/currencies.php` and manually maintained currency metadata will be retired after all current consumers use the currency service.

## Interface translation ownership

`EnglishTranslationSource` will use an explicit application-owned source definition rather than scanning every English language file indiscriminately.

Initially owned keys are:

- `navigation.*`;
- `number_formats.*`;
- `auth.login.*`;
- `auth.verification.*`;
- `auth.password_requirements`;
- `auth.password_optional_reset`;
- the `public.*` keys still rendered by login, verification, and invitation pages.

Excluded keys include:

- `validation.*`, `pagination.*`, and `passwords.*` from Laravel Lang;
- Laravel's `auth.failed`, `auth.password`, and `auth.throttle` defaults;
- `currencies.*`, which Symfony Intl replaces;
- `homepage.*`, which belongs to the future WordPress site.

Normal `translations:sync` remains additive. An explicit `--prune` option will delete only database rows that are outside the application-owned source definition. The command will report created, existing, and pruned counts. Pruning is never implicit.

## Side-menu English copy

The user-facing menu will use task-focused product terminology:

- `Overview` instead of `Dashboard`;
- `Formulas` instead of `Recipes`;
- `Ingredients`;
- `Packaging` instead of `Packaging Items`;
- `Compliance`;
- `Account`;
- `Settings`;
- `Signed in`;
- `Free account`;
- `Sign out`.

`Admin` remains English-only and outside this localization slice. Its label will not enter the customer interface translation group, and this work will not change its existing visibility behavior.

All menu text, mobile accessibility labels, and the default page heading will use complete `navigation.*` keys rather than hard-coded strings.

## Translation workflow

The English menu is approved before translation. Contextual draft translations will then be written for French, Spanish, German, Italian, and Dutch as navigation labels, not translated word-by-word in isolation.

For this trial, the drafts are stored in the local `language_lines` rows and inspected through the actual menu in each locale. No production translation data is changed during the trial. After the user reviews the rendered result, a separate decision will define how approved database translations are promoted to production without overwriting later Filament edits.

Dutch will be registered with locale code `nl`, number locale `nl_NL`, left-to-right direction, and the next display position. It will only be activated for the local trial once the required navigation keys exist.

## Failure and fallback behavior

- Missing interface translations render the canonical English string.
- Missing currency localization renders the currency code.
- A stored historical currency remains readable but is not offered for a new selection when it is no longer active legal tender.
- Placeholder validation continues to protect translated interface sentences.
- Pruning affects only `language_lines`; Laravel Lang files remain available for framework validation and authentication errors.

## Verification

Tests will prove that:

- only application-owned English keys are synchronized;
- `--prune` removes framework, currency, and homepage rows without removing owned translations;
- currency choices use localized Symfony Intl names and exclude known retired currencies;
- existing historical currency codes remain displayable;
- Dutch is registered correctly;
- the side menu renders English fallback and the five contextual translations;
- the Admin label remains English-only.

Run the focused Pest tests, Pint for modified PHP files, and Graphify update after implementation. Existing unrelated working-tree changes remain untouched.
