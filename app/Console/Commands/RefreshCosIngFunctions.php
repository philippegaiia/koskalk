<?php

namespace App\Console\Commands;

use App\Services\IngredientEnrichment\CosIngFunctionRefresher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ingredients:refresh-cosing-functions
    {--ingredient=* : Limit the refresh to these catalog keys}
    {--apply : Write the changes; without it the command only previews}')]
#[Description('Re-read CosIng and replace only the CosIng-sourced function links of platform ingredients')]
class RefreshCosIngFunctions extends Command
{
    public function __construct(private readonly CosIngFunctionRefresher $refresher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $catalogKeys = collect((array) $this->option('ingredient'))
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->map(fn (string $key): string => trim($key))
            ->values()
            ->all();
        $plan = $this->refresher->plan($catalogKeys);

        $this->table(
            ['Catalog key', 'Ingredient', 'Status', 'Added', 'Removed', 'Reason'],
            $plan
                ->map(fn (array $row): array => [
                    $row['catalog_key'] ?? '—',
                    $row['display_name'] ?? '—',
                    $row['status'],
                    implode(', ', $row['added']) ?: '—',
                    implode(', ', $row['removed']) ?: '—',
                    $row['reason'] ?? '',
                ])
                ->all(),
        );

        $counts = $plan->countBy('status');
        $summary = sprintf(
            '%d to change, %d unchanged, %d skipped.',
            $counts->get('change', 0),
            $counts->get('unchanged', 0),
            $counts->get('skipped', 0),
        );

        if (! $this->option('apply')) {
            $this->comment("Dry run only: {$summary} Pass --apply to write the changes.");

            return self::SUCCESS;
        }

        $updated = $this->refresher->apply($plan);
        $this->info("CosIng functions refreshed: {$updated} ingredients updated. {$summary}");

        return self::SUCCESS;
    }
}
