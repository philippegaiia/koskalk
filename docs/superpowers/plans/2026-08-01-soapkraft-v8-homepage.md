# Soapkraft V8 Homepage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a separate V8 static homepage that preserves V7, restores the capability marquee, clarifies the two free entry points, and explains Soapkraft's path from formulation to batch records and production management.

**Architecture:** Copy the dependency-free V7 prototype into a new sibling `v8/` directory, then make focused semantic HTML, CSS, JavaScript, and Node verification-test changes there. Keep native HTML behavior wherever possible, retain V7's progressive enhancement and pricing logic, and isolate all new visual components behind V8-specific classes.

**Tech Stack:** Semantic HTML5, modern CSS with OKLCH colours and media queries, vanilla JavaScript, Node.js `node:test`, local WebP asset.

---

## File Map

- Create `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/index.html`: V8 content structure and copy.
- Create `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/styles.css`: V8 visual system, marquee, workflow matrix, production distinction, FAQ, responsive states, and reduced motion.
- Create `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/script.js`: V7 navigation, reveal, bench, and billing behavior with no framework dependency.
- Create `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs`: semantic, copy, pricing, motion, accessibility, and local-reference contracts for V8.
- Copy `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v7/assets/soapkraft-hero-benches.webp` to `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/assets/soapkraft-hero-benches.webp`: unchanged hero artwork.

### Task 1: Create an isolated V8 baseline

**Files:**
- Create: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/index.html`
- Create: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/styles.css`
- Create: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/script.js`
- Create: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs`
- Create: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/assets/soapkraft-hero-benches.webp`

- [ ] **Step 1: Confirm the immutable source and absent target**

Run:

```bash
test -f '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v7/index.html'
test ! -e '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8'
```

Expected: both commands exit 0. If `v8` exists, stop and inspect it rather than overwriting it.

- [ ] **Step 2: Create the V8 generation by copying V7 once**

Run:

```bash
mkdir -p '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8'
cp -R '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v7/.' '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/'
```

Expected: V8 contains `index.html`, `styles.css`, `script.js`, `verify.test.mjs`, and `assets/soapkraft-hero-benches.webp`; V7 has no changed modification times or contents.

- [ ] **Step 3: Rename the baseline verification contract**

Use `apply_patch` in `v8/verify.test.mjs` to replace every human-readable `V7` test-name prefix with `V8`. Do not weaken or delete the inherited assertions yet.

- [ ] **Step 4: Run the copied verification suite**

Run:

```bash
node --test '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs'
```

Expected: all inherited tests pass.

- [ ] **Step 5: Record the baseline if the reference folder is version-controlled**

Run:

```bash
git -C '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference' rev-parse --is-inside-work-tree
```

Expected: if the command prints `true`, stage only `v8/` and commit with `Create Soapkraft V8 baseline`. If it is not a Git work tree, continue without creating a repository.

### Task 2: Restore the approved hero message and capability marquee

**Files:**
- Modify: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/index.html`
- Modify: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/styles.css`
- Test: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs`

- [ ] **Step 1: Add failing hero and marquee contracts**

Append this test to `v8/verify.test.mjs`:

```js
test("V8 restores the approved hero and capability marquee", () => {
    const html = read("index.html");
    const css = read("styles.css");

    assert.match(html, /Your formula, your ingredients,[\s\S]*your bench/);
    assert.match(html, /Start with a quick lye calculation/);
    assert.match(html, />Start calculating<\/a>[\s\S]*>Open free workspace<\/a>/);
    assert.match(html, /No account needed for calculation/);
    assert.equal((html.match(/class="marquee-sequence"/g) ?? []).length, 2);
    assert.match(html, /IFRA references/);
    assert.match(html, /Production planning/);
    assert.match(css, /@keyframes marquee-scroll/);
    assert.match(css, /\.capability-marquee:is\(:hover, :focus-visible\) \.marquee-track/);
});
```

- [ ] **Step 2: Run the focused test and confirm failure**

Run:

```bash
node --test --test-name-pattern='approved hero and capability marquee' '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs'
```

Expected: FAIL because V8 still contains V7's hero copy and no marquee.

