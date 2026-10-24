# DIP Phase 3b — IFRA Ingredient-Use Check Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Check every fragrance ingredient of a saved bar-soap formula against its IFRA limit for the formula's IFRA category and amendment: supplier IFRA certificates first, with the amendment and grade they cover recorded; the ingredient's own IFRA certificate only as an unconcluded fallback; missing or unusable information kept « À justifier »; an established exceedance blocking approval with its own reason.

**Architecture:** IFRA certificate documents gain two recorded facts (amendment covered, applies to the qualified grade) and a limit type (`maximum` or `no_restriction`) on their use limits. A new `IfraUseChecker` evaluates each recipe ingredient **as purchased** — a blend at its whole dosage, before any decomposition into components — against the formula's IFRA category and amendment, and returns one result per ingredient. `DossierAssessmentEngine` adds these results as `ifra_uses`, separate from the substance rows, and refuses approval unless each is `conforme` or `not_applicable`. Peroxide values move from one mmol/L field to value + unit + comparison + basis, without conversion.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4 with Filament 5 schemas, Pest 4.

**Origin:** Codex's design review of 2026-10-24 (four P1, three P2 points, all verified against the code and accepted). Owner decision the same day: the bar-soap pilot requires IFRA category 9, or a justified exception.

---

## Before you start

- Branch `codex/dip-pilot`. `export LANG=en_US.UTF-8` before running tests.
- Read `.ai/rules/index.md` and the rule files for the paths you touch: `app.md`, `actions.md`, `migrations.md`, `models.md`, `livewire.md`, `lang.md`, `data.md`, `services.md`, `tests.md`.
- Pint after PHP changes. Do not migrate the owner's local database until Task 5.
- Checked on 2026-10-24: no DIP evidence item in the local database has stored facts, so renaming the peroxide fact needs no data migration.

## Decisions

1. **IFRA applies to the ingredient as used.** The concentration compared is `100 × the ingredient's mass in the formula ÷ cured product mass`, all of it assumed retained (conservative; IFRA limits refer to the finished consumer product). A purchased blend is checked at its whole dosage against its own certificate. Restrictions arising from constituents shared across several ingredients are **not** checked automatically in the pilot; whenever an IFRA-relevant ingredient is present, the engine warns `ifra_constituent_review_required`, so this limited check is never presented as full IFRA conformity.
2. **Which ingredients are checked:** category `aromatic_materials`, or `requires_aromatic_compliance`, or any IFRA data on the ingredient (a supplier IFRA certificate or an ingredient IFRA certificate). Others are `not_applicable`. `requires_aromatic_compliance = false` never hides IFRA data that exists.
3. **Formula category and amendment:** the saved version's `ifra_product_category_id` and `ifra_amendment_id`. The pilot requires category code `9` (`dossiers.pilot_ifra_category_code`); another category needs a justification in the dossier options (`ifraCategoryJustification`). A missing category or amendment, or a non-9 category without justification, makes every checked ingredient `a_justifier` (`ifra_category_unresolved`, `ifra_amendment_unresolved`).
4. **A supplier certificate applies** when it is a current (not withdrawn) IFRA certificate on a **validated** qualification of the ingredient, records the amendment it covers equal to the formula's amendment (`facts.ifra_amendment_id`), is confirmed to cover the qualified grade (`facts.covers_supplied_grade = true`), and states a limit for the formula's category. Otherwise the qualification is **uncovered**, with the reason: `no_certificate`, `amendment_missing`, `amendment_mismatch`, `grade_not_confirmed`, `no_limit_for_category`. Older-amendment certificates stay as evidence; they never conclude conformity and are never silently accepted.
5. **Limit values:** a number is compared (`0` means not permitted at any positive use); `no_restriction` is an explicit statement recorded on the use limit; anything else is unresolved. On ingredient-level certificates a blank `max_percentage` means **unknown** (the admin form allows blanks; the editor does not), whatever its note says.
6. **State of one ingredient use** (separate from the toxicology row states):
   - a category or amendment problem → `a_justifier`;
   - the strictest applicable supplier limit exceeded → `non_conforme`, reason `ifra_limit_exceeded` (an established exceedance blocks approval even when other suppliers are uncovered);
   - no validated qualification → the ingredient certificate is shown as a fallback but never concludes: `a_justifier` with `ifra_fallback_only`, `ifra_fallback_limit_exceeded` or `ifra_no_evidence`;
   - any validated qualification uncovered → `a_justifier`, `ifra_supplier_uncovered`, listing each uncovered qualification and its reason;
   - otherwise `conforme`.
7. **Approval:** `can_approve` also requires every IFRA use to be `conforme` or `not_applicable`. Submission is not blocked: the assessor must see the IFRA results. `RowStateResolver` is unchanged, so a passing MoS can never hide an IFRA result.
8. **Peroxide value:** facts `peroxide_value`, `peroxide_unit` (`meq_o2_kg` or `mmol_l`), `peroxide_comparison` (`lte`, `lt`, `eq`) and `peroxide_basis` (`measured` or `specification`), replacing `peroxide_value_mmol_l`. Stored as given, never converted: mass and volume units need the density and the method's equivalence factor (IFRA peroxide method §5). The canonical unit for later comparisons is meq O₂/kg; converting other values is phase 4 work, with the density on record.

## File structure

| Area | Files |
| --- | --- |
| Limit type and facts | create `app/Enums/UseLimitType.php`, `app/Enums/PeroxideUnit.php`, `app/Enums/ReportedComparison.php`, `app/Enums/ReportedValueBasis.php`, migration `add_limit_type_to_dossier_evidence_use_limits`; modify `app/Models/DossierEvidenceUseLimit.php`, `app/Enums/EvidenceType.php`, `app/Actions/Dossiers/SaveDossierEvidenceData.php`, `database/factories/DossierEvidenceUseLimitFactory.php`; tests `tests/Feature/Dossiers/DossierEvidenceDataTest.php` |
| Document panel | modify `app/Livewire/Dossiers/RawMaterialDetail.php`, `resources/views/livewire/dossiers/raw-material-detail.blade.php`, `lang/en/dossiers.php`, `database/seeders/data/interface-translations.json`; test `tests/Feature/Dossiers/RawMaterialPagesTest.php` |
| Checker | create `app/Services/Dossiers/Calculation/IfraUseChecker.php`; modify `config/dossiers.php`; test `tests/Feature/Dossiers/Calculation/IfraUseCheckerTest.php` |
| Engine | modify `app/Data/DossierAssessmentOptions.php`, `app/Services/Dossiers/Calculation/DossierAssessmentEngine.php`; test `tests/Feature/Dossiers/Calculation/DossierAssessmentEngineTest.php` |
| Docs | modify the spec |

---

### Task 1: Limit type and IFRA certificate facts

**Files:**
- Create: `app/Enums/UseLimitType.php`, `app/Enums/PeroxideUnit.php`, `app/Enums/ReportedComparison.php`, `app/Enums/ReportedValueBasis.php`, `database/migrations/<timestamp>_add_limit_type_to_dossier_evidence_use_limits.php`
- Modify: `app/Models/DossierEvidenceUseLimit.php`, `app/Enums/EvidenceType.php`, `app/Actions/Dossiers/SaveDossierEvidenceData.php`
- Test: `tests/Feature/Dossiers/DossierEvidenceDataTest.php`

- [ ] **Step 1: Update the existing test data and add failing tests**

In `tests/Feature/Dossiers/DossierEvidenceDataTest.php`, replace `'facts' => ['peroxide_value_mmol_l' => '4.2'],` with:

```php
        'facts' => ['peroxide_value' => '4.2', 'peroxide_unit' => 'mmol_l', 'peroxide_comparison' => 'lte', 'peroxide_basis' => 'specification'],
```

and in the "unknown fact" dataset row replace `['facts' => ['peroxide_value_mmol_l' => '1']]` with `['facts' => ['peroxide_value' => '1']]`. If an assertion in that file reads `facts['peroxide_value_mmol_l']`, change it to `facts['peroxide_value']`.

