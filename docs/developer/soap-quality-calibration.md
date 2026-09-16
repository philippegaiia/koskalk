# Soap quality calibration

Model version: `2026-09-13` — first empirical pass.

## Purpose and evidence

These indices help compare formulations. They are not laboratory measurements, probabilities of defects, percentages of cleansing efficacy, or clinical skin-tolerance ratings. The normal reference is an approximately four-week NaOH bar, made with ordinary oils within their useful shelf life. Cure conditions, process temperature, gel phase, dimensions, storage, wash conditions, and individual skin response are not inputs.

The product owner's practical observations supplied the score anchors and desired relative behavior. The coefficients below are an explicit fit to those observations, not constants established by the cited literature.

Supporting reading:

- [Kevin Dunn, The Water Discount](https://www.soapguild.org/tools-and-resources/resource-center/164/the-water-discount/) reports a controlled water/firmness experiment, including an olive-only soap that was harder than his mixed-oil soap. This supports separating physical hardness from traditional saturated-fat totals; it does not establish our exact high-oleic curve or an 80/20 olive/coconut comparison.
- [Classic Bells, Dual lye](https://classicbells.com/soap/dualLye.asp) describes the practical solubility and lather effects of potassium soap. Our numerical KOH multipliers remain provisional.
- [Classic Bells, Curing soap](https://classicbells.com/soap/cure.asp) discusses water loss and changes during cure. Our 30% concentration region and shrinkage index are practical calibration choices.
- [Kevin Dunn, The Dreaded Orange Spot](https://cavemanchemistry.com/DreadedOrangeSpot-Dunn.pdf) investigates oxidation and additives under specific test conditions. It does not justify a universal protection multiplier for an ingredient category.

## Cleansing strength

Input fatty-acid percentages are weighted by oil mass. Ingredient names do not enter the formula. Missing acids remain missing; the engine does not silently renormalize incomplete profiles.

Let `S` be non-negative superfat in percentage points and:

```text
Q = lauric + myristic + capric + 0.65 × caprylic + 0.35 × caproic
B = 100 × min(1, (max(0, Q) / 76.62)^0.62)
cleansing_strength = B × (1 − S / 100)^3.9
superfat_buffer = B − cleansing_strength
```

The reference `76.62` is the weighted quick-acid content of the captured coconut profile: caproic 0.2%, caprylic 7%, capric 8%, lauric 48%, myristic 16%. A different oil with those same acids receives the same result. A more concentrated quick-acid profile can also reach 100. This is a bounded comparison scale, not a claim that coconut is chemically identical to pure lauric acid.

Normalization happens **before** the superfat multiplier. The previous model could retain a hidden raw score above 100, leaving the visible score at 100 despite added superfat. This pass removes that plateau. The superfat curve is a perceived-behavior calibration, not an estimate of which fatty acids remain unsaponified.

| Benchmark | Superfat | Expected cleansing |
| --- | ---: | ---: |
| Reference coconut fatty-acid profile | 0% | 100 |
| Same profile | 5% | 81.9 |
| Same profile | 20% | 41.9 |
| 24–25% lauric/myristic, remainder non-quick acids | 5% | About 40 |

Shorter-chain weighting means that equal **unweighted** quick-acid totals can differ slightly. The suggested everyday body-bar range is 0–40; higher values are permitted. A low value describes a low quick-cleansing contribution, not an inability to clean. Mildness and conditioning remain secondary heuristic indices and are not validated skin-safety claims.

## Physical structure, liquid, and superfat

`VS` contains C6–C14 saturated acids, including caproic. `HS` contains palmitic, stearic, arachidic, behenic, and lignoceric. `MU`, `PU`, and `SP` retain the existing monounsaturated, polyunsaturated, and ricinoleic groups.

Let `F = max(0, (S − 2) × 0.8)`, `L/O` be total dilution-liquid mass divided by oil mass, and `k` be the KOH substitution fraction from 0 to 1:

```text
W = clamp((0.38 − L/O) × 30, −6, 6)
K = 1 − 0.60 × k
unmolding_firmness = clamp((0.85 VS + 1.25 HS − 0.25 MU − F + W + 18) × K, 0, 100)
cured_hardness = clamp((1.35 HS + 0.55 VS + 0.20 MU − 0.50 PU − 0.45 F + 0.5 W + H + 8) × K, 0, 100)
longevity = clamp((0.85 HS + 0.18 VS − 0.35 SP − 0.30 PU − 0.70 F + 20) × K, 0, 100)
```

The old narrow, triangular unmolding structure bonus is removed. Stronger HS weights give palmitic/stearic-rich formulas more firmness. The cured-hardness `H` term captures the practical high-oleic exception:

```text
smooth(x, a, b) = t² × (3 − 2t), where t = clamp((x − a)/(b − a), 0, 1)
H = 45 × smooth(oleic, 65, 75) × (1 − smooth(VS, 0, 15))
```

This term is continuous and depends on fatty acids, not olive-oil identity. It is not included in longevity: a physically hard high-oleic bar can dissolve faster during use. The 65–75% interval and 45-point maximum need further practical benchmarking, particularly intermediate blends. Four-week hardness is an approximate reference, not a time simulation.

Superfat retains physical softening above 2% and lather penalties above 5%. Thus those effects do not vary below their existing thresholds. More superfat lowers longevity and increases DOS. Cure speed retains its previous formula and liquid modifier; it is an index, not a number of days or proof that curing is complete.

The UI uses total dilution liquid as a practical proxy. It does not estimate the water fraction of milk, juice, or other liquids. Equivalent liquid amounts in percent-of-oils, lye-ratio, and lye-concentration modes produce equivalent quality modifiers. Fixed lye concentration can mean less absolute liquid when superfat is increased; the resulting liquid and superfat effects are both included.

### Shrinkage during cure

Using active selected alkali mass `A` and total dilution liquid `L`:

```text
C = 100 × A / (A + L)
shrinkage_risk = 100 / (1 + exp((C − 28) / 3))
```

The index is approximately 73 at 25% concentration, 34 at 30%, and 16 at 33%. The 0–20 display region indicates a lower tendency; the visible flag starts at 35. These are smooth scores around the product owner's observation that shrinkage becomes noticeable below approximately 30% concentration. They do not predict dimensional loss, weight loss, cracking, or warping. KOH purity retains the existing active-alkali convention.

## KOH and liquid workflows

Available custom KOH metrics display as **tendencies**, with no target bands or success/danger score treatment. Provisional multipliers reduce bar firmness and longevity with KOH, add `0.20 × HS × k` to bubble volume, and multiply the existing slime estimate by `1 − 0.75 × k`. Lather volume is not a measured foam-generation time.

Above 40% KOH, bar-only metrics (including shrinkage and bar DOS) remain unavailable. The UI omits their cards and explains the limitation; supported lather/feel tendencies stay visible. The cutoff controls model applicability, not a sudden physical change in the soap.

Negative superfat remains permitted only under the existing high-KOH/liquid guard. Cleansing strength, mildness, and conditioning feel are unavailable for that unfinished process. Stored numerical helpers must not be presented without their applicability metadata. A future liquid bench must account for neutralization, final dilution and concentration, paste dilution difficulty, clarity, viscosity, and stability.

The applicability `confidence` field is a heuristic display hint, not a statistical confidence estimate. Even NaOH entries marked `score` remain empirical.

## DOS and future additives

The DOS equation is unchanged:

```text
base = 1.8 × min(PU, 10)
     + 4.5 × max(0, min(PU − 10, 5))
     + 8 × max(0, PU − 15)
dos_risk = clamp(base + 0.8 × max(S, 0) + 0.04 × max(iodine − 55, 0), 0, 100)
```

The stronger increase above 15% PU remains aligned with the owner's experience. Reference coconut DOS remains 3.6 at 0% superfat and 19.6 at 20%. No changes were made to oil freshness or storage assumptions.

No automatic additive protection credit is implemented. Sodium citrate, citric acid, sodium phytate, ROE, EDTA, and tocopherol need explicit material/function/dose data before any calibration. Category membership alone is insufficient.

For future research, the owner's practice is citrate starting around 0.5%, commonly 1–2% of oil mass, and citric acid at 1–2%. These are recorded observations, not implemented dose rules. Citric acid requires correct alkali accounting before citrate effects can be considered. Sodium phytate was the intended material, not sodium levulinate. Tocopherol should not receive a blanket finished-soap DOS bonus based on its oil-storage antioxidant role.

## Regression benchmarks and remaining work

`tests/Feature/SoapQualityCalibrationTest.php` freezes representative fatty-acid profiles independently of mutable user records. The captured “Nouveau savon” formulation is 28% olive, 25% coconut, 7% castor, 40% palm, 7% superfat, and 30% lye concentration. It now sits comfortably within the unmolding target of 45–70. Its DOS stays 18.1784; adjusted NaOH stays 139.0168 g and dilution liquid 324.3725 g per 1000 g oils.

Tests cover the cleansing anchors, monotonic superfat response, stronger palmitic/stearic firmness, high-oleic hardness versus longevity, equivalent liquid modes, smooth shrinkage/slime transitions, KOH applicability, and independence from ingredient names and batch size. Existing tests retain legacy reference metrics and reaction-mass expectations. Frontend tests cover the 40-point boundary, shrinkage text, and hiding unsupported cards.

The next calibration pass should use observed bars across intermediate high-oleic blends, palmitic/stearic-rich profiles, high-superfat quick-acid formulas, and mixed-alkali soaps. Keep practical observations separate from coefficients fitted to them. Neither the liquid bench nor additive protection is complete in this version.