- [ ] **Step 3: Replace the hero copy and insert the marquee**

Use `apply_patch` to replace only the hero heading, lede, actions, and note in `v8/index.html` with:

```html
<p class="hero-brand reveal">SOAPKRAFT</p>
<h1 class="display reveal" id="hero-heading" style="--delay: 80ms">
    Your formula, your ingredients,<br>your bench <span class="underline">in one place<svg viewBox="0 0 220 18" preserveAspectRatio="none" aria-hidden="true"><path d="M5 12 C 68 5, 157 7, 215 10"></path></svg></span>.
</h1>
<p class="hero-lede reveal" style="--delay: 160ms">
    Start with a quick lye calculation, or build and save a complete soap or cosmetic formula with phases, ingredients, costs, and label signals in one place.
</p>
<div class="hero-actions reveal" style="--delay: 240ms">
    <a class="button button-secondary" href="#bench-demo">Start calculating</a>
    <a class="button button-primary" href="#plans">Open free workspace</a>
</div>
<p class="hero-note reveal" style="--delay: 300ms">No account needed for calculation · Register only when you want to save</p>
```

Insert this non-interactive strip immediately after the closing `</section>` for `#hero`:

```html
<div class="capability-marquee" role="region" aria-label="Soapkraft capabilities" tabindex="0">
    <div class="marquee-track">
        <div class="marquee-sequence" aria-hidden="true">
            <span>Soap and cosmetic formulas</span><i>✳</i><span>Phases and additives</span><i>✳</i><span>Private ingredients</span><i>✳</i><span>Formula costing</span><i>✳</i><span>IFRA references</span><i>✳</i><span>Allergen signals</span><i>✳</i><span>INCI and label guidance</span><i>✳</i><span>Batch records</span><i>✳</i><span>Production planning</span><i>✳</i>
        </div>
        <div class="marquee-sequence" aria-hidden="true">
            <span>Soap and cosmetic formulas</span><i>✳</i><span>Phases and additives</span><i>✳</i><span>Private ingredients</span><i>✳</i><span>Formula costing</span><i>✳</i><span>IFRA references</span><i>✳</i><span>Allergen signals</span><i>✳</i><span>INCI and label guidance</span><i>✳</i><span>Batch records</span><i>✳</i><span>Production planning</span><i>✳</i>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Add restrained marquee and hero-transition styling**

Append these component rules before V8's responsive media queries in `v8/styles.css`:

```css
.capability-marquee {
    position: relative;
    z-index: 4;
    overflow: hidden;
    border-block: 1px solid color-mix(in oklch, var(--accent) 24%, transparent);
    background: var(--accent-strong);
    color: var(--panel);
}

.marquee-track {
    display: flex;
    width: max-content;
    animation: marquee-scroll 44s linear infinite;
    will-change: transform;
}

.marquee-sequence {
    display: flex;
    flex: none;
    align-items: center;
    gap: 1.25rem;
    padding: 0.8rem 0.625rem;
    white-space: nowrap;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.09em;
    text-transform: uppercase;
}

.marquee-sequence i {
    color: var(--chemistry);
    font-style: normal;
}

.capability-marquee:is(:hover, :focus-visible) .marquee-track {
    animation-play-state: paused;
}

@keyframes marquee-scroll {
    to { transform: translateX(-50%); }
}
```

Adjust the existing hero rules rather than layering competing overrides: keep `.hero-media img` at `opacity: 0.68`, ensure it uses `object-fit: cover`, and give the hero's lower edge a surface-coloured gradient so the bench and next section join without an exposed blank corner.

- [ ] **Step 5: Make reduced motion deterministic**

Inside the existing `@media (prefers-reduced-motion: reduce)` block, add:

```css
.marquee-track {
    animation: none !important;
    transform: none !important;
}

.marquee-sequence:nth-child(2) {
    display: none;
}
```

- [ ] **Step 6: Run the focused and full tests**

Run:

```bash
node --test --test-name-pattern='approved hero and capability marquee' '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs'
node --test '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs'
```

Expected: the focused test passes. The full suite may now fail only where inherited V7 copy assertions intentionally need V8 updates; record those failures for Task 5 rather than weakening unrelated contracts.

### Task 3: Add the connected workflow section

**Files:**
- Modify: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/index.html`
- Modify: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/styles.css`
- Test: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs`

