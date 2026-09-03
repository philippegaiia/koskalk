// Generator + auditor for the 22 ingredient-category badge colours.
//
//   node scripts/badge-palette.mjs              audit the scale and print the CSS
//   node scripts/badge-palette.mjs --check      verify app.css still matches generation
//   node scripts/badge-palette.mjs --out DIR    also write badge-palette.css + palette.json
//
// Every text colour is SOLVED against its own background for a fixed contrast
// ratio rather than hand-picked, so all 22 read with equal legibility. Re-tuning
// one value by hand breaks that parity -- change the inputs below and regenerate.
//
// Colour maths: oklch -> Oklab -> linear sRGB -> WCAG contrast ratio.

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const REPO = fileURLToPath(new URL('../', import.meta.url));

const f = (x) => (x <= 0.0031308 ? 12.92 * x : 1.055 * Math.pow(x, 1 / 2.4) - 0.055);
const fInv = (x) => (x <= 0.04045 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4));

const oklchToOklab = ({ L, C, h }) => {
  const hr = (h * Math.PI) / 180;
  return { L, a: C * Math.cos(hr), b: C * Math.sin(hr) };
};

function oklabToLinearSrgb({ L, a, b }) {
  const l_ = L + 0.3963377774 * a + 0.2158037573 * b;
  const m_ = L - 0.1055613458 * a - 0.0638541728 * b;
  const s_ = L - 0.0894841775 * a - 1.291485548 * b;
  const l = l_ * l_ * l_, m = m_ * m_ * m_, s = s_ * s_ * s_;
  return {
    r: 4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
    g: -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
    b: -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
  };
}

const oklchToLinear = (s) => oklabToLinearSrgb(oklchToOklab(s));
const oklchToRgb255 = (s) => {
  const lin = oklchToLinear(s);
  return {
    r: Math.min(255, Math.max(0, Math.round(f(lin.r) * 255))),
    g: Math.min(255, Math.max(0, Math.round(f(lin.g) * 255))),
    b: Math.min(255, Math.max(0, Math.round(f(lin.b) * 255))),
  };
};
const lum = ({ r, g, b }) => 0.2126 * fInv(r / 255) + 0.7152 * fInv(g / 255) + 0.0722 * fInv(b / 255);
const ratio = (a, b) => {
  const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};
