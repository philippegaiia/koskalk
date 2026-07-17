# Translation Work Handoff

Last updated: 2026-07-17

## Goal

Prepare Soapkraft's user-facing application for high-quality translation without translating unstable, sales-like, repetitive, or chemically inaccurate English copy. The Filament admin remains English-only.

## Start here

1. Review and approve English copy before extracting or translating it.
2. Start with the soap and cosmetic benches, then continue through the authenticated user pages.
3. Translate one inactive locale at a time only after its English source and terminology are stable.

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
- `en` is active and default; `fr`, `es`, `de`, and `it` are registered but inactive.
- Add or rename English keys, then run `php artisan translations:sync`.
- Review both interface and relevant platform ingredient content before activating a locale.
- Never activate incomplete translations.

## WordPress context

The user is building the WordPress marketing site. Reproduce the existing Laravel homepage before redesigning it. The source of truth is:

- `resources/views/welcome.blade.php`
- `resources/views/layouts/public.blade.php`
- `lang/en/homepage.php`
- `lang/en/public.php`
- `public/images/public/soapkraft-hero-benches.webp`

WordPress can become the marketing and long-form documentation layer. Confirm at the start of the next conversation whether it replaces the Laravel homepage or is initially a separate site.

## Reference documents

- `docs/developer/localization.md`
- `docs/developer/content-audit.md`
- `docs/superpowers/specs/2026-07-11-language-selector-design.md`
- `docs/superpowers/specs/2026-07-11-platform-ingredient-translations-design.md`
