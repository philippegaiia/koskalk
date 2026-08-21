# Soap alkali-solution terminology

Research date: 2026-08-20

## Decision

The interface must distinguish:

1. the **alkali solution**: the complete solution made from NaOH, KOH, or both, plus water or another suitable liquid; and
2. the **solution liquid**: the water, milk, hydrosol, infusion, or other liquid in which the alkali is dissolved.

Do not call the second concept “lye liquid”. That phrase can be read as either the corrosive finished solution or its liquid component. Established soapmaking sources consistently describe the corrosive mixture as a solution and separately describe water, milk, hydrosols, and similar ingredients as the liquid that replaces all or part of the water.

The workbench supports NaOH, KOH, and dual-alkali formulas. Therefore, the stable section heading should be mode-neutral. A mode-specific summary can name the actual alkali:

- NaOH: sodium-hydroxide / caustic-soda solution
- KOH: potassium-hydroxide / caustic-potash solution
- dual alkali: mixed alkali solution

For French specifically, **Solution de soude** is the natural NaOH-specific term requested by the product owner and used by French soapmaking sources. It is not accurate for KOH or dual-alkali formulas, so the stable heading should be **Solution alcaline**, with **Solution de soude**, **Solution de potasse**, or **Solution de soude et de potasse** in the mode-specific summary.

## Recommended UI terminology

| Locale | Stable section heading | NaOH-specific summary | Liquid concept | Alternative-liquid toggle | Water-only summary |
|---|---|---|---|---|---|
| English | Lye solution | Sodium hydroxide solution | Liquid | Use an alternative liquid | Lye solution: water only |
| French | Solution alcaline | Solution de soude | Liquide de dissolution | Utiliser un autre liquide | Solution de soude : eau uniquement |
| Spanish | Solución alcalina | Solución de sosa cáustica | Líquido de disolución | Usar otro líquido | Solución de sosa cáustica: solo agua |
| German | Laugenlösung | Natronlauge | Laugenflüssigkeit | Andere Laugenflüssigkeit verwenden | Natronlauge: nur Wasser |
| Italian | Soluzione alcalina | Soluzione di soda caustica | Liquido | Usa un altro liquido | Soluzione di soda caustica: solo acqua |
| Dutch | Loogoplossing | Natronloogoplossing | Vloeistof voor de loogoplossing | Een andere vloeistof gebruiken | Natronloogoplossing: alleen water |
| Brazilian Portuguese | Solução alcalina | Solução de soda cáustica | Líquido da solução | Usar outro líquido | Solução de soda cáustica: somente água |

If the summary follows the selected alkali type, use:

| Locale | NaOH | KOH | Dual alkali |
|---|---|---|---|
| English | Sodium hydroxide solution | Potassium hydroxide solution | Mixed alkali solution |
| French | Solution de soude | Solution de potasse | Solution de soude et de potasse |
| Spanish | Solución de sosa cáustica | Solución de potasa cáustica | Solución mixta de sosa y potasa |
| German | Natronlauge | Kalilauge | Gemischte Laugenlösung |
| Italian | Soluzione di soda caustica | Soluzione di potassa caustica | Soluzione alcalina mista |
| Dutch | Natronloogoplossing | Kaliloogoplossing | Gemengde loogoplossing |
| Brazilian Portuguese | Solução de soda cáustica | Solução de potassa cáustica | Solução alcalina mista |

For table headings and percentages, prefer the short, unambiguous **total liquid** formulation:

| Locale | “% of total liquid” |
|---|---|
| English | % of total liquid |
| French | % du liquide total |
| Spanish | % del líquido total |
| German | % der Gesamtflüssigkeit |
| Italian | % del liquido totale |
| Dutch | % van de totale vloeistof |
| Brazilian Portuguese | % do líquido total |

## French editorial note: dissolution, not dilution

“Liquide de dilution” is understandable but is not chemically exact for the normal workflow. **Dissolution** means dissolving solid NaOH or KOH in a solvent; **dilution** means lowering the concentration of a solution that already exists. Because this control chooses the liquid in which solid alkali is dissolved, **liquide de dissolution** is the precise technical label.

In compact task copy, **liquide** or **autre liquide** is clearer than either technical term. Recommended French copy for this surface:

- section: **Solution alcaline**
- current state in NaOH mode: **Solution de soude : eau uniquement**
- toggle: **Utiliser un autre liquide**
- helper: **Conservez l’eau par défaut, ou remplacez-en une partie ou la totalité par un hydrolat, du lait ou un autre liquide adapté.**
- column: **% du liquide total**

The existing **Liquide de soude** wording should be retired throughout the interface because it does not clearly name either the solution or the liquid component.

## Evidence by locale

### English