const hex = ({ r, g, b }) => '#' + [r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('');

// Out-of-gamut check: linear sRGB must stay inside [0,1] or the browser clips and the
// rendered hue drifts from the one we audited.
function inGamut(spec, tol = 0.004) {
  const lin = oklchToLinear(spec);
  return Object.values(lin).every((v) => v >= -tol && v <= 1 + tol);
}

const PANEL = { L: 0.985, C: 0.006, h: 85 }; // --color-panel, what the badge sits on

// Chroma tiers: chroma carries "how much attention this deserves" alongside hue.
// hazard = vivid, standard = normal, muted = composite/blend, neutral = no signal.
// Backgrounds sit at L 94.8%, where sRGB max chroma is only ~0.03-0.05 depending on
// hue, so background chroma stays low and gamut is checked for BOTH layers below.
const TIERS = {
  hazard:  { bg: 0.038, text: 0.150 },
  standard:{ bg: 0.030, text: 0.120 },
  muted:   { bg: 0.020, text: 0.078 },
  neutral: { bg: 0.006, text: 0.012 },
};

// Small lightness offsets, cycled so that hue neighbours never land on the same L.
// Hue spacing alone gives a chord of only ~2*C*sin(8deg); alternating L roughly
// doubles the separation between adjacent members of a family.
const L_OFFSETS = [0, -0.030, 0.030, -0.015, 0.015];

// [enum value, hue, tier, family]
// Hues are 16 deg apart around the wheel; related categories occupy adjacent slots so
// the palette reads as families rather than as 22 unrelated colours.
// Must stay in step with App\Enums\IngredientCategory::cases().
const CATS = [
  ['preservation_stability',   10, 'hazard',   'process chemistry'],
  ['ph_adjusters_buffers',     26, 'hazard',   'process chemistry'],
  ['aromatic_materials',       42, 'hazard',   'aromatic / IFRA'],
  ['exfoliants_abrasives',     58, 'standard', 'earthy / particulate'],
  ['minerals_salts_powders',   74, 'standard', 'earthy / particulate'],
  ['other',                    90, 'neutral',  'unclassified'],
  ['hydrocarbons',            106, 'standard', 'oil phase'],
  ['bases_blends_premixes',   122, 'muted',    'composite'],
  ['waxes',                   138, 'standard', 'oil phase'],
  ['botanicals_extracts',     154, 'standard', 'oil phase'],
  ['lipids',                  170, 'standard', 'oil phase'],
  ['fatty_derivatives',       186, 'standard', 'oil phase'],
  ['water_solvents_carriers', 202, 'standard', 'water phase'],
  ['humectants_polyols',      218, 'standard', 'water phase'],
  ['surfactants',             234, 'standard', 'surface chemistry'],
  ['emulsifiers',             250, 'standard', 'surface chemistry'],
  ['rheology_modifiers',      266, 'standard', 'texture / polymers'],
  ['functional_polymers',     282, 'standard', 'texture / polymers'],
  ['silicones',               298, 'standard', 'texture / polymers'],
  ['actives',                 314, 'standard', 'specialty'],
  ['colourants',              330, 'standard', 'colour'],
  ['soapmaking_alkalis',      346, 'hazard',   'process chemistry'],
];

const TARGET = 6.0; // comfortable margin over the 4.5:1 floor for 12px text

function solveTextL(bg, h, C, target) {
  // Contrast falls monotonically as the text lightens against a pale background.
  let lo = 0.18, hi = 0.62;
  for (let i = 0; i < 60; i++) {
    const mid = (lo + hi) / 2;
    const r = ratio(oklchToRgb255({ L: mid, C, h }), bg);
    if (r >= target) { lo = mid; } else { hi = mid; }
  }
  return lo;
}

const rows = [];
let idx = 0;
for (const [key, hue, tier, family] of CATS) {
  const { bg, text } = TIERS[tier];
  const offset = L_OFFSETS[idx % L_OFFSETS.length];
  idx++;

  // Pull background chroma back first: at L 94.8% it clips well before the text does.
  let bgC = bg;
  let bgGuard = 0;
  while (!inGamut({ L: 0.948, C: bgC, h: hue }) && bgGuard < 40) {
    bgC *= 0.94;
    bgGuard++;
  }
  const bgSpec = { L: 0.948, C: bgC, h: hue };
  const bgRgb = oklchToRgb255(bgSpec);

  let C = text;
  let L = Math.min(0.60, Math.max(0.20, solveTextL(bgRgb, hue, C, TARGET) + offset));
  let guard = 0;
  while (!inGamut({ L, C, h: hue }) && guard < 40) {
    C *= 0.94;
    L = Math.min(0.60, Math.max(0.20, solveTextL(bgRgb, hue, C, TARGET) + offset));
    guard++;
  }

  const fgSpec = { L, C, h: hue };
  const fgRgb = oklchToRgb255(fgSpec);
  rows.push({
    key, hue, tier, family, bgSpec, fgSpec, bgRgb, fgRgb,
    contrast: ratio(fgRgb, bgRgb),
    onPanel: ratio(bgRgb, oklchToRgb255(PANEL)),
    gamut: inGamut(fgSpec) && inGamut(bgSpec),
    chromaPulled: guard,
    bgPulled: bgGuard,
  });
}

const pct = (v) => (v * 100).toFixed(1) + '%';
const famOf = Object.fromEntries(CATS.map(([k, , , fam]) => [k, fam]));

// Grouped, commented CSS ready to splice into resources/css/app.css. Ordered by
// hue, which is also family order, so the file reads as families not as a list.
const FAMILY_NOTE = {
  'process chemistry': 'Corrosive / reactive: the most saturated treatment in the palette.',
  'aromatic / IFRA': 'Fragrance materials, flagged like the other hazard-handling groups.',
  'earthy / particulate': 'Dry, mineral, abrasive.',
  unclassified: 'Deliberately near-neutral: no signal to carry.',
  'oil phase': 'Lipophilic materials, one continuous hue run.',
  composite: 'Muted: a blend has no single chemistry of its own.',
  'water phase': 'Hydrophilic materials.',
  'surface chemistry': 'Surfactants and emulsifiers.',
  'texture / polymers': 'Rheology, film-formers, silicones.',
  specialty: 'Actives.',
  colour: 'Colourants.',
};

// Emit grouped by family (each group ordered by hue) rather than in raw wheel
// order: the hue 122 composite slot would otherwise split the oil-phase run in
// two. Source order is irrelevant to these rules -- same specificity, disjoint
// selectors -- so the file is ordered for readability instead.
const byFamily = new Map();
for (const r of rows) {
  const fam = famOf[r.key];
  if (!byFamily.has(fam)) { byFamily.set(fam, []); }
  byFamily.get(fam).push(r);
}
const ordered = [...byFamily.entries()]
  .sort((a, b) => Math.min(...a[1].map((r) => r.hue)) - Math.min(...b[1].map((r) => r.hue)))
  .flatMap(([, rs]) => rs.sort((a, b) => a.hue - b.hue));

function buildCss() {
  const out = [];
  let lastFam = null;
  for (const r of ordered) {
    const fam = famOf[r.key];
    if (fam !== lastFam) {
      if (lastFam !== null) { out.push(''); }
      out.push(`    /* ${fam} — ${FAMILY_NOTE[fam] ?? ''} */`);
      lastFam = fam;
    }
    const { L, C, h } = r.fgSpec;
    out.push(
      `    .sk-badge-${r.key} {\n` +
      `        background-color: oklch(${pct(r.bgSpec.L)} ${r.bgSpec.C.toFixed(4)} ${r.bgSpec.h});\n` +
      `        color: oklch(${pct(L)} ${C.toFixed(3)} ${h});\n` +
      `    }`,
    );
  }
  return out.join('\n') + '\n';
}

const css = buildCss();
const argv = process.argv.slice(2);

// --check: the taxonomy test only proves each rule exists and sets both colours.
// It cannot see the values, so a hand-edited hue still passes. This closes that.
if (argv.includes('--check')) {
  const app = readFileSync(REPO + 'resources/css/app.css', 'utf8');
  const bad = [];
  for (const r of rows) {
    const re = new RegExp(
      '\\.sk-badge-' + r.key + '\\s*\\{([^}]*)\\}',
    );
    const m = app.match(re);
    if (!m) { bad.push(`${r.key}: no rule in app.css`); continue; }
    const wantBg = `oklch(${pct(r.bgSpec.L)} ${r.bgSpec.C.toFixed(4)} ${r.bgSpec.h})`;
    const wantFg = `oklch(${pct(r.fgSpec.L)} ${r.fgSpec.C.toFixed(3)} ${r.fgSpec.h})`;
    if (!m[1].includes(wantBg)) { bad.push(`${r.key}: background is not ${wantBg}`); }
    if (!m[1].includes(wantFg)) { bad.push(`${r.key}: colour is not ${wantFg}`); }
  }
  if (bad.length) {
    console.error(`FAIL -- app.css has drifted from scripts/badge-palette.mjs:\n`);
    bad.forEach((b) => console.error('  ' + b));
    process.exit(1);
  }
  console.log(`ok -- all ${rows.length} badge rules in resources/css/app.css match generation`);
  process.exit(0);
}

console.log('category                    hue  tier      text/bg  bg/panel  gamut  L(text)  C(text)');
console.log('-'.repeat(94));
for (const r of rows) {
  console.log(
    r.key.padEnd(26) +
    String(r.hue).padStart(4) + '  ' +
    r.tier.padEnd(9) + ' ' +
    r.contrast.toFixed(2).padStart(7) + '  ' +
    r.onPanel.toFixed(2).padStart(8) + '  ' +
    (r.gamut ? 'ok  ' : 'CLIP') + '    ' +
    r.fgSpec.L.toFixed(3) + '   ' +
    r.fgSpec.C.toFixed(3) +
    (r.chromaPulled ? `  (pulled ${r.chromaPulled}x)` : ''),
  );
}

const worst = rows.reduce((a, b) => (a.contrast < b.contrast ? a : b));
console.log(`\nworst text/bg: ${worst.key} ${worst.contrast.toFixed(2)}:1  (AA needs 4.5)`);
console.log(`all pass AA  : ${rows.every((r) => r.contrast >= 4.5)}`);
console.log(`all in gamut : ${rows.every((r) => r.gamut)}`);

// Distinguishability: Oklab Euclidean distance between the TEXT colours, which carry
// the identity. Report the closest pairs.
const pairs = [];
for (let i = 0; i < rows.length; i++) {
  for (let j = i + 1; j < rows.length; j++) {
    const A = oklchToOklab(rows[i].fgSpec), B = oklchToOklab(rows[j].fgSpec);
    const dE = Math.hypot(A.L - B.L, A.a - B.a, A.b - B.b);
    pairs.push([dE, rows[i].key, rows[j].key]);
  }
}
pairs.sort((a, b) => a[0] - b[0]);
console.log('\nclosest pairs (Oklab dE between text colours):');
pairs.slice(0, 8).forEach(([d, a, b]) => {
  const same = famOf[a] === famOf[b] ? 'same family' : '** CROSS-FAMILY **';
  console.log(`  ${d.toFixed(4)}  ${a.padEnd(24)} / ${b.padEnd(24)} ${same}`);
});
const cross = pairs.filter(([, a, b]) => famOf[a] !== famOf[b]);
console.log(`\nclosest CROSS-FAMILY pair: ${cross[0][0].toFixed(4)}  ${cross[0][1]} / ${cross[0][2]}`);
console.log(`median dE (all pairs)    : ${pairs[Math.floor(pairs.length / 2)][0].toFixed(4)}`);
const within = pairs.filter(([, a, b]) => famOf[a] === famOf[b]);
console.log(`median dE (within family): ${within[Math.floor(within.length / 2)][0].toFixed(4)}`);

console.log('\n--- CSS ------------------------------------------------------------');
console.log(css);

const outFlag = argv.indexOf('--out');
if (outFlag !== -1 && argv[outFlag + 1]) {
  const dir = argv[outFlag + 1].replace(/\/$/, '');
  writeFileSync(dir + '/badge-palette.css', css);
  // Machine-readable form so downstream tooling (swatch sheet, docs) reuses the
  // audited numbers instead of re-deriving the colour maths.
  writeFileSync(dir + '/palette.json', JSON.stringify({
    target: TARGET,
    panel: PANEL,
    categories: rows.map((r) => ({
      key: r.key,
      hue: r.hue,
      tier: r.tier,
      family: famOf[r.key],
      contrast: Number(r.contrast.toFixed(2)),
      onPanel: Number(r.onPanel.toFixed(2)),
      background: r.bgSpec,
      text: r.fgSpec,
      hex: { background: hex(r.bgRgb), text: hex(r.fgRgb) },
    })),
  }, null, 2));
  console.log(`(also written to ${dir}/badge-palette.css and ${dir}/palette.json)`);
}