Then append (the file's helpers `evidenceDataWorkspace()` and `evidenceOfType()` give the workspace and the document):

```php
it('records the IFRA amendment, grade coverage and a no-restriction limit', function (): void {
    [$owner, $workspace] = evidenceDataWorkspace();
    $item = evidenceOfType($workspace, EvidenceType::IfraCertificate);
    $category9 = IfraProductCategory::factory()->create(['code' => '9']);
    $category4 = IfraProductCategory::factory()->create(['code' => '4']);
    $amendment = IfraAmendment::factory()->create();

    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $item, [
        'use_limits' => [
            ['ifra_product_category_id' => $category9->id, 'limit_type' => 'maximum', 'max_percent' => '2.5'],
            ['ifra_product_category_id' => $category4->id, 'limit_type' => 'no_restriction', 'max_percent' => null],
        ],
        'facts' => ['ifra_amendment_id' => $amendment->id, 'covers_supplied_grade' => true],
    ]);
    $limits = $item->refresh()->useLimits->keyBy('ifra_product_category_id');

    expect($item->facts)->toBe(['ifra_amendment_id' => $amendment->id, 'covers_supplied_grade' => true])
        ->and($limits[$category9->id]->limit_type)->toBe(UseLimitType::Maximum)
        ->and($limits[$category9->id]->max_percent)->toBe('2.500000')
        ->and($limits[$category4->id]->limit_type)->toBe(UseLimitType::NoRestriction)
        ->and($limits[$category4->id]->max_percent)->toBeNull();
});

it('keeps a grade-coverage answer of no', function (): void {
    [$owner, $workspace] = evidenceDataWorkspace();
    $item = evidenceOfType($workspace, EvidenceType::IfraCertificate);

    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $item, ['facts' => ['covers_supplied_grade' => false]]);

    expect($item->refresh()->facts)->toBe(['covers_supplied_grade' => false]);
});

it('refuses incomplete IFRA and peroxide data', function (array $data, string $errorKey): void {
    [$owner, $workspace] = evidenceDataWorkspace();
    $item = evidenceOfType($workspace, EvidenceType::IfraCertificate);
    $category = IfraProductCategory::factory()->create();
    $data = json_decode(str_replace('CATEGORY', (string) $category->id, json_encode($data)), true);

    expect(fn () => app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $item, $data))
        ->toThrow(function (ValidationException $exception) use ($errorKey): void {
            expect($exception->errors())->toHaveKey($errorKey);
        });
})->with([
    'maximum without a value' => [['use_limits' => [['ifra_product_category_id' => 'CATEGORY', 'limit_type' => 'maximum', 'max_percent' => null]]], 'use_limits.0.max_percent'],
    'no restriction with a value' => [['use_limits' => [['ifra_product_category_id' => 'CATEGORY', 'limit_type' => 'no_restriction', 'max_percent' => '5']]], 'use_limits.0.max_percent'],
    'unknown limit type' => [['use_limits' => [['ifra_product_category_id' => 'CATEGORY', 'limit_type' => 'guess', 'max_percent' => '5']]], 'use_limits.0.limit_type'],
    'unknown amendment' => [['facts' => ['ifra_amendment_id' => 999999]], 'facts.ifra_amendment_id'],
    'peroxide value without unit' => [['facts' => ['peroxide_value' => '5', 'peroxide_comparison' => 'lte', 'peroxide_basis' => 'measured']], 'facts.peroxide_unit'],
    'peroxide value without basis' => [['facts' => ['peroxide_value' => '5', 'peroxide_unit' => 'meq_o2_kg', 'peroxide_comparison' => 'lte']], 'facts.peroxide_basis'],
    'the former mmol/L field' => [['facts' => ['peroxide_value_mmol_l' => '5']], 'facts'],
]);

it('keeps older use limits as maxima', function (): void {
    [$owner, $workspace] = evidenceDataWorkspace();
    $item = evidenceOfType($workspace, EvidenceType::IfraCertificate);
    $category = IfraProductCategory::factory()->create();

    app(SaveDossierEvidenceData::class)->handle($owner, $workspace, $item, [
        'use_limits' => [['ifra_product_category_id' => $category->id, 'max_percent' => '1.2']],
    ]);

    expect($item->useLimits()->sole()->limit_type)->toBe(UseLimitType::Maximum);
});
```

Add the imports the file lacks: `App\Enums\UseLimitType`, `App\Models\IfraAmendment`, `App\Models\IfraProductCategory`, `Illuminate\Validation\ValidationException`.

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Dossiers/DossierEvidenceDataTest.php`
Expected: FAIL (`UseLimitType` not found; the renamed peroxide fact is refused).

- [ ] **Step 3: Enums**

`app/Enums/UseLimitType.php`:

```php
<?php

namespace App\Enums;

/**
 * What a document states for one IFRA category or product class: a maximum (0 means not permitted) or, explicitly,
 * no restriction. Nothing else concludes a use.
 */
enum UseLimitType: string
{
    case Maximum = 'maximum';
    case NoRestriction = 'no_restriction';
}
```

`app/Enums/PeroxideUnit.php`:

```php
<?php

namespace App\Enums;

/**
 * Units a peroxide value is reported in. They are not interchangeable: converting needs the density and the method's
 * equivalence factor, so values are stored as reported.
 */
enum PeroxideUnit: string
{
    case MeqO2PerKg = 'meq_o2_kg';
    case MmolPerLitre = 'mmol_l';
}
```

`app/Enums/ReportedComparison.php`:

```php
<?php

namespace App\Enums;

/**
 * How a reported value relates to the true value: at most, below, or equal.
 */
enum ReportedComparison: string
{
    case AtMost = 'lte';
    case Below = 'lt';
    case Equal = 'eq';
}
```

`app/Enums/ReportedValueBasis.php`:

```php
<?php

namespace App\Enums;

/**
 * Whether a reported value is a measured result or a specification limit.
 */
enum ReportedValueBasis: string
{
    case Measured = 'measured';
    case Specification = 'specification';
}
```

- [ ] **Step 4: Migration**

Run: `php artisan make:migration add_limit_type_to_dossier_evidence_use_limits --no-interaction`, then:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_evidence_use_limits', function (Blueprint $table) {
            $table->string('limit_type', 16)->default('maximum')->after('max_percent');
            $table->decimal('max_percent', 12, 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        // A "no restriction" statement has no maximum to restore; those rows cannot exist in the older schema.
        DB::table('dossier_evidence_use_limits')->where('limit_type', 'no_restriction')->delete();

        Schema::table('dossier_evidence_use_limits', function (Blueprint $table) {
            $table->decimal('max_percent', 12, 6)->nullable(false)->change();
            $table->dropColumn('limit_type');
        });
    }
};
```

The table has no triggers or partial indexes (check with `php artisan truss:export --format=llm --focus=dossier_evidence_use_limits --depth=0` before running), so the SQLite rebuild of `->change()` loses nothing.

- [ ] **Step 5: Model and type facts**

In `app/Models/DossierEvidenceUseLimit.php`: add `'limit_type'` to the `Fillable` list, `use App\Enums\UseLimitType;`, cast `'limit_type' => UseLimitType::class`, and update the class comment:

```php
/**
 * A use level stated by an IFRA certificate (per IFRA category) or an RCE (per product class): a maximum, or an
 * explicit "no restriction".
 */
```

In `database/factories/DossierEvidenceUseLimitFactory.php`, add `'limit_type' => UseLimitType::Maximum,` to the definition (with the import).

In `app/Enums/EvidenceType.php`, replace the `factKeys()` match arms:

```php
            self::TechnicalSheet, self::CertificateOfAnalysis => ['microbiology_specification'],
            self::RegulatoryConformityCertificate => self::PEROXIDE_FACTS,
            self::IfraCertificate => ['ifra_amendment_id', 'covers_supplied_grade', ...self::PEROXIDE_FACTS],
```

and add to the enum:

```php
    /**
     * A peroxide value is kept as reported: value, unit, comparison and whether it is measured or a specification.
     */
    public const PEROXIDE_FACTS = ['peroxide_value', 'peroxide_unit', 'peroxide_comparison', 'peroxide_basis'];
```

- [ ] **Step 6: Validation and storage**

In `app/Actions/Dossiers/SaveDossierEvidenceData.php`:

1. Imports: `App\Enums\PeroxideUnit`, `App\Enums\ReportedComparison`, `App\Enums\ReportedValueBasis`, `App\Enums\UseLimitType`.
2. In `validate()`, after `$useLimits = array_values($data['use_limits'] ?? []);`, default the limit type of older callers:

```php
        $useLimits = array_map(fn (array $row): array => ['limit_type' => UseLimitType::Maximum->value, ...$row], $useLimits);
```

3. Replace the `'use_limits.*.max_percent'` rule and the `'facts.peroxide_value_mmol_l'` rule with:

```php
            'use_limits.*.limit_type' => ['required', Rule::enum(UseLimitType::class)],
            // A maximum needs its value (zero means not permitted); an explicit "no restriction" has none.
            'use_limits.*.max_percent' => ['exclude_unless:use_limits.*.limit_type,maximum', 'required', 'numeric', 'gte:0', 'lte:100'],
            'facts.ifra_amendment_id' => ['nullable', 'integer', 'exists:ifra_amendments,id'],
            'facts.covers_supplied_grade' => ['nullable', 'boolean'],
            'facts.peroxide_value' => ['nullable', 'numeric', 'gte:0'],
            'facts.peroxide_unit' => ['required_with:facts.peroxide_value', 'nullable', Rule::enum(PeroxideUnit::class)],
            'facts.peroxide_comparison' => ['required_with:facts.peroxide_value', 'nullable', Rule::enum(ReportedComparison::class)],
            'facts.peroxide_basis' => ['required_with:facts.peroxide_value', 'nullable', Rule::enum(ReportedValueBasis::class)],
```

4. After the `$validator->after(...)` block, add a check that refuses a value on a "no restriction" row (the `exclude_unless` rule drops the value silently otherwise):

```php
        $validator->after(function ($validator) use ($useLimits): void {
            foreach ($useLimits as $index => $row) {
                if (($row['limit_type'] ?? null) === UseLimitType::NoRestriction->value && filled($row['max_percent'] ?? null)) {
                    $validator->errors()->add("use_limits.{$index}.max_percent", __('dossiers.evidence.no_restriction_without_value'));
                }
            }
        });
```

5. When creating each use limit in `handle()`, store the type and clear the maximum of a "no restriction" row:

```php
            foreach ($validated['use_limits'] as $row) {
                $type = UseLimitType::from($row['limit_type']);
                $current->useLimits()->create([
                    'ifra_product_category_id' => $current->type->useLimitBasis() === 'ifra_category' ? $row['ifra_product_category_id'] : null,
                    'product_class_label' => $current->type->useLimitBasis() === 'product_class' ? trim((string) $row['product_class_label']) : null,
                    'limit_type' => $type,
                    'max_percent' => $type === UseLimitType::Maximum ? $row['max_percent'] : null,
                ]);
            }
```

6. Casting the grade answer: in `validate()`, before returning, normalise it to a boolean so `false` is kept (it is "filled") and `'1'`/`'0'` from forms become booleans:

```php
        if (array_key_exists('covers_supplied_grade', $facts)) {
            $facts['covers_supplied_grade'] = filter_var($facts['covers_supplied_grade'], FILTER_VALIDATE_BOOL);
        }

        if (array_key_exists('ifra_amendment_id', $facts)) {
            $facts['ifra_amendment_id'] = (int) $facts['ifra_amendment_id'];
        }
```

Add to `lang/en/dossiers.php` under `'evidence'`:

```php
        'no_restriction_without_value' => 'A "no restriction" statement has no maximum. Choose "Maximum" to enter a value.',
```

and the matching catalogue entry in Task 2.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact tests/Feature/Dossiers/DossierEvidenceDataTest.php`
Expected: PASS. The translation-catalogue tests will fail until Task 2 adds the catalogue entries; run them there.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums app/Models/DossierEvidenceUseLimit.php app/Actions/Dossiers/SaveDossierEvidenceData.php database/migrations database/factories/DossierEvidenceUseLimitFactory.php lang/en/dossiers.php tests/Feature/Dossiers/DossierEvidenceDataTest.php
git commit -m "Record the IFRA amendment, grade coverage and explicit no-restriction limits on DIP certificates"
```

---

### Task 2: Document data panel

**Files:**
- Modify: `app/Livewire/Dossiers/RawMaterialDetail.php`, `resources/views/livewire/dossiers/raw-material-detail.blade.php`, `lang/en/dossiers.php`, `database/seeders/data/interface-translations.json`
- Test: `tests/Feature/Dossiers/RawMaterialPagesTest.php`

- [ ] **Step 1: Failing page test**

Append to `tests/Feature/Dossiers/RawMaterialPagesTest.php` (imports: `App\Enums\UseLimitType`, `App\Models\IfraAmendment`, `App\Models\IfraProductCategory`):

```php
it('records an IFRA certificate with its amendment, grade coverage and limits from the page', function (): void {
    [$owner, $workspace] = rawMaterialWorkspace();
    $lavender = ingredientUsedIn($workspace, 'Lavender');
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $lavender->id]);
    $item = DossierEvidenceItem::factory()->create(['supplier_qualification_id' => $qualification->id, 'type' => EvidenceType::IfraCertificate]);
    $category9 = IfraProductCategory::factory()->create(['code' => '9']);
    $category4 = IfraProductCategory::factory()->create(['code' => '4']);
    $amendment = IfraAmendment::factory()->create(['code' => '51']);
    $this->actingAs($owner);

    Livewire::test(RawMaterialDetail::class, ['ingredient' => $lavender->public_id])
        ->call('startEditData', $item->id)
        ->fillForm([
            'facts' => [
                'ifra_amendment_id' => $amendment->id,
                'covers_supplied_grade' => true,
                'peroxide_value' => '3.1',
                'peroxide_unit' => 'meq_o2_kg',
                'peroxide_comparison' => 'eq',
                'peroxide_basis' => 'measured',
            ],
            'use_limits' => [
                ['ifra_product_category_id' => $category9->id, 'limit_type' => 'maximum', 'max_percent' => '2.5'],
                ['ifra_product_category_id' => $category4->id, 'limit_type' => 'no_restriction'],
            ],
        ], 'evidenceDataForm')
        ->call('saveEvidenceData')
        ->assertHasNoFormErrors([], 'evidenceDataForm')
        ->assertSee('9 ≤ 2.5 %')
        ->assertSee(__('dossiers.evidence_data.no_restriction_short'));

    $item->refresh();

    expect($item->facts['ifra_amendment_id'])->toBe($amendment->id)
        ->and($item->facts['covers_supplied_grade'])->toBeTrue()
        ->and($item->useLimits->firstWhere('ifra_product_category_id', $category4->id)->limit_type)->toBe(UseLimitType::NoRestriction);
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/RawMaterialPagesTest.php --filter="IFRA certificate"`
Expected: FAIL (the form has no amendment, grade or limit-type fields).

- [ ] **Step 3: Form fields**

In `app/Livewire/Dossiers/RawMaterialDetail.php` (imports: `App\Enums\PeroxideUnit`, `App\Enums\ReportedComparison`, `App\Enums\ReportedValueBasis`, `App\Enums\UseLimitType`, `App\Models\IfraAmendment`):

1. Replace the use-limit repeater's schema with:

```php
                    ->schema([
                        $basis === 'ifra_category'
                            ? Select::make('ifra_product_category_id')
                                ->label(__('dossiers.evidence_data.ifra_category'))
                                ->options(fn (): array => IfraProductCategory::query()->orderBy('code')->get()->mapWithKeys(fn (IfraProductCategory $category): array => [$category->id => $category->code.' — '.$category->localizedName()])->all())
                                ->required()
                            : TextInput::make('product_class_label')->label(__('dossiers.evidence_data.product_class'))->required()->maxLength(255),
                        Select::make('limit_type')
                            ->label(__('dossiers.evidence_data.limit_type'))
                            ->options(collect(UseLimitType::cases())->mapWithKeys(fn (UseLimitType $type): array => [$type->value => __('dossiers.evidence_data.limit_types.'.$type->value)])->all())
                            ->default(UseLimitType::Maximum->value)
                            ->live()
                            ->required(),
                        TextInput::make('max_percent')
                            ->label(__('dossiers.evidence_data.max_percent'))
                            ->numeric()
                            ->suffix('%')
                            ->visible(fn (Get $get): bool => $get('limit_type') !== UseLimitType::NoRestriction->value)
                            ->required(fn (Get $get): bool => $get('limit_type') !== UseLimitType::NoRestriction->value),
                    ])
                    ->columns(['md' => 3])
```

2. Replace the `peroxide_value_mmol_l` field with the IFRA facts and the four peroxide fields:

```php
                in_array('ifra_amendment_id', $facts, true)
                    ? Select::make('facts.ifra_amendment_id')
                        ->label(__('dossiers.evidence_data.ifra_amendment'))
                        ->options(fn (): array => IfraAmendment::query()->orderByDesc('code')->pluck('code', 'id')->all())
                    : null,
                in_array('covers_supplied_grade', $facts, true)
                    ? Toggle::make('facts.covers_supplied_grade')
                        ->label(__('dossiers.evidence_data.covers_supplied_grade'))
                        ->helperText(__('dossiers.evidence_data.covers_supplied_grade_help'))
                    : null,
                in_array('peroxide_value', $facts, true)
                    ? TextInput::make('facts.peroxide_value')->label(__('dossiers.evidence_data.peroxide_value'))->numeric()->live(onBlur: true)
                    : null,
                in_array('peroxide_unit', $facts, true)
                    ? Select::make('facts.peroxide_unit')
                        ->label(__('dossiers.evidence_data.peroxide_unit'))
                        ->options(collect(PeroxideUnit::cases())->mapWithKeys(fn (PeroxideUnit $unit): array => [$unit->value => __('dossiers.evidence_data.peroxide_units.'.$unit->value)])->all())
                        ->required(fn (Get $get): bool => filled($get('facts.peroxide_value')))
                    : null,
                in_array('peroxide_comparison', $facts, true)
                    ? Select::make('facts.peroxide_comparison')
                        ->label(__('dossiers.evidence_data.peroxide_comparison'))
                        ->options(collect(ReportedComparison::cases())->mapWithKeys(fn (ReportedComparison $comparison): array => [$comparison->value => __('dossiers.evidence_data.peroxide_comparisons.'.$comparison->value)])->all())
                        ->required(fn (Get $get): bool => filled($get('facts.peroxide_value')))
                    : null,
                in_array('peroxide_basis', $facts, true)
                    ? Select::make('facts.peroxide_basis')
                        ->label(__('dossiers.evidence_data.peroxide_basis'))
                        ->options(collect(ReportedValueBasis::cases())->mapWithKeys(fn (ReportedValueBasis $basis): array => [$basis->value => __('dossiers.evidence_data.peroxide_bases.'.$basis->value)])->all())
                        ->required(fn (Get $get): bool => filled($get('facts.peroxide_value')))
                    : null,
```

3. In `startEditData()`, fill the limit type:

```php
            'use_limits' => $item->useLimits->map(fn ($row): array => [
                'ifra_product_category_id' => $row->ifra_product_category_id,
                'product_class_label' => $row->product_class_label,
                'limit_type' => $row->limit_type->value,
                'max_percent' => $row->max_percent,
            ])->all(),
```

- [ ] **Step 4: Display**

In `resources/views/livewire/dossiers/raw-material-detail.blade.php`, replace the use-limit line with:

```blade
                                    @foreach ($item->useLimits as $limit)
                                        · {{ $limit->ifraProductCategory?->code ?? $limit->product_class_label }}
                                        @if ($limit->limit_type === \App\Enums\UseLimitType::NoRestriction) {{ __('dossiers.evidence_data.no_restriction_short') }}
                                        @else ≤ {{ rtrim(rtrim($limit->max_percent, '0'), '.') }} % @endif
                                    @endforeach
```

and, next to it, show the IFRA amendment and grade answer when present:

```blade
                                    @if (filled($item->facts['ifra_amendment_id'] ?? null))
                                        · {{ __('dossiers.evidence_data.ifra_amendment') }} {{ \App\Models\IfraAmendment::query()->whereKey($item->facts['ifra_amendment_id'])->value('code') }}
                                    @endif
                                    @if (array_key_exists('covers_supplied_grade', $item->facts ?? []))
                                        · {{ $item->facts['covers_supplied_grade'] ? __('dossiers.evidence_data.covers_grade_yes') : __('dossiers.evidence_data.covers_grade_no') }}
                                    @endif
```

Read the surrounding markup first and keep its structure; if the view loads items without `useLimits.ifraProductCategory`, add that to the eager load in `RawMaterialDetail::render()`.

- [ ] **Step 5: Labels and catalogue**

Add to `lang/en/dossiers.php` under `'evidence_data'`:

```php
        'limit_type' => 'Stated as',
        'limit_types' => [
            'maximum' => 'Maximum',
            'no_restriction' => 'No restriction',
        ],
        'no_restriction_short' => 'no restriction',
        'ifra_amendment' => 'IFRA amendment covered',
        'covers_supplied_grade' => 'Covers this supplier’s grade',
        'covers_supplied_grade_help' => 'Turn on only when the certificate names this supplier’s product or reference. Without it the certificate does not conclude the IFRA check.',
        'covers_grade_yes' => 'grade confirmed',
        'covers_grade_no' => 'grade not confirmed',
        'peroxide_unit' => 'Peroxide unit',
        'peroxide_units' => [
            'meq_o2_kg' => 'meq O₂/kg',
            'mmol_l' => 'mmol/L',
        ],
        'peroxide_comparison' => 'Reported as',
        'peroxide_comparisons' => [
            'lte' => 'at most (≤)',
            'lt' => 'below (<)',
            'eq' => 'equal to (=)',
        ],
        'peroxide_basis' => 'Peroxide value is',
        'peroxide_bases' => [
            'measured' => 'a measured result',
            'specification' => 'a specification maximum',
        ],
```

Add one entry per new key to `database/seeders/data/interface-translations.json`, group `dossiers`, **in the catalogue's sorted position** (`InterfaceTranslationCatalogue` refuses unsorted entries; insert each one before the first existing entry whose `group.key` sorts after it). Texts:

| key | de | es | fr | it | nl | pt_BR |
| --- | --- | --- | --- | --- | --- | --- |
| `evidence.no_restriction_without_value` | Eine Angabe „keine Einschränkung“ hat kein Maximum. Wählen Sie „Maximum“, um einen Wert einzugeben. | Una indicación «sin restricción» no tiene máximo. Elija «Máximo» para introducir un valor. | Une mention « sans restriction » n’a pas de maximum. Choisissez « Maximum » pour saisir une valeur. | Un’indicazione «nessuna restrizione» non ha un massimo. Scegli «Massimo» per inserire un valore. | Een vermelding „geen beperking” heeft geen maximum. Kies „Maximum” om een waarde in te voeren. | Uma indicação «sem restrição» não tem máximo. Escolha «Máximo» para inserir um valor. |
| `evidence_data.covers_grade_no` | Qualität nicht bestätigt | calidad no confirmada | qualité non confirmée | qualità non confermata | kwaliteit niet bevestigd | grau não confirmado |
| `evidence_data.covers_grade_yes` | Qualität bestätigt | calidad confirmada | qualité confirmée | qualità confermata | kwaliteit bevestigd | grau confirmado |
| `evidence_data.covers_supplied_grade` | Gilt für die Qualität dieses Lieferanten | Cubre la calidad de este proveedor | Couvre la qualité de ce fournisseur | Copre la qualità di questo fornitore | Geldt voor de kwaliteit van deze leverancier | Abrange o grau deste fornecedor |
| `evidence_data.covers_supplied_grade_help` | Nur aktivieren, wenn das Zertifikat das Produkt oder die Referenz dieses Lieferanten nennt. Andernfalls schließt das Zertifikat die IFRA-Prüfung nicht ab. | Actívelo solo si el certificado nombra el producto o la referencia de este proveedor. Si no, el certificado no concluye la verificación IFRA. | À activer seulement si le certificat nomme le produit ou la référence de ce fournisseur. Sinon, le certificat ne conclut pas la vérification IFRA. | Attivalo solo se il certificato nomina il prodotto o il riferimento di questo fornitore. Altrimenti il certificato non conclude la verifica IFRA. | Alleen inschakelen als het certificaat het product of de referentie van deze leverancier noemt. Anders sluit het certificaat de IFRA-controle niet af. | Ative apenas se o certificado nomear o produto ou a referência deste fornecedor. Caso contrário, o certificado não conclui a verificação IFRA. |
| `evidence_data.ifra_amendment` | Abgedeckte IFRA-Änderung | Enmienda IFRA cubierta | Amendement IFRA couvert | Emendamento IFRA coperto | Gedekt IFRA-amendement | Emenda IFRA abrangida |
| `evidence_data.limit_type` | Angegeben als | Indicado como | Indiqué comme | Indicato come | Vermeld als | Indicado como |
| `evidence_data.limit_types.maximum` | Maximum | Máximo | Maximum | Massimo | Maximum | Máximo |
| `evidence_data.limit_types.no_restriction` | Keine Einschränkung | Sin restricción | Sans restriction | Nessuna restrizione | Geen beperking | Sem restrição |
| `evidence_data.no_restriction_short` | keine Einschränkung | sin restricción | sans restriction | nessuna restrizione | geen beperking | sem restrição |
| `evidence_data.peroxide_bases.measured` | ein Messergebnis | un resultado medido | un résultat mesuré | un risultato misurato | een meetresultaat | um resultado medido |
| `evidence_data.peroxide_bases.specification` | ein Spezifikationsmaximum | un máximo de especificación | un maximum de spécification | un massimo di specifica | een specificatiemaximum | um máximo de especificação |
| `evidence_data.peroxide_basis` | Die Peroxidzahl ist | El índice de peróxido es | L’indice de peroxyde est | Il numero di perossidi è | Het peroxidegetal is | O índice de peróxido é |
| `evidence_data.peroxide_comparison` | Angegeben als | Indicado como | Indiqué comme | Indicato come | Vermeld als | Indicado como |
| `evidence_data.peroxide_comparisons.eq` | gleich (=) | igual a (=) | égal à (=) | uguale a (=) | gelijk aan (=) | igual a (=) |
| `evidence_data.peroxide_comparisons.lt` | unter (<) | inferior a (<) | inférieur à (<) | inferiore a (<) | onder (<) | abaixo de (<) |
| `evidence_data.peroxide_comparisons.lte` | höchstens (≤) | como máximo (≤) | au plus (≤) | al massimo (≤) | hoogstens (≤) | no máximo (≤) |
| `evidence_data.peroxide_unit` | Einheit der Peroxidzahl | Unidad del índice de peróxido | Unité de l’indice de peroxyde | Unità del numero di perossidi | Eenheid van het peroxidegetal | Unidade do índice de peróxido |
| `evidence_data.peroxide_units.meq_o2_kg` | meq O₂/kg | meq O₂/kg | meq O₂/kg | meq O₂/kg | meq O₂/kg | meq O₂/kg |
| `evidence_data.peroxide_units.mmol_l` | mmol/L | mmol/L | mmol/L | mmol/L | mmol/L | mmol/L |

If the existing `evidence_data.peroxide_value` label still reads "Peroxide value (mmol/L)" anywhere, keep the plain "Peroxide value": the unit is now its own field.

- [ ] **Step 6: Run the tests**

```bash
php artisan test --compact tests/Feature/Dossiers/RawMaterialPagesTest.php tests/Feature/Dossiers/DossierEvidenceDataTest.php
php artisan test --compact --filter=InterfaceTranslation
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Dossiers/RawMaterialDetail.php resources/views/livewire/dossiers/raw-material-detail.blade.php lang/en/dossiers.php database/seeders/data/interface-translations.json tests/Feature/Dossiers/RawMaterialPagesTest.php
git commit -m "Enter the IFRA amendment, grade coverage, limit type and peroxide details on DIP documents"
```

---

### Task 3: The IFRA ingredient-use checker

**Files:**
- Create: `app/Services/Dossiers/Calculation/IfraUseChecker.php`
- Modify: `config/dossiers.php`
- Test: `tests/Feature/Dossiers/Calculation/IfraUseCheckerTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

use App\Enums\EvidenceType;
use App\Enums\IngredientCategory;
use App\Enums\SupplierQualificationStatus;
use App\Enums\UseLimitType;
use App\Models\DossierEvidenceItem;
use App\Models\DossierEvidenceUseLimit;
use App\Models\IfraAmendment;
use App\Models\IfraCertificate;
use App\Models\IfraCertificateLimit;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use App\Services\Dossiers\Calculation\IfraUseChecker;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DossierCalculationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * @return array{workspace: Workspace, category: IfraProductCategory, amendment: IfraAmendment, lavender: Ingredient}
 */
function ifraSetting(): array
{
    return [
        'workspace' => Workspace::factory()->create(),
        'category' => IfraProductCategory::factory()->create(['code' => '9']),
        'amendment' => IfraAmendment::factory()->create(['code' => '51']),
        'lavender' => Fixtures::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials),
    ];
}

/**
 * A validated supplier's IFRA certificate. Pass null for a fact to leave it unrecorded.
 */
function supplierIfraCertificate(Workspace $workspace, Ingredient $ingredient, ?IfraProductCategory $category, ?IfraAmendment $amendment, ?string $maximum, ?bool $coversGrade = true, UseLimitType $type = UseLimitType::Maximum): SupplierQualification
{
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $ingredient->id]);
    $item = DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => $qualification->id,
        'type' => EvidenceType::IfraCertificate,
        'facts' => array_filter(['ifra_amendment_id' => $amendment?->id, 'covers_supplied_grade' => $coversGrade], fn (mixed $value): bool => $value !== null),
    ]);

    if ($category !== null) {
        DossierEvidenceUseLimit::factory()->create([
            'dossier_evidence_item_id' => $item->id,
            'ifra_product_category_id' => $category->id,
            'limit_type' => $type,
            'max_percent' => $maximum,
        ]);
    }

    return $qualification;
}

/**
 * The lavender at 30 g in a 1300 g cured soap: 2.307692 %.
 *
 * @param  array<string, mixed>  $setting
 * @return array<string, mixed>
 */
function lavenderUse(array $setting, ?string $justification = null, ?IfraProductCategory $category = null, ?int $amendmentId = -1): array
{
    $results = app(IfraUseChecker::class)->check(
        $setting['workspace'],
        [['key' => 'item:fragrance:0', 'ingredient_id' => $setting['lavender']->id, 'grams' => '30']],
        '1300',
        $category ?? $setting['category'],
        $amendmentId === -1 ? $setting['amendment']->id : $amendmentId,
        $justification,
    );

    return $results[0];
}

it('concludes when every validated supplier has an applicable certificate within its limit', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '5');
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '3');

    $use = lavenderUse($setting);

    expect(DecimalMath::round($use['concentration_percent'], 6))->toBe('2.307692')
        ->and($use['state'])->toBe('conforme')
        ->and(DecimalMath::round($use['limit_percent'], 2))->toBe('3.00')
        ->and($use['uncovered'])->toBe([]);
});

it('fails on the strictest established limit even when another supplier is uncovered', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '2');
    SupplierQualification::factory()->create(['workspace_id' => $setting['workspace']->id, 'ingredient_id' => $setting['lavender']->id]);

    $use = lavenderUse($setting);

    expect($use['state'])->toBe('non_conforme')
        ->and($use['reason'])->toBe('ifra_limit_exceeded');
});

it('treats a zero maximum as not permitted', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '0');

    expect(lavenderUse($setting)['state'])->toBe('non_conforme');
});

it('accepts an explicit no-restriction statement', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], null, type: UseLimitType::NoRestriction);

    $use = lavenderUse($setting);

    expect($use['state'])->toBe('conforme')
        ->and($use['limit_percent'])->toBeNull()
        ->and($use['limit_type'])->toBe('no_restriction');
});

it('names why a validated supplier does not conclude', function (Closure $certificate, string $reason): void {
    $setting = ifraSetting();
    $certificate($setting);

    $use = lavenderUse($setting);

    expect($use['state'])->toBe('a_justifier')
        ->and($use['reason'])->toBe('ifra_supplier_uncovered')
        ->and($use['uncovered'][0]['reason'])->toBe($reason);
})->with([
    'no certificate' => [fn (array $s) => SupplierQualification::factory()->create(['workspace_id' => $s['workspace']->id, 'ingredient_id' => $s['lavender']->id]), 'no_certificate'],
    'amendment not recorded' => [fn (array $s) => supplierIfraCertificate($s['workspace'], $s['lavender'], $s['category'], null, '5'), 'amendment_missing'],
    'older amendment' => [fn (array $s) => supplierIfraCertificate($s['workspace'], $s['lavender'], $s['category'], IfraAmendment::factory()->create(['code' => '50']), '5'), 'amendment_mismatch'],
    'grade not confirmed' => [fn (array $s) => supplierIfraCertificate($s['workspace'], $s['lavender'], $s['category'], $s['amendment'], '5', coversGrade: null), 'grade_not_confirmed'],
    'grade denied' => [fn (array $s) => supplierIfraCertificate($s['workspace'], $s['lavender'], $s['category'], $s['amendment'], '5', coversGrade: false), 'grade_not_confirmed'],
    'no limit for the category' => [fn (array $s) => supplierIfraCertificate($s['workspace'], $s['lavender'], IfraProductCategory::factory()->create(['code' => '4']), $s['amendment'], '5'), 'no_limit_for_category'],
]);

it('shows the ingredient certificate as an unconcluded fallback when no supplier is validated', function (?string $maximum, string $reason): void {
    $setting = ifraSetting();
    $certificate = IfraCertificate::factory()->create(['ingredient_id' => $setting['lavender']->id, 'ifra_amendment_id' => $setting['amendment']->id, 'is_current' => true]);
    IfraCertificateLimit::factory()->create(['ifra_certificate_id' => $certificate->id, 'ifra_product_category_id' => $setting['category']->id, 'max_percentage' => $maximum]);

    $use = lavenderUse($setting);

    expect($use['state'])->toBe('a_justifier')
        ->and($use['reason'])->toBe($reason)
        ->and($use['fallback']['certificate_id'])->toBe($certificate->id);
})->with([
    'within the fallback limit' => ['5', 'ifra_fallback_only'],
    'above the fallback limit' => ['2', 'ifra_fallback_limit_exceeded'],
    'blank maximum means unknown' => [null, 'ifra_no_evidence'],
]);

it('requires category 9 or a justification, and a known amendment', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '5');
    $other = IfraProductCategory::factory()->create(['code' => '5A']);

    expect(lavenderUse($setting, category: $other)['reason'])->toBe('ifra_category_unresolved')
        ->and(lavenderUse($setting, amendmentId: null)['reason'])->toBe('ifra_amendment_unresolved')
        ->and(lavenderUse($setting)['state'])->toBe('conforme');
});

it('does not check an ingredient with no fragrance role and no IFRA data', function (): void {
    $setting = ifraSetting();
    $olive = Fixtures::ingredient('Olive Oil', 'OLEA EUROPAEA FRUIT OIL', IngredientCategory::Lipids);

    $results = app(IfraUseChecker::class)->check($setting['workspace'], [['key' => 'item:saponified_oils:0', 'ingredient_id' => $olive->id, 'grams' => '1000']], '1300', $setting['category'], $setting['amendment']->id, null);

    expect($results[0]['state'])->toBe('not_applicable');
});

it('ignores a supplier that is not validated', function (): void {
    $setting = ifraSetting();
    supplierIfraCertificate($setting['workspace'], $setting['lavender'], $setting['category'], $setting['amendment'], '5');
    SupplierQualification::factory()->create([
        'workspace_id' => $setting['workspace']->id,
        'ingredient_id' => $setting['lavender']->id,
        'status' => SupplierQualificationStatus::EnEvaluation,
    ]);

    expect(lavenderUse($setting)['state'])->toBe('conforme');
});
```


- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/IfraUseCheckerTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Config**

Append to `config/dossiers.php`:

```php

    // The bar-soap pilot is assessed in IFRA category 9; another category needs a justification (owner decision 2026-10-24).
    'pilot_ifra_category_code' => '9',
```

- [ ] **Step 4: Implement**

```php
<?php

namespace App\Services\Dossiers\Calculation;

use App\Enums\EvidenceType;
use App\Enums\IngredientCategory;
use App\Enums\SupplierQualificationStatus;
use App\Enums\UseLimitType;
use App\Models\DossierEvidenceItem;
use App\Models\DossierEvidenceUseLimit;
use App\Models\IfraCertificate;
use App\Models\IfraCertificateLimit;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * IFRA check of each recipe ingredient as used (phase 3b decisions 1–6): the ingredient as purchased — a blend at its
 * whole dosage — against the formula's IFRA category and amendment. Supplier certificates conclude only when they record
 * the amendment and cover the qualified grade; the ingredient's own certificate is shown but never concludes. Missing or
 * unusable information stays « À justifier ».
 */
class IfraUseChecker
{
    /**
     * Uncovered reasons, from the closest to applicable to the furthest.
     */
    private const UNCOVERED_ORDER = ['no_limit_for_category', 'grade_not_confirmed', 'amendment_mismatch', 'amendment_missing'];

    /**
     * @param  list<array{key: string, ingredient_id: int|null, grams: string}>  $initial
     * @return list<array<string, mixed>>
     */
    public function check(Workspace $workspace, array $initial, string $curedMass, ?IfraProductCategory $category, ?int $amendmentId, ?string $categoryJustification): array
    {
        $settingReason = $this->settingReason($category, $amendmentId, $categoryJustification);

        return collect($initial)
            ->whereNotNull('ingredient_id')
            ->groupBy('ingredient_id')
            ->map(function (Collection $rows, int|string $ingredientId) use ($workspace, $curedMass, $category, $amendmentId, $settingReason): array {
                $ingredient = Ingredient::withoutGlobalScopes()->find($ingredientId);
                $concentration = DecimalMath::percentOf(DecimalMath::add(...$rows->pluck('grams')->map(fn (mixed $grams): string => DecimalMath::of($grams))->all()), $curedMass);
                $suppliers = $ingredient instanceof Ingredient && $category !== null && $amendmentId !== null
                    ? $this->supplierCoverage($workspace, $ingredient, $category->id, $amendmentId)
                    : [];
                $fallback = $ingredient instanceof Ingredient && $category !== null ? $this->ingredientCertificate($ingredient, $category->id, $amendmentId) : null;
                $applicable = $ingredient instanceof Ingredient && $this->isApplicable($workspace, $ingredient, $fallback);
                $result = $applicable
                    ? $this->state($concentration, $suppliers, $fallback, $settingReason)
                    : ['state' => 'not_applicable', 'reason' => null, 'limit_percent' => null, 'limit_type' => null];

                return [
                    'ingredient_id' => (int) $ingredientId,
                    'name' => $ingredient?->display_name,
                    'initial_keys' => $rows->pluck('key')->values()->all(),
                    'concentration_percent' => $concentration,
                    'category_id' => $category?->id,
                    'amendment_id' => $amendmentId,
                    ...$result,
                    'suppliers' => $suppliers,
                    'uncovered' => array_values(array_filter($suppliers, fn (array $supplier): bool => ! $supplier['covered'])),
                    'fallback' => $fallback,
                ];
            })
            ->values()
            ->all();
    }

    private function settingReason(?IfraProductCategory $category, ?int $amendmentId, ?string $categoryJustification): ?string
    {
        if ($category === null || ($category->code !== (string) config('dossiers.pilot_ifra_category_code') && blank($categoryJustification))) {
            return 'ifra_category_unresolved';
        }

        return $amendmentId === null ? 'ifra_amendment_unresolved' : null;
    }

    /**
     * @param  array<string, mixed>|null  $fallback
     */
    private function isApplicable(Workspace $workspace, Ingredient $ingredient, ?array $fallback): bool
    {
        return $ingredient->category === IngredientCategory::AromaticMaterials
            || (bool) $ingredient->requires_aromatic_compliance
            || $fallback !== null
            || IfraCertificate::query()->where('ingredient_id', $ingredient->id)->exists()
            || DossierEvidenceItem::query()
                ->where('workspace_id', $workspace->id)
                ->where('type', EvidenceType::IfraCertificate->value)
                ->whereHas('supplierQualification', fn ($query) => $query->where('ingredient_id', $ingredient->id))
                ->exists();
    }

    /**
     * One entry per validated qualification: covered with its limit, or uncovered with the reason.
     *
     * @return list<array{qualification_id: int, covered: bool, reason: string|null, evidence_item_id: int|null, limit_type: string|null, max_percent: string|null}>
     */
    private function supplierCoverage(Workspace $workspace, Ingredient $ingredient, int $categoryId, int $amendmentId): array
    {
        return SupplierQualification::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $ingredient->id)
            ->where('status', SupplierQualificationStatus::Valide->value)
            ->orderBy('id')
            ->get()
            ->map(function (SupplierQualification $qualification) use ($categoryId, $amendmentId): array {
                $certificates = DossierEvidenceItem::query()
                    ->where('supplier_qualification_id', $qualification->id)
                    ->where('type', EvidenceType::IfraCertificate->value)
                    ->whereNull('retired_at')
                    ->with('useLimits')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (DossierEvidenceItem $item): array => $this->certificateStatus($item, $categoryId, $amendmentId));
                $applicable = $certificates->where('reason', null);

                if ($applicable->isEmpty()) {
                    $reasons = $certificates->pluck('reason');

                    return [
                        'qualification_id' => $qualification->id,
                        'covered' => false,
                        'reason' => collect(self::UNCOVERED_ORDER)->first(fn (string $reason): bool => $reasons->contains($reason)) ?? 'no_certificate',
                        'evidence_item_id' => null,
                        'limit_type' => null,
                        'max_percent' => null,
                    ];
                }

                $strictest = $this->strictest($applicable);

                return ['qualification_id' => $qualification->id, 'covered' => true, 'reason' => null, ...$strictest];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{evidence_item_id: int, reason: string|null, limit_type: string|null, max_percent: string|null}
     */
    private function certificateStatus(DossierEvidenceItem $item, int $categoryId, int $amendmentId): array
    {
        $facts = $item->facts ?? [];
        $limit = $item->useLimits->first(fn (DossierEvidenceUseLimit $row): bool => (int) $row->ifra_product_category_id === $categoryId);
        $reason = match (true) {
            ! isset($facts['ifra_amendment_id']) => 'amendment_missing',
            (int) $facts['ifra_amendment_id'] !== $amendmentId => 'amendment_mismatch',
            ($facts['covers_supplied_grade'] ?? null) !== true => 'grade_not_confirmed',
            ! $limit instanceof DossierEvidenceUseLimit => 'no_limit_for_category',
            default => null,
        };

        return [
            'evidence_item_id' => $item->id,
            'reason' => $reason,
            'limit_type' => $limit?->limit_type?->value,
            'max_percent' => $limit?->limit_type === UseLimitType::Maximum ? DecimalMath::of($limit->max_percent) : null,
        ];
    }

    /**
     * The strictest of several applicable certificates: the lowest maximum; "no restriction" only when none states a
     * maximum.
     *
     * @param  Collection<int, array<string, mixed>>  $certificates
     * @return array{evidence_item_id: int, limit_type: string, max_percent: string|null}
     */
    private function strictest(Collection $certificates): array
    {
        $maxima = $certificates->where('limit_type', UseLimitType::Maximum->value)
            ->sort(fn (array $left, array $right): int => DecimalMath::cmp($left['max_percent'], $right['max_percent']));
        $chosen = $maxima->first() ?? $certificates->first();

        return ['evidence_item_id' => $chosen['evidence_item_id'], 'limit_type' => $chosen['limit_type'], 'max_percent' => $chosen['max_percent']];
    }

    /**
     * The ingredient's current IFRA certificate for the category: a blank maximum is unknown, never "no limit".
     *
     * @return array{certificate_id: int, amendment_matches: bool, max_percent: string|null, restriction_note: string|null}|null
     */
    private function ingredientCertificate(Ingredient $ingredient, int $categoryId, ?int $amendmentId): ?array
    {
        $certificate = IfraCertificate::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('is_current', true)
            ->with('limits')
            ->orderByDesc('id')
            ->first();

        if (! $certificate instanceof IfraCertificate) {
            return null;
        }

        $limit = $certificate->limits->first(fn (IfraCertificateLimit $row): bool => (int) $row->ifra_product_category_id === $categoryId);

        return [
            'certificate_id' => $certificate->id,
            'amendment_matches' => $amendmentId !== null && (int) $certificate->ifra_amendment_id === $amendmentId,
            'max_percent' => $limit?->max_percentage === null ? null : DecimalMath::of($limit->max_percentage),
            'restriction_note' => $limit?->restriction_note,
        ];
    }

    /**
     * Decision 6.
     *
     * @param  list<array<string, mixed>>  $suppliers
     * @param  array<string, mixed>|null  $fallback
     * @return array{state: string, reason: string|null, limit_percent: string|null, limit_type: string|null}
     */
    private function state(string $concentration, array $suppliers, ?array $fallback, ?string $settingReason): array
    {
        $result = fn (string $state, ?string $reason, ?string $limit = null, ?string $type = null): array => [
            'state' => $state,
            'reason' => $reason,
            'limit_percent' => $limit,
            'limit_type' => $type,
        ];

        if ($settingReason !== null) {
            return $result('a_justifier', $settingReason);
        }

        $covered = collect($suppliers)->where('covered', true);
        $strictest = $covered->isEmpty() ? null : $this->strictest($covered);

        if ($strictest !== null && $strictest['max_percent'] !== null && DecimalMath::cmp($concentration, $strictest['max_percent']) > 0) {
            return $result('non_conforme', 'ifra_limit_exceeded', $strictest['max_percent'], UseLimitType::Maximum->value);
        }

        if ($suppliers === []) {
            return match (true) {
                $fallback === null || $fallback['max_percent'] === null => $result('a_justifier', 'ifra_no_evidence'),
                DecimalMath::cmp($concentration, $fallback['max_percent']) > 0 => $result('a_justifier', 'ifra_fallback_limit_exceeded', $fallback['max_percent'], UseLimitType::Maximum->value),
                default => $result('a_justifier', 'ifra_fallback_only', $fallback['max_percent'], UseLimitType::Maximum->value),
            };
        }

        if ($covered->count() < count($suppliers)) {
            return $result('a_justifier', 'ifra_supplier_uncovered', $strictest['max_percent'] ?? null, $strictest['limit_type'] ?? null);
        }

        return $result('conforme', null, $strictest['max_percent'], $strictest['limit_type']);
    }
}
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/IfraUseCheckerTest.php`
Expected: PASS (16 tests).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Dossiers/Calculation/IfraUseChecker.php config/dossiers.php tests/Feature/Dossiers/Calculation/IfraUseCheckerTest.php
git commit -m "Check each DIP fragrance ingredient against its applicable IFRA limit"
```


---

### Task 4: Engine integration

**Files:**
- Modify: `app/Data/DossierAssessmentOptions.php`, `app/Services/Dossiers/Calculation/DossierAssessmentEngine.php`
- Test: `tests/Feature/Dossiers/Calculation/DossierAssessmentEngineTest.php`

- [ ] **Step 1: Failing tests**

Append to `tests/Feature/Dossiers/Calculation/DossierAssessmentEngineTest.php` (imports: `App\Enums\UseLimitType`, `App\Models\DossierEvidenceUseLimit`):

```php
/**
 * Puts the soap's saved formula in IFRA category 9 and amendment 51, and gives the lavender supplier an applicable
 * IFRA certificate with the given category 9 maximum.
 *
 * @param  array<string, mixed>  $soap
 * @return array<string, mixed>
 */
function withIfraCertificate(array $soap, string $maximum, string $categoryCode = '9'): array
{
    $category = IfraProductCategory::factory()->create(['code' => $categoryCode]);
    $amendment = IfraAmendment::factory()->create(['code' => '51']);
    RecipeVersion::withoutGlobalScopes()->whereKey($soap['version']->id)->update(['ifra_product_category_id' => $category->id, 'ifra_amendment_id' => $amendment->id]);
    $qualification = SupplierQualification::query()->where('ingredient_id', $soap['lavender']->id)->firstOrFail();
    $item = DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => $qualification->id,
        'type' => EvidenceType::IfraCertificate,
        'facts' => ['ifra_amendment_id' => $amendment->id, 'covers_supplied_grade' => true],
    ]);
    DossierEvidenceUseLimit::factory()->create(['dossier_evidence_item_id' => $item->id, 'ifra_product_category_id' => $category->id, 'limit_type' => UseLimitType::Maximum, 'max_percent' => $maximum]);

    return [...$soap, 'version' => $soap['version']->fresh()];
}

