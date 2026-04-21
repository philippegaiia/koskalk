# Carrier Oil Data Seeder — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Seed carrier oil chemistry (fatty acid profiles, KOH SAP, iodine, INS, INCI names) from Mendrulandia calculator data, driven by a user-provided CSV of common names.

**Architecture:** An Artisan command parses the Mendrulandia JS for oil data, matches by common name against the user's CSV diff, enriches with INCI lookup, and writes a JSON file consumed by the existing `CarrierOilChemistrySeeder`. A separate diff command identifies missing oils.

**Tech Stack:** Laravel Artisan, existing seeder infrastructure, PHP DOMDocument for JS parsing, static INCI lookup table.

---

## File Structure

- `app/Console/Commands/ImportCarrierOilChemistryFromMendrulandia.php` — new command
- `app/Console/Commands/DiffCarrierOilsFromCsv.php` — new diff command
- `database/seeders/data/mendrulandia_oils.json` — extracted oil data (generated)
- `config/catalog-imports.php` — already exists, no changes needed

---

## Task 1: Extract Mendrulandia Oil Data

**Files:**
- Create: `database/seeders/data/mendrulandia_oils.json`

- [ ] **Step 1: Write a temporary extraction script**

Create `scripts/extract_mendrulandia_oils.php` to parse the embedded JS and output JSON:

```php
<?php
// scripts/extract_mendrulandia_oils.php
// Run once: php scripts/extract_mendrulandia_oils.php

$jsPath = $argv[1] ?? 'https://calc.mendrulandia.es/js/o_en.js?ver=4.7.45';
$jsContent = file_get_contents($jsPath);

// Find the oils/lista JSON data in the JS
// Look for patterns like: lista = [...] or JSON.parse("...")
// Extract and decode

$oils = []; // Parsed oils

file_put_contents(
    __DIR__.'/database/seeders/data/mendrulandia_oils.json',
    json_encode($oils, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
```

- [ ] **Step 2: Run the extraction script**

Run: `php scripts/extract_mendrulandia_oils.php`
Expected: Generates `database/seeders/data/mendrulandia_oils.json` with ~60 oils

- [ ] **Step 3: Review and clean up the generated JSON**

Check that fatty acid keys map correctly. The JS uses numeric keys (p23, p24...) which need mapping to platform keys (lauric→p24, myristic→p23, etc.)

---

## Task 2: INCI Name Lookup Table

**Files:**
- Create: `app/Data/InciNameLookup.php`

- [ ] **Step 1: Write the INCI lookup class**