- [ ] **Step 1: Add a failing category-comparison contract**

Append:

```js
test("V8 compares connected workflows without naming competitors", () => {
    const html = read("index.html");

    assert.match(html, /id="workflow"/);
    assert.match(html, /Follow the formula from calculation to production\./);
    for (const label of ["Quick calculators", "Formulation software", "Production ERP", "Soapkraft"]) {
        assert.ok(html.includes(label), `Missing workflow category: ${label}`);
    }
    for (const stage of ["Calculate", "Formulate", "Save", "Labels", "Batch record", "Production", "Team"]) {
        assert.ok(html.includes(stage), `Missing workflow stage: ${stage}`);
    }
    assert.match(html, /Typical coverage varies by product/);
    assert.doesNotMatch(html, /Katana|Craftybase|Odoo|SoapMaker/i);
});
```

- [ ] **Step 2: Run the test and confirm failure**

Run:

```bash
node --test --test-name-pattern='connected workflows' '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs'
```

Expected: FAIL because `#workflow` does not exist.

- [ ] **Step 3: Insert the workflow section**

Insert this section after `#formula-record` and before `#founder`:

```html
<section class="workflow section" id="workflow" aria-labelledby="workflow-heading">
    <div class="shell">
        <header class="section-heading section-heading-wide reveal">
            <p class="eyebrow">One connected path</p>
            <h2 class="display" id="workflow-heading">Follow the formula from calculation to production.</h2>
            <p>Most tools solve one part of the job. Soapkraft keeps the calculation, formula record, label guidance, batch evidence and production work connected.</p>
        </header>

        <div class="workflow-table reveal" role="table" aria-label="Typical workflow coverage by software category">
            <div class="workflow-row workflow-head" role="row">
                <span role="columnheader">Tool category</span><span role="columnheader">Calculate</span><span role="columnheader">Formulate</span><span role="columnheader">Save</span><span role="columnheader">Labels</span><span role="columnheader">Batch record</span><span role="columnheader">Production</span><span role="columnheader">Team</span>
            </div>
            <div class="workflow-row" role="row">
                <strong role="rowheader">Quick calculators</strong><span class="has" aria-label="Usually included">●</span><span class="partial" aria-label="Sometimes included">◐</span><span class="none" aria-label="Usually absent">○</span><span class="none" aria-label="Usually absent">○</span><span class="none" aria-label="Usually absent">○</span><span class="none" aria-label="Usually absent">○</span><span class="none" aria-label="Usually absent">○</span>
            </div>
            <div class="workflow-row" role="row">
                <strong role="rowheader">Formulation software</strong><span class="partial" aria-label="Sometimes included">◐</span><span class="has" aria-label="Usually included">●</span><span class="has" aria-label="Usually included">●</span><span class="partial" aria-label="Sometimes included">◐</span><span class="partial" aria-label="Sometimes included">◐</span><span class="none" aria-label="Usually absent">○</span><span class="partial" aria-label="Sometimes included">◐</span>
            </div>
            <div class="workflow-row" role="row">
                <strong role="rowheader">Production ERP</strong><span class="none" aria-label="Usually absent">○</span><span class="none" aria-label="Usually absent">○</span><span class="partial" aria-label="Sometimes included">◐</span><span class="none" aria-label="Usually absent">○</span><span class="has" aria-label="Usually included">●</span><span class="has" aria-label="Usually included">●</span><span class="has" aria-label="Usually included">●</span>
            </div>
            <div class="workflow-row workflow-soapkraft" role="row">
                <strong role="rowheader">Soapkraft</strong><span class="has" aria-label="Included">●</span><span class="has" aria-label="Included">●</span><span class="has" aria-label="Included">●</span><span class="has" aria-label="Included">●</span><span class="has" aria-label="Included">●</span><span class="has" aria-label="Included in Studio">●</span><span class="has" aria-label="Included in Team">●</span>
            </div>
        </div>

        <div class="workflow-legend reveal" aria-label="Comparison legend">
            <span><i class="has">●</i> Usually included</span><span><i class="partial">◐</i> Sometimes included</span><span><i class="none">○</i> Usually absent</span>
            <p>Typical coverage varies by product. Soapkraft production management starts with Studio; team access starts with Team.</p>
        </div>
    </div>
</section>
```

