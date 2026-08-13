<?php

use App\Enums\IngredientCategory;
use App\Enums\OwnerType;
use App\Models\Ingredient;
use App\Models\IngredientTranslation;
use App\Services\IngredientEnrichment\ExportPlatformIngredientEnrichment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exports incomplete platform ingredients in deterministic catalog order', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $first = Ingredient::factory()->create([
        'catalog_key' => 'ADM-A',
        'category' => IngredientCategory::Other,
        'info_markdown' => null,
    ]);
    Ingredient::factory()->create([
        'catalog_key' => 'ADM-B',
        'category' => IngredientCategory::Other,
        'info_markdown' => null,
    ]);
    makeCompleteIngredient('ADM-C');

    $path = sys_get_temp_dir().'/platform-ingredient-export-'.uniqid().'.jsonl';
    $exporter = app(ExportPlatformIngredientEnrichment::class);

    $firstExport = $exporter->handle($path);
    $firstContents = file_get_contents($path);
    $secondExport = $exporter->handle($path);

    expect($firstExport['records'])->toBe(2)
        ->and($secondExport['sha256'])->toBe($firstExport['sha256'])
        ->and(file_get_contents($path))->toBe($firstContents)
        ->and(json_decode(explode(PHP_EOL, trim((string) $firstContents))[0], true)['catalog_key'])
        ->toBe($first->catalog_key);

    unlink($path);
});

it('supports explicit complete selection and excludes private ingredients', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $complete = makeCompleteIngredient('ADM-COMPLETE');
    $private = Ingredient::factory()->create([
        'catalog_key' => 'USR-PRIVATE',
        'owner_type' => OwnerType::User,
        'owner_id' => 999,
    ]);

    $path = sys_get_temp_dir().'/platform-ingredient-export-'.uniqid().'.jsonl';
    $result = app(ExportPlatformIngredientEnrichment::class)->handle(
        $path,
        [$complete->catalog_key, $private->catalog_key],
    );
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    expect($result['records'])->toBe(1)
        ->and(json_decode($lines[0], true)['catalog_key'])->toBe($complete->catalog_key);

    unlink($path);
});

it('fails clearly when an explicit catalog key does not exist', function (): void {
    $path = sys_get_temp_dir().'/platform-ingredient-export-'.uniqid().'.jsonl';

    expect(fn () => app(ExportPlatformIngredientEnrichment::class)->handle($path, ['MISSING-KEY']))
        ->toThrow(RuntimeException::class, 'Unknown ingredient catalog key');
});

it('writes the fixed input contract including vocabulary, requested output, and research rules', function (): void {
    $this->seed(SupportedLocaleSeeder::class);
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'ADM-CONTRACT-EXPORT',
        'category' => IngredientCategory::Other,
    ]);
    $path = sys_get_temp_dir().'/platform-ingredient-export-'.uniqid().'.jsonl';

    app(ExportPlatformIngredientEnrichment::class)->handle($path, [$ingredient->catalog_key]);
    $record = json_decode((string) file_get_contents($path), true);

    expect($record)->toHaveKeys([
        'format',
        'schema_version',
        'catalog_key',
        'source_fingerprint',
        'current',
        'vocabulary',
        'requested_output',
        'research_rules',
    ])
        ->and($record['format'])->toBe('soapkraft-platform-ingredient-enrichment-input')
        ->and($record['vocabulary']['markets'])->toBe(['eu', 'us'])
        ->and($record['requested_output']['fields'])->toContain('market_labels')
        ->and($record['research_rules']['deferred_fields'])->toContain('sap');

    unlink($path);
});

function makeCompleteIngredient(string $catalogKey): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => $catalogKey,
        'category' => IngredientCategory::Other,
        'info_markdown' => "## Overview\nA useful ingredient.\n\n## Formulation use\nUsed in simple formulas.",
    ]);

    foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
        IngredientTranslation::factory()->create([
            'ingredient_id' => $ingredient->id,
            'locale' => $locale,
            'display_name' => "Name {$locale}",
            'info_markdown' => "## Overview\nA translated ingredient.\n\n## Formulation use\nUsed in translated formulas.",
        ]);
    }

    return $ingredient;
}
