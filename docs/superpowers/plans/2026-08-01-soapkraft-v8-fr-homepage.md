# Soapkraft V8-fr Homepage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a separate French V8 homepage that preserves V8’s design and interactions while adapting every user-facing message for French soap makers, cosmetic formulators, artisans, and small brands.

**Architecture:** Copy the verified static V8 prototype into an isolated temporary folder, adapt semantic HTML and JavaScript announcements without changing layout contracts, replace the V8 tests with French editorial contracts, then copy the verified result into a new sibling `v8-fr/` folder. Keep `styles.css` and the hero asset byte-identical to V8 unless French text length exposes a verified responsive defect.

**Tech Stack:** Semantic HTML5, modern CSS, vanilla JavaScript, Node.js `node:test`, local WebP asset.

---

## File Map

- Create `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8-fr/index.html`: complete French page copy and metadata.
- Create `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8-fr/script.js`: V8 behavior with French control labels and billing announcements.
- Create `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8-fr/styles.css`: copy of V8 styling, changed only if a tested French text-length correction is necessary.
- Create `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8-fr/verify.test.mjs`: French language, product terminology, accessibility, interaction, and isolation contracts.
- Create `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8-fr/assets/soapkraft-hero-benches.webp`: unchanged V8 hero asset.

### Task 1: Create an isolated French baseline

**Files:**
- Create in `/private/tmp/soapkraft-v8-fr-build/`: `index.html`, `script.js`, `styles.css`, `verify.test.mjs`, and `assets/soapkraft-hero-benches.webp`
- Source: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/`

- [ ] **Step 1: Confirm source integrity and target absence**

Run:

```bash
test -f '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/index.html'
test ! -e '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8-fr'
node --test '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs'
```

Expected: V8 exists, V8-fr does not exist, and all 25 V8 tests pass.

- [ ] **Step 2: Create the isolated build folder**

Run:

```bash
test ! -e /private/tmp/soapkraft-v8-fr-build
mkdir -p /private/tmp/soapkraft-v8-fr-build
cp -R '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/.' '/private/tmp/soapkraft-v8-fr-build/'
```

Expected: the temporary folder is newly created, contains the five V8 deliverables, and inherited tests pass there. If the folder already exists, stop and inspect it instead of overwriting it.

- [ ] **Step 3: Add the first failing French language contract**

Replace the V8 hero-copy test in the temporary `verify.test.mjs` with:

```js
test("V8-fr exposes French metadata and the approved French hero", () => {
    const html = read("index.html");
    const readableText = html
        .replace(/<[^>]+>/g, " ")
        .replace(/\s+/g, " ")
        .replace(/\s+([,.])/g, "$1")
        .trim();

    assert.match(html, /<html lang="fr">/);
    assert.match(html, /<title>Soapkraft : calculateur de savon et espace de formulation<\/title>/);
    assert.match(html, /formules de savon et de cosmétique/);
    assert.ok(readableText.includes("Vos formules, vos ingrédients, votre atelier. Tout au même endroit."));
    assert.ok(readableText.includes("Réalisez rapidement un calcul de soude"));
    assert.match(html, /Lancer un calcul<\/a>\s*<a class="button button-primary" href="#plans">Ouvrir mon espace gratuit/);
});
```

- [ ] **Step 4: Run the focused test and confirm failure**

Run:

```bash
node --test --test-name-pattern='French metadata and the approved French hero' '/private/tmp/soapkraft-v8-fr-build/verify.test.mjs'
```

Expected: FAIL because the copied page is still in English.

### Task 2: Adapt metadata, navigation, hero, marquee, and shared controls

**Files:**
- Modify: `/private/tmp/soapkraft-v8-fr-build/index.html`
- Modify: `/private/tmp/soapkraft-v8-fr-build/script.js`
- Test: `/private/tmp/soapkraft-v8-fr-build/verify.test.mjs`

- [ ] **Step 1: Adapt document metadata**

Use `apply_patch` to make these exact replacements:

```html
<html lang="fr">
<title>Soapkraft : calculateur de savon et espace de formulation</title>
<meta name="description" content="Utilisez le calculateur de savon Soapkraft sans compte, ou créez un espace gratuit pour enregistrer vos formules de savon et de cosmétique.">
```

- [ ] **Step 2: Adapt navigation and general controls**

Use these exact French labels in both desktop and mobile navigation:

```text
Start free → Commencer gratuitement
Workflow → Parcours
Workspace → Espace de travail
Plans → Tarifs
Open free workspace → Ouvrir mon espace gratuit
Open navigation → Ouvrir le menu
Close navigation → Fermer le menu
Skip to content → Aller au contenu
Soapkraft home → Accueil Soapkraft
Mobile → Navigation mobile
Pause animation → Mettre l’animation en pause
Play animation → Relancer l’animation
```

Change the static HTML labels in `index.html` and the dynamic menu and animation labels in `script.js`.

- [ ] **Step 3: Adapt the complete hero**

Replace the hero content with:

```html
<p class="hero-brand reveal">SOAPKRAFT</p>
<h1 class="display reveal" id="hero-heading" style="--delay: 80ms">
    Vos formules, vos ingrédients,<br>votre atelier. <span class="underline">Tout au même endroit<svg viewBox="0 0 220 18" preserveAspectRatio="none" aria-hidden="true"><path d="M5 12 C 68 5, 157 7, 215 10"></path></svg></span>.
