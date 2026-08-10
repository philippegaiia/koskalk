<?php

use App\Services\Translations\InterfaceTranslationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