- [ ] **Step 4: Add responsive workflow styling**

Add component rules that use an eight-column CSS grid at desktop, retain `min-width: 760px` inside an overflow container at tablet widths, and present visible scroll affordance. Use copper only for the Soapkraft row and green only for positive capability markers. Include:

```css
.workflow { background: var(--surface); }
.workflow-table { overflow-x: auto; border: 1px solid var(--line); border-radius: 12px; background: var(--panel); }
.workflow-row { display: grid; grid-template-columns: minmax(10rem, 1.5fr) repeat(7, minmax(4.5rem, 0.7fr)); min-width: 760px; border-bottom: 1px solid var(--line); }
.workflow-row:last-child { border-bottom: 0; }
.workflow-row > * { display: grid; min-height: 3.75rem; place-items: center; padding: 0.75rem; border-right: 1px solid var(--line); text-align: center; }
.workflow-row > :first-child { place-items: center start; text-align: left; }
.workflow-row > :last-child { border-right: 0; }
.workflow-head { background: var(--panel-strong); color: var(--ink-soft); font-size: 0.72rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
.workflow-soapkraft { background: var(--accent-soft); box-shadow: inset 0 0 0 1px color-mix(in oklch, var(--accent) 30%, transparent); }
.workflow-soapkraft strong { color: var(--accent-strong); }
.workflow-row .has { color: var(--living-green); }
.workflow-row .partial { color: var(--chemistry); }
.workflow-row .none { color: color-mix(in oklch, var(--ink-soft) 58%, transparent); }
.workflow-legend { display: flex; flex-wrap: wrap; gap: 0.8rem 1.25rem; margin-top: 1rem; color: var(--ink-soft); font-size: 0.78rem; }
.workflow-legend p { flex-basis: 100%; margin: 0; }
```

- [ ] **Step 5: Add workflow links to desktop and mobile navigation**

Replace the least informative `Soap & cosmetics` nav item with `Workflow`, linking to `#workflow`, in both `.nav-links` and `#mobile-menu`. Keep the other navigation targets intact and preserve five mobile links so the inherited staggered animation remains valid.

- [ ] **Step 6: Run the focused test**

Run:

```bash
node --test --test-name-pattern='connected workflows' '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs'
```

Expected: PASS.

### Task 4: Clarify batch records, production packaging, FAQ, and final actions

**Files:**
- Modify: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/index.html`
- Modify: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/styles.css`
- Test: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs`

- [ ] **Step 1: Add failing product-boundary tests**

Append:

```js
test("V8 separates batch evidence from production management", () => {
    const html = read("index.html");
    const readableText = html.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();

    assert.match(readableText, /Batch record Record what was made/);
    assert.match(readableText, /Production module Plan and manage what happens next/);
    assert.match(readableText, /formula snapshot and ingredient batch numbers/);
    assert.match(readableText, /Production planning, stock and material consumption, suppliers and supplier listings/);
    assert.doesNotMatch(readableText, /simplified ERP/i);
});

test("V8 pricing and FAQ communicate the approved plan ladder", () => {
    const html = read("index.html");

    for (const plan of ["Free", "Maker", "Studio", "Team"]) {
        assert.match(html, new RegExp(`<h3>${plan}</h3>`));
    }
    assert.match(html, /Limited batch records/);
    assert.match(html, /Higher batch-record allowance/);
    assert.match(html, /Complete production module/);
    assert.match(html, /Members, roles and permissions/);
    assert.match(html, /id="faq"/);
    assert.match(html, /Does Soapkraft replace professional regulatory review\?/);
    assert.match(html, /Guidance does not replace qualified regulatory review/);
});
```

- [ ] **Step 2: Run the focused tests and confirm failure**

Run:

```bash
node --test --test-name-pattern='batch evidence|approved plan ladder' '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs'
```

Expected: FAIL because the new distinction and FAQ are absent.

- [ ] **Step 3: Extend the free-workspace path**

In the registered Free path under `#start-free`, keep the existing formula, ingredient, image, note, and version items, then add:

