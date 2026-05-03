# Public UI

Last updated: 2026-03-23

## Current architecture

The public app is intentionally separate from Filament.

Current public routes:

- `/`
- `/dashboard`

Current controllers:

- `App\Http\Controllers\HomeController`
- `App\Http\Controllers\DashboardController`

Current shared layouts:

- `resources/views/layouts/public.blade.php`
- `resources/views/layouts/app-shell.blade.php`

## Design direction

The public UI uses fully personalized Tailwind, not Flux as a foundation.

Reasoning:

- the product is a formulation workspace, not a generic SaaS back office
- the formulation page will need dense custom layouts and interactions
- the dashboard should feel connected to that workbench, not like a separate starter-kit app

## What the current shell is for

The current home page and dashboard are not the full product.

They provide:

- shared visual language
- navigation structure
- spacing and density direction
- a place to evolve into the real recipe and formulation experience

## Accessibility implementation

All public Blade views follow a mandatory accessibility checklist. These rules are codified in CLAUDE.md and must be applied to every new or modified Blade file without requiring a separate audit.

### Labels
Every `<input>` and `<select>` must have a programmatic label:
- Static fields: `aria-labelledby="label-id"` where the visible label `<p>` or `<label>` carries the matching `id`
- Dynamic fields inside Alpine loops: `:aria-label="'Percentage for ' + row.name"` or similar

### ARIA roles by component type

| Component | Required ARIA |
|-----------|--------------|
| Tab navigation | Container `role="tablist"`, buttons `role="tab" :aria-selected`, panels `role="tabpanel" aria-labelledby` |
| Toggle pill groups | Container `role="radiogroup" aria-label"`, buttons `role="radio" :aria-checked` |
| Modals | `role="dialog" aria-modal="true" aria-labelledby="heading-id"` |
| Progress bars | `role="progressbar" :aria-valuenow="..." aria-valuemin="0" aria-valuemax="100" :aria-label` |
| Sections | `aria-labelledby` pointing to the section heading |
| Scrollable lists | `role="region" aria-label` |
| Status messages | `role="status"` for confirmations/counts, `role="alert"` for warnings/errors |
| Icon-only buttons | `aria-label` describing the action |

### Touch targets
- Toggle pills: `py-2.5` minimum (~36px height)
- Modal buttons: `py-2.5` minimum
- Small buttons in tight grids: at least `py-2`

### Font size
- Minimum `text-xs` (12px). Never use `text-[11px]` or smaller.

### Disabled/placeholder links
- `aria-disabled="true" tabindex="-1" title="Coming soon"`
- `href="javascript:void(0)"` to prevent navigation

## Near-term next work

- add authenticated dashboard behavior when public auth is introduced
- add recipe list and recipe creation entry points
- add formulation workbench route and page shell
- keep small-option controls as ticks, radios, or toggle buttons instead of generic selects