</h1>
<p class="hero-lede reveal" style="--delay: 160ms">
    Réalisez rapidement un calcul de soude, ou construisez et enregistrez une formule complète de savon ou de cosmétique avec ses phases, ses ingrédients, ses coûts et les informations utiles pour l’étiquetage.
</p>
<div class="hero-actions reveal" style="--delay: 240ms">
    <a class="button button-secondary" href="#bench-demo">Lancer un calcul</a>
    <a class="button button-primary" href="#plans">Ouvrir mon espace gratuit</a>
</div>
<p class="hero-note reveal" style="--delay: 300ms">Aucun compte nécessaire pour calculer · Inscrivez-vous uniquement lorsque vous souhaitez enregistrer votre travail</p>
```

- [ ] **Step 4: Adapt the capability marquee**

Use this sequence twice, preserving the two `.marquee-sequence` elements and their accessibility attributes:

```html
<span>Formules de savon et de cosmétique</span><i>✳</i><span>Phases et additifs</span><i>✳</i><span>Matières premières personnelles</span><i>✳</i><span>Coût de revient</span><i>✳</i><span>Références IFRA</span><i>✳</i><span>Allergènes à déclarer</span><i>✳</i><span>Liste INCI et étiquetage</span><i>✳</i><span>Fiches de lot</span><i>✳</i><span>Planification de la production</span><i>✳</i>
```

Set the region label to `Fonctionnalités de Soapkraft`.

- [ ] **Step 5: Run the focused French hero test**

Run:

```bash
node --test --test-name-pattern='French metadata and the approved French hero' '/private/tmp/soapkraft-v8-fr-build/verify.test.mjs'
```

Expected: PASS.

### Task 3: Adapt formulation, workspace, workflow, founder, and production sections

**Files:**
- Modify: `/private/tmp/soapkraft-v8-fr-build/index.html`
- Test: `/private/tmp/soapkraft-v8-fr-build/verify.test.mjs`

- [ ] **Step 1: Add failing soap-and-cosmetics and production-boundary tests**

Append:

```js
test("V8-fr speaks precisely about soap and cosmetic formulation", () => {
    const html = read("index.html");
    const readableText = html.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();

    for (const wording of [
        "Formulation de savon",
        "Formulation cosmétique",
        "soude ou de potasse",
        "phases aqueuse, huileuse et de refroidissement",
        "matières premières",
        "liste INCI",
        "références IFRA",
    ]) {
        assert.ok(readableText.includes(wording), `Terminologie absente : ${wording}`);
    }
});