```html
<li>Limited batch records with formula snapshots and ingredient batch numbers</li>
```

Do not describe stock, planning, purchasing, or supplier tools in the Free path.

- [ ] **Step 4: Insert the production distinction before pricing**

Insert after `#founder` and before `#plans`:

```html
<section class="production-path section" aria-labelledby="production-path-heading">
    <div class="shell production-path-grid">
        <header class="section-heading reveal">
            <p class="eyebrow">From evidence to operations</p>
            <h2 class="display" id="production-path-heading">Keep a batch record now. Manage production when you need it.</h2>
            <p>The free workspace can document a small number of batches. Studio adds the operational tools for running production.</p>
        </header>
        <div class="production-steps reveal" style="--delay: 100ms">
            <article>
                <span>Included with Free, within limits</span>
                <h3>Batch record</h3>
                <strong>Record what was made</strong>
                <p>Keep the formula snapshot and ingredient batch numbers with the production batch.</p>
            </article>
            <i aria-hidden="true">→</i>
            <article class="is-production">
                <span>Included with Studio</span>
                <h3>Production module</h3>
                <strong>Plan and manage what happens next</strong>
                <p>Production planning, stock and material consumption, suppliers and supplier listings.</p>
            </article>
        </div>
    </div>
</section>
```

- [ ] **Step 5: Rewrite plan features without changing price data**

Keep the existing monthly/annual values and billing toggle. Replace each plan's feature list with:

```html
<!-- Free -->
<ul class="plan-features">
    <li>Saved formulas and your own ingredients, within limits</li>
    <li>Images, notes and version history</li>
    <li>Limited batch records</li>
</ul>

<!-- Maker -->
<ul class="plan-features">
    <li>More formulas and ingredients</li>
    <li>Labels, allergen and IFRA guidance</li>
    <li>Higher batch-record allowance</li>
</ul>

<!-- Studio -->
<ul class="plan-features">
    <li>Everything in Maker</li>
    <li>Complete production module</li>
    <li>Planning, stock and supplier management</li>
</ul>

<!-- Team -->
<ul class="plan-features">
    <li>Everything in Studio</li>
    <li>Members, roles and permissions</li>
    <li>Shared company workspace</li>
</ul>
```

Mark Studio visually as `For production` and keep Team as the collaboration plan. Do not label either paid plan `Most popular` without customer evidence.

- [ ] **Step 6: Add the native FAQ and final action section**

Insert after `#plans` and before the footer:

```html
<section class="faq section" id="faq" aria-labelledby="faq-heading">
    <div class="shell faq-grid">
        <header class="section-heading reveal">
            <p class="eyebrow">Before you start</p>
            <h2 class="display" id="faq-heading">A few practical answers.</h2>
        </header>
        <div class="faq-list reveal" style="--delay: 100ms">
            <details><summary>Is the calculator free without an account?</summary><p>Yes. Use the soap calculator without registering. Create an account only when you want to save formulas and keep a workspace.</p></details>
            <details><summary>What does the registered Free plan include?</summary><p>A limited formula portfolio with your own ingredients, images, notes, versions and simple batch records.</p></details>
            <details><summary>What is the difference between a batch record and the production module?</summary><p>A batch record documents what was made, including the formula snapshot and ingredient batch numbers. Studio adds planning, stock, material consumption and supplier management.</p></details>
            <details><summary>Does Soapkraft replace professional regulatory review?</summary><p>No. Soapkraft provides calculations, ingredient lists, allergen signals and IFRA guidance. Guidance does not replace qualified regulatory review.</p></details>
            <details><summary>Can I use my own ingredients?</summary><p>Yes. The Free plan includes private ingredients within its limits, with higher capacity on paid plans.</p></details>
            <details><summary>Can a team share the workspace?</summary><p>Yes. Team adds members, roles and permissions to the Studio workspace.</p></details>
        </div>
    </div>
</section>

<section class="closing-action section" aria-labelledby="closing-action-heading">
    <div class="shell closing-action-inner reveal">
        <p class="eyebrow">Start where you are</p>
        <h2 class="display" id="closing-action-heading">Calculate now. Keep the formula when it is worth saving.</h2>
        <div class="hero-actions">
            <a class="button button-secondary" href="#bench-demo">Start calculating</a>
            <a class="button button-primary" href="#plans">Open free workspace</a>
        </div>
    </div>
</section>
```

