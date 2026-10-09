<?php

use App\Services\Translations\InterfaceTranslationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

uses(RefreshDatabase::class);

it('does not leave raw English validation messages in production lifecycle actions', function (): void {
    $rawMessages = collect(productionLifecycleActionFiles())
        ->filter(fn (string $path): bool => File::exists($path))
        ->flatMap(function (string $path): array {
            preg_match_all("/=>\\s*['\"]([A-Z][^'\"]*)['\"]/", File::get($path), $matches);

            return collect($matches[1])
                ->map(fn (string $message): string => "{$path}: {$message}")
                ->all();
        })
        ->values()
        ->all();

    expect($rawMessages)->toBeEmpty();
});

it('references owned English keys from production lifecycle actions', function (): void {
    $missingKeys = collect(productionLifecycleActionFiles())
        ->filter(fn (string $path): bool => File::exists($path))
        ->flatMap(function (string $path): array {
            preg_match_all("/__\(['\"]production_bench\.([^'\"]+)/", File::get($path), $matches);

            return $matches[1];
        })
        ->unique()
        ->filter(fn (string $key): bool => ! Lang::has("production_bench.{$key}", 'en'))
        ->values()
        ->all();

    expect($missingKeys)->toBeEmpty();
});

it('references owned English keys from the production index view', function (): void {
    $contents = File::get(resource_path('views/livewire/production-bench/production/production-index.blade.php'));
    preg_match_all("/__\(['\"]production_bench\.([^'\"]+)/", $contents, $matches);

    $missingKeys = collect($matches[1])
        ->unique()
        ->filter(fn (string $key): bool => ! Lang::has("production_bench.{$key}", 'en'))
        ->values()
        ->all();

    expect($missingKeys)->toBeEmpty();
});

it('keeps the production translation catalogue importable', function (): void {
    $this->seed('Database\\Seeders\\SupportedLocaleSeeder');

    expect(app(InterfaceTranslationCatalogue::class)->read(
        database_path('seeders/data/interface-translations.json'),
    ))->toHaveKey('translations');
});

/** @return list<string> */
function productionLifecycleActionFiles(): array
{
    return [
        app_path('Actions/Ingredients/CreateManufacturedIngredient.php'),
        app_path('Actions/Production/AbortProduction.php'),
        app_path('Actions/Production/AssignProductionBatchNumbers.php'),
        app_path('Actions/Production/CompleteProduction.php'),
        app_path('Actions/Production/CreateProductionDraft.php'),
        app_path('Actions/Production/DeleteProductionRun.php'),
        app_path('Actions/Production/GenerateFlashProductions.php'),
        app_path('Actions/Production/GenerateProductionTasks.php'),
        app_path('Actions/Production/IssueFinishedGoods.php'),
        app_path('Actions/Production/PrepareProductionStock.php'),
        app_path('Actions/Production/ReleaseOutputLot.php'),
        app_path('Actions/Production/ReleaseProductionStock.php'),
        app_path('Actions/Production/ReopenProductionTask.php'),
        app_path('Actions/Production/ResetProductionTaskDate.php'),
        app_path('Actions/Production/RescheduleProduction.php'),
        app_path('Actions/Production/RescheduleProductionTask.php'),
        app_path('Actions/Production/SaveProductionActuals.php'),
        app_path('Actions/Production/SaveProductionOutputSettings.php'),
        app_path('Actions/Production/ScheduleProduction.php'),
        app_path('Actions/Production/StartProduction.php'),
        app_path('Actions/Production/UpdateProductionPlan.php'),
    ];
}

it('provides every editing message in all six catalogue locales with matching placeholders', function (): void {
    $this->seed('Database\\Seeders\\SupportedLocaleSeeder');
    $english = Arr::dot(Lang::get('production_bench.editing', [], 'en'));
    $catalogue = app(InterfaceTranslationCatalogue::class)->read(database_path('seeders/data/interface-translations.json'));
    $rows = collect($catalogue['translations'])->where('group', 'production_bench')->keyBy('key');
    foreach ($english as $key => $message) {
        foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
            $translated = $rows->get('editing.'.$key)['text'][$locale] ?? null;
            expect($translated)->toBeString()->not->toBeEmpty();
            preg_match_all('/:[a-z_]+/', $message, $sourcePlaceholders);
            preg_match_all('/:[a-z_]+/', $translated, $translatedPlaceholders);
            expect($translatedPlaceholders[0])->toBe($sourcePlaceholders[0]);
        }
    }
});

it('provides date save and upload feedback in all six catalogue locales', function (): void {
    $this->seed('Database\\Seeders\\SupportedLocaleSeeder');
    $catalogue = app(InterfaceTranslationCatalogue::class)->read(database_path('seeders/data/interface-translations.json'));
    $rows = collect($catalogue['translations'])->where('group', 'production_bench')->keyBy('key');
    foreach (['production.save_date', 'production.uploading', 'production.upload_ready', 'production.upload_failed', 'production.attaching'] as $key) {
        expect(Lang::has('production_bench.'.$key, 'en'))->toBeTrue();
        foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
            expect($rows->get($key)['text'][$locale] ?? null)->toBeString()->not->toBeEmpty();
        }
    }
});

it('provides translated recovery messages for reload and saved display failures', function (): void {
    $catalogue = collect(json_decode(File::get(database_path('seeders/data/interface-translations.json')), true, flags: JSON_THROW_ON_ERROR)['translations']);
    foreach (['refresh_failed', 'reload_failed'] as $key) {
        expect(Lang::has("production_bench.editing.{$key}", 'en'))->toBeTrue();
        $row = $catalogue->first(fn (array $row): bool => $row['group'] === 'production_bench' && $row['key'] === "editing.{$key}");
        foreach (['de', 'es', 'fr', 'it', 'nl', 'pt_BR'] as $locale) {
            expect($row['text'][$locale] ?? null)->toBeString()->not->toBeEmpty();
        }
    }
});