/**
 * @param  array<string, mixed>  $assessment
 * @return array<string, mixed>
 */
function ifraUseOf(array $assessment, int $ingredientId): array
{
    return collect($assessment['ifra_uses'])->firstWhere('ingredient_id', $ingredientId) ?? throw new RuntimeException("No IFRA use for ingredient [{$ingredientId}].");
}

it('reports each fragrance ingredient against its IFRA limit, and only fragrance ingredients', function (): void {
    $soap = withIfraCertificate(Fixtures::assessedLavenderSoap(), '5');

    $assessment = assessSoap($soap);
    $lavender = ifraUseOf($assessment, $soap['lavender']->id);

    expect(DecimalMath::round($lavender['concentration_percent'], 6))->toBe('2.307011')
        ->and($lavender['state'])->toBe('conforme')
        ->and(ifraUseOf($assessment, $soap['olive']->id)['state'])->toBe('not_applicable')
        ->and(collect($assessment['warnings'])->pluck('code')->all())->toContain('ifra_constituent_review_required');
});

it('blocks approval on an exceeded IFRA limit, whatever the toxicology rows say', function (): void {
    $soap = withIfraCertificate(Fixtures::assessedLavenderSoap(), '2');

    $assessment = assessSoap($soap);

    expect(ifraUseOf($assessment, $soap['lavender']->id)['state'])->toBe('non_conforme')
        ->and(ifraUseOf($assessment, $soap['lavender']->id)['reason'])->toBe('ifra_limit_exceeded')
        ->and($assessment['can_submit'])->toBeTrue()
        ->and($assessment['can_approve'])->toBeFalse();
});