- [ ] **Step 7: Add production, FAQ, and final-action styling**

Add component rules using the existing tokens. The production path should use a neutral card for batch evidence and a copper-accented card for Studio, not two green panels. Use native `details` markers hidden only when a visible custom plus/minus indicator is supplied via `summary::after`.

```css
.production-path { background: var(--panel); }
.production-path-grid { display: grid; grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr); gap: clamp(2rem, 6vw, 6rem); align-items: center; }
.production-steps { display: grid; grid-template-columns: 1fr auto 1fr; gap: 1rem; align-items: center; }
.production-steps article { min-height: 15rem; padding: 1.5rem; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); }
.production-steps article.is-production { border-color: color-mix(in oklch, var(--accent) 45%, var(--line)); background: var(--accent-soft); }
.production-steps > i { color: var(--accent); font-style: normal; font-size: 1.5rem; }
.faq-grid { display: grid; grid-template-columns: minmax(0, 0.7fr) minmax(0, 1.3fr); gap: clamp(2rem, 6vw, 6rem); align-items: start; }
.faq-list details { border-top: 1px solid var(--line); }
.faq-list details:last-child { border-bottom: 1px solid var(--line); }
.faq-list summary { display: flex; min-height: 3.5rem; align-items: center; justify-content: space-between; gap: 1rem; cursor: pointer; font-weight: 700; list-style: none; }
.faq-list summary::-webkit-details-marker { display: none; }
.faq-list summary::after { content: "+"; color: var(--accent); font-size: 1.25rem; }
.faq-list details[open] summary::after { content: "−"; }
.faq-list details p { max-width: 46rem; margin: 0 0 1.25rem; color: var(--ink-soft); }
.closing-action { background: var(--accent-strong); color: var(--panel); text-align: center; }
.closing-action-inner { display: grid; justify-items: center; }
```

At the existing mobile breakpoint, set `.production-path-grid`, `.faq-grid`, and `.production-steps` to one column; rotate the production arrow visually to point downward with `transform: rotate(90deg)`.

- [ ] **Step 8: Run the focused tests**

Run:

```bash
node --test --test-name-pattern='batch evidence|approved plan ladder' '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs'
```

Expected: PASS.

### Task 5: Reconcile inherited tests and strengthen accessibility contracts

**Files:**
- Modify: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs`
- Modify if required by test failures: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/index.html`
- Modify if required by test failures: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/styles.css`

- [ ] **Step 1: Replace stale V7 copy assertions with V8's approved semantics**

Update the inherited hero test so it asserts the approved V8 heading, subhead, action order, and support line. Update the free-workspace test so it allows `Open free workspace`, `label signals`, and the new registered-Free copy. Keep all inherited accessibility, touch-target, billing, progressive-enhancement, colour-token, and local-reference assertions.

The readable-copy assertions must include:

```js
assert.ok(readableText.includes("Your formula, your ingredients, your bench in one place."));
assert.ok(readableText.includes("Start with a quick lye calculation"));
assert.ok(readableText.includes("No account needed for calculation · Register only when you want to save"));
assert.match(html, /Start calculating<\/a>\s*<a class="button button-primary" href="#plans">Open free workspace/);
```

- [ ] **Step 2: Add structural and accessibility assertions**

Append:

```js
test("V8 exposes complete navigation and accessible disclosure contracts", () => {
    const html = read("index.html");
    const css = read("styles.css");

    for (const id of ["hero", "start-free", "formulation-types", "formula-record", "workflow", "founder", "plans", "faq"]) {
        assert.match(html, new RegExp(`id="${id}"`));
    }
    assert.match(html, /href="#workflow"/);
    assert.match(html, /<details><summary>/);
    assert.match(css, /summary:focus-visible|:focus-visible/);
    assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
    assert.match(css, /\.marquee-track[\s\S]*animation:\s*none !important/);
});

