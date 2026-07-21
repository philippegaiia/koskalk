# Translation Work Handoff

Last updated: 2026-07-21

## Goal

Prepare Soapkraft's user-facing application for high-quality translation without translating unstable, sales-like, repetitive, or chemically inaccurate English copy. The Filament admin remains English-only.

## Start here

1. Review and approve English copy before extracting or translating it.
2. Continue reviewing complete authenticated user surfaces. The first index sequence—Ingredients, Packaging, then Products—is complete. The program is not limited to the workbenches.
3. Translate a complete reviewed surface into `fr`, `es`, `de`, `it`, and `nl`, then review each language in the rendered task.

Current working state:

- Branch: `codex/database-interface-translations`
- Local review site: `http://koskalk-translations.test`
- The Ingredients index and Add/Edit Ingredient editor have approved English terminology and 222 interface keys: 95 for the index and 127 for the editor. The editor total includes its conditional Composition, Soap chemistry, Compliance, Soapkraft reference, custom validation, and carrier-oil saponification warning states. Contextual drafts for all five non-English locales are complete in the local `language_lines` database for rendered review.
- The Packaging index and Add/Edit Packaging editor have approved English terminology and 60 interface keys: 40 for the index and 20 for the editor. Contextual drafts for all five non-English locales are complete in the local `language_lines` database for rendered review. Saved packaging names and notes remain user-authored content and are not translated. Laravel and Filament validation and upload messages stay in their framework translation files.
- The Products index has approved product-first English terminology and 48 interface keys. Contextual drafts for all five non-English locales are complete in the local `language_lines` database for rendered review. `Product` is the complete saved item; its formula and packaging are parts of that product. Laravel routes and models may retain `recipe` internally, but that implementation term does not appear on this index.
- The Account page has approved personal-profile, password, plan-usage, and billing copy in 40 interface keys. Developer-facing Paddle configuration messages no longer appear there. Contextual drafts for all five non-English locales are complete in the local `language_lines` database for rendered review. Plan names, descriptions, and prices remain catalog data rather than interface strings.
- The Settings page now contains only display preferences and workspace defaults. Profile and password management remain exclusively in Account. Its 17 Settings keys cover the page structure, Workspace terminology, owner-only guidance, actions, and statuses; shared language and number-format labels remain in their existing translation groups. Contextual drafts for all five non-English locales are complete in the local `language_lines` database for rendered review.
- Use `Workspace` consistently in user-facing copy. The current MVP is owner-only. The schema contains workspace membership and roles, but formula access is still enforced as owner-only in current policies and tests. Do not expose member invitations, workspace switching, or collaboration claims until that contradiction is explicitly resolved. Account owns personal settings; Settings owns display preferences and workspace defaults.
- Ingredient display names, guidance, category taxonomy, and INCI cleanup are not part of that interface pass. Review the ingredient catalog before populating `ingredient_translations`.
- The deployed application works, but its `language_lines` table was reported empty. `translations:sync` will create keys there; it will not copy reviewed local translations.

## Editorial rules

- Use natural, task-focused language. Do not use headers or helper text to sell the product or describe its software architecture.
- Remove repetition between eyebrows, headings, and explanatory paragraphs.
- Keep safety-critical warnings direct and visible.
- Use complete translation keys with placeholders for dynamic sentences. Do not concatenate sentence fragments in Blade, PHP, or JavaScript.
- Establish and maintain a glossary before translating a surface.

Current soap-bench terminology decisions:

- `Saponification` replaces `Core reaction`.
- `Formula additions` replaces `Post-reaction phases`, because cold-process saponification can still be underway when ingredients are added.

## Translation boundaries

- Interface labels, buttons, messages, concise warnings, empty states, and accessibility text use Laravel/Spatie interface translation keys.
- Platform ingredient display names and guidance use `ingredient_translations`.
- Private user-owned ingredients and other user-authored content remain as authored.
- Do not translate INCI, CAS/EC identifiers, SAP values, fatty-acid values, percentages, calculation constants, limits, dates, slugs, IDs, or sourced regulatory nomenclature.
- Interface language and number-format preference remain independent.

## Bench priorities

Review and then translate: navigation, formula settings, ingredient browser, saponification, formula additions, Soapkraft qualities, output, costing, packaging, save/version messages, warnings, empty states, and accessible labels.

The longer Soapkraft-quality explanations should not become a large set of ordinary interface strings. Keep concise labels and states in the bench, then plan translatable contextual help and deeper documentation for scientific interpretation.

## Rollout rules

- English is the canonical fallback.
- `en` is active and default; `fr`, `es`, `de`, `it`, and `nl` are registered but inactive.
- Add or rename English keys, then run `php artisan translations:sync`.
- Keep non-English application strings only in `language_lines`. Do not create locale copies of application files or seed translation values during deployment.
- Draft only blank locale values, preserve reviewed database edits and placeholders, and review translations in the rendered task before activation.
- Contextual drafting is done in this development task and does not require an OpenAI API key or an in-application translation provider.
- Before the first production localization release, explicitly promote the reviewed local `language_lines` values into production. Because production is currently pre-launch and used only by the owner, its interface-translation rows may be replaced once by a complete reviewed export instead of maintaining incremental imports. This applies only to `language_lines`, not to application or user data. After that baseline, ordinary deployments run `translations:sync` only for missing keys and preserve production edits.
- Review both interface and relevant platform ingredient content before activating a locale.
- Never activate incomplete translations.

## Ingredient catalog workflow

- First review the canonical English ingredient records, including names, INCI formatting, guidance, and duplicates.
- The initial production population of `ingredient_translations` should contain the reviewed translations for the approved platform catalog. It is a platform-data import, not an interface translation seed.
- After launch, add translations for a new platform ingredient directly in the production Filament ingredient editor when that ingredient is created or reviewed.
- Private user-owned ingredients remain exactly as authored.

## WordPress context

The CMS will be WordPress, but that work is intentionally deferred while the application interface is reviewed and translated. When WordPress work begins, reproduce the existing Laravel homepage before redesigning it. The source of truth is:

- `resources/views/welcome.blade.php`
- `resources/views/layouts/public.blade.php`
- `lang/en/homepage.php`
- `lang/en/public.php`
- `public/images/public/soapkraft-hero-benches.webp`

WordPress can become the marketing, editorial, and long-form documentation layer. After reproducing the homepage, explicitly decide whether WordPress replaces `/` or begins as a separate site.

## Reference documents

- `docs/developer/localization.md`
- `docs/developer/content-audit.md`
- `docs/superpowers/specs/2026-07-11-language-selector-design.md`
- `docs/superpowers/specs/2026-07-11-platform-ingredient-translations-design.md`