test("V8-fr distinguishes the simplified batch record from production", () => {
    const html = read("index.html");
    const readableText = html.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();

    assert.match(readableText, /Fiche de lot simplifiée Conserver la trace de ce qui a été fabriqué/);
    assert.match(readableText, /Module de production Planifier, suivre et gérer les prochaines fabrications/);
    assert.match(readableText, /numéros de lot des matières premières/);
    assert.match(readableText, /planification, consommations de matières, stocks, fournisseurs et références fournisseurs/);
    assert.doesNotMatch(readableText, /ERP simplifié|snapshot de batch/i);
});
```

- [ ] **Step 2: Run the new tests and confirm failure**

Run:

```bash
node --test --test-name-pattern='soap and cosmetic formulation|simplified batch record' '/private/tmp/soapkraft-v8-fr-build/verify.test.mjs'
```

Expected: FAIL while the main sections remain in English.

- [ ] **Step 3: Adapt the two free paths**

Use these exact headings and messages:

```text
Need a calculation, or need to keep the formula? → Un calcul rapide, ou une formule à conserver ?
Use the calculator on its own... → Utilisez le calculateur seul, ou ouvrez un espace gratuit lorsque la formule mérite d’être conservée.
No account → Sans compte
Run a soap calculation → Calculer la soude ou la potasse
For the quick calculation... → Pour obtenir rapidement les quantités utiles à l’atelier. Le calculateur n’enregistre pas votre travail et ne demande aucune inscription.
NaOH and KOH calculations → Calculs NaOH et KOH
Water and superfat controls → Réglage de l’eau et du surgraissage
Results update as you formulate → Résultats actualisés pendant la formulation
Use soap calculator → Ouvrir le calculateur
Free account → Compte gratuit
Keep the formulas you want to make again → Conserver les formules que vous souhaitez refaire
Open the workspace... → Ouvrez l’espace de travail lorsqu’une formule mérite d’être conservée. Le compte gratuit comprend :
Limited formula portfolio → Portefeuille de formules en nombre limité
Your own ingredients and images → Vos matières premières et vos images
Notes and version history → Notes et historique des versions
Limited batch records... → Fiches de lot simplifiées en nombre limité, avec instantané de la formule et numéros de lot des matières premières
Create free workspace → Ouvrir mon espace gratuit
```

- [ ] **Step 4: Adapt the soap-and-cosmetics section**

Use:

```text
Soap and cosmetics, without switching apps. → Savon et cosmétique, sans changer d’application.
Soap recipes start... → Une formule de savon part des huiles et de la lessive alcaline. Une formule cosmétique s’organise en phases et en pourcentages. Soapkraft conserve les deux dans le même portefeuille.
Soap formulation → Formulation de savon
Work from oils and lye → Partir des huiles et de la soude
Add oils and additives... → Ajoutez les huiles et les additifs, puis ajustez la formule. Soapkraft recalcule la soude ou la potasse, l’eau, le surgraissage et les propriétés du savon.
Reaction core → Base de calcul
Oils → Huiles
Cosmetic formulation → Formulation cosmétique
Work in phases → Travailler par phases
Build the water... → Construisez les phases aqueuse, huileuse et de refroidissement en pourcentages. Les totaux, la liste INCI, les indications sur les allergènes et les références IFRA restent visibles à côté de la formule.
Water → Aqueuse
Oil → Huileuse
Cool down → Refroidissement
Preservative → Conservateur
Fragrance → Parfum
ingredient list → liste INCI
allergens → allergènes
```

- [ ] **Step 5: Adapt the formula-record section**

Use:

```text
Save more than the calculation. → Conserver bien plus qu’un calcul.
The formula record holds... → Le dossier de formule réunit les matières premières, le mode opératoire, les images, les coûts, les informations d’étiquetage et l’historique des versions. Vous le retrouvez lorsque vous refaites le lot.
Formula portfolio → Portefeuille de formules
Images · notes · versions → Images · notes · versions
Live calculation → Calcul en direct
Saved to free workspace → Enregistré dans l’espace gratuit
Label preview → Aperçu de l’étiquette
Label checks → Vérifications d’étiquetage
No declarable fragrance allergens detected → Aucun allergène de parfum à déclarer détecté
IFRA usage references checked → Références d’utilisation IFRA vérifiées
Ingredient list generated → Liste INCI générée
Cost per 100 g → Coût pour 100 g
Your ingredients → Vos matières premières
Photos and instructions → Photos et mode opératoire
Costs and label guidance → Coûts et informations d’étiquetage
Versions and history → Versions et historique
```

Also translate the decorative application preview labels: `Formulas` → `Formules`, `Ingredients` → `Matières premières`, `Media` → `Médias`, `Saved` → `Enregistré`, `Save version` → `Enregistrer une version`, `Costing` → `Coûts`, `Output` → `Sortie`, `Instructions & media` → `Mode opératoire et médias`, `Ingredients & additions` → `Matières premières et ajouts`, `Ingredient` → `Matière première`, `Weight` → `Masse`, `Images & notes` → `Images et notes`, and `today` → `aujourd’hui`.

- [ ] **Step 6: Adapt workflow, founder, and production sections**

Use these complete central messages:

```text
One connected path → Un parcours continu
Follow the formula from calculation to production. → Suivre la formule, du calcul à la production.
Most tools solve one part... → La plupart des logiciels ne couvrent qu’une partie du travail. Soapkraft relie le calcul, le dossier de formule, l’étiquetage, la traçabilité du lot et la production.
Tool category → Catégorie de logiciel
Calculate → Calculer
Formulate → Formuler
Save → Enregistrer
Labels → Étiquetage
Batch record → Fiche de lot
Team → Équipe
Quick calculators → Calculateurs rapides
Formulation software → Logiciels de formulation
Production ERP → Logiciels de gestion de production
Usually included → Généralement inclus
Sometimes included → Parfois inclus
Usually absent → Généralement absent
Typical coverage varies... → La couverture fonctionnelle varie selon les logiciels. Chez Soapkraft, la gestion de production est incluse à partir de Studio et le travail en équipe à partir de Team.
Built at the bench → Conçu à l’atelier
Built after twenty years... → Conçu après vingt ans d’utilisation de logiciels de formulation.
"I built Soapkraft..." → « J’ai développé Soapkraft pour mes propres formules et je l’utilise toujours à mon atelier. Je le partage aujourd’hui avec d’autres savonniers et formulateurs. »
Founder and formulator → Fondateur et formulateur
The problems I kept running into → Les problèmes que je retrouvais sans cesse
From evidence to operations → De la traçabilité à la production
Keep a batch record now... → Tenir une fiche de lot aujourd’hui. Gérer la production lorsque vous en avez besoin.
The free workspace can document... → L’espace gratuit permet de documenter un nombre limité de lots. Studio ajoute les outils nécessaires pour organiser et suivre la production.
Included with Free, within limits → Inclus avec Free, dans les limites du plan
Batch record → Fiche de lot simplifiée
Record what was made → Conserver la trace de ce qui a été fabriqué
Keep the formula snapshot... → Conservez un instantané de la formule et les numéros de lot des matières premières utilisées.
Included with Studio → Inclus avec Studio
Production module → Module de production
Plan and manage what happens next → Planifier, suivre et gérer les prochaines fabrications
Production planning... → Planification, consommations de matières, stocks, fournisseurs et références fournisseurs.
```

Replace the founder problem list with:

```html
<ul>
    <li>Un calcul qui ne pouvait pas rejoindre un véritable portefeuille de formules</li>
    <li>Des calculateurs de savon sans place pour les additifs, les coûts ou les notes de production</li>
    <li>Une application pour le savon et une autre pour les cosmétiques</li>
    <li>L’ajout de mes propres matières premières et l’aide à l’étiquetage réservés à des logiciels coûteux, pensés pour de plus grandes entreprises</li>