```php
<?php

namespace App\Data;

class InciNameLookup
{
    /**
     * @return array<string, string> common_name => INCI name
     */
    public static function map(): array
    {
        return [
            'Coconut Oil' => 'Cocos Nucifera',
            'Olive Oil' => 'Olea Europaea',
            'Palm Oil' => 'Elaeis Guineensis',
            'Castor Oil' => 'Ricinus Communis',
            'Sunflower Oil' => 'Helianthus Annuus',
            'Sweet Almond Oil' => 'Prunus Amygdalus Dulcis',
            'Apricot Kernel Oil' => 'Prunus Armeniaca',
            'Avocado Oil' => 'Persea Gratissima',
            'Babassu Oil' => 'Orbignya Oleifera',
            'Black Cumin Oil' => 'Nigella Sativa',
            'Borage Oil' => 'Borago Officinalis',
            'Broccoli Seed Oil' => 'Brassica Oleracea Italica',
            'Camellia Oil' => 'Camellia Japonica',
            'Canola Oil' => 'Brassica Campestris',
            'Carrot Seed Oil' => 'Daucus Carota Sativa',
            'Cherry Kernel Oil' => 'Prunus Avium',
            'Chia Seed Oil' => 'Salvia Hispanica',
            'Cocoa Butter' => 'Theobroma Cacao',
            'Coconut Oil, Fractionated' => 'Caprylic/Capric Triglyceride',
            'Corn Oil' => 'Zea Mays',
            'Flaxseed Oil' => 'Linum Usitatissimum',
            'Grape Seed Oil' => 'Vitis Vinifera',
            'Hazelnut Oil' => 'Corylus Avellana',
            'Hempseed Oil' => 'Cannabis Sativa',
            'Jojoba Oil' => 'Simmondsia Chinensis',
            'Karanja Oil' => 'Pongamia Glabra',
            'Kukui Nut Oil' => 'Aleurites Moluccana',
            'Linseed Oil' => 'Linum Usitatissimum',
            'Macadamia Oil' => 'Macadamia Integrifolia',
            'Mango Butter' => 'Mangifera Indica',
            'Meadowfoam Oil' => 'Limnanthes Alba',
            'Moringa Oil' => 'Moringa Oleifera',
            'Neem Oil' => 'Azadirachta Indica',
            'Nightly Primrose Oil' => 'Oenothera Biennis',
            'Oiticica Oil' => 'Licania Rigida',
            'Palm Kernel Oil' => 'Elaeis Guineensis',
            'Peach Kernel Oil' => 'Prunus Persica',
            'Peanut Oil' => 'Arachis Hypogaea',
            'Pistachio Oil' => 'Pistacia Vera',
            'Pomegranate Oil' => 'Punica Granatum',
            'Pumpkin Seed Oil' => 'Cucurbita Pepo',
            'Rapeseed Oil' => 'Brassica Napus',
            'Rice Bran Oil' => 'Oryza Sativa',
            'Rosehip Oil' => 'Rosa Rubiginosa',
            'Safflower Oil' => 'Carthamus Tinctorius',
            'Sal Butter' => 'Shorea Robusta',
            'Sesame Oil' => 'Sesamum Indicum',
            'Shea Butter' => 'Butyrospermum Parkii',
            'Soybean Oil' => 'Glycine Soja',
            'Tung Oil' => 'Aleurites Fordii',
            'Walnut Oil' => 'Juglans Regia',
            'Wheat Germ Oil' => 'Triticum Vulgare',
        ];
    }

    public static function find(string $commonName): ?string
    {
        return self::map()[$commonName] ?? null;
    }
}
```

- [ ] **Step 2: Add to composer autoload**

Add `App\\Data\\` to composer.json autoload if not present, then run `composer dump-autoload`.

---

## Task 3: Import Command

**Files:**
- Create: `app/Console/Commands/ImportCarrierOilChemistryFromMendrulandia.php`

- [ ] **Step 1: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Data\InciNameLookup;
use App\IngredientCategory;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use JsonException;
use RuntimeException;