Bramble Berry’s professional soapmaking guidance explicitly distinguishes the “dissolved lye solution” from the “liquid” used to dissolve it, then lists distilled water, milk, tea, and alcoholic beverages as possible liquids. This directly supports **Lye solution** for the completed mixture and **liquid** for the replaceable component. [Bramble Berry / Soap Queen — alcoholic beverages in cold-process soap](https://www.soapqueen.com/bath-and-body-tutorials/tips-and-tricks/how-to-use-alcoholic-beverages-in-cold-process-soap/)

### French

Aroma-Zone uses **solution de soude**, says that the soda is dissolved in water, and separately describes hydrosols as replacements for the water. This supports **solution de soude** for the NaOH solution and **liquide** for the replaceable water/hydrosol/milk component. [Aroma-Zone — cold-process soap instructions using “solution de soude”](https://www.aroma-zone.com/info/fiche-technique/beurre-vegetal-cacao-blanc-bio-en-pastilles-aroma-zone), [Aroma-Zone — hydrosols replacing water in the soda mixture](https://www.aroma-zone.com/page/hydroxyde-de-sodium-a-quoi-ca-sert)

### Spanish

The National Autonomous University of Mexico describes the reactant as a **solución alcalina**, made from water and caustic soda. That mode-neutral term is preferable for a workbench that also supports KOH and dual alkali. The familiar NaOH-specific term is **solución de sosa cáustica**; the same source distinguishes the water used to dissolve it. [UNAM — Química en la vida cotidiana: El jabón](https://prometeo.matem.unam.mx/recursos/VariosNiveles/iCartesiLibri/recursos/Quimica_en_la_vida_cotidiana_El_jabon/index.html)

### German

The State of Styria’s vocational-school material calls the prepared mixture **Laugenlösung**. BIO AUSTRIA likewise distinguishes the water and goat milk from the **Laugenlösung**. German specialist soapmaking sources use **Laugenflüssigkeit** for water, milk, vinegar, or another liquid used to prepare the lye, making it a concise and established label for the second concept. [Land Steiermark — soapmaking with “Laugenlösung”](https://www.berufsschulen.steiermark.at/cms/beitrag/12886801/118922887/), [BIO AUSTRIA — goat-milk soap](https://www.bio-austria.at/r/ziegen-milchseife/), [Absolut Seife — alternative “Laugenflüssigkeiten”](https://www.absolut-seife.de/c/seife-selber-machen/basiszutaten-einer-seife/alternative-laugenfluessigkeiten)

### Italian

La Saponaria uses **soluzione caustica** for the prepared solution. Federchimica’s soapmaking material explicitly defines it as the combination of soda and a liquid, and says that the liquid is usually water but may instead be a decoction, infusion, milk, or juice. This supports a clear separation between **soluzione alcalina / soluzione di soda caustica** and **liquido**. [La Saponaria — soap recipe using “soluzione caustica”](https://www.lasaponaria.it/ricette-fai-da-te/ricetta-sapone-alla-cedrina), [Federchimica — soapmaking material](https://www.federchimica.it/docs/default-source/vincitori-premio-2020/a00189_la-saponetta-con-copertina.pdf?sfvrsn=69814593_2)

### Dutch

Dutch soapmaking instructions use **loogoplossing** for the completed mixture and separately instruct the maker to add the loog crystals to water. Other Dutch guidance describes replacing recipe water with milk or another chosen liquid. This supports **Loogoplossing** as the section and the explicit **vloeistof voor de loogoplossing** for the replaceable component. [Huydt — soapmaking instructions using “loogoplossing”](https://www.huydt.nl/natuurcosmetica/zeep-maken/zoete-sinaasappel-voedingszeep/), [Zeep-info — replacing water with milk](https://www.zeep-info.nl/maak-je-eigen-melkzeep-melk-voor-het-mengen-van-de-loog-koele-techniek/)

### Brazilian Portuguese

Brazilian public and academic sources use **solução de soda cáustica** or the shorter **solução de soda** for the prepared NaOH solution and separately describe dissolving the alkali in water. A Brazilian soapmaking source calls milk, beer, aloe, juice, and similar replacements **líquido alternativo**, supporting plain **líquido** for the replaceable component. [CETESB — sodium hydroxide solution terminology](https://produtosquimicos.cetesb.sp.gov.br/ficha/produto/39), [UFRJ / CAPES — soapmaking material using “solução de soda”](https://educapes.capes.gov.br/bitstream/capes/574863/2/Produto%20-%20PROFQUI.pdf), [Fórmula Sabão Artesanal — alternative liquids](https://formuladesabaoartesanal.com/problemas-que-surgem-na-elaboracao-do-sabao-parte-2/)

## Implementation implication

Replace translations as a coherent set rather than changing only the section heading. The affected family includes the heading, water-only summary, toggle, add/search prompt, percentage column, removal confirmation, validation messages, and any accessibility labels. Every string should preserve the distinction between:

- the **solution** as a whole;
- the selected **liquid** and its percentage of total liquid; and
- the selected **alkali type** (NaOH, KOH, or dual).