</ul>
```

- [ ] **Step 7: Run the focused terminology tests**

Run:

```bash
node --test --test-name-pattern='soap and cosmetic formulation|simplified batch record' '/private/tmp/soapkraft-v8-fr-build/verify.test.mjs'
```

Expected: PASS.

### Task 4: Adapt pricing, FAQ, footer, and dynamic billing language

**Files:**
- Modify: `/private/tmp/soapkraft-v8-fr-build/index.html`
- Modify: `/private/tmp/soapkraft-v8-fr-build/script.js`
- Test: `/private/tmp/soapkraft-v8-fr-build/verify.test.mjs`

- [ ] **Step 1: Add failing pricing and regulatory-language tests**

Append:

```js
test("V8-fr presents the four plans and French billing language", () => {
    const html = read("index.html");
    const script = read("script.js");

    for (const plan of ["Free", "Maker", "Studio", "Team"]) {
        assert.match(html, new RegExp(`<h3>${plan}</h3>`));
    }
    assert.match(html, />Mensuel<\/button>/);
    assert.match(html, />Annuel <span>2 mois offerts<\/span><\/button>/);
    assert.match(html, /data-monthly-note="Facturé mensuellement"/);
    assert.match(html, /data-annual-note="Facturé 120 € par an"/);
    assert.match(script, /Facturation annuelle/);
    assert.match(script, /par mois/);
});

