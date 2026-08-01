# Soapkraft V8 Homepage Design

## Objective

Create a new `v8` homepage from V7 without modifying or replacing any earlier version. V8 should make Soapkraft immediately understandable to practical soap and cosmetic makers: visitors can either calculate without an account or use a free registered workspace that saves formulas and supports a small portfolio. The page should then show the natural path from formulation to batch records and full production management.

V8 should feel grounded, capable, and made by someone who understands the bench. It must retain the application's green-and-copper palette without becoming predominantly green or drifting into a generic terracotta SaaS aesthetic.

## Source and Deliverable

- Source: `soapkraft-homepage-reference/v7/`
- Deliverable: a separate `soapkraft-homepage-reference/v8/` folder
- Preserve V2 through V7 unchanged.
- Reuse V7's static HTML, CSS, JavaScript, hero asset, and lightweight test structure where appropriate.
- The result remains a standalone static prototype with no new build system or dependency.

## Audience and Positioning

The primary audience already uses formulation or production tools and recognizes the gaps: calculators do not save formulas, basic formulation tools omit additives or private ingredients, compliance signals are fragmented, and conventional ERP systems are often too complex for small producers.

Soapkraft should be positioned as a formulation workspace with its own practical production module, not as a reduced ERP. The page should avoid broad superiority claims and let the connected workflow demonstrate the difference.

Core message:

> Your formula, your ingredients, your bench — in one place.

Supporting message:

> Start with a quick lye calculation, or build and save a complete soap or cosmetic formula with phases, ingredients, costs, and label signals in one place.

Primary actions:

- `Start calculating` — no account required.
- `Open free workspace` — registration is required because formulas and related content are saved.

Support line:

> No account needed for calculation · Register only when you want to save

## Content Architecture

### 1. Header and hero

Retain the V7 navigation model and the two distinct calls to action. The hero uses the existing application-interface background at reduced visual strength so the copy remains dominant. The background should fill the hero without exposing the previous empty lower-left area.

The visual transition into the bench preview should be soft and intentional: shared background tones, overlapping cards, and a gradient or mask rather than a hard horizontal cut.

### 2. Restored capability marquee

Restore the moving strip that existed in V3, placed immediately below the hero. It acts as a quick vocabulary signal, not a feature checklist.

Use concise terms such as:

- Soap and cosmetic formulas
- Phases and additives
- Private ingredients
- Formula costing
- IFRA references
- Allergen signals
- INCI and label guidance
- Batch records
- Production planning

The loop must be seamless. Pause on hover and keyboard focus. Disable movement when reduced motion is requested.

### 3. Bench and workspace preview

Keep V7's compact animated interface preview rather than restoring V3's much larger annotated showcase. It should demonstrate saved formulas, a live calculation, additives, costs, and label signals without trying to simulate the entire application.

Animation remains subordinate to comprehension: small reveal, count, or status changes are acceptable; constant decorative motion is not.

### 4. Calculator versus free workspace

Explain the two free entry points side by side:

- **Quick calculator:** immediate calculation, no account, no saved portfolio.
- **Free workspace:** registered application access, saved formulas, images and notes, private ingredients within plan limits, and limited simple batch records.

This section should prevent `free calculator` from being mistaken for the complete Free plan.

### 5. Connected workflow comparison

Add a category-coverage section titled along the lines of `Follow the formula from calculation to production.` It should compare workflows rather than named competitors.

Columns or stages:

1. Calculate
2. Formulate
3. Save and document
4. Prepare labels
5. Record a batch
6. Manage production
7. Work together

Rows or categories:

- Quick calculators
- Formulation software
- Production ERP
- Soapkraft

Use plain indicators for typical coverage and include a short qualification that individual products vary. The purpose is to show fragmentation across categories and Soapkraft's connected workflow, not claim that every competing product lacks every feature.

### 6. Founder proof

Keep the founder section concise and first-person in tone. Philippe has used formulation and production tools for two decades, developed Soapkraft around gaps encountered in real work, and now uses it himself. Avoid inflated biography, anonymous testimonials, and unverified numerical claims.

### 7. Pricing preview

Show four plans with a monthly/annual toggle:

