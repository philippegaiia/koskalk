# Carrier Oil Data Seeder — Design Spec

Last updated: 2026-04-12

## Context

The platform needs a base catalog of ~300 ingredients (carrier oils + essential oils) with full chemical data (fatty acid profiles, SAP, iodine, INS) to power the soap calculator and formulation engine.

This spec covers Phase 1: **carrier oils only**.

## Goal

Seed the platform's carrier oil catalog with:
- INCI (Latin) names
- Fatty acid profiles (lauric, myristic, palmitic, stearic, ricinoleic, oleic, linoleic, linolenic)
- KOH SAP value
- Iodine value
- INS value

## Data Sources

### Primary: Mendrulandia Calculator
The JavaScript at `https://calc.mendrulandia.es/js/o_en.js` contains embedded JSON with ~60 oils and their full chemical profiles. This is the primary data source — no web scraping needed, just parsing embedded data.

### Fallback: SoapCalc
SoapCalc at `https://www.soapcalc.net` may have additional oils not in Mendrulandia. If Mendrulandia doesn't have an oil, check SoapCalc.

### User CSV
The user provides a CSV with common names of oils they want. The system diffs against the DB to find which are missing, then populates them from calculator data.

## Pipeline

```
1. User provides CSV of common names (carrier oils)
       ↓
2. Diff against DB → list missing oils
       ↓
3. Parse Mendrulandia embedded data → lookup by common name
       ↓
4. For matched oils:
       - display_name ← common name from CSV
       - inci_name ← from lookup table (common name → INCI)
       - fatty acid profile ← from calculator data
       - KOH SAP, iodine, INS ← from calculator data
       ↓
5. Insert into DB: ingredients + ingredient_versions + ingredient_version_fatty_acids + ingredient_sap_profiles
```

## INCI Name Enrichment

Mendrulandia data does NOT contain INCI names. A static lookup table maps common names to INCI names for known oils:

```php
[
    'Coconut Oil' => 'Cocos Nucifera',
    'Olive Oil' => 'Olea Europaea',
    'Palm Oil' => 'Elaeis Guineensis',
    'Castor Oil' => 'Ricinus Communis',
    // ... etc
]
```

For oils not in the lookup table, the INCI field is left blank and flagged for manual review.

## Database Write Sequence

For each oil being seeded:

1. **ingredients** — create row with `category = 'carrier_oils'`, `is_potentially_saponifiable = true`
2. **ingredient_versions** — create current version with `display_name`, `inci_name`, `source_file = 'mendrulandia'`
3. **ingredient_version_fatty_acids** — one row per fatty acid present in the profile
4. **ingredient_sap_profiles** — KOH SAP, iodine, INS

## Fatty Acid Set

The platform uses a fixed fatty acid set:
- lauric, myristic, palmitic, stearic, ricinoleic, oleic, linoleic, linolenic

The Mendrulandia data uses numeric keys (p23, p24, etc.). A key mapping table translates these to platform fatty acid keys.

## Diff Output

The diff command should output:
- List of oils found in DB (already imported)
- List of oils from CSV not in DB (need importing)
- List of oils from CSV not found in any calculator data (need manual entry)

## Manual Add (Out of Scope for Phase 1)

After bulk seeding, new carrier oils can be added via Filament admin panel. The fatty acid fields are available in the form for manual entry.

## Future Phases

- Phase 2: Essential oils (with allergen data)
- Allergen data scraping (separate spec)
- User-contributed ingredient requests (separate spec)

## Acceptance Criteria

- [ ] CSV diff correctly identifies missing oils
- [ ] Mendrulandia data correctly parsed and mapped to platform fatty acid keys
- [ ] All fatty acid percentages sum to ~100% (within tolerance) when imported
- [ ] INCI lookup table covers top 50 common carrier oils
- [ ] Seed command is idempotent (re-running doesn't duplicate oils)
- [ ] Oils not found in calculators are flagged for manual entry