test("V8-fr uses careful French regulatory language", () => {
    const html = read("index.html");
    const readableText = html.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();

    assert.match(readableText, /Soapkraft remplace-t-il la validation réglementaire d’un professionnel qualifié \?/);
    assert.match(readableText, /ne remplace pas la validation d’un professionnel qualifié/);
    assert.doesNotMatch(readableText, /conformité garantie|garantit la conformité|entièrement conforme/i);
});
```

- [ ] **Step 2: Run and confirm failure**

Run:

```bash
node --test --test-name-pattern='four plans and French billing|careful French regulatory' '/private/tmp/soapkraft-v8-fr-build/verify.test.mjs'
```

Expected: FAIL because pricing and FAQ remain in English.

- [ ] **Step 3: Adapt plan headings, descriptions, and features**

Keep plan names and numeric price data unchanged. Use:

```text
Use the free workspace for as long as it fits. → Utilisez l’espace gratuit aussi longtemps qu’il vous suffit.
Upgrade when... → Changez de plan lorsque vous avez besoin de davantage de formules, de la gestion de production ou du travail en équipe.
Monthly → Mensuel
Annual → Annuel
2 months free → 2 mois offerts
/month → /mois
You do not need a card → Aucune carte bancaire demandée
For a small working portfolio → Pour commencer à constituer votre portefeuille
For active solo makers → Pour les artisans qui formulent régulièrement
For a working studio → Pour organiser un atelier en production
For a shared workspace → Pour travailler à plusieurs
For production → Production
Saved formulas and your own ingredients, within limits → Formules enregistrées et matières premières personnelles, dans les limites du plan
Images, notes and version history → Images, notes et historique des versions
Limited batch records → Fiches de lot simplifiées en nombre limité
More formulas and ingredients → Davantage de formules et de matières premières
Labels, allergen and IFRA guidance → Étiquettes, indications sur les allergènes et références IFRA
Higher batch-record allowance → Capacité supérieure pour les fiches de lot
Everything in Maker → Tout le contenu de Maker
Complete production module → Module de production complet
Planning, stock and supplier management → Planification, stocks et gestion des fournisseurs
Everything in Studio → Tout le contenu de Studio
Members, roles and permissions → Membres, rôles et droits d’accès
Shared company workspace → Espace de travail partagé pour l’entreprise
Start with a free workspace. → Commencez avec un espace gratuit.
```

Set billing notes to `Facturé mensuellement`, `Facturé 120 € par an`, `Facturé 288 € par an`, and `Facturé 588 € par an`.

- [ ] **Step 4: Adapt the FAQ and final action**

Use these six questions and answers:

```html
<details><summary>Le calculateur est-il gratuit et accessible sans compte ?</summary><p>Oui. Vous pouvez utiliser le calculateur de savon sans vous inscrire. Créez un compte uniquement lorsque vous souhaitez enregistrer vos formules et conserver un espace de travail.</p></details>
<details><summary>Que comprend le plan Free avec inscription ?</summary><p>Un portefeuille de formules en nombre limité avec vos matières premières, images, notes, versions et fiches de lot simplifiées.</p></details>
<details><summary>Quelle est la différence entre une fiche de lot et le module de production ?</summary><p>La fiche de lot conserve la trace de ce qui a été fabriqué, avec l’instantané de la formule et les numéros de lot des matières premières. Studio ajoute la planification, les stocks, les consommations de matières et la gestion des fournisseurs.</p></details>
<details><summary>Soapkraft remplace-t-il la validation réglementaire d’un professionnel qualifié ?</summary><p>Non. Soapkraft fournit des calculs, des listes INCI, des indications sur les allergènes et des références IFRA. Cette aide ne remplace pas la validation d’un professionnel qualifié.</p></details>
<details><summary>Puis-je ajouter mes propres matières premières ?</summary><p>Oui. Le plan Free permet d’ajouter des matières premières personnelles dans ses limites, avec une capacité supérieure dans les plans payants.</p></details>
<details><summary>Une équipe peut-elle partager le même espace de travail ?</summary><p>Oui. Team ajoute les membres, les rôles et les droits d’accès à l’espace Studio.</p></details>
```

Use `Quelques réponses pratiques.` for the FAQ heading, `Commencez là où vous en êtes` for the closing eyebrow, and `Calculez maintenant. Enregistrez la formule lorsqu’elle mérite d’être conservée.` for the closing heading. Reuse `Lancer un calcul` and `Ouvrir mon espace gratuit` for the final actions.

- [ ] **Step 5: Adapt footer copy and links**

Use:

```text
Calculate soap without an account... → Calculez un savon sans compte, ou enregistrez vos formules de savon et de cosmétique dans un espace de travail pratique.
Start free → Commencer gratuitement
Formulation methods → Méthodes de formulation
Workspace → Espace de travail
Plans → Tarifs
Built from the bench → Conçu à l’atelier
```

- [ ] **Step 6: Adapt dynamic billing announcements in JavaScript**

Replace the pricing announcement fragments with:

```js
const priceSummary = prices
    .map((price) => `${price.closest(".plan-card")?.querySelector("h3")?.textContent} ${price.dataset[period]} € par mois`)
    .join(", ");