- **Free:** saved formulas and private ingredients within limits, images and notes, plus limited simple batch records containing a production snapshot and ingredient batch numbers. No stock, planning, purchasing, or supplier management.
- **Maker:** higher formula, ingredient, and batch-record allowances; label generator; allergen and IFRA guidance.
- **Studio:** the complete production module is included, not sold as an add-on. It covers production planning, stock and material consumption, suppliers, supplier listings, and fuller traceability.
- **Team:** everything in Studio plus members, roles, permissions, and a shared company workspace.

Retain the current indicative price ladder for the prototype: Free €0, Maker €12 monthly, Studio €29 monthly, and Team €59 monthly; annual equivalents display as €10, €24, and €49 per month. These are prototype prices intended to preserve launch-discount headroom, not a claim that final commercial pricing has been approved.

Do not publish exact numeric formula, ingredient, or batch-record limits until product entitlements are finalized. Use honest language such as `limited` and `higher allowance`.

### 8. Production distinction

Where pricing or workflow coverage mentions production, make this distinction explicit:

- **Batch record:** records what was made, the formula snapshot, and ingredient batch numbers.
- **Production module:** plans and manages what happens next, including production work, materials, stock, and suppliers.

Never label the simple batch record as production management. Never describe Soapkraft as a simplified ERP.

### 9. FAQ

Answer only questions that materially reduce uncertainty:

- Is the calculator free without an account?
- What does the registered Free plan include?
- What is the difference between a batch record and the production module?
- Does Soapkraft replace professional regulatory review?
- Can I use my own ingredients?
- Can a team share the workspace?

Compliance language must describe calculations, references, signals, and guidance. It must not promise legal compliance or replace qualified review.

### 10. Final action and footer

Repeat the same two honest choices from the hero: calculate now or create a free workspace. Do not introduce a third competing primary action at the end of the page.

## Visual Direction

- Preserve V7's warm off-white foundation, dark ink typography, application green, and copper accent.
- Use green for functional or successful states and copper for actions, highlights, rules, and selective emphasis.
- Avoid washing whole sections in green.
- Avoid orange-pink terracotta gradients and the familiar AI landing-page pattern of isolated italic words in a contrasting colour.
- Keep typography editorial but readable. Serif display type can carry the main message; interface and explanatory copy should remain plain and direct.
- Prefer subtle borders, warm surfaces, modest radii, and layered bench cards over floating glass panels.
- Vary section backgrounds and overlap edges gently so the page does not feel like a stack of sharply cut bands.

## Interaction and Motion

- Monthly/annual pricing toggle updates displayed prices and billing labels without layout shift.
- Header navigation scrolls to the matching sections.
- Mobile navigation remains keyboard-operable and closes after selecting a link.
- Marquee motion is slow, seamless, and optional under reduced-motion preferences.
- Scroll reveals may use small opacity and vertical-position changes only.
- Interface previews may animate once when entering view; they must not distract from reading.
- No autoplay sound, parallax-heavy effects, cursor gimmicks, or animation required to understand content.

## Responsive and Accessibility Requirements

- Preserve readable line lengths and clear action hierarchy from wide desktop to narrow mobile.
- Comparison content must remain understandable when it cannot display as a wide table; convert it into category cards or horizontally labeled rows rather than forcing tiny text.
- Pricing cards may stack, but plan order and the Studio emphasis must remain clear.
- Provide visible focus states, semantic headings, landmark regions, and descriptive link/button labels.
- Decorative imagery should use empty alternative text; meaningful interface images require concise descriptions.
- Meet WCAG AA contrast for text and controls.
- Respect `prefers-reduced-motion` and avoid hover-only disclosures.

## Implementation Boundaries

- Create V8 only after this specification and its implementation plan are approved.
- Do not modify V7 or earlier versions.
- Do not add live authentication, billing, database integration, analytics, or real calculator logic to this static homepage prototype.
- Do not name competitors in the comparison.
- Do not add unverified testimonials, customer counts, savings claims, or compliance guarantees.

## Verification

Update the static verification test to assert:

- The V8 page contains the two distinct free entry points.
- The marquee exists and repeats enough content for a seamless loop.
- The Free, Maker, Studio, and Team plans are present.
- The Studio plan includes the full production module.
- The simple batch record is not described as stock, planning, or supplier management.
- Monthly and annual prices can be toggled.
- Reduced-motion rules cover the marquee and reveal animations.
- Internal navigation targets exist.

Perform visual checks at representative desktop, tablet, and mobile widths, including keyboard navigation and reduced-motion mode.