it('leaves IFRA unresolved outside category 9 unless the dossier justifies the category', function (): void {
    $soap = withIfraCertificate(Fixtures::assessedLavenderSoap(), '5', categoryCode: '5A');

    $unjustified = ifraUseOf(assessSoap($soap), $soap['lavender']->id);
    $justified = ifraUseOf(assessSoap($soap, new DossierAssessmentOptions(ifraCategoryJustification: 'Sold as a body wash bar; category 5A agreed with the assessor.')), $soap['lavender']->id);

    expect($unjustified['reason'])->toBe('ifra_category_unresolved')
        ->and($justified['state'])->toBe('conforme');
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/DossierAssessmentEngineTest.php`
Expected: FAIL (`ifra_uses` missing; unknown named parameter `ifraCategoryJustification`).

- [ ] **Step 3: Options**

In `app/Data/DossierAssessmentOptions.php`, add a constructor parameter after `$evaluatedOn`:

```php
        public ?string $ifraCategoryJustification = null,
```

and document it in the class comment ("a justification when the formula's IFRA category is not the pilot's category 9").

- [ ] **Step 4: Engine**

In `app/Services/Dossiers/Calculation/DossierAssessmentEngine.php`:

1. Inject `private readonly IfraUseChecker $ifraUses,` in the constructor and import `App\Models\IfraProductCategory`.
2. After the overlays are built, compute the uses:

```php
        $ifraUses = $this->ifraUses->check(
            $workspace,
            $composition['initial'],
            $composition['basis']['cured_mass_g'],
            $version->ifra_product_category_id === null ? null : IfraProductCategory::query()->find($version->ifra_product_category_id),
            $version->ifra_amendment_id,
            $options->ifraCategoryJustification,
        );
```

3. Add `'ifra_uses' => $ifraUses,` to the returned array after `'rows'`, and replace the `can_approve` line with:

```php
            'can_approve' => $blockers === []
                && collect($rows)->every(fn (array $row): bool => $row['state'] === DossierRowState::Conforme->value)
                && collect($ifraUses)->every(fn (array $use): bool => in_array($use['state'], ['conforme', 'not_applicable'], true)),
```

4. Pass `$ifraUses` to `warnings()` and add at its end:

```php
        if (collect($ifraUses)->contains(fn (array $use): bool => $use['state'] !== 'not_applicable')) {
            // The check covers each ingredient as used; restrictions from constituents shared across ingredients are not
            // checked automatically in the pilot (phase 3b decision 1).
            $warnings[] = ['code' => 'ifra_constituent_review_required'];
        }
```

Update the `warnings()` docblock with the new `@param list<array<string, mixed>> $ifraUses`.

5. Add `'ifra_uses' => [],` to the `cannot_run` return array, so the result shape stays the same.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/DossierAssessmentOptions.php app/Services/Dossiers/Calculation/DossierAssessmentEngine.php tests/Feature/Dossiers/Calculation/DossierAssessmentEngineTest.php
git commit -m "Report DIP IFRA ingredient uses and block approval until each one concludes"
```

---

### Task 5: Verification, migration and spec

**Files:**
- Modify: `docs/superpowers/specs/2026-10-21-product-information-file-dip-design.md`

- [ ] **Step 1: Full suite**

```bash
export LANG=en_US.UTF-8
php artisan test --compact tests/Feature/Dossiers
php artisan test --compact --parallel
```

Expected: all pass.

- [ ] **Step 2: Migrate the owner's local database (with the owner present)**

```bash
php artisan migrate --no-interaction
php artisan truss:diff
```

Expected: one migration ran; `dossier_evidence_use_limits` has `limit_type` and a nullable `max_percent`.

- [ ] **Step 3: Spec**

In §4.2, after the line starting with `- **Several oils, one allergen:**`, add:

```markdown
- **IFRA use check (phase 3b):** each fragrance ingredient, as purchased (a blend at its whole dosage), is compared with its IFRA limit for the formula's category (category 9 for the bar-soap pilot, or a justified exception) and amendment. Supplier IFRA certificates conclude only when they record that amendment and are confirmed to cover the qualified grade; every validated supplier must be covered. The ingredient's own IFRA certificate is shown as a fallback but never concludes. An exceeded limit is `non_conforme` with its own reason; missing or unusable information is « À justifier »; both block approval. Restrictions from constituents shared across ingredients are flagged for manual review.
```

In §3.2, in the structured-summary row of IFRA certificates, add "amendment covered, grade coverage, limit per IFRA category (maximum or explicit no restriction)" and replace "peroxide value" with "peroxide value as reported (value, unit, comparison, measured or specification)".

- [ ] **Step 4: Commit**

```bash
graphify update .
git add docs/superpowers/specs/2026-10-21-product-information-file-dip-design.md
git commit -m "Record the DIP IFRA ingredient-use check in the spec"
```

---

## Self-review notes

- **Codex point 1 (missing information):** uncovered qualifications are listed with their reason and keep the use `a_justifier`, which blocks approval (Task 3 states, Task 4 gate).
- **Point 2 (amendment and grade):** recorded on the certificate (Task 1), required to conclude (Task 3); older amendments stay as evidence with `amendment_mismatch`; the ingredient certificate never concludes.
- **Point 3 (blends):** the check runs on recipe ingredients as purchased, before the engine's component expansion; constituent-level combinations get an explicit warning.
- **Point 4 (approval):** IFRA results are separate from `RowStateResolver` and gate `can_approve` directly, with `ifra_limit_exceeded` distinct from annex breaches.
- **Point 5 (null):** supplier limits are a number or an explicit no-restriction statement (Task 1); an ingredient certificate's blank maximum is unknown (Task 3).
- **Point 6 (peroxide):** value, unit, comparison and basis stored as reported; no conversion (Tasks 1–2).
- **Point 7 (basis and category):** finished cured mass, all fragrance retained; category 9 or a justified exception; `requires_aromatic_compliance` widens, never narrows, the check.
- **Not covered:** an IFRA standards database (restrictions by constituent), conversion of peroxide units, IFRA for leave-on products — later work.