const periodLabel = period === "annual" ? "Facturation annuelle" : "Facturation mensuelle";
billingAnnouncement.textContent = `${periodLabel} sélectionnée. ${priceSummary}.`;
```

Keep all calculation, reveal, visibility, timing, and pricing-toggle logic unchanged.

- [ ] **Step 7: Run the focused tests**

Run:

```bash
node --test --test-name-pattern='four plans and French billing|careful French regulatory' '/private/tmp/soapkraft-v8-fr-build/verify.test.mjs'
```

Expected: PASS.

### Task 5: Reconcile the full French test suite and publish V8-fr

**Files:**
- Modify: `/private/tmp/soapkraft-v8-fr-build/verify.test.mjs`
- Verify: `/private/tmp/soapkraft-v8-fr-build/index.html`
- Verify: `/private/tmp/soapkraft-v8-fr-build/script.js`
- Verify: `/private/tmp/soapkraft-v8-fr-build/styles.css`
- Create: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8-fr/`

- [ ] **Step 1: Replace stale English editorial assertions**

Retain V8’s structural, design-token, pricing-data, motion, accessibility, touch-target, and local-file tests. Replace tests that require English visible copy with the approved French wording. Add this English-leak contract:

```js
test("V8-fr contains no principal English interface copy", () => {
    const html = read("index.html");
    const script = read("script.js");
    const forbidden = [
        "Start calculating",
        "Open free workspace",
        "Need a calculation",
        "Soap formulation",
        "Cosmetic formulation",
        "Save more than",
        "Quick calculators",
        "Batch record",
        "Production module",
        "Monthly billing",
        "Annual billing",
        "A few practical answers",
    ];

    for (const wording of forbidden) {
        assert.ok(!html.includes(wording) && !script.includes(wording), `Texte anglais restant : ${wording}`);
    }
});
```

- [ ] **Step 2: Run all automated checks**

Run:

```bash
node --check '/private/tmp/soapkraft-v8-fr-build/script.js'
node --test '/private/tmp/soapkraft-v8-fr-build/verify.test.mjs'
tidy -errors -quiet -utf8 '/private/tmp/soapkraft-v8-fr-build/index.html'
```

Expected: JavaScript syntax passes; all Node tests pass; HTML Tidy reports no structural errors. Its inherited warnings about decorative empty elements and `fetchpriority` are acceptable.

- [ ] **Step 3: Check text-length and responsive contracts statically**

Confirm the CSS still contains the V8 mobile breakpoints, full-width mobile hero actions, stacked pricing cards, stacked production cards, horizontal workflow overflow, and reduced-motion marquee override. Confirm no French heading has been made smaller through inline styles.

Run:

```bash
rg -n '@media \(max-width: 820px\)|@media \(max-width: 620px\)|overflow-x: auto|prefers-reduced-motion|\.hero-actions \.button|\.production-steps' '/private/tmp/soapkraft-v8-fr-build/styles.css'
```

Expected: all responsive contracts are present.

- [ ] **Step 4: Confirm V8 remains unchanged**

Record SHA-256 hashes of V8’s `index.html`, `styles.css`, `script.js`, and `verify.test.mjs` before publishing. Re-run the same hashes after publishing V8-fr and require exact matches.

- [ ] **Step 5: Copy the verified build into the permanent V8-fr folder**

After confirming the target still does not exist, copy the complete temporary folder:

```bash
cp -R '/private/tmp/soapkraft-v8-fr-build' '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8-fr'
```

Expected: `v8-fr/index.html` opens locally and all relative references resolve.

- [ ] **Step 6: Run final verification from the permanent folder**

Run:

```bash
node --test '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8-fr/verify.test.mjs'
node --check '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8-fr/script.js'
diff -q '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/styles.css' '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8-fr/styles.css'
diff -q '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/assets/soapkraft-hero-benches.webp' '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8-fr/assets/soapkraft-hero-benches.webp'
```

Expected: all French tests pass, JavaScript syntax passes, and the visual stylesheet and hero asset remain identical to V8.