#[Signature('catalog:import-carrier-oil-chemistry {--csv= : Path to CSV with common_name column}')]
#[Description('Import carrier oil chemistry from Mendrulandia data, matched by CSV common names.')]
class ImportCarrierOilChemistryFromMendrulandia extends Command
{
    public function handle(): int
    {
        $csvPath = $this->option('csv');
        $mendrulandiaPath = database_path('seeders/data/mendrulandia_oils.json');

        $oils = $this->loadMendrulandiaOils($mendrulandiaPath);
        $csvNames = $csvPath ? $this->loadCsvNames($csvPath) : null;

        $fattyAcidIdsByKey = FattyAcid::query()->pluck('id', 'key');

        $imported = 0;
        $skipped = 0;

        foreach ($oils as $oilData) {
            $commonName = $oilData['common_name'] ?? null;
            if (! $commonName) {
                continue;
            }

            if ($csvNames !== null && ! in_array($commonName, $csvNames, true)) {
                $skipped++;
                continue;
            }

            $ingredient = Ingredient::query()
                ->where('category', IngredientCategory::CarrierOil->value)
                ->where('display_name', $commonName)
                ->first();

            if (! $ingredient) {
                $this->warn("Ingredient not found: {$commonName}");
                continue;
            }

            $this->importChemistry($ingredient, $oilData);
            $imported++;
        }

        $this->info("Imported: {$imported}, skipped: {$skipped}");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Implement helper methods**

Add `loadMendrulandiaOils()` method that reads and parses the JSON file with fatty acid mapping from JS `pXX` keys to platform fatty acid keys:

```php
private function mendrulandiaKeyToPlatformKey(string $pKey): ?string
{
    return match ($pKey) {
        'p24' => 'lauric',
        'p23' => 'myristic',
        'p27' => 'palmitic',
        'p36' => 'stearic',
        'p28' => 'ricinoleic',
        'p30' => 'oleic',
        'p31' => 'linoleic',
        'p33' => 'linolenic',
        default => null,
    };
}
```

Also implement `loadCsvNames()` to extract unique common names from the CSV.

- [ ] **Step 3: Add chemistry import logic**

In `importChemistry()`, for each matched ingredient:
1. `IngredientSapProfile::updateOrCreate` with koh_sap_value, iodine_value, ins_value
2. Delete existing `IngredientFattyAcid` rows for the ingredient
3. Create new `IngredientFattyAcid` rows from the matched oil data

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/ImportCarrierOilChemistryFromMendrulandia.php app/Data/InciNameLookup.php
git commit -m "feat: add carrier oil chemistry import command"
```

---

## Task 4: Diff Command

**Files:**
- Create: `app/Console/Commands/DiffCarrierOilsFromCsv.php`

- [ ] **Step 1: Write the diff command**

```php
<?php

namespace App\Console\Commands;

use App\IngredientCategory;
use App\Models\Ingredient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('catalog:diff-carrier-oils {--csv= : Path to CSV with common_name column}')]
#[Description('Diff CSV common names against existing carrier oils in DB.')]
class DiffCarrierOilsFromCsv extends Command
{
    public function handle(): int
    {
        $csvPath = $this->option('csv');

        if (! $csvPath) {
            $this->error('--csv option is required');
            return self::FAILURE;
        }

        $csvNames = $this->loadCsvNames($csvPath);
        $dbOils = $this->existingCarrierOils();

        $inDb = [];
        $missingFromDb = [];

        foreach ($csvNames as $name) {
            if (in_array($name, $dbOils, true)) {
                $inDb[] = $name;
            } else {
                $missingFromDb[] = $name;
            }
        }

        $this->info("In DB ({$this->count($inDb)}):");
        $this->line(implode("\n", $inDb));

        $this->newLine();
        $this->warn("Missing from DB ({$this->count($missingFromDb)}):");
        $this->line(implode("\n", $missingFromDb));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Add helper methods**

Implement `loadCsvNames()` and `existingCarrierOils()` methods.

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/DiffCarrierOilsFromCsv.php
git commit -m "feat: add carrier oil CSV diff command"
```

---

## Task 5: Test the Pipeline

- [ ] **Step 1: Run diff on a sample CSV**

```bash
php artisan catalog:diff-carrier-oils --csv=/path/to/oils.csv
```

- [ ] **Step 2: Run the import on a few oils**

```bash
php artisan catalog:import-carrier-oil-chemistry --csv=/path/to/test.csv
```

- [ ] **Step 3: Verify data in DB**

```bash
php artisan catalog:report-missing-carrier-oil-chemistry
```

Expected: fewer missing oils after import.

---

## Spec Coverage Check

| Spec Requirement | Task |
|---|---|
| CSV diff against DB | Task 4 |
| Lookup by common name in calculator data | Task 3 |
| INCI name enrichment | Task 2 |
| Fatty acid profile import | Task 3 |
| KOH SAP, iodine, INS import | Task 3 |
| Idempotent (updateOrCreate) | Task 3 |
| Flag oils not in calculator | Task 3 (warns) |

## Dependencies

- `FattyAcid` records must already exist in DB (run `php artisan db:seed --class=FattyAcidSeeder` first)
- `mendrulandia_oils.json` must be generated (Task 1)
- INCI lookup covers top 50 oils — extend over time as needed
