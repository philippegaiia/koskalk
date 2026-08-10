<?php

namespace App\Console\Commands;

use App\Services\IngredientCatalogConsolidationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('ingredients:consolidate-catalog {--apply : Apply only when every catalogue decision is explicitly resolved} {--json : Output machine-readable results}')]
#[Description('Preview taxonomy metadata and enforce the reviewed ingredient-consolidation gate')]
class ConsolidateIngredientCatalog extends Command
{
    public function __construct(private readonly IngredientCatalogConsolidationService $consolidation)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('apply')) {
            $preview = $this->consolidation->preview();

            if ($this->option('json')) {
                $this->line($preview->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->table(
                    ['Catalog key', 'Ingredient', 'Current', 'Canonical', 'Subcategory'],
                    $preview->map(fn (array $row): array => [
                        $row['catalog_key'],
                        $row['display_name'],
                        $row['from'] ?? '—',
                        $row['to'] ?? 'MISSING',
                        $row['subcategory'] ?? '—',
                    ])->all(),
                );
                $this->comment('Dry run only. Resolve every review decision before using --apply. Metadata seeding is intentionally separate from destructive consolidation.');
            }

            return self::SUCCESS;
        }

        try {
            $result = $this->consolidation->apply();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->info(sprintf(
                'Reviewed catalog applied: %d metadata updated, %d unchanged, %d merged, %d removed.',
                $result['updated'],
                $result['unchanged'],
                $result['merged'],
                $result['removed'],
            ));
        }

        return self::SUCCESS;
    }
}
