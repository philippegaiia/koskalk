---
paths:
  - 'resources/views/livewire/dashboard/partials/recipe-workbench/**'
---

# Partials Recipe Workbench

## Keep the soap ingredient rail row-bounded
At the desktop workbench breakpoint, keep the ingredient selector and soap fatty-acid profile inside the same sticky wrapper. The wrapper is bounded by the formula grid row, so it stops with the formula content. Do not make the ingredient selector independently sticky; the fatty-acid panel will scroll underneath and overlap it.

## Keep the lye and water summary flat
Inside the soap Oils card, render the Lye & water heading directly above the compact calculated-value cards. Do not wrap this summary in another sk-card or sk-inset container; preserve the individual value cards for scanability.
