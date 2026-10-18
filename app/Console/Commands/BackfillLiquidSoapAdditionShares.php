<?php

namespace App\Console\Commands;

use App\Enums\SoapProcess;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Services\RecipeWorkbenchPhaseBlueprints;
use App\Services\RecipeWorkbenchService;
use App\Support\NumberLocale;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Liquid soap additions saved before their share of the finished batch was
 * stored get it back from their saved weight and the finished batch that
 * version recalculates to with current ingredient data, so a chemistry
 * change since the save shows in the recovered share. Bar soap and cosmetic
 * rows are never touched.
 */
#[Signature('liquid-soap:backfill-addition-shares {--apply : Store the recovered shares; otherwise preview only}')]
#[Description('Preview or store the share of the finished batch for liquid soap additions saved before shares were stored')]
class BackfillLiquidSoapAdditionShares extends Command
{
    public function __construct(private readonly RecipeWorkbenchService $workbench)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $set = 0;
        $unresolved = 0;

        RecipeVersion::withoutGlobalScopes()
            ->whereHas('recipe', fn (EloquentBuilder $recipe) => $recipe->withoutGlobalScopes()->whereHas('productFamily', fn (EloquentBuilder $family) => $family->where('soap_process', SoapProcess::LiquidPaste)))
            ->whereExists(fn (QueryBuilder $items) => $this->missingShares($items->select(DB::raw(1))->from('recipe_items')->whereColumn('recipe_items.recipe_version_id', 'recipe_versions.id')))
            ->eachById(function (RecipeVersion $version) use (&$set, &$unresolved): void {
                try {
                    $shares = $this->recoveredShares($version);
                } catch (Throwable $exception) {
                    $shares = null;
                    $this->warn("Version {$version->id}: {$exception->getMessage()}");
                }

                if ($shares === null) {
                    $unresolved++;
                    $this->warn("Version {$version->id}: liquid soap process could not be calculated, left unchanged.");

                    return;
                }

                $this->line("Version {$version->id}: ".count($shares).' addition share(s)');

                if (! $this->option('apply')) {
                    $set += count($shares);

                    return;
                }

                $set += DB::transaction(fn (): int => collect($shares)
                    ->sum(fn (string $share, int $itemId): int => RecipeItem::withoutGlobalScopes()->whereKey($itemId)->whereNull('final_percentage')->update(['final_percentage' => $share])), attempts: 5);
            });

        $this->info(($this->option('apply') ? 'Set' : 'Would set').": {$set}; unresolved versions (unchanged): {$unresolved}.");

        return $unresolved > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Each addition row without a share, keyed by id, with its share of the
     * finished batch the saved weights give; null when the process fails.
     *
     * @return array<int, string>|null
     */
    private function recoveredShares(RecipeVersion $version): ?array
    {
        $snapshot = $this->workbench->versionSnapshot(Recipe::withoutGlobalScopes()->find($version->recipe_id), (int) $version->id);
        $process = data_get($snapshot, 'calculation.liquid_process');
        $finalWeight = is_array($process) && ($process['status'] ?? null) === 'ok' ? (string) $process['final_weight'] : null;

        if ($finalWeight === null || bccomp($finalWeight, '0', 18) <= 0) {
            return null;
        }

        $oilWeight = NumberLocale::normalizeDecimalString(sprintf('%.12F', (float) data_get($snapshot, 'draft.oilWeight', 0))) ?? '0';

        return $this->missingShares(RecipeItem::withoutGlobalScopes()->where('recipe_version_id', $version->id))
            ->get(['recipe_items.id', 'recipe_items.weight', 'recipe_items.percentage'])
            ->mapWithKeys(function (RecipeItem $item) use ($oilWeight, $finalWeight): array {
                // The preview uses a row's weight, or its % of oils when it has none.
                $weight = NumberLocale::normalizeDecimalString((string) $item->weight) ?? '0';
                $weight = bccomp($weight, '0', 18) > 0
                    ? $weight
                    : bcdiv(bcmul(NumberLocale::normalizeDecimalString((string) $item->percentage) ?? '0', $oilWeight, 18), '100', 18);

                return [$item->id => bcadd(bcdiv(bcmul($weight, '100', 18), $finalWeight, 18), '0.00005', 4)];
            })
            ->all();
    }

    /**
     * Restricts a recipe_items query to additive and fragrance rows without a share.
     */
    private function missingShares(EloquentBuilder|QueryBuilder $items): EloquentBuilder|QueryBuilder
    {
        return $items->whereNull('recipe_items.final_percentage')
            ->whereIn('recipe_items.recipe_phase_id', fn (QueryBuilder $phases) => $phases->select('id')->from('recipe_phases')->whereIn('slug', RecipeWorkbenchPhaseBlueprints::ADDITION_PHASES));
    }
}
