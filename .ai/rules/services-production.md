---
paths:
  - 'app/Services/Production/**'
---

# Services Production

## Build manufacturing records from captured facts
Production printouts are read-only. Capture sanitized procedure content when creating the formula snapshot and lot identity when completing or aborting under the existing locks. Terminal records use those captured facts, never today's recipe, ingredient prices or lot labels; preserve execution metadata in explicit backfills and identify missing legacy facts without print-time backfill. Configure the sanitizer for the full captured input: its default length limit silently truncates procedures.

## Batch printouts group frozen materials and inline lot evidence
Batch printouts use captured formula phases: soap oils, lye/liquids, additives and fragrances in order; cosmetic phases retain their authored order. Put active reservations beside their material on batch sheets, or frozen consumption lots on terminal records; packaging has its own table. Retain unmatched historical consumption separately. Omit source formula version from the print header. Format percentages at 2 decimals, masses at 2–4 like formula sheets, and counts at 0, without rounding stored facts.