test("V8 does not overclaim compliance or production access", () => {
    const html = read("index.html");
    const readableText = html.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim();
    const freePlan = html.match(/<article class="plan-card[^>]*>[\s\S]*?<h3>Free<\/h3>[\s\S]*?<\/article>/)?.[0] ?? "";

    assert.doesNotMatch(readableText, /guaranteed compliant|ensures compliance|fully compliant/i);
    assert.notEqual(freePlan, "");
    assert.doesNotMatch(freePlan, /stock|planning|supplier management/i);
    assert.match(readableText, /Studio adds planning, stock, material consumption and supplier management/);
});
```

- [ ] **Step 3: Run the complete suite and fix only concrete failures**

Run:

```bash
node --test '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs'
```

Expected: all tests pass. If a failure identifies a genuine mismatch, fix the V8 HTML or CSS with `apply_patch`; do not delete the contract unless it directly contradicts the approved design specification.

- [ ] **Step 4: Run static integrity checks**

Run:

```bash
rg -n 'href="#"|simplified ERP|guaranteed compliant|Most popular' '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8'
```

Expected: no matches.

### Task 6: Visual and interaction verification

**Files:**
- Verify: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/index.html`
- Verify: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/styles.css`
- Verify: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/script.js`
- Verify: `/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs`

- [ ] **Step 1: Open V8 directly in the browser**

Open:

```text
file:///Users/philippe/My%20Drive/_Philippe__PCRAG/02%20CAPS/Applications%20SAAS%20PLUGIN/soapkraft-homepage-reference/v8/index.html
```

Expected: no missing local assets, console errors, or flash of permanently hidden content.

- [ ] **Step 2: Inspect desktop at approximately 1440 × 900**

Verify:

- Hero background supports rather than competes with the headline.
- No blank lower-left hero area is visible.
- Bench reads as a continuation of the hero.
- Marquee loops without a visible jump and pauses on hover.
- Workflow table is readable without clipping.
- Studio is visually emphasized with copper; the page is not predominantly green.
- Pricing toggle changes 12/29/59 to 10/24/49 and updates the billing notes.

- [ ] **Step 3: Inspect tablet at approximately 820 × 1180**

Verify:

- Mobile navigation opens, closes on link selection, and closes with Escape.
- Workflow comparison scrolls horizontally with readable labels.
- Production distinction and pricing cards maintain order.
- Section transitions remain soft rather than sharp accidental cuts.

- [ ] **Step 4: Inspect mobile at approximately 390 × 844**

Verify:

- Hero text does not overflow and both actions remain at least 44px high.
- Bench context cards do not create horizontal page overflow.
- Marquee is clipped to its own container.
- Production arrow points downward between stacked cards.
- FAQ summaries are keyboard- and touch-operable.
- Footer links and final actions fit without tiny text.

- [ ] **Step 5: Verify keyboard and reduced motion**

Tab through the page from the skip link. Confirm every interactive control has a visible focus state and the mobile menu returns focus on Escape. Enable reduced motion in the browser or operating system and reload; confirm the marquee is static, reveals are visible, and the bench shows a useful final state.

- [ ] **Step 6: Run the final automated verification**

Run:

```bash
node --test '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8/verify.test.mjs'
```

Expected: all V8 tests pass with zero failures.

- [ ] **Step 7: Confirm version isolation**

Run:

```bash
diff -rq '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v7' '/Users/philippe/My Drive/_Philippe__PCRAG/02 CAPS/Applications SAAS PLUGIN/soapkraft-homepage-reference/v8'
```

Expected: differences are reported for V8's HTML, CSS, and test file; V7 still exists and remains untouched. The hero WebP and unchanged JavaScript should compare as identical unless a concrete verified interaction fix required a V8-only JavaScript edit.
