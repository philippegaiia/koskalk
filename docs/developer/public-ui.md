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

## Near-term next work

- add authenticated dashboard behavior when public auth is introduced
- add recipe list and recipe creation entry points
- add formulation workbench route and page shell
- keep small-option controls as ticks, radios, or toggle buttons instead of generic selects
