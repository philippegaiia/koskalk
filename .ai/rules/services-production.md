---
paths:
  - 'app/Services/Production/**'
---

# Services Production

## Build manufacturing records from captured facts
Production printouts are read-only. Capture sanitized procedure content when creating the formula snapshot and lot identity when completing or aborting under the existing locks. Terminal records use those captured facts, never today's recipe, ingredient prices or lot labels; preserve execution metadata in explicit backfills and identify missing legacy facts without print-time backfill. Configure the sanitizer for the full captured input: its default length limit silently truncates procedures.
