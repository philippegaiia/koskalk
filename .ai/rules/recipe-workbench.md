---
paths:
  - 'resources/**/recipe-workbench/**'
---

# Recipe Workbench

## Align workbench values on localized decimals
Workbench percentages render with exactly two decimals from first paint. Soap mass values use the role-aware precision policy below, while cosmetic row weights retain three decimals. Numeric table values use the shared responsive decimal anchor and locale separator; calculations keep their unrounded precision.

## Use role-aware workbench mass precision
Soap mass readouts use the shared unit-aware precision helper: standard oils, lye, dilution liquids, and batch totals show g up to 2 decimals, kg/lb at 3 decimals, and oz at 2 decimals (3 below 1 oz). Formula additions use the addition profile: g/oz at 3 decimals and kg/lb at 4. Keep calculations unrounded and apply precision only when displaying values.

## Round large calculated gram masses
For calculated soap masses—lye, dilution liquids, produced glycerine, wet weight, and cured weight—display no decimals when the selected unit is g and the value is strictly greater than 100. At 100 g or below, retain the standard gram precision. This calculated profile does not change oil-row or addition precision.
